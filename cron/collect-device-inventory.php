<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/GenieACS.php';
require_once __DIR__ . '/../lib/GenieACS_Fast.php';
require_once __DIR__ . '/../lib/JrcDeviceInventoryCache.php';
require_once __DIR__ . '/../lib/JrcIxcInventoryReader.php';

$lock = fopen(sys_get_temp_dir() . '/jrc-acs-inventory-collector.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Coleta anterior em andamento\n");
    exit(2);
}

try {
    $db = getDBConnection();
    $credentials = $db->query("SELECT host, port, username, password
        FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if (!$credentials) throw new RuntimeException('GenieACS nao configurado');
    $acs = new App\GenieACS(
        $credentials['host'], $credentials['port'],
        $credentials['username'], $credentials['password']
    );

    $devices = [];
    $seen = [];
    $limit = 100;
    for ($page = 0; $page < 1000; $page++) {
        $response = $acs->getDevices([], $limit, $page * $limit);
        if (empty($response['success']) || !isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException('Falha na coleta GenieACS (pagina ' . ($page + 1) . ')');
        }
        foreach ($response['data'] as $raw) {
            $device = App\GenieACS_Fast::parseDeviceDataFast($raw);
            $id = (string)($device['device_id'] ?? '');
            if ($id === '' || $id === 'N/A' || isset($seen[$id])) {
                throw new RuntimeException('Dispositivo repetido ou sem ID entre paginas ACS');
            }
            $seen[$id] = true;
            $lastInform = $raw['_lastInform'] ?? null;
            $device['last_inform_timestamp'] = $lastInform ? (strtotime($lastInform) ?: null) : null;
            unset($device['wifi_password'], $device['ping']);
            $devices[] = $device;
        }
        if (count($response['data']) < $limit) break;
        if ($page === 999) throw new RuntimeException('Limite de paginas ACS atingido');
    }
    if (!$devices) throw new RuntimeException('ACS retornou inventario vazio');

    $ixcMatched = 0;
    $note = 'IXC nao configurado no coletor; dados TR-069 mantidos';
    $url = trim((string)getenv('JRC_IXC_URL'));
    $token = trim((string)getenv('JRC_IXC_TOKEN'));
    if ($url !== '' && $token !== '') {
        try {
            $map = JrcIxcInventoryReader::load($url, $token);
            foreach ($devices as &$device) {
                $serial = JrcIxcInventoryReader::normalSerial((string)($device['serial_number'] ?? ''));
                $extra = $map[$serial] ?? null;
                if (!$extra) continue;
                foreach ($extra as $key => $value) {
                    if ($value !== null && $value !== '') $device[$key] = $value;
                }
                $ixcMatched++;
            }
            unset($device);
            $note = 'IXC consultado; ONUs correspondentes: ' . $ixcMatched;
        } catch (Throwable $e) {
            // Independent source: failed enrichment must not block a valid GenieACS snapshot.
            $note = 'IXC indisponivel; somente GenieACS no snapshot';
            error_log('JRC inventory IXC: ' . $e->getMessage());
        }
    } elseif ($url !== '' || $token !== '') {
        $note = 'Configuracao IXC incompleta; somente GenieACS';
    }
    $published = JrcDeviceInventoryCache::publish($db, $devices, $ixcMatched, $note);
    echo 'COLETA_OK total=' . $published['total'] .
         ' ixc_matched=' . $published['ixc_matched'] .
         ' snapshot=' . $published['snapshot'] . PHP_EOL;
} catch (Throwable $e) {
    error_log('JRC inventory collection: ' . $e->getMessage());
    fwrite(STDERR, 'COLETA_FALHOU: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
