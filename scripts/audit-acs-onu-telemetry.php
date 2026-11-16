<?php
declare(strict_types=1);

/**
 * Read-only, one-shot audit of the local GenieACS NBI.
 *
 * Usage (on ACS host):
 * php scripts/audit-acs-onu-telemetry.php
 *
 * Only GET /devices via local loopback. No TR-069 task, refresh, provision,
 * IXC call, DB write, cron installation, deployment or CPE action.
 * No subscriber identifiers, Wi-Fi secrets or raw JSON in stdout/logs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/JrcOnuTelemetryAudit.php';

function auditNoTelemetry(string $message, int $exit = 1): never
{
    fwrite(STDERR, 'AUDITORIA_FALHOU: ' . $message . PHP_EOL);
    exit($exit);
}

try {
    $root = '/var/www/gacs';
    if (!is_file($root . '/config/config.php')) {
        throw new RuntimeException('Configuracao de producao nao encontrada');
    }
    require_once $root . '/config/config.php';
    require_once $root . '/lib/GenieACS_Fast.php';
    if (!function_exists('getDBConnection') ||
        !class_exists(\App\GenieACS_Fast::class)) {
        throw new RuntimeException('Dependencias do ACS indisponiveis');
    }

    $db = getDBConnection();
    $query = $db->query(
        'SELECT host, port, username, password FROM genieacs_credentials ' .
        'WHERE is_connected=1 ORDER BY id DESC LIMIT 1'
    );
    $cred = $query ? $query->fetch_assoc() : null;
    if (!$cred) throw new RuntimeException('GenieACS nao configurado');

    $host = trim((string)$cred['host']);
    $port = (int)$cred['port'];
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) ||
        $port < 1 || $port > 65535) {
        throw new RuntimeException('NBI nao e loopback local; auditoria recusada');
    }

    $url = 'http://127.0.0.1:' . $port . '/devices/';
    $auth = (string)($cred['username'] ?? '');
    $pass = (string)($cred['password'] ?? '');
    unset($cred, $query);

    $groups = [];
    $observations = [];
    $seen = [];
    $pageSize = 60;
    $maxPages = 30; // up to 1,800 records, bounded
    $finished = false;
    $clock = time();

    for ($page = 0; $page < $maxPages; ++$page) {
        $params = http_build_query([
            'limit' => $pageSize,
            'skip' => $page * $pageSize,
            'sort' => json_encode(['_id' => 1])
        ], '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url . '?' . $params);
        if ($ch === false) throw new RuntimeException('cURL indisponivel');
        $opts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($auth !== '' && $pass !== '') {
            $opts[CURLOPT_USERPWD] = $auth . ':' . $pass;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response) || $code !== 200) {
            throw new RuntimeException('Consulta GET NBI falhou, HTTP ' . $code);
        }
        $devices = json_decode($response, true);
        unset($response);
        if (!is_array($devices) || !array_is_list($devices)) {
            throw new RuntimeException('Resposta NBI incompleta/invalida');
        }

        foreach ($devices as $raw) {
            $id = is_array($raw) ? (string)($raw['_id'] ?? '') : '';
            if ($id === '' || isset($seen[$id])) {
                throw new RuntimeException('Paginacao NBI duplicada ou ID ausente');
            }
            $seen[$id] = true;
            $parsed = \App\GenieACS_Fast::parseDeviceDataFast($raw);
            $observations[] = JrcOnuTelemetryAudit::inspect($raw, $parsed, $clock);
            // Never store raw records, subscriber fields or credentials in output.
        }
        unset($devices);
        if (count($seen) > $maxPages * $pageSize) {
            throw new RuntimeException('Limite de frota excedido');
        }
        // Batch less than page size = last page.
        // Note that the chunk is already fully inspected and discarded.
        if (count($observations) < ($page + 1) * $pageSize) {
            $finished = true;
            break;
        }
        usleep(100000); // bounded polite pacing
    }
    if (!$finished || $observations === []) {
        throw new RuntimeException('Paginacao nao concluida; nenhum resumo parcial sera publicado');
    }
    unset($auth, $pass);

    $report = JrcOnuTelemetryAudit::summarize($observations);
    unset($observations);
    $t = $report['totais'];

    echo "JRC_ONU_TELEMETRIA_AUDITORIA_OK\n";
    echo "DATA_UTC=" . gmdate('Y-m-d H:i:s') . "\n";
    echo "FONTE=GenieACS NBI local (somente GET; dados ja cadastrados)\n";
    echo "TOTAL_CPE=" . $t['analisados'] .
         " ONU_IDENTIFICADA=" . $t['onus'] .
         " ROTEADORES=" . $t['roteadores'] .
         " TIPO_NAO_CONFIRMADO=" . $t['tipo_a_confirmar'] . "\n";
    echo "ONU_SEM_RX=" . $t['onus_sem_rx'] .
         " ONU_SEM_RX_COM_INFORM_15MIN=" . $t['onus_sem_rx_com_inform_recente'] . "\n";
    echo "ATENCAO=Inform recente nao prova suporte ao RX; ausente no NBI pode significar parametro nao descoberto.\n";
    echo "ATENCAO=RX/temperatura coletados do cadastro TR-069; IXC optico nao foi consultado nesta auditoria.\n";
    echo "MODELOS_POR_PRIORIDADE:\n";

    foreach ($report['modelos'] as $m) {
        if ($m['tipo'] !== 'onu' && $m['tipo'] !== 'desconhecido') continue;
        if ($m['tipo'] === 'onu' && $m['sem_rx'] === 0) continue;
        echo "MODELO=" . $m['fabricante'] . " / " . $m['modelo'] .
             " TIPO=" . $m['tipo'] .
             " TOTAL=" . $m['total'] .
             " RX_OK=" . $m['rx_ok'] .
             " ONU_SEM_RX=" . $m['sem_rx'] .
             " SEM_RX_INFORM_RECENTE=" . $m['sem_rx_com_inform_recente'] .
             " TEMP_AUSENTE=" . $m['temperatura_sem_dado'] . "\n";

        arsort($m['causas']);
        foreach ($m['causas'] as $cause => $count) {
            echo "  HIPOTESE=" . $cause . " QUANTIDADE=" . $count . "\n";
        }
        arsort($m['firmwares']);
        foreach (array_slice($m['firmwares'], 0, 5, true) as $firmware => $count) {
            echo "  FIRMWARE=" . $firmware . " QTD=" . $count . "\n";
        }
        arsort($m['caminhos_rx']);
        foreach (array_slice($m['caminhos_rx'], 0, 6, true) as $path => $count) {
            echo "  CAMINHO_RX_NBI=" . $path . " QTD=" . $count . "\n";
        }
    }
    echo "FIM_AUDITORIA=OK\n";
} catch (Throwable $error) {
    // Never echo raw HTTP response, stack traces, subscriber values or tokens.
    $message = $error instanceof RuntimeException
        ? $error->getMessage() : 'Erro interno ' . get_class($error);
    auditNoTelemetry($message);
}
