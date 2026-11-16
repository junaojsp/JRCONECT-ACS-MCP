import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('deploy_snmp', Path(__file__).parents[1] / 'scripts/deploy-acs-snmp.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class OverlayTests(unittest.TestCase):
    def test_inventory_overlay_is_exact(self):
        original = '<?php\n            $devices[] = $parsed;\n'
        result = deploy.overlay('api/get-devices.php', original)
        self.assertIn('JrcSnmpTelemetry::apply', result)
        with self.assertRaises(ValueError):
            deploy.overlay('api/get-devices.php', original + original)

    def test_device_detail_and_actions_are_never_modified(self):
        protected = ['device-detail.php', 'lib/GenieACS.php', 'lib/GenieACS_Fast.php',
                     'api/get-onu-optical.php', 'api/get-device-detail.php',
                     'api/update-wifi-config.php', 'api/update-wan-config.php',
                     'api/get-ixc-live-traffic.php', 'api/get-ne-live-traffic.php',
                     'api/get-tr069-live-traffic.php', 'api/device-actions.php']
        for path in protected:
            with self.subTest(path=path), self.assertRaises(ValueError):
                deploy.overlay(path, '<?php\n        return $data;\n')
            self.assertNotIn(path, deploy.NEW_FILES)

    def test_existing_hook_is_not_silently_overwritten(self):
        with self.assertRaises(ValueError):
            deploy.overlay('api/get-devices.php', '<?php\n// JrcSnmpTelemetry')


if __name__ == '__main__':
    unittest.main()
