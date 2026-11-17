#!/usr/bin/env python3
"""Patch the installed list without replacing its newer PHP/backend implementation."""
import argparse
import datetime
import pathlib
import re
import shutil
import subprocess
import tempfile

MARK = 'JRC_CC_DEVICES_REFERENCE_V1'
DEFAULT = "['ipv6', 'descricao', 'preconfig']"

def once(text, old, new):
    if text.count(old) != 1:
        raise ValueError('Versão instalada incompatível: âncora ausente ou repetida: ' + old[:70])
    return text.replace(old, new, 1)

def layout(text):
    if MARK in text:
        return text
    text = once(text, "var LS_HIDDEN = 'jrc-devices-hidden-cols-v3';", "var LS_HIDDEN = 'jrc-devices-hidden-cols-v4';")
    text = once(text, "var hidden = lsGet(LS_HIDDEN, []);\n    if (!Array.isArray(hidden)) hidden = [];", """var defaults = ['ipv6', 'descricao', 'preconfig'];
    var hidden = lsGet(LS_HIDDEN, null);
    if (!Array.isArray(hidden)) {
        var previous = lsGet('jrc-devices-hidden-cols-v3', []);
        hidden = Array.from(new Set(defaults.concat(Array.isArray(previous) ? previous : [])));
        lsSet(LS_HIDDEN, hidden);
    }""")
    text = once(text, "{ name: 'Padrão', hidden: [] }", "{ name: 'Padrão', hidden: " + DEFAULT + " }")
    text = once(text, "return { text: real(d.dns_wan) };", "return dnsCell(d.dns_wan);")
    text = once(text, "return { text: dateTime(d.last_bootstrap_at), title: d.last_bootstrap_at ? 'Último BOOTSTRAP TR-069' : '' };", """var bootstrap = distinctBootstrap(d);
            return { text: dateTime(bootstrap), title: bootstrap ? 'Último BOOTSTRAP TR-069 distinto do registro' : 'Sem BOOTSTRAP distinto do registro' };""")
    text = once(text, 'function renderHead() {', """function renderHead() {
        grid.dataset.compactDefault = hidden.indexOf('ipv6') >= 0 && hidden.indexOf('descricao') >= 0 && hidden.indexOf('preconfig') >= 0 ? 'true' : 'false';""")
    helpers = r'''
    // JRC_CC_DEVICES_REFERENCE_V1: technical values only, never technical headings.
    function dnsCell(value) {
        var list = (Array.isArray(value) ? value : String(value || '').split(/[,;\s]+/))
            .map(function (v) { return real(v); }).filter(Boolean);
        list = Array.from(new Set(list));
        if (!list.length) return { text: '' };
        var remaining = list.slice(1);
        return { html: '<span class="jrc-dns-first" title="' + esc(list[0]) + '">' + esc(list[0]) + '</span>' +
            (remaining.length ? ' <span class="jrc-dns-more" tabindex="0" title="' + esc(remaining.join(', ')) + '" aria-label="Outros DNS: ' + esc(remaining.join(', ')) + '">+' + remaining.length + '</span>' : '') };
    }
    function distinctBootstrap(d) {
        // BOOT is a reboot, not a provisioning event. Never fall back to registration/BOOT.
        var bootstrap = Date.parse(d.last_bootstrap_at || '');
        var registered = Date.parse(d.registered_at || '');
        return isFinite(bootstrap) && (!isFinite(registered) || bootstrap > registered)
            ? d.last_bootstrap_at : '';
    }
'''
    return once(text, '    var SITUACAO =', helpers + '\n    var SITUACAO =')

def plan(root):
    repo = pathlib.Path(__file__).resolve().parents[1]
    changes = {}
    js = root / 'assets/js/jrc-devices-layout.js'
    changes[js] = layout(js.read_text())
    for relative in ['assets/css/jrc-devices-layout.css', 'assets/css/jrc-m4g-shell.css', 'assets/js/jrc-m4g-nav.js']:
        target = root / relative
        current = target.read_text()
        fragment = (repo / 'scripts/devices-reference' / pathlib.Path(relative).name).read_text()
        changes[target] = current if MARK in current else current + '\n' + fragment
    # Locate actual includes, including conditionally loaded footer assets; never copy stale devices.php.
    for path in [root / 'devices.php', *sorted((root / 'views/layouts').glob('*.php'))]:
        content = path.read_text()
        updated = re.sub(r'((?:jrc-devices-layout\.(?:js|css)|jrc-m4g-shell\.css|jrc-m4g-nav\.js)\?v=)[^\s\"\'<>]+', r'\g<1>20261117-cc1', content)
        if updated != content:
            changes[path] = updated
    return {p: t for p, t in changes.items() if p.read_text() != t}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['plan', 'apply', 'rollback'])
    parser.add_argument('--root', type=pathlib.Path, default=pathlib.Path('/var/www/gacs'))
    parser.add_argument('--stamp', help='Sufixo exato do backup para rollback')
    args = parser.parse_args()
    root = args.root.resolve()
    if args.action == 'rollback':
        if not args.stamp or not re.fullmatch(r'\d{8}-\d{6}', args.stamp):
            parser.error('--stamp AAAAMMDD-HHMMSS é obrigatório')
        backups = list(root.rglob('*.bak-cc-' + args.stamp))
        if not backups:
            raise ValueError('Nenhum backup encontrado')
        for backup in backups:
            target = pathlib.Path(str(backup).removesuffix('.bak-cc-' + args.stamp))
            shutil.copy2(backup, target)
            print('RESTAURADO:', target)
        return
    changes = plan(root)
    for path in changes:
        print('ALTERAR:', path)
    if args.action == 'plan':
        print('PLAN_OK' if changes else 'JA_INSTALADO')
        return
    # Validate every staged file before the first production write.
    with tempfile.TemporaryDirectory(prefix='acs-cc-') as temp:
        for index, (path, content) in enumerate(changes.items()):
            staged = pathlib.Path(temp) / (str(index) + path.suffix)
            staged.write_text(content)
            if path.suffix == '.php':
                subprocess.run(['php', '-l', str(staged)], check=True)
            elif path.suffix == '.js':
                subprocess.run(['node', '--check', str(staged)], check=True)
    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    written = []
    try:
        for path, content in changes.items():
            backup = pathlib.Path(str(path) + '.bak-cc-' + stamp)
            if backup.exists():
                raise ValueError('Backup já existe: ' + str(backup))
            shutil.copy2(path, backup)
            written.append((path, backup))
            staging = pathlib.Path(str(path) + '.cc-tmp')
            shutil.copy2(path, staging)
            staging.write_text(content)
            staging.replace(path)
    except Exception:
        for path, backup in reversed(written):
            shutil.copy2(backup, path)
        raise
    print('APLICADO_OK' if changes else 'JA_INSTALADO')
    if changes:
        print('REVERTER: python3 scripts/deploy-devices-reference.py rollback --stamp ' + stamp)

if __name__ == '__main__':
    main()
