#!/usr/bin/env python3
"""Bounded read-only AN5516 SNMP pilot, invoked by the existing collector CLI."""
import fcntl
import ipaddress
import json
import math
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

BASE = '.1.3.6.1.4.1.5875.800.3.9.3.3.1'
# AN5116/5516 MIB Open Interface Specifications, ONU Uplink Optical Power.
# Firmware mapping and actual sample age still require validation against UNM.
FIELDS = {
    'location': (2, None, None, None),
    'rx': (6, 100, (-40, -3), 'dBm'),
    'tx': (7, 100, (-10, 10), 'dBm'),
    'voltage': (8, 100, (2, 5), 'V'),
    'bias_current': (9, 100, (0.01, 100), 'mA'),
    'temperature': (10, 100, (-30, 130), 'C'),
}
DEFAULT_INDICES = '371196416,371196672,403177984'
OUTPUT_DIR = Path('/var/lib/jrc-collector')


def indices(value):
    parts = value.split(',')
    if not 1 <= len(parts) <= 10 or len(set(parts)) != len(parts):
        raise ValueError('indices_invalidos')
    if any(not re.fullmatch(r'[1-9][0-9]{0,9}', p) or int(p) > 4294967295 for p in parts):
        raise ValueError('indices_invalidos')
    return parts


def parse_reply(output, requested):
    result = {}
    for line in output.splitlines():
        match = re.fullmatch(r'(\.?[0-9]+(?:\.[0-9]+)+)\s*=\s*(.*)', line.strip())
        if not match:
            continue
        oid = '.' + match[1].lstrip('.')
        if oid not in requested or oid in result:
            raise ValueError('resposta_oid_inesperado')
        value = match[2].strip()
        # Keep noSuchInstance/missing values unavailable; never coerce them to zero.
        if value.startswith(('No Such', 'NULL', 'End of MIB')):
            continue
        result[oid] = value
    return result


def metric(raw, divisor, bounds, unit):
    result = {'raw': raw, 'candidate_value': None, 'unit': unit,
              'validated': False, 'measured_at_epoch': None,
              'issue': 'ausente_ou_nao_suportado'}
    if raw is None:
        return result
    if not re.fullmatch(r'-?[0-9]+', raw):
        result['issue'] = 'valor_nao_inteiro'
        return result
    number = int(raw)
    if number in (0, 65535, 2147483647, 4294967295, -2147483648):
        result['issue'] = 'zero_ou_sentinela'
        return result
    value = number / divisor
    if not math.isfinite(value) or not bounds[0] <= value <= bounds[1]:
        result['issue'] = 'fora_da_faixa_do_piloto'
        return result
    result['candidate_value'] = value
    result['issue'] = 'aguarda_validacao_unm_e_idade_da_medicao'
    return result


def collect(host, targets, community, runner=subprocess.run, clock=time.time):
    ipaddress.IPv4Address(host)
    if not re.fullmatch(r'[A-Za-z0-9_.@:+/=-]{1,128}', community):
        raise ValueError('formato_comunidade_nao_suportado')
    rows = []
    # Net-SNMP reads the secret here, never from command arguments or logs.
    with tempfile.TemporaryDirectory(prefix='jrc-snmp-') as private:
        config = Path(private) / 'snmp.conf'
        fd = os.open(config, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write('defCommunity ' + community + '\n')
        env = dict(os.environ, SNMPCONFPATH=private, MIBS='')
        # At most 10 requests, 3 seconds per ONU, no retries, no walk or SET.
        for index in targets:
            requested = {f'{BASE}.{column}.{index}' for column, _, _, _ in FIELDS.values()}
            started = clock()
            values = {}
            issue = None
            try:
                reply = runner(['snmpget', '-v2c', '-t', '2', '-r', '0', '-On', '-OQ',
                                host + ':161', *sorted(requested)],
                               capture_output=True, text=True, timeout=3, env=env, check=False)
                values = parse_reply(reply.stdout, requested)
                if reply.returncode:
                    issue = 'resposta_parcial_ou_erro_snmp'
            except subprocess.TimeoutExpired:
                issue = 'timeout'
            except (OSError, ValueError):
                issue = 'falha_na_consulta'
            ended = clock()
            location = values.get(f'{BASE}.2.{index}')
            if location:
                location = location.strip('"')
                if not re.fullmatch(r'PON [0-9]+/[0-9]+/[0-9]+', location):
                    location = None
            rows.append({'index': index, 'location': location,
                         'source': 'olt_snmp', 'polled_at_epoch': int(ended),
                         'duration_ms': round((ended - started) * 1000),
                         'issue': issue, 'metrics': {
                             name: dict(metric(values.get(f'{BASE}.{column}.{index}'), divisor, bounds, unit),
                                        oid=f'{BASE}.{column}.{index}')
                             for name, (column, divisor, bounds, unit) in FIELDS.items()
                             if divisor is not None}})
    return {'schema': 1, 'source': 'olt_snmp', 'host': host,
            'completed_at_epoch': int(clock()), 'validation': 'pilot_only',
            'measurement_age_known': False, 'rows': rows}


def atomic_json(path, value):
    descriptor, temporary = tempfile.mkstemp(prefix='.snmp-pilot-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            json.dump(value, stream, ensure_ascii=False, indent=2)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    target_dir = OUTPUT_DIR
    target_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (target_dir / 'snmp-pilot.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        secret_path = Path(os.environ.get('JRC_SNMP_COMMUNITY_FILE',
                                          '/run/jrc-collector-secrets/snmp_community'))
        if secret_path.stat().st_mode & 0o077:
            raise ValueError('permissoes_comunidade_inseguras')
        targets = indices(os.environ.get('JRC_SNMP_PILOT_INDICES', DEFAULT_INDICES))
        report = collect(os.environ.get('JRC_SNMP_HOST', '172.30.50.2'), targets,
                         secret_path.read_text().strip())
        returned = sum(any(m['raw'] is not None for m in row['metrics'].values())
                       for row in report['rows'])
        status = {'attempted_at_epoch': report['completed_at_epoch'],
                  'requested': len(targets), 'returned': returned,
                  'complete': returned == len(targets) and all(
                      r['issue'] is None and r['location'] is not None and
                      all(m['raw'] is not None for m in r['metrics'].values())
                      for r in report['rows'])}
        atomic_json(target_dir / 'snmp-pilot-status.json', status)
        if not returned:
            # Never relabel an old successful snapshot as a new measurement.
            raise RuntimeError('nenhuma_resposta_optica')
        atomic_json(target_dir / 'snmp-pilot.json', report)
        print('SNMP_PILOT_OK ' + json.dumps(status))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Do not print subprocess output, exception details or secret values.
        print('SNMP_PILOT_FAILED ' + type(error).__name__)
        raise SystemExit(1)
