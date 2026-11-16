import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('pilot', Path(__file__).parents[1] / 'collector/snmp_pilot.py')
pilot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pilot)


class PilotTests(unittest.TestCase):
    def test_wire_values_mapping_and_secret_is_not_in_arguments(self):
        def query(args, **kwargs):
            self.assertNotIn('test-community', ' '.join(args))
            self.assertNotIn('snmpset', args)
            self.assertEqual(kwargs['timeout'], 3)
            config = Path(kwargs['env']['SNMPCONFPATH']) / 'snmp.conf'
            self.assertEqual(config.stat().st_mode & 0o777, 0o600)
            self.assertIn('test-community', config.read_text())
            return subprocess.CompletedProcess(args, 0, '\n'.join(
                f'{pilot.BASE}.{col}.371196416 = {val}' for col, val in
                [(2, '"PON 11/4/2"'), (6, '-2387'), (7, '250'),
                 (8, '330'), (9, '1840'), (10, '5125')]), '')
        report = pilot.collect('172.30.50.2', ['371196416'], 'test-community', query)
        row = report['rows'][0]
        self.assertEqual(row['location'], 'PON 11/4/2')
        for name, expected in [('rx', -23.87), ('tx', 2.5), ('voltage', 3.3),
                               ('bias_current', 18.4), ('temperature', 51.25)]:
            self.assertEqual(row['metrics'][name]['candidate_value'], expected)
            self.assertFalse(row['metrics'][name]['validated'])
            self.assertIsNone(row['metrics'][name]['measured_at_epoch'])
        self.assertNotIn('test-community', str(report))
        self.assertFalse(report['measurement_age_known'])

    def test_zero_sentinel_and_implausible_rx_are_not_healthy(self):
        for raw in ['0', '65535', '-116', 'bad']:
            self.assertIsNone(pilot.metric(raw, 100, (-40, -3), 'dBm')['candidate_value'])

    def test_timeout_does_not_reuse_values(self):
        def query(*args, **kwargs):
            raise subprocess.TimeoutExpired('snmpget', 3)
        row = pilot.collect('172.30.50.2', ['371196416'], 'test', query)['rows'][0]
        self.assertEqual(row['issue'], 'timeout')
        self.assertTrue(all(m['raw'] is None for m in row['metrics'].values()))

    def test_oid_and_sample_limits(self):
        for value in ['', '1,1', '1;id', ','.join(str(n) for n in range(1, 12))]:
            with self.assertRaises(ValueError):
                pilot.indices(value)
        with self.assertRaises(ValueError):
            pilot.parse_reply('.1.2.3 = 1', {'.1.2.4'})
        self.assertEqual(pilot.parse_reply('.1.2.4 = No Such Instance currently exists', {'.1.2.4'}), {})

    def test_failed_run_preserves_last_sample_and_marks_attempt(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            destination = root / 'snmp-pilot.json'
            destination.write_text('{"old": true}')
            secret = root / 'community'
            secret.write_text('test')
            secret.chmod(0o600)
            report = {'completed_at_epoch': 123, 'rows': [
                {'issue': 'timeout', 'location': None,
                 'metrics': {'rx': {'raw': None}}}]}
            with patch.object(pilot, 'OUTPUT_DIR', root), \
                 patch.object(pilot, 'collect', return_value=report), \
                 patch.dict(pilot.os.environ, {'JRC_SNMP_COMMUNITY_FILE': str(secret)}):
                with self.assertRaises(RuntimeError):
                    pilot.main()
            self.assertEqual(destination.read_text(), '{"old": true}')
            self.assertFalse(pilot.json.loads((root / 'snmp-pilot-status.json').read_text())['complete'])

    def test_atomic_snapshot_is_private(self):
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / 'pilot.json'
            pilot.atomic_json(destination, {'test': 1})
            self.assertEqual(destination.stat().st_mode & 0o777, 0o600)
            self.assertEqual(len(list(Path(directory).iterdir())), 1)


if __name__ == '__main__':
    unittest.main()
