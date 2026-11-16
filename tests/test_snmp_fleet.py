import importlib.util
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).parents[1] / 'collector'))
import snmp_fleet as fleet


class FleetTests(unittest.TestCase):
    def test_serial_identity_formats(self):
        self.assertEqual(fleet.canonical_serial('HWTC8feedba7'), 'HWTC8FEEDBA7')
        self.assertEqual(fleet.canonical_serial('485754438FEEDBA7'), 'HWTC8FEEDBA7')
        self.assertEqual(fleet.canonical_serial('GLGAbc08fad0'), 'GLGABC08FAD0')
        self.assertEqual(fleet.canonical_serial('DLNK007f0ad3'), 'DLNK007F0AD3')
        self.assertIsNone(fleet.canonical_serial('PON 11/4/2'))

    def columns(self):
        values = {'serial': 'HWTC8feedba7', 'state': '1', 'location': '"PON 11/4/2"',
                  'rx': '-2387', 'tx': '228', 'voltage': '328',
                  'bias_current': '700', 'temperature': '4800'}
        return {key: {'values': {'371196416': value}, 'polled_at_epoch': 1000,
                      'duration_ms': 10} for key, value in values.items()}

    def test_join_uses_index_then_canonical_serial(self):
        result = fleet.assemble(self.columns(), 1100)
        row = result['devices']['HWTC8FEEDBA7']
        self.assertEqual(row['metrics']['rx']['candidate_value'], -23.87)
        self.assertEqual(row['metrics']['temperature']['candidate_value'], 48)
        self.assertEqual(row['state'], 'online')
        self.assertIsNone(row['metrics']['rx']['measured_at_epoch'])

    def test_offline_retained_optical_values_are_hidden(self):
        columns = self.columns()
        columns['state']['values']['371196416'] = '2'
        row = fleet.assemble(columns, 1100)['devices']['HWTC8FEEDBA7']
        self.assertEqual(row['state'], 'sem_energia')
        self.assertEqual(row['metrics']['rx']['raw'], '-2387')
        self.assertIsNone(row['metrics']['rx']['candidate_value'])

    def test_duplicate_serial_quarantines_both_indices(self):
        columns = self.columns()
        columns['serial']['values']['371196672'] = '485754438FEEDBA7'
        report = fleet.assemble(columns, 1100)
        self.assertEqual(report['devices'], {})
        self.assertEqual(report['quarantined_count'], 2)
        self.assertEqual(report['discovered_total'], 2)
        self.assertTrue(all(row['issue'] == 'serial_duplicado' for row in report['quarantined']))

    def test_invalid_serial_does_not_discard_other_onus(self):
        columns = self.columns()
        columns['serial']['values']['371196672'] = '"0000000000000000"'
        report = fleet.assemble(columns, 1100)
        self.assertEqual(report['total'], 1)
        self.assertEqual(report['discovered_total'], 2)
        self.assertEqual(report['quarantined_count'], 1)
        self.assertIn('HWTC8FEEDBA7', report['devices'])
        self.assertEqual(report['quarantined'][0]['issue'], 'serial_sem_mapeamento')

    def test_column_boundaries_and_partial_walk(self):
        self.assertEqual(fleet.parse_column(f'{fleet.SERIAL_OID}.371196416 = "HWTC8feedba7"', fleet.SERIAL_OID),
                         {'371196416': '"HWTC8feedba7"'})
        for reply in ['', f'{fleet.SERIAL_OID} = No Such Object', '.1.2.3 = 4']:
            with self.assertRaises(ValueError):
                fleet.parse_column(reply, fleet.SERIAL_OID)


if __name__ == '__main__':
    unittest.main()
