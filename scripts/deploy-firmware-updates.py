#!/usr/bin/env python3
"""Install individual updates; preserve live customizations and existing firmware data."""
import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parent.parent
NEW = ['lib/FirmwareUpdates.php', 'assets/js/firmware-updates.js', 'firmware-updates.php']


def patch(name, text):
    if '/firmware-updates.php' in text:
        return text
    if name == 'firmware-library.php':
        anchor = '<h2>Repositorio Central de Firmwares</h2>'
        addition = '\n<p><a href="/firmware-updates.php" style="color:#86caff">Atualizar uma ONU e consultar histórico</a></p>'
        text = text.replace('<td class="fw-tag">Nao homologado</td>', '<td class="fw-tag"><?= fwEsc(($item[\'status\'] ?? \'\') === \'homologado\' ? \'Homologado\' : \'Nao homologado\') ?></td>')
        text = text.replace('<div class="fw-muted">Atualizacoes em execucao</div><div class="fw-num">0</div><div class="fw-muted">Modulo de envio nao habilitado</div>', '<div class="fw-muted">Atualizações individuais</div><a href="/firmware-updates.php#history">Consultar histórico e situação</a>')
    elif name == 'firmware-homologation.php':
        anchor = '<div class="card"><h3>Atualização</h3><div class="notice">Envio bloqueado enquanto não validarmos a integração do GenieACS e o procedimento oficial desta imagem.</div><button type="button" disabled aria-disabled="true" class="fw-simple-disabled">Atualizar ONU (ainda indisponível)</button></div>'
        replacement = '<div class="card"><h3>Atualização individual</h3><a href="/firmware-updates.php">Validar, confirmar envio e acompanhar resultado</a></div>'
        if text.count(anchor) != 1:
            raise ValueError('Bloco de atualização desconhecido: ' + name)
        return text.replace(anchor, replacement, 1)
    else:
        anchor = '        <button\n            class="btn btn-primary"'
        addition = '        <a class="btn btn-primary" href="/firmware-updates.php?device_id=<?= htmlspecialchars(rawurlencode((string)$deviceId), ENT_QUOTES, \'UTF-8\') ?>">Atualizar firmware</a>\n\n'
        if text.count(anchor) != 1:
            # This shortcut is optional; the repository and bench links are
            # the supported entry points when a live device page is customized.
            print('AVISO: device-detail.php personalizado; preservado sem adicionar atalho. '
                  'Acesse pelo repositório de firmwares ou /firmware-updates.php.')
            return text
        return text.replace(anchor, addition + anchor, 1)
    if text.count(anchor) != 1:
        raise ValueError('Página de repositório desconhecida')
    return text.replace(anchor, anchor + addition, 1)


def prepare(root):
    if not (root / 'config/config.php').is_file():
        raise ValueError('Configuração do painel ausente')
    changes = {}
    for name in NEW:
        source = (SOURCE / name).read_bytes()
        target = root / name
        if target.is_symlink():
            raise ValueError('Destino é link: ' + name)
        before = target.read_bytes() if target.exists() else None
        if before is not None and before != source:
            raise ValueError('Arquivo do novo módulo já existe com outro conteúdo: ' + name)
        changes[name] = (before, source)
    for name in ['firmware-library.php', 'firmware-homologation.php', 'device-detail.php']:
        target = root / name
        if target.is_symlink():
            raise ValueError('Destino é link: ' + name)
        before = target.read_bytes()
        changes[name] = (before, patch(name, before.decode('utf-8')).encode('utf-8'))
    for name, (_, after) in changes.items():
        with tempfile.NamedTemporaryFile(suffix=Path(name).suffix) as temp:
            temp.write(after)
            temp.flush()
            if name.endswith('.php'):
                subprocess.run(['php', '-l', temp.name], check=True, capture_output=True)
            elif shutil.which('node'):
                subprocess.run(['node', '--check', temp.name], check=True, capture_output=True)
    return {name: pair for name, pair in changes.items() if pair[0] != pair[1]}


def install(root, changes, backup_root):
    backup = backup_root / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True, mode=0o700)
    replaced = []
    try:
        for name, (before, after) in changes.items():
            target = root / name
            if (target.read_bytes() if target.exists() else None) != before:
                raise ValueError('Alteração concorrente: ' + name)
            if before is not None:
                saved = backup / name
                saved.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(target, saved)
            target.parent.mkdir(parents=True, exist_ok=True)
            fd, temp = tempfile.mkstemp(prefix='.firmware-', dir=target.parent)
            try:
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(after)
                os.chmod(temp, 0o644)
                if before is not None:
                    st = target.stat()
                    os.chown(temp, st.st_uid, st.st_gid)
                    os.chmod(temp, st.st_mode & 0o777)
                os.replace(temp, target)
                replaced.append(name)
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)
        (backup / 'new-files.txt').write_text('\n'.join(name for name, (before, _) in changes.items() if before is None))
    except BaseException:
        for name in reversed(replaced):
            if changes[name][0] is None:
                (root / name).unlink()
            else:
                shutil.copy2(backup / name, root / name)
        raise
    return backup


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['plan', 'apply'])
    parser.add_argument('--root', type=Path, default=Path('/var/www/gacs'))
    parser.add_argument('--backups', type=Path, default=Path('/var/backups/jrc-acs-firmware-updates'))
    args = parser.parse_args()
    changes = prepare(args.root)
    for name in changes:
        print('ARQUIVO:', name)
    print('Dados de firmware, configuração, menu e serviços: preservados. Nenhuma tarefa enviada.')
    if args.mode == 'plan':
        print('PLAN_OK')
    elif not changes:
        print('JA_INSTALADO')
    else:
        print('APLICADO_OK; backup:', install(args.root, changes, args.backups))


if __name__ == '__main__':
    main()
