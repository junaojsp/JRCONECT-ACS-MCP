#!/usr/bin/env python3
"""Patch only confirmed production markers; default is a dry run."""
import argparse
from datetime import datetime, timezone
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

MARK = '// JRC DASHBOARD SINGLE FLIGHT V1'
WRAPPER = '''// JRC DASHBOARD SINGLE FLIGHT V1
var jrcDashboardFlight = null;
function loadFinalDiscoveryData() {
    if (jrcDashboardFlight) return jrcDashboardFlight;
    jrcDashboardFlight = Promise.resolve().then(function () {
        return jrcLoadFinalDiscoveryDataOriginal.apply(window, []);
    }).finally(function () { jrcDashboardFlight = null; });
    return jrcDashboardFlight;
}
'''

def dashboard_patch(text):
    if MARK in text:
        if text.count(MARK) != 1 or 'async function jrcLoadFinalDiscoveryDataOriginal(' not in text:
            raise ValueError('Bloco instalado incompleto')
        return text
    pattern = r'async\s+function\s+loadFinalDiscoveryData\s*\(\s*\)\s*\{'
    if len(re.findall(pattern, text)) != 1:
        raise ValueError('Carregador ausente ou ambiguo; nenhum arquivo alterado')
    return re.sub(pattern, WRAPPER + '\nasync function jrcLoadFinalDiscoveryDataOriginal() {', text)

def chart_patch(text):
    # Keep the existing coverage/online safety logic, renderer and traffic guard.
    if 'root.renderReferenceDashboard = render' not in text:
        raise ValueError('Renderer personalizado desconhecido')
    count = len(re.findall(r'rx\s*<=\s*-28\b', text))
    if count == 0 and re.search(r'rx\s*<=\s*-27\b', text):
        return text
    if count != 1:
        raise ValueError('Regra critica ausente ou ambigua')
    text = re.sub(r'rx\s*<=\s*-28\b', 'rx <= -27', text)
    # Change only labels referring to RX, including escaped Unicode literals.
    text = re.sub(r'(RX[^\n`\'"<>]{0,35})-28', r'\g<1>-27', text)
    return text

def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--root', default='/var/www/gacs')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()
    root = Path(args.root).resolve()
    paths = ['dashboard.php', 'assets/js/dashboard-chart-fix.js']
    patches = [dashboard_patch, chart_patch]
    changes = []
    for rel, patch in zip(paths, patches):
        path = root / rel
        if path.is_symlink() or not path.is_file():
            raise ValueError('Arquivo regular necessario: ' + rel)
        old = path.read_bytes()
        new = patch(old.decode('utf-8')).encode('utf-8')
        if new != old:
            changes.append((path, old, new))
    if not changes:
        print('ALREADY_INSTALLED'); return
    # Validate all candidates before the first write.
    with tempfile.TemporaryDirectory() as tmp:
        for path, old, new in changes:
            candidate = Path(tmp) / path.name
            candidate.write_bytes(new)
            if path.suffix == '.php':
                subprocess.run(['php', '-l', str(candidate)], check=True)
            else:
                subprocess.run(['node', '--check', str(candidate)], check=True)
    print('PLAN: ' + ', '.join(str(p.relative_to(root)) for p, _, _ in changes))
    if not args.apply:
        print('DRY_RUN; use --apply'); return
    backup = Path('/var/backups/jrc-dashboard-consistency') / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True, mode=0o700)
    written = []
    try:
        for path, old, new in changes:
            target = backup / path.relative_to(root)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
        for path, old, new in changes:
            if path.read_bytes() != old:
                raise ValueError('Arquivo mudou durante a preparacao')
            # Keep inode ownership/mode; restore on any write failure.
            written.append((path, old))
            path.write_bytes(new)
    except Exception:
        for path, old in reversed(written):
            path.write_bytes(old)
        raise
    print('APPLIED backup=' + str(backup))
    print('Recarregue o navegador com Ctrl+F5. Nenhuma API ou acao foi alterada.')

if __name__ == '__main__':
    main()
