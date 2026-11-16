#!/usr/bin/env python3
"""Print only route names and telemetry markers; never source/credentials."""
import json
from pathlib import Path
import re
import sys
root = Path(sys.argv[1] if len(sys.argv) == 2 else '/var/www/gacs')
files = [root / name for name in ['dashboard.php', 'view-logs.php', 'devices.php', 'device-detail.php']]
files += list((root / 'api').glob('*.php'))
files += list((root / 'assets/js').glob('*.js'))
result = []
for path in files:
    text = path.read_text(errors='replace')
    markers = {key: text.count(key) for key in ['JrcDashboardSnapshot', 'JrcDeviceInventoryCache',
        'JrcSnmpTelemetry', 'radpop_radio_cliente_fibra', 'get-onu-optical.php',
        'ixc_rx_power', 'rx_power', 'onu_rx'] if key in text}
    if not markers and path.name not in ['dashboard.php', 'view-logs.php', 'devices.php', 'device-detail.php']:
        continue
    routes = sorted(set(re.findall(r'(?:/?api/)[A-Za-z0-9_-]+\.php', text)))
    result.append({'file': str(path.relative_to(root)), 'markers': markers, 'routes': routes})
print(json.dumps(result, indent=2))
