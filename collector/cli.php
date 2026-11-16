<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/NbiReadOnly.php';
require_once __DIR__ . '/IxcReadOnly.php';
require_once __DIR__ . '/Diagnostics.php';
require_once __DIR__ . '/Consolidator.php';
require_once __DIR__ . '/ShadowRepository.php';
require_once __DIR__ . '/../lib/GenieACS_Fast.php';

use JRC\Collector\Config;
use JRC\Collector\NbiReadOnly;
use JRC\Collector\IxcReadOnly;
use JRC\Collector\Consolidator;
use JRC\Collector\ShadowRepository;

date_default_timezone_set('UTC');
$mode = $argv[1] ?? '--check';
// Explicit pilot mode uses the existing collector entry point, independently of NBI/IXC.
if ($mode === '--snmp-pilot' && count($argv) === 2) {
    $process = proc_open(['python3', __DIR__ . '/snmp_pilot.py'],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    exit(is_resource($process) ? proc_close($process) : 1);
}
if (!in_array($mode, ['--check', '--shadow'], true) || count($argv) > 2) {
    fwrite(STDERR, "Use: php collector/cli.php [--check|--shadow|--snmp-pilot]\n");
    exit(2);
}
if ($mode === '--shadow' && Config::env('JRC_COLLECTOR_SHADOW_WRITE') !== '1') {
    fwrite(STDERR, "Somente --check permitido sem habilitar o modo shadow\n");
    exit(2);
}
try {
    $acsUrl = Config::env('JRC_ACS_URL');
    if ($acsUrl === '') throw new \RuntimeException('JRC_ACS_URL nao configurada');
    $nbi = new NbiReadOnly(
        $acsUrl, Config::env('JRC_ACS_USER'), Config::secret('JRC_ACS_PASSWORD')
    );
    $raw = $nbi->all();
    if (!$raw) throw new \RuntimeException('Inventario GenieACS vazio, coleta rejeitada');

    $ixc = [];
    $note = 'GenieACS coletado; IXC desabilitado';
    $ixcUrl = Config::env('JRC_IXC_URL');
    if ($ixcUrl !== '') {
        try {
            $ixc = (new IxcReadOnly(
                $ixcUrl, Config::secret('JRC_IXC_TOKEN'),
                Config::env('JRC_IXC_USERS_ENABLED') === '1'
            ))->fleet();
            $note = 'GenieACS e IXC consultados';
        } catch (\Throwable $error) {
            // Never expose subscriber data or remote server response in logs.
            $note = 'IXC indisponivel; snapshot parcialmente enriquecido';
            error_log('JRC Collector: IXC read-only lookup failed (' . get_class($error) . ')');
        }
    }

    $tempWarn = Config::env('JRC_TEMPERATURE_WARNING_C');
    $tempWarnC = $tempWarn === '' ? null : filter_var($tempWarn, FILTER_VALIDATE_FLOAT);
    if ($tempWarn !== '' && ($tempWarnC === false || $tempWarnC < 20 || $tempWarnC > 110)) {
        throw new \RuntimeException('Limite de temperatura invalido');
    }
    $fleet = Consolidator::fleet($raw, $ixc, time(), $tempWarnC);
    $metrics = $fleet['summary'];
    if ($mode === '--check') {
        echo 'COLLECTOR_CHECK_OK ' . json_encode([
            'metrics' => $metrics,
            'ixc_matched' => $fleet['ixc_matched'],
            'note' => $note
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $db = Config::db();
    $published = ShadowRepository::publish(
        $db, $fleet['devices'], $metrics, $fleet['ixc_matched'], $note
    );
    $db->close();
    // IDs, credentials, device rows and subscriber PII are never logged.
    echo 'COLLECTOR_SHADOW_OK ' . json_encode([
        'total' => $published['total'],
        'ixc_matched' => $published['ixc_matched'],
        'metrics' => $published['metrics'],
        'note' => $note
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (\Throwable $error) {
    error_log('JRC Collector ' . $mode . ': ' . get_class($error));
    fwrite(STDERR, 'COLETA_FALHOU: ' . get_class($error) . PHP_EOL);
    exit(1);
}
