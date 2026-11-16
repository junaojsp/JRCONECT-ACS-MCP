import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('publisher', Path(__file__).parents[1] / 'scripts/run-acs-snmp-collector.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class PublisherTests(unittest.TestCase):
    def test_atomic_readable_snapshot_has_no_secret_config(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'private.json'
            source.write_text(json.dumps({'schema': 2, 'devices': {}}))
            target = root / 'public.json'
            with patch.object(publisher.os, 'fchown') as chown:
                publisher.publish(source, target, 33)
            self.assertEqual(target.stat().st_mode & 0o777, 0o640)
            chown.assert_called_once()
            self.assertEqual(json.loads(target.read_text())['schema'], 2)
            self.assertFalse(any(p.name.startswith('.snmp-publish-') for p in root.iterdir()))

    def test_invalid_json_does_not_overwrite_previous_snapshot(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'bad.json'
            source.write_text('not json')
            target = root / 'public.json'
            target.write_text('{"old":true}')
            with self.assertRaises(ValueError):
                publisher.publish(source, target, 33)
            self.assertEqual(target.read_text(), '{"old":true}')

    def test_non_object_is_never_published(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'bad.json'
            source.write_text('[]')
            with self.assertRaises(ValueError):
                publisher.publish(source, root / 'public.json', 33)
            self.assertFalse((root / 'public.json').exists())


if __name__ == '__main__':
    unittest.main()
