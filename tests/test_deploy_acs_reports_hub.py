#!/usr/bin/env python3
"""Test safe split of ACS Consultas vs Relatorios without accessing production."""
from pathlib import Path
import importlib.util
import tempfile

file = Path(__file__).resolve().parents[1] / "scripts/deploy-acs-reports-hub.py"
spec = importlib.util.spec_from_file_location("acs_reports_hub", file)
assert spec and spec.loader
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)

header = (
    '<?php $pageTitle = "Consulta"; ?>\n'
    '<ul class="sidebar-menu">\n'
    '<li><a href="/dashboard.php"><span>Visão geral</span></a></li>\n'
    '<li><a class="active" href="/view-logs.php" data-tooltip="Relatórios">'
    '<i class="bi bi-file-earmark-text"></i><span>Relatórios</span></a></li>\n'
    '<li><a href="/devices.php"><span>Dispositivos</span></a></li>\n'
    '</ul>\n'
)
router = (
    '<?php echo "Roteadores"; ?>\n'
    '<a class="jrc-router-btn secondary" href="/view-logs.php">'
    'Voltar aos relatórios</a>\n'
    '<a href="/router-report.php?q=router">Buscar</a>\n'
)
signal = '<?php // Optical API and fetches are untouched ?>'
new_link = '/reports-hub.php'

with tempfile.TemporaryDirectory() as base, tempfile.TemporaryDirectory() as backups:
    root = Path(base)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // mock\n')
    (root / 'views/layouts').mkdir(parents=True)
    (root / deploy.NAV).write_text(header, encoding='utf-8')
    (root / deploy.ROUTER).write_text(router, encoding='utf-8')
    (root / 'view-logs.php').write_text(signal, encoding='utf-8')
    (root / 'dashboard.php').write_text('<?php echo "original"; ?>')

    planned = deploy.plan(root)
    assert set(planned) == {deploy.HUB, deploy.NAV, deploy.ROUTER}
    assert (root / deploy.NAV).read_text() == header
    assert (root / deploy.ROUTER).read_text() == router

    updated_nav = planned[deploy.NAV][1].decode()
    updated_router = planned[deploy.ROUTER][1].decode()
    assert updated_nav == header.replace('href="/view-logs.php"', f'href="{new_link}"')
    assert updated_router == router.replace('href="/view-logs.php"', f'href="{new_link}"')
    assert updated_nav.count('href="/devices.php"') == 1

    saved = deploy.apply(root, Path(backups), planned)
    assert saved and (saved / deploy.NAV).read_text() == header
    assert (saved / deploy.ROUTER).read_text() == router
    assert (root / 'view-logs.php').read_text() == signal
    assert (root / 'dashboard.php').read_text() == '<?php echo "original"; ?>'
    assert (root / deploy.HUB).is_file()

    assert not deploy.plan(root), 'Second installation must be idempotent'

    (root / deploy.NAV).write_text(
        (root / deploy.NAV).read_text().replace("Relatórios</span>", "Consultas</span>")
    )
    try:
        deploy.plan(root)
        raise AssertionError('Customized menu needs rejection')
    except deploy.UnsafeInstall:
        pass

# Never guess a target when the reports entry is missing or duplicated.
for incorrect in [
    header.replace('Relatórios</span>', 'Ajuda</span>'),
    header + '<a href="/view-logs.php">Relatórios</a>',
    '<a href="/custom-query.php">Relatórios</a>',
]:
    try:
        deploy.patch_navigation(incorrect.encode())
        raise AssertionError('Unknown/ambiguous sidebar should be rejected')
    except deploy.UnsafeInstall:
        pass

# Optical filter links must not be changed.
direct = ('<a href="/view-logs.php?filter=fraco">Sinal fraco</a>'
          '<a href="/view-logs.php?filter=critico">Sinal crítico</a>'
          + header)
out = deploy.patch_navigation(direct.encode()).decode()
assert 'href="/view-logs.php?filter=fraco"' in out
assert 'href="/view-logs.php?filter=critico"' in out
assert out.count('href="/reports-hub.php"') == 1

# The hub is navigation-only, no IXC requests at page entry.
hub = (deploy.SOURCE / deploy.HUB).read_text()
assert 'href="/view-logs.php"' in hub
assert 'href="/router-report.php"' in hub
assert 'fetch(' not in hub and 'getDevices(' not in hub and 'getIxcConfig(' not in hub

print("JRC_REPORTS_HUB_DEPLOY_TESTS_OK")
