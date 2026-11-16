import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('deploy_snmp', Path(__file__).parents[1] / 'scripts/deploy-acs-snmp.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class OverlayTests(unittest.TestCase):
    def test_strict_types_stays_first_and_legacy_source_not_replaced(self):
        legacy = '<?php\ndeclare(strict_types=1);\n$privateConfig = getenv("TEST_ONLY");\n'
        result = deploy.overlay('api/get-onu-optical.php', legacy)
        self.assertTrue(result.startswith('<?php\ndeclare(strict_types=1);'))
        self.assertIn('$privateConfig = getenv("TEST_ONLY");', result)
        self.assertLess(result.index('get-onu-snmp.php'), result.index('$privateConfig'))

    def test_parser_hook_is_unambiguous_and_no_actions_are_patched(self):
        result = deploy.overlay('lib/GenieACS_Fast.php', '<?php\n        return $data;\n')
        self.assertIn('JrcSnmpTelemetry::apply', result)
        with self.assertRaises(ValueError):
            deploy.overlay('lib/GenieACS_Fast.php', 'return $data; return $data;')
        with self.assertRaises(ValueError):
            deploy.overlay('api/update-wifi-config.php', '<?php')

    def test_existing_hook_is_not_silently_overwritten(self):
        with self.assertRaises(ValueError):
            deploy.overlay('api/get-onu-optical.php', '<?php\n// JrcSnmpTelemetry')


if __name__ == '__main__':
    unittest.main()
