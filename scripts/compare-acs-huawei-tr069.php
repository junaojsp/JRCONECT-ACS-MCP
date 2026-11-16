<?php
declare(strict_types=1);

/**
 * Compare a problematic Huawei with another Huawei from the SAME read-only
 * GenieACS NBI, including consistency of full-list vs serial-filter query.
 *
 * Run from Git checkout with --serial=DEVICE_SERIAL. Does NOT talk to IXC,
 * POST tasks, refresh devices, install code or change any ACS files.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../lib/JrcTr069ProfileCompare.php';

function failHuawei(string $code): never
{
    fwrite(STDERR, "COMPARACAO_FALHOU=$code\n");
    exit(1);
}
function minuteAge(?int $age): string
{
    return $age === null ? 'SEM_DATA' : $age . 'min';
}
function displayHuaweiProfile(string $label, array $profile): void
{
    echo $label .
        " SERIAL=" . $profile['serial'] .
        " FABRICANTE=" . $profile['vendor'] .
        " MODELO=" . $profile['model'] .
        " FIRMWARE=" . $profile['firmware'] .
        " OUI=" . $profile['oui'] .
        " ULTIMO_INFORM=" . minuteAge($profile['last_inform_age_min']) .
        " RX_NUMERICO=" . ($profile['rx_numeric'] ? 'SIM' : 'NAO') .
        " RX_NUMERICO_ATUAL_15MIN=" . ($profile['rx_fresh_numeric'] ? 'SIM' : 'NAO') .
        "\n";
    if ($profile['optical_path_truncated']) {
        echo "  AVISO=VARREDURA_DE_CAMINHOS_LIMITADA\n";
    }
    foreach (array_slice(array_merge($profile['rx'], $profile['temperature']), 0, 25) as $path) {
        echo "  " . $path['kind'] .
             " CAMINHO=" . $path['path'] .
             " VALOR=" . $path['state'] .
             " IDADE=" . minuteAge($path['age_min']) .
             " LEITOR_ATUAL=" . ($path['reader_mapped'] ? 'RECONHECE' : 'NAO_RECONHECE') .
             " UNIDADE=" . ($path['unit_verified'] ? 'VALIDADA' : 'DESCONHECIDA') .
             "\n";
    }
    if ($profile['rx'] === []) {
        echo "  RX=SEM_CAMINHO_CADASTRADO_NO_NBI\n";
    }
}
function nbiGet(string $host, int $port, string $username, string $password, array $query): array
{
    $url = "http://127.0.0.1:$port/devices/?" .
        http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException('CURL_NAO_DISPONIVEL');
    $settings = [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($username !== '' && $password !== '') {
        $settings[CURLOPT_USERPWD] = $username . ':' . $password;
    }
    curl_setopt_array($ch, $settings);
    $answer = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($answer) || $status !== 200) {
        throw new RuntimeException('NBI_GET_HTTP_' . $status);
    }
    $decoded = json_decode($answer, true);
    unset($answer);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        throw new RuntimeException('NBI_JSON_INVALIDO');
    }
    return $decoded;
}

$serial = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--serial=')) {
        $serial = substr($arg, strlen('--serial='));
    } else {
        failHuawei('ARGUMENTO_DESCONHECIDO');
    }
}
if (!preg_match('/^[A-Za-z0-9_.:-]{4,80}$/D', $serial)) {
    failHuawei('USO_php_compare-acs-huawei-tr069.php_--serial=SERIAL');
}
try {
    $root = '/var/www/gacs';
    if (!is_file("$root/config/config.php")) {
        throw new RuntimeException('CONFIG_ACS_AUSENTE');
    }
    require_once "$root/config/config.php";
    if (!function_exists('getDBConnection')) {
        throw new RuntimeException('BANCO_ACS_INDISPONIVEL');
    }
    $db = getDBConnection();
    $rs = $db->query(
        'SELECT host,port,username,password FROM genieacs_credentials ' .
        'WHERE is_connected=1 ORDER BY id DESC LIMIT 1'
    );
    $creds = $rs ? $rs->fetch_assoc() : null;
    if (!$creds) throw new RuntimeException('NBI_SEM_CONFIGURACAO');
    $host = trim((string)($creds['host'] ?? ''));
    $port = (int)($creds['port'] ?? 0);
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) ||
        $port < 1 || $port > 65535) {
        throw new RuntimeException('NBI_NAO_E_LOCAL');
    }
    $user = (string)($creds['username'] ?? '');
    $pass = (string)($creds['password'] ?? '');
    unset($rs, $creds);

    $allProfiles = [];
    $ids = [];
    $targetScan = null;
    $clock = time();
    $seenTarget = 0;
    $totalScanned = 0;
    $complete = false;
    $size = 60;

    // Identical full-list GET for all devices; same time, same parser, same NBI.
    for ($page = 0; $page < 30; ++$page) {
        $batch = nbiGet($host, $port, $user, $pass, [
            'limit' => $size,
            'skip' => $page * $size,
            'sort' => json_encode(['_id' => 1]),
        ]);
        $count = count($batch);
        foreach ($batch as $device) {
            $id = is_array($device) ? (string)($device['_id'] ?? '') : '';
            if ($id === '' || isset($ids[$id])) {
                throw new RuntimeException('NBI_ID_AUSENTE_OU_DUPLICADO');
            }
            $ids[$id] = true;
            $totalScanned++;
            $candidateSerial = (string)($device['_deviceId']['_SerialNumber'] ?? '');
            if (strcasecmp($serial, $candidateSerial) === 0) {
                $seenTarget++;
                $targetScan = [
                    'id' => $id,
                    'profile' => JrcTr069ProfileCompare::profile($device, $clock),
                ];
            }
            if (JrcTr069ProfileCompare::isHuawei($device)) {
                $allProfiles[] = JrcTr069ProfileCompare::profile($device, $clock);
            }
        }
        unset($batch);
        if ($count < $size) {
            $complete = true;
            break;
        }
        usleep(100000);
    }
    if (!$complete || $totalScanned === 0 || $seenTarget !== 1 || $targetScan === null) {
        throw new RuntimeException(
            !$complete ? 'NBI_PAGINACAO_INCOMPLETA' :
            ($seenTarget > 1 ? 'SERIAL_DUPLICADO' : 'SERIAL_ALVO_NAO_LOCALIZADO')
        );
    }
    $target = $targetScan['profile'];
    if (!array_filter($allProfiles, static fn(array $x): bool =>
        $x['serial'] === $target['serial'])) {
        throw new RuntimeException('SERIAL_NAO_IDENTIFICADO_COMO_HUAWEI');
    }

    // Explicitly compare the serial-filtered NBI response to the full-scan
    // snapshot: prior observations showed divergent path presence.
    $individual = nbiGet($host, $port, $user, $pass, [
        'query' => json_encode(['_deviceId._SerialNumber' => $serial]),
        'limit' => 5,
    ]);
    $matches = array_values(array_filter($individual, static fn(array $x): bool =>
        strcasecmp((string)($x['_deviceId']['_SerialNumber'] ?? ''), $serial) === 0));
    unset($individual);
    if (count($matches) !== 1) {
        throw new RuntimeException('FILTRO_SERIAL_RETORNOU_' . count($matches));
    }
    $singleId = (string)($matches[0]['_id'] ?? '');
    $single = JrcTr069ProfileCompare::profile($matches[0], $clock);
    unset($matches);

    $targetOptical = array_column(array_merge($target['rx'], $target['temperature']),
        'state', 'path');
    $singleOptical = array_column(array_merge($single['rx'], $single['temperature']),
        'state', 'path');
    ksort($targetOptical);
    ksort($singleOptical);
    $consistent = $singleId === $targetScan['id'] &&
        $targetOptical === $singleOptical;

    $comparator = JrcTr069ProfileCompare::chooseComparator($target, $allProfiles);
    unset($allProfiles, $ids, $user, $pass);

    echo "JRC_HUAWEI_TR069_COMPARACAO_OK\n";
    echo "FONTE=GENIEACS_NBI_LOCAL_GET; IXC_NAO_CONSULTADO; NENHUM_COMANDO_TR069\n";
    echo "CPE_EXAMINADOS=$totalScanned\n";
    echo "ALERTA=RX_NUMERICO_NAO_GARANTE_dBm; INFORM_RECENTE_NAO_GARANTE_RX_RECENTE\n";
    displayHuaweiProfile('ALVO_VARREDURA_GERAL', $target);
    echo "ALVO_CONSULTA_DIRETA=" . ($consistent ? 'CONCORDA' : 'DIVERGE') .
        " MESMO_ID=" . ($singleId === $targetScan['id'] ? 'SIM' : 'NAO') .
        " CAMINHOS_GERAL=" . count($targetOptical) .
        " CAMINHOS_INDIVIDUAL=" . count($singleOptical) . "\n";
    if (!$consistent) {
        displayHuaweiProfile('ALVO_CONSULTA_INDIVIDUAL', $single);
        echo "ATENCAO=VERIFICAR_DIFERENCA_ENTRE_RESPOSTA_PAGINADA_E_FILTRADA\n";
    }
    if ($comparator === null) {
        echo "COMPARADOR=SEM_HUAWEI_COM_RX_NUMERICO_CADASTRADO_NO_TR069\n";
        echo "OBS=SE_HOUVER_RX_NO_PAINEL_CONFERIR_SE_ORIGEM_E_IXC\n";
    } else {
        $fresh = $comparator['rx_fresh_numeric'];
        echo "REFERENCIA_TR069=" . ($fresh
            ? 'RX_NUMERICO_RECENTE_CANDIDATO'
            : 'SOMENTE_RX_HISTORICO_NAO_CONSIDERAR_FUNCIONANDO') . "\n";
        displayHuaweiProfile('COMPARADOR', $comparator);
        $diff = JrcTr069ProfileCompare::differences($target, $comparator);
        echo "MESMO_MODELO=" . ($diff['same_model'] ? 'SIM' : 'NAO') .
            " MESMO_FIRMWARE=" . ($diff['same_firmware'] ? 'SIM' : 'NAO') . "\n";
        foreach (['paths_only_target' => 'SOMENTE_ALVO',
                  'paths_only_reference' => 'SOMENTE_REFERENCIA',
                  'paths_in_both' => 'CAMINHO_COMUM',
                  'different_value_states' => 'ESTADO_DIFERENTE'] as $key => $label) {
            echo $label . "_TOTAL=" . count($diff[$key]) . "\n";
            foreach (array_slice($diff[$key], 0, 12) as $item) {
                echo "  $label=$item\n";
            }
        }
        echo "INTERPRETACAO=CAMINHO_SOMENTE_NA_REFERENCIA_INDICA_DESCOBERTA_OU_FIRMWARE; " .
            "CAMINHO_SEM_VALOR_INDICA_CONSULTA_INCOMPLETA; " .
            "PARAMETRO_ANTIGO_INDICA_FALTA_DE_ATUALIZACAO\n";
    }
    echo "PROPOSTA=DESCOBERTA_AUTOMATICA_POR_ARVORE_DE_PARAMETROS_E_FINGERPRINT" .
        "_OUI_MODELO_FIRMWARE; HOMOLOGAR_UNIDADES_E_VALIDADE_ANTES_DE_ATIVAR\n";
    echo "FIM_COMPARACAO=OK\n";
} catch (Throwable $e) {
    // No raw NBI data, stack trace, tokens or subscriber fields.
    failHuawei($e instanceof RuntimeException ? $e->getMessage() : 'ERRO_INTERNO');
}
