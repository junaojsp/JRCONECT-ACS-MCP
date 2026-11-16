<?php
declare(strict_types=1);

/**
 * ONU pilot path audit. CLI only, local GenieACS NBI GET only.
 * Operates from the GitHub checkout, not /var/www/gacs, and never installs.
 *
 * One sample with and one without stored optical parameters per model;
 * requires recent TR-069 Inform and RX missing in the fast ACS parser.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../lib/JrcOnuTelemetryAudit.php';
require_once __DIR__ . '/../lib/JrcOnuPilotPaths.php';

$models = ['HG6143D', 'HG6245D', 'EG8145V5', 'HG8546M'];
$selected = [];
$scanned = 0;
$clock = time();

try {
    $root = '/var/www/gacs';
    if (!is_file($root . '/config/config.php')) {
        throw new RuntimeException('Config de producao nao localizado');
    }
    require_once $root . '/config/config.php';
    require_once $root . '/lib/GenieACS_Fast.php';
    if (!function_exists('getDBConnection') ||
        !class_exists(\App\GenieACS_Fast::class)) {
        throw new RuntimeException('Dependencias ACS ausentes');
    }

    $db = getDBConnection();
    $res = $db->query(
        'SELECT host,port,username,password FROM genieacs_credentials ' .
        'WHERE is_connected=1 ORDER BY id DESC LIMIT 1'
    );
    $credentials = $res ? $res->fetch_assoc() : null;
    if (!$credentials) throw new RuntimeException('Sem credenciais NBI cadastradas');
    $host = trim((string)$credentials['host']);
    $port = (int)$credentials['port'];
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) ||
        $port < 1 || $port > 65535) {
        throw new RuntimeException('NBI nao local; consulta recusada');
    }
    $auth = (string)($credentials['username'] ?? '');
    $pass = (string)($credentials['password'] ?? '');
    unset($credentials, $res);

    $finished = false;
    $seen = [];
    $size = 60;

    for ($page = 0; $page < 30; ++$page) {
        $query = http_build_query([
            'limit' => $size,
            'skip' => $page * $size,
            'sort' => json_encode(['_id' => 1])
        ], '', '&', PHP_QUERY_RFC3986);
        $url = 'http://127.0.0.1:' . $port . '/devices/?' . $query;
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('cURL indisponivel');
        $options = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($auth !== '' && $pass !== '') {
            $options[CURLOPT_USERPWD] = $auth . ':' . $pass;
        }
        curl_setopt_array($handle, $options);
        $json = curl_exec($handle);
        $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (!is_string($json) || $httpCode !== 200) {
            throw new RuntimeException('GET NBI falhou: HTTP ' . $httpCode);
        }
        $batch = json_decode($json, true);
        unset($json);
        if (!is_array($batch) || !array_is_list($batch)) {
            throw new RuntimeException('Resposta NBI invalida');
        }
        $count = count($batch);

        foreach ($batch as $raw) {
            if (!is_array($raw)) throw new RuntimeException('Dispositivo NBI invalido');
            $id = (string)($raw['_id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                throw new RuntimeException('NBI com ID ausente ou duplicado');
            }
            $seen[$id] = true;
            ++$scanned;
            $parsed = \App\GenieACS_Fast::parseDeviceDataFast($raw);
            $diag = JrcOnuTelemetryAudit::inspect($raw, $parsed, $clock);
            $model = strtoupper((string)$diag['model']);
            if (!in_array($model, $models, true) ||
                $diag['type'] !== 'onu' ||
                !$diag['recent_inform'] ||
                $diag['rx_valid']) {
                continue;
            }

            $paths = JrcOnuPilotPaths::inspect($raw, $clock);
            $category = count($paths) > 0 ? 'CAMINHOS_PRESENTES' : 'SEM_CAMINHOS';
            if (isset($selected[$model][$category])) continue;
            $serial = trim((string)($parsed['serial_number'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.:-]{4,80}$/D', $serial)) {
                $serial = 'SEM_SERIAL';
            }
            $selected[$model][$category] = [
                'serial' => $serial,
                'firmware' => preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$diag['firmware']),
                'rx_state' => $diag['status'],
                'temperature' => $diag['temp_valid'] ? 'PRESENTE' : 'AUSENTE',
                'paths' => $paths,
            ];
        }
        unset($batch);
        if ($count < $size) {
            $finished = true;
            break;
        }
        usleep(100000);
    }
    if (!$finished || $scanned === 0) {
        throw new RuntimeException('Paginacao nao concluida; nao mostrar resultado parcial');
    }
    unset($auth, $pass);

    echo "JRC_PILOTOS_RX_AUDITORIA_OK\n";
    echo "MODO=SOMENTE_LEITURA_NBI; SEM_COMANDOS_TR069; SEM_IXC\n";
    echo "CPE_EXAMINADOS=$scanned\n";
    echo "ATENCAO=Inform recente nao garante que a leitura optica esteja atualizada\n";
    echo "ATENCAO=BRUTO_OPTICO e valor SEM UNIDADE VALIDADA; nao interpretar como dBm ou Celsius\n";

    foreach ($models as $model) {
        echo "MODELO=$model\n";
        if (empty($selected[$model])) {
            echo "  SEM_PILOTO_COM_INFORM_RECENTE_E_RX_AUSENTE\n";
            continue;
        }
        foreach (['CAMINHOS_PRESENTES', 'SEM_CAMINHOS'] as $kind) {
            if (!isset($selected[$model][$kind])) continue;
            $p = $selected[$model][$kind];
            echo "  PILOTO=$kind SERIAL=" . $p['serial'] .
                 " FIRMWARE=" . $p['firmware'] .
                 " RX=" . $p['rx_state'] .
                 " TEMPERATURA=" . $p['temperature'] . "\n";
            if ($kind === 'SEM_CAMINHOS') {
                echo "    NBI_SEM_PARAMETROS_OPTICOS_CADASTRADOS\n";
            }
            foreach ($p['paths'] as $item) {
                $age = $item['age_minutes'] === null
                    ? 'SEM_TIMESTAMP' : $item['age_minutes'] . 'min';
                echo "    PARAM=" . $item['path'] .
                     " VALOR=" . $item['state'] .
                     " IDADE=" . $age .
                     " LEITOR_ATUAL=" . ($item['mapped'] ? 'MAPEADO' : 'NAO_MAPEADO') .
                     ($item['raw_optical_sample'] !== null
                        ? " BRUTO_OPTICO=" . $item['raw_optical_sample'] : "") .
                     "\n";
            }
        }
    }
    echo "FIM_PILOTOS=OK\n";
} catch (Throwable $exception) {
    // Never print NBI JSON, secrets, stack traces or subscriber records.
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage() : get_class($exception);
    fwrite(STDERR, "JRC_PILOTOS_FALHOU: $message\n");
    exit(1);
}
