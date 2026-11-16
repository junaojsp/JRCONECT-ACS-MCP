#!/usr/bin/env python3
"""Read-only AN5516 fleet collector: bounded column walks, atomic serial snapshot."""
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
import fcntl
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

from snmp_pilot import BASE, FIELDS, atomic_json, metric

SERIAL_OID = '.1.3.6.1.4.1.5875.800.3.10.1.1.10'
STATUS_OID = '.1.3.6.1.4.1.5875.800.3.10.1.1.11'
ROOT = Path('/var/lib/jrc-collector')
MAX_ROWS = 4096
STATE = {'0': 'sem_sinal', '1': 'online', '2': 'sem_energia', '3': 'offline'}


def canonical_serial(raw):
    value = raw.strip().strip('"').upper()
    if re.fullmatch(r'[0-9A-F]{16}', value):
        try:
            vendor = bytes.fromhex(value[:8]).decode('ascii')
        except (UnicodeError, ValueError):
            return None
        value = vendor + value[8:]
    return value if re.fullmatch(r'[A-Z]{4}[0-9A-F]{8}', value) else None


def parse_column(text, root):
    rows = {}
    prefix = root + '.'
    for line in text.splitlines():
        if not line.strip():
            continue
        match = re.fullmatch(r'(\.?[0-9]+(?:\.[0-9]+)+)\s*=\s*(.*)', line.strip())
        if not match:
            raise ValueError('resposta_invalida')
        oid = '.' + match[1].lstrip('.')
        value = match[2].strip()
        if value.startswith(('No Such', 'End of MIB')):
            raise ValueError('coluna_ausente')
        if not oid.startswith(prefix):
            raise ValueError('fora_da_coluna')
        index = oid[len(prefix):]
        if not re.fullmatch(r'[1-9][0-9]{0,9}', index) or int(index) > 4294967295:
            raise ValueError('indice_invalido')
        if index in rows or len(rows) >= MAX_ROWS:
            raise ValueError('coluna_duplicada_ou_excessiva')
        rows[index] = value
    if not rows:
        raise ValueError('coluna_vazia')
    return rows


@contextmanager
def private_config(community):
    if not re.fullmatch(r'[A-Za-z0-9_.@:+/=-]{1,128}', community):
        raise ValueError('comunidade_invalida')
    with tempfile.TemporaryDirectory(prefix='jrc-snmp-fleet-') as directory:
        path = Path(directory) / 'snmp.conf'
        fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write('defCommunity ' + community + '\n')
        yield dict(os.environ, SNMPCONFPATH=directory, MIBS='')


def read_column(host, oid, env, runner=subprocess.run):
    started = time.time()
    reply = runner(['snmpbulkwalk', '-v2c', '-Cr10', '-t', '2', '-r', '0', '-On', '-OQ',
                    host + ':161', oid], capture_output=True, text=True,
                   timeout=45, env=env, check=False)
    if reply.returncode:
        raise RuntimeError('coluna_incompleta')
    values = parse_column(reply.stdout, oid)
    elapsed = round((time.time() - started) * 1000)
    print('SNMP_COLUMN_OK ' + json.dumps({'oid': oid, 'rows': len(values), 'duration_ms': elapsed}), flush=True)
    return {'values': values, 'polled_at_epoch': int(time.time()), 'duration_ms': elapsed}


def assemble(columns, now):
    serials = columns['serial']['values']
    rows = {}
    issues = {}
    quarantined = []
    groups = {}
    for index, raw_serial in serials.items():
        serial = canonical_serial(raw_serial)
        if serial is not None:
            groups.setdefault(serial, []).append(index)
    for index, raw_serial in serials.items():
        serial = canonical_serial(raw_serial)
        if serial is None or len(groups[serial]) != 1:
            # Never guess an identity or let duplicate rows overwrite one another.
            # Keep the evidence local for audit, excluded from the serial lookup.
            quarantined.append({'index': index, 'raw_serial': raw_serial,
                                'issue': 'serial_sem_mapeamento' if serial is None else 'serial_duplicado'})
            continue
        location = columns['location']['values'].get(index, '').strip('"')
        if not re.fullmatch(r'PON [0-9]+/[0-9]+/[0-9]+', location):
            location = None
        status_raw = columns['state']['values'].get(index)
        state = STATE.get(status_raw, 'desconhecido')
        metrics = {}
        for name, (column, divisor, bounds, unit) in FIELDS.items():
            if divisor is None:
                continue
            source = columns.get(name)
            raw = source['values'].get(index) if source else None
            item = metric(raw, divisor, bounds, unit)
            item['polled_at_epoch'] = source['polled_at_epoch'] if source and raw is not None else None
            item['oid'] = f'{BASE}.{column}.{index}'
            # Offline ONU optical memory is never exposed as a current reading.
            if state != 'online':
                item['candidate_value'] = None
                item['issue'] = 'onu_nao_confirmada_online'
            metrics[name] = item
        rows[serial] = {'serial': serial, 'index': index, 'location': location,
                        'state': state, 'state_raw': status_raw,
                        'state_polled_at_epoch': columns['state']['polled_at_epoch'],
                        'source': 'olt_snmp', 'metrics': metrics}
        if location is None or state == 'desconhecido':
            issues[serial] = 'posicao_ou_estado_ausente'
    return {'schema': 2, 'source': 'olt_snmp', 'profile': 'AN5516_MIB_candidate',
            'completed_at_epoch': now, 'measurement_age_known': False,
            'unit_validation': 'pending_unm', 'total': len(rows),
            'discovered_total': len(serials), 'quarantined': quarantined,
            'quarantined_count': len(quarantined),
            'incomplete_identity_rows': len(issues), 'devices': rows,
            'column_stats': {key: {k: v for k, v in value.items() if k != 'values'}
                             for key, value in columns.items()}}


def run(root=ROOT):
    started = time.time()
    root.mkdir(mode=0o750, parents=True, exist_ok=True)
    with (root / 'snmp-fleet.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        status = {'attempted_at_epoch': int(started), 'success': False}
        try:
            host = os.environ.get('JRC_SNMP_HOST', '172.30.50.2')
            ipaddress.IPv4Address(host)
            secret = Path(os.environ.get('JRC_SNMP_COMMUNITY_FILE',
                                         '/etc/jrc-collector/snmp_community'))
            if secret.stat().st_mode & 0o077:
                raise ValueError('permissoes_comunidade')
            roots = {'serial': SERIAL_OID, 'state': STATUS_OID}
            roots.update({name: f'{BASE}.{column}' for name, (column, _, _, _) in FIELDS.items()})
            with private_config(secret.read_text().strip()) as env:
                # Two concurrent column walks, GETBULK max-repetitions=10.
                # Eight columns * 45s / two workers: bounded below four minutes.
                with ThreadPoolExecutor(max_workers=2) as executor:
                    futures = {key: executor.submit(read_column, host, oid, env) for key, oid in roots.items()}
                    columns = {key: future.result() for key, future in futures.items()}
            report = assemble(columns, int(time.time()))
            if not report['devices']:
                raise ValueError('nenhum_serial_associavel')
            destination = root / 'snmp-fleet.json'
            if destination.exists():
                previous = json.loads(destination.read_text())
                if (report['total'] < previous['total'] * .75 or
                    report['discovered_total'] < previous.get('discovered_total', previous['total']) * .75):
                    raise ValueError('queda_de_inventario')
            report['host'] = host
            report['duration_ms'] = round((time.time() - started) * 1000)
            atomic_json(destination, report)
            status.update(success=True, total=report['total'],
                          discovered_total=report['discovered_total'],
                          quarantined_count=report['quarantined_count'],
                          identity_complete=report['quarantined_count'] == 0,
                          online=sum(d['state'] == 'online' for d in report['devices'].values()),
                          duration_ms=report['duration_ms'],
                          incomplete_identity_rows=report['incomplete_identity_rows'])
        except Exception as error:
            status['error_type'] = type(error).__name__
            # Only a static allowlist of diagnostic codes may reach logs.
            codes = {'nenhum_serial_associavel', 'queda_de_inventario', 'permissoes_comunidade',
                     'coluna_ausente', 'coluna_vazia', 'fora_da_coluna', 'indice_invalido',
                     'coluna_duplicada_ou_excessiva', 'resposta_invalida', 'coluna_incompleta'}
            if str(error) in codes:
                status['error_code'] = str(error)
        status['finished_at_epoch'] = int(time.time())
        atomic_json(root / 'snmp-fleet-status.json', status)
        print('SNMP_FLEET ' + json.dumps(status))
        return 0 if status['success'] else 1


if __name__ == '__main__':
    raise SystemExit(run())
