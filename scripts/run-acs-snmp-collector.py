#!/usr/bin/env python3
"""Root-only scheduled acquisition; publish only non-secret snapshots to PHP."""
import fcntl
import grp
import json
import os
from pathlib import Path
import stat
import sys
import tempfile

ROOT = Path('/var/lib/jrc-collector')
PRIVATE = ROOT / 'private'
COLLECTOR = Path('/var/www/gacs/collector')


def publish(source, destination, gid):
    # Validate before publication; never publish errors or Python tracebacks as JSON.
    payload = json.loads(source.read_text())
    if not isinstance(payload, dict):
        raise ValueError('snapshot_invalid')
    fd, temporary = tempfile.mkstemp(prefix='.snmp-publish-', dir=destination.parent)
    try:
        with os.fdopen(fd, 'w') as output:
            os.fchown(output.fileno(), 0, gid)
            os.fchmod(output.fileno(), 0o640)
            json.dump(payload, output, ensure_ascii=False)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, destination)
    finally:
        Path(temporary).unlink(missing_ok=True)


def main():
    if os.geteuid() != 0:
        raise PermissionError('root_required')
    os.umask(0o077)
    gid = grp.getgrnam('www-data').gr_gid
    for directory in (ROOT, PRIVATE):
        if directory.is_symlink():
            raise ValueError('directory_symlink')
    ROOT.mkdir(parents=True, exist_ok=True)
    os.chown(ROOT, 0, gid)
    os.chmod(ROOT, 0o750)
    PRIVATE.mkdir(mode=0o700, exist_ok=True)
    os.chown(PRIVATE, 0, 0)
    os.chmod(PRIVATE, 0o700)
    lock_path = PRIVATE / 'publish.lock'
    with lock_path.open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print('SNMP_SCHEDULE_ALREADY_RUNNING')
            return 2
        # Preserve drop protection on the first scheduled acquisition.
        old = ROOT / 'snmp-fleet.json'
        baseline = PRIVATE / 'snmp-fleet.json'
        if not baseline.exists() and old.is_file():
            data = json.loads(old.read_text())
            baseline.write_text(json.dumps(data))
            baseline.chmod(0o600)
        sys.path.insert(0, str(COLLECTOR))
        from snmp_fleet import run
        code = run(PRIVATE)
        # Publish data first and attempt status last. Readers fail closed while
        # generations disagree. On failure only attempt status is replaced.
        if code == 0:
            publish(PRIVATE / 'snmp-fleet.json', ROOT / 'snmp-fleet.json', gid)
        publish(PRIVATE / 'snmp-fleet-status.json', ROOT / 'snmp-fleet-status.json', gid)
        print('SNMP_PUBLISHED ' + json.dumps({'success': code == 0}))
        return code


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as error:
        print('SNMP_PUBLISH_FAILED ' + type(error).__name__)
        raise SystemExit(1)
