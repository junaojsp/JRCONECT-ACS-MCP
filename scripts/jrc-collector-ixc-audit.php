<?php
declare(strict_types=1);

/**
 * Compare the currently configured IXC optical feed with the existing ACS
 * inventory snapshot. All operations are read-only; output is aggregate only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../collector/Config.php';
require_once __DIR__ . '/../collector/Diagnostics.php';
require_once __DIR__ . '/../collector/IxcReadOnly.php';
require_once __DIR__ . '/../collector/LegacyOpticalConfig.php';

use JRC\Collector\IxcReadOnly;
use JRC\Collector\LegacyOpticalConfig;
use JRC\Collector\Diagnostics;

if (count($argv) !== 1) {
    fwrite(STDERR, "Uso: php scripts/jrc-collector-ixc-audit.php\n");
    exit(2);
}
$tz = getenv('JRC_IXC_TIMEZONE') ?: 'America/Sao_Paulo';
if (!in_array($tz, timezone_identifiers_list(), true)) {
    fwrite(STDERR, "AUDITORIA_FALHOU: fuso horario invalido\n");
    exit(2);
}
date_default_timezone_set($tz);

try {
    $root = getenv('JRC_ACS_WEBROOT') ?: '/var/www/gacs';
    $configFile = rtrim($root, '/') . '/api/get-onu-optical.php';
    $dbConfigFile = rtrim($root, '/') . '/config/config.php';
    if (!is_file($dbConfigFile)) {
        throw new RuntimeException('Config do banco ACS indisponivel');
    }

    // Load only the trusted app configuration. In production the optical
    // endpoint uses getIxcConfig() from this configuration file.
    // NEVER include/execute the endpoint itself.
    require_once $dbConfigFile;
    $optical = LegacyOpticalConfig::read($configFile);
    // The existing API uses IXC "listar". This invocation is read-only.
    $ixc = (new IxcReadOnly($optical['url'], $optical['token'], false))->fleet();
    unset($optical);

    // Existing app config provides getDBConnection(); do not touch any flags.
    $db = getDBConnection();
    $stateQuery = $db->query('SELECT snapshot_id, total FROM jrc_device_inventory_state WHERE id=1');
    $state = $stateQuery ? $stateQuery->fetch_assoc() : null;
    if (!$state) throw new RuntimeException('Snapshot atual ausente');
    $snapshotId = (string)$state['snapshot_id'];

    $query = $db->prepare(
        'SELECT payload_json FROM jrc_device_inventory_rows WHERE snapshot_id=?'
    );
    $query->bind_param('s', $snapshotId);
    $query->execute();
    $rows = $query->get_result();

    $summary = [
        'acs_total' => (int)$state['total'],
        'ixc_fibras_com_identificador' => count($ixc),
        'correspondencias_serial' => 0,
        'correspondencias_mac' => 0,
        'correspondencias_ambiguas' => 0,
        'sem_correspondencia' => 0,
        'correspondencias_total' => 0,
        'rx_preenchido' => 0,
        'rx_recente_15min' => 0,
        'temperatura_preenchida' => 0,
        'temperatura_recente_15min' => 0,
        'tx_preenchido' => 0,
        'tensao_preenchida' => 0
    ];
    $now = time();
    $scanned = 0;
    while ($r = $rows->fetch_assoc()) {
        $device = json_decode($r['payload_json'], true);
        if (!is_array($device)) throw new RuntimeException('Registro cache corrompido');
        $scanned++;
        $serial = IxcReadOnly::serial((string)($device['serial_number'] ?? ''));
        $mac = IxcReadOnly::serial((string)($device['mac_address'] ?? ''));

        $bySerial = $serial !== '' && isset($ixc[$serial]);
        $byMac = $mac !== '' && isset($ixc[$mac]);
        if ($bySerial && $byMac && $serial !== $mac) {
            $summary['correspondencias_ambiguas']++;
            continue;
        }
        if (!$bySerial && !$byMac) {
            $summary['sem_correspondencia']++;
            continue;
        }
        $summary[$bySerial ? 'correspondencias_serial' : 'correspondencias_mac']++;
        $summary['correspondencias_total']++;
        $fiber = $ixc[$bySerial ? $serial : $mac];
        $time = (int)($fiber['measured_at_epoch'] ?? 0);
        $fresh = $time > 0 && $time <= $now + 300 && $now - $time <= 900;
        if (Diagnostics::number($fiber['rx'] ?? null, -80, -0.00001) !== null) {
            $summary['rx_preenchido']++;
            if ($fresh) $summary['rx_recente_15min']++;
        }
        if (Diagnostics::number($fiber['temperature'] ?? null, -30, 130) !== null) {
            $summary['temperatura_preenchida']++;
            if ($fresh) $summary['temperatura_recente_15min']++;
        }
        if (Diagnostics::number($fiber['tx'] ?? null, -80, 20) !== null) {
            $summary['tx_preenchido']++;
        }
        if (Diagnostics::number($fiber['voltage'] ?? null, 0.01, 100) !== null) {
            $summary['tensao_preenchida']++;
        }
    }
    $query->close();
    if ($scanned !== (int)$state['total']) {
        throw new RuntimeException('Inventario trocado durante auditoria; repetir');
    }

    // No tokens, subscriber names, MACs, serials, IPs or logins are printed.
    echo 'IXC_OPTICAL_AUDIT_OK ' .
         json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    // Known error messages deliberately contain no secrets/customer data.
    fwrite(STDERR, 'IXC_OPTICAL_AUDIT_FALHOU: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
