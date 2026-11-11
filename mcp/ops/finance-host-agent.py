#!/usr/bin/env python3
"""Forced SSH command for Financeiro maintenance. Install root-owned, never writable by SSH user."""
import base64
import difflib
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import selectors
import signal
import stat
import subprocess
import sys
import tempfile
import time
import uuid

REPO = Path('/root/JRCONECT-WIKI')
STATE = Path('/var/lib/jr-finance-mcp')
CONTAINER = 'jr-financeiro-app'
REMOTE = 'jrc-adm'
CONFIRM = 'CONFIRMAR_MANUTENCAO_FINANCEIRO'
READS = {'status', 'git', 'containers', 'logs'}
WRITES = {'fetch', 'restart', 'cache_clear', 'plan_update', 'apply_update', 'rollback', 'migrate'}
ALLOWED_PREFIXES = ('app/', 'routes/', 'config/', 'database/migrations/')
LIMIT = 1024 * 1024


def run(argv, data=None, timeout=30, strip=True, merge_stderr=False):
    """Bounded output and timeout; never invoke a shell or return stderr."""
    # stdin comes only from a reviewed repository blob (PHP lint); use a temp file
    # to avoid deadlocking while simultaneously writing stdin and reading stdout.
    with tempfile.TemporaryFile() as source:
        if data is not None:
            source.write(data.encode()); source.seek(0)
        p = subprocess.Popen(argv, stdin=source if data is not None else subprocess.DEVNULL,
                             stdout=subprocess.PIPE, stderr=subprocess.STDOUT if merge_stderr else subprocess.DEVNULL, start_new_session=True)
        output = bytearray()
        deadline = time.monotonic() + timeout
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(p.stdout, selectors.EVENT_READ)
                while selector.get_map():
                    if time.monotonic() > deadline:
                        raise ValueError('Command timed out')
                    for key, _ in selector.select(min(0.2, max(0, deadline - time.monotonic()))):
                        chunk = os.read(key.fd, 65536)
                        if not chunk:
                            selector.unregister(key.fileobj)
                        else:
                            output.extend(chunk)
                            if len(output) > LIMIT: raise ValueError('Command output too large')
            p.wait(timeout=max(0.1, deadline - time.monotonic()))
            if p.returncode: raise ValueError('Command failed')
            text = output.decode('utf-8', errors='strict')
            return text.strip() if strip else text
        finally:
            if p.poll() is None:
                os.killpg(p.pid, signal.SIGKILL)
                p.wait()
            p.stdout.close()


def git(*args):
    return run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', str(REPO), *args])


def redact(text):
    text = re.sub(r'(?i)(https?://)[^/\s@]+@', r'\1[redacted]@', text)
    text = re.sub(r'(?i)\bBearer\s+\S+', 'Bearer [redacted]', text)
    text = re.sub(r'(?i)((?:password|passwd|secret|token|api[_-]?key|authorization)\s*[=:]\s*)[^\s,;]+', r'\1[redacted]', text)
    return text


def digest(data):
    return hashlib.sha256(data).hexdigest()


def target(path):
    if not isinstance(path, str) or not path.startswith(ALLOWED_PREFIXES):
        raise ValueError('Path outside Financeiro code allowlist')
    p = PurePosixPath(path)
    if p.is_absolute() or '..' in p.parts or str(p) != path or not path.endswith('.php'):
        raise ValueError('Invalid path')
    dest = REPO / path
    # Refuse symlinks in all components, including existing directories.
    for component in [dest, *dest.parents]:
        if component.is_symlink(): raise ValueError('Symlink not allowed')
        if component == REPO: break
    if dest.exists() and not dest.is_file(): raise ValueError('Not a regular file')
    return dest


def check_remote():
    url = git('remote', 'get-url', REMOTE)
    if url not in ('https://github.com/junaojsp/JRC_ADM.git', 'https://github.com/junaojsp/JRC_ADM', 'git@github.com:junaojsp/JRC_ADM.git'):
        raise ValueError('Unexpected Financeiro source remote')


def plan_file(plan_id):
    if not isinstance(plan_id, str) or not re.fullmatch(r'[0-9a-f]{32}', plan_id): raise ValueError('Invalid plan ID')
    return STATE / (plan_id + '.json')


def atomic_write(path, content, mode=0o600, uid=None, gid=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.mcp-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(content); out.flush(); os.fsync(out.fileno())
            os.fchmod(out.fileno(), mode)
            if uid is not None: os.fchown(out.fileno(), uid, gid)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)


def save(plan):
    atomic_write(plan_file(plan['id']), json.dumps(plan).encode())


def plan_update(payload):
    commit, paths = payload.get('commit'), payload.get('paths')
    if not isinstance(commit, str) or not re.fullmatch(r'[0-9a-f]{40}', commit): raise ValueError('Full commit SHA required')
    if not isinstance(paths, list) or not 1 <= len(paths) <= 30 or len(set(paths)) != len(paths): raise ValueError('1-30 unique paths required')
    check_remote()
    # The source object must already have been fetched from the dedicated ADM remote.
    if git('merge-base', '--is-ancestor', commit, f'refs/remotes/{REMOTE}/main') != '':
        raise ValueError('Commit not in Financeiro main')
    plan = {'id': uuid.uuid4().hex, 'commit': commit, 'created': time.time(), 'state': 'planned', 'files': [], 'applied': []}
    total = 0
    preview = []
    for path in paths:
        dest = target(path)
        # git show is used only with validated paths and a full SHA; no filters/hooks.
        new = run(['git', '-C', str(REPO), 'show', f'{commit}:{path}'], strip=False).encode()
        total += len(new)
        if total > LIMIT: raise ValueError('Update too large')
        run(['docker', 'exec', '-i', CONTAINER, 'php', '-l'], new.decode(), timeout=5)
        old = dest.read_bytes() if dest.exists() else None
        if old is not None and len(old) > LIMIT: raise ValueError('Local file too large')
        diff = ''.join(difflib.unified_diff((old or b'').decode('utf-8').splitlines(True), new.decode('utf-8').splitlines(True), fromfile='local/'+path, tofile='commit/'+path))
        preview.append(redact(diff))
        if sum(len(x.encode()) for x in preview) > 256 * 1024: raise ValueError('Diff too large; plan fewer files')
        st = dest.stat() if dest.exists() else dest.parent.stat()
        plan['files'].append({'path': path, 'old': base64.b64encode(old).decode() if old is not None else None,
                              'old_sha256': digest(old) if old is not None else None, 'new': base64.b64encode(new).decode(),
                              'new_sha256': digest(new), 'mode': stat.S_IMODE(st.st_mode) if old is not None else 0o644, 'uid': st.st_uid, 'gid': st.st_gid})
    save(plan)
    return {'plan_id': plan['id'], 'commit': commit, 'expires_in_seconds': 3600, 'diff': ''.join(preview), 'diff_redacted': True,
            'files': [{k: f[k] for k in ('path', 'old_sha256', 'new_sha256')} for f in plan['files']]}


def current_sha(dest):
    return digest(dest.read_bytes()) if dest.exists() else None


def apply_update(plan_id):
    plan = json.loads(plan_file(plan_id).read_text())
    if plan['state'] != 'planned' or time.time() - plan['created'] > 3600: raise ValueError('Plan expired or already used')
    for f in plan['files']:
        if current_sha(target(f['path'])) != f['old_sha256']: raise ValueError('Local file changed; create a new plan')
    plan['state'] = 'applying'; save(plan)
    try:
        for f in plan['files']:
            dest = target(f['path'])
            if current_sha(dest) != f['old_sha256']: raise ValueError('Local file changed during update')
            # Persist intent first, so even a process crash leaves a recoverable plan.
            plan['applied'].append(f['path']); save(plan)
            atomic_write(dest, base64.b64decode(f['new']), f['mode'], f['uid'], f['gid'])
        run(['docker', 'exec', CONTAINER, 'php', 'artisan', 'optimize:clear'])
        plan['state'] = 'applied'; save(plan)
        return {'plan_id': plan_id, 'state': 'applied', 'files': plan['applied'], 'database_migrated': False}
    except Exception:
        # Preserve backup and intent; rollback checks each current file before restoring.
        plan['state'] = 'needs_rollback'; save(plan)
        raise ValueError('Update incomplete; use rollback with the same plan ID')


def rollback(plan_id):
    plan = json.loads(plan_file(plan_id).read_text())
    if plan['state'] not in ('applied', 'applying', 'needs_rollback'): raise ValueError('Plan is not applied')
    files = [f for f in plan['files'] if f['path'] in plan['applied']]
    # Check every file before restoring any. Preserve subsequent human edits.
    for f in files:
        if current_sha(target(f['path'])) not in (f['old_sha256'], f['new_sha256']): raise ValueError('File changed after update; manual reconciliation required')
    for f in files:
        dest = target(f['path'])
        if f['old'] is None:
            if dest.exists(): dest.unlink()
        else: atomic_write(dest, base64.b64decode(f['old']), f['mode'], f['uid'], f['gid'])
    run(['docker', 'exec', CONTAINER, 'php', 'artisan', 'optimize:clear'])
    plan['state'] = 'rolled_back'; save(plan)
    return {'plan_id': plan_id, 'state': 'rolled_back', 'database_rolled_back': False}


def dispatch(request):
    action, payload = request.get('action'), request.get('payload', {})
    if action not in READS | WRITES or not isinstance(payload, dict): raise ValueError('Invalid action')
    if action in WRITES and request.get('confirm') != CONFIRM: raise ValueError('Confirmation required')
    if action == 'status':
        return {'linux': run(['uname', '-srmo']), 'uptime': run(['uptime', '-p'])}
    if action == 'git':
        return {'head': git('rev-parse', 'HEAD'), 'branch': git('branch', '--show-current'),
                'status': git('status', '--short', '--untracked-files=normal'),
                'remotes': redact(git('remote', '-v')), 'tracking': git('branch', '-vv')}
    if action == 'containers':
        return {'container': CONTAINER, 'status': run(['docker', 'ps', '-a', '--filter', 'name=^/'+CONTAINER+'$', '--format', '{{json .}}'])}
    if action == 'logs':
        lines = payload.get('lines', 100)
        if type(lines) is not int or not 1 <= lines <= 200: raise ValueError('Invalid line limit')
        source = payload.get('source', 'app')
        if source == 'app':
            output = run(['docker', 'exec', CONTAINER, 'tail', '-n', str(lines), '--', 'storage/logs/laravel.log'])
        elif source == 'container':
            output = run(['docker', 'logs', '--tail', str(lines), CONTAINER], merge_stderr=True)
        else: raise ValueError('Invalid log source')
        return {'logs': redact(output), 'untrusted_content': True}
    if action == 'fetch':
        check_remote(); git('fetch', '--no-tags', REMOTE, 'main:refs/remotes/'+REMOTE+'/main')
        return {'fetched': True, 'commit': git('rev-parse', f'{REMOTE}/main')}
    if action == 'restart':
        run(['docker', 'restart', '--time', '10', CONTAINER]); return {'restarted': CONTAINER}
    if action == 'cache_clear':
        run(['docker', 'exec', CONTAINER, 'php', 'artisan', 'optimize:clear']); return {'cache_cleared': True}
    if action == 'plan_update': return plan_update(payload)
    if action == 'apply_update': return apply_update(payload.get('plan_id'))
    if action == 'rollback': return rollback(payload.get('plan_id'))
    if action == 'migrate':
        # Explicitly migrate only a file delivered by an applied, reviewed update plan.
        plan = json.loads(plan_file(payload.get('plan_id')).read_text())
        path = payload.get('path')
        if plan['state'] != 'applied': raise ValueError('Applied plan required')
        f = next((f for f in plan['files'] if f['path'] == path), None)
        if not f or not path.startswith('database/migrations/') or current_sha(target(path)) != f['new_sha256']:
            raise ValueError('Migration outside applied plan')
        run(['docker', 'exec', CONTAINER, 'php', 'artisan', 'migrate', '--force', '--path='+path], timeout=60)
        return {'migrated': path, 'automatic_database_rollback': False}


def main():
    def expired(*_): raise ValueError('Host request timed out')
    signal.signal(signal.SIGALRM, expired)
    signal.alarm(80)
    os.umask(0o077)
    try:
        request = json.loads(sys.stdin.read(65537))
        if not isinstance(request, dict): raise ValueError('Invalid request')
        STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
        with (STATE / 'lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            result = dispatch(request)
        # Append metadata only, never payload/logs/files. A failed audit write fails closed.
        with (STATE / 'audit.jsonl').open('a') as audit:
            audit.write(json.dumps({'time': time.time(), 'action': request.get('action'), 'ok': True})+'\n')
        print(json.dumps({'ok': True, 'result': result}))
    except Exception as e:
        print(json.dumps({'ok': False, 'error': str(e) if isinstance(e, ValueError) else 'Finance host operation failed'}))


if __name__ == '__main__': main()
