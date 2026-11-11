import base64
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('agent', Path(__file__).with_name('finance-host-agent.py'))
a = importlib.util.module_from_spec(spec); spec.loader.exec_module(a)


class HostTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name) / 'repo'; self.repo.mkdir()
        self.state = Path(self.tmp.name) / 'state'; self.state.mkdir()
        self.old_repo, self.old_state = a.REPO, a.STATE
        a.REPO, a.STATE = self.repo, self.state
        (self.repo / 'app').mkdir()

    def tearDown(self):
        a.REPO, a.STATE = self.old_repo, self.old_state
        self.tmp.cleanup()

    def test_forbidden_paths_and_symlinks(self):
        for path in ('/etc/passwd', '.env', 'app/../../.env', 'app//test.php', 'app/test.sh', 'mcp/server.js'):
            with self.assertRaises(ValueError): a.target(path)
        (self.repo / 'app/link').symlink_to('/tmp', target_is_directory=True)
        with self.assertRaises(ValueError): a.target('app/link/x.php')

    def test_reject_command_and_unconfirmed_writes(self):
        for request in ({'action': 'exec', 'payload': {'command': 'id'}}, {'action': 'restart'}, {'action': 'logs', 'payload': {'lines': 1000}}, {'action': 'logs', 'payload': {'source': '../../.env'}}):
            with self.assertRaises(ValueError): a.dispatch(request)

    def test_scoped_restart_and_logs(self):
        with patch.object(a, 'run', return_value='password=abc Bearer sensitive') as run:
            result = a.dispatch({'action': 'logs', 'payload': {'source': 'app', 'lines': 3}})
            self.assertNotIn('sensitive', result['logs']); self.assertNotIn('abc', result['logs'])
            self.assertEqual(run.call_args.args[0], ['docker', 'exec', a.CONTAINER, 'tail', '-n', '3', '--', 'storage/logs/laravel.log'])
            a.dispatch({'action': 'restart', 'confirm': a.CONFIRM})
            self.assertEqual(run.call_args.args[0], ['docker', 'restart', '--time', '10', a.CONTAINER])

    def make_plan(self):
        dest = self.repo / 'app/test.php'; dest.write_bytes(b'old')
        plan = {'id': 'a'*32, 'created': __import__('time').time(), 'state': 'planned', 'applied': [], 'files': [
            {'path': 'app/test.php', 'old': base64.b64encode(b'old').decode(), 'new': base64.b64encode(b'new').decode(),
             'old_sha256': a.digest(b'old'), 'new_sha256': a.digest(b'new'), 'mode': 0o640, 'uid': dest.stat().st_uid, 'gid': dest.stat().st_gid}]}
        a.save(plan); return plan, dest

    def test_apply_rollback_preserves_local_changes_and_mode(self):
        plan, dest = self.make_plan()
        with patch.object(a, 'run', return_value=''):
            result = a.apply_update(plan['id']); self.assertEqual(result['state'], 'applied')
            self.assertEqual(dest.read_bytes(), b'new'); self.assertEqual(dest.stat().st_mode & 0o777, 0o640)
            dest.write_bytes(b'human edit')
            with self.assertRaises(ValueError): a.rollback(plan['id'])
            self.assertEqual(dest.read_bytes(), b'human edit')
            dest.write_bytes(b'new'); a.rollback(plan['id']); self.assertEqual(dest.read_bytes(), b'old')
            with self.assertRaises(ValueError): a.apply_update(plan['id'])

    def test_apply_refuses_changed_local_file(self):
        plan, dest = self.make_plan(); dest.write_bytes(b'human edit')
        with self.assertRaises(ValueError): a.apply_update(plan['id'])
        self.assertEqual(dest.read_bytes(), b'human edit')

    def test_failed_cache_clear_leaves_recoverable_backup(self):
        plan, dest = self.make_plan()
        with patch.object(a, 'run', side_effect=ValueError('failed')):
            with self.assertRaisesRegex(ValueError, 'incomplete'): a.apply_update(plan['id'])
        self.assertEqual(json.loads(a.plan_file(plan['id']).read_text())['state'], 'needs_rollback')
        with patch.object(a, 'run', return_value=''): a.rollback(plan['id'])
        self.assertEqual(dest.read_bytes(), b'old')

    def test_fixed_source_remote(self):
        with patch.object(a, 'git', return_value='https://evil.test/repo'):
            with self.assertRaises(ValueError): a.check_remote()

    def test_migrate_rejects_non_applied_plan(self):
        plan, _ = self.make_plan()
        with self.assertRaises(ValueError): a.dispatch({'action': 'migrate', 'confirm': a.CONFIRM, 'payload': {'plan_id': plan['id'], 'path': 'database/migrations/x.php'}})

    def test_plan_returns_reviewable_diff_and_preserves_exact_source_bytes(self):
        dest = self.repo / 'app/test.php'; dest.write_bytes(b'<?php\n// local change\n')
        source = '<?php\n// reviewed commit\n\n'
        def fake_git(*args):
            if args[:2] == ('remote', 'get-url'): return 'https://github.com/junaojsp/JRC_ADM.git'
            return ''
        def fake_run(args, *_, **kwargs):
            return source if args[0] == 'git' else ''
        with patch.object(a, 'git', side_effect=fake_git), patch.object(a, 'run', side_effect=fake_run):
            result = a.plan_update({'commit': 'a'*40, 'paths': ['app/test.php']})
        self.assertIn('-// local change', result['diff'])
        self.assertIn('+// reviewed commit', result['diff'])
        plan = json.loads(a.plan_file(result['plan_id']).read_text())
        self.assertEqual(base64.b64decode(plan['files'][0]['new']), source.encode())
        self.assertEqual(dest.read_bytes(), b'<?php\n// local change\n')

    def test_bounded_subprocess(self):
        import sys
        self.assertEqual(a.run([sys.executable, '-c', 'print("ok")']), 'ok')
        with self.assertRaises(ValueError): a.run([sys.executable, '-c', 'print("x"*1100000)'])
        with self.assertRaises(ValueError): a.run([sys.executable, '-c', 'import time; time.sleep(10)'], timeout=0.1)


if __name__ == '__main__': unittest.main()
