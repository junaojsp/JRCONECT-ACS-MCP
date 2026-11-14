<?php

declare(strict_types=1);

use App\CPEProfiles;

/*
 * =========================================================
 * JR CONECT ACS
 * GET ONU OPTICAL - VIA IXC API
 * =========================================================
 *
 * Arquivo:
 * /var/www/gacs/api/get-onu-optical.php
 *
 * Fluxo:
 *
 * device_id
 *    ↓
 * GenieACS
 *    ↓
 * Serial da ONU
 *    ↓
 * IXC API
 *    ↓
 * radpop_radio_cliente_fibra
 *    ↓
 * busca pelo campo MAC
 *    ↓
 * RX / TX / Temperatura / Voltagem / PON
 *
 * =========================================================
 */


/* =========================================================
   CONFIGURAÇÃO DO PAINEL
   ========================================================= */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/IXCConfig.php';


if (function_exists('requireLogin')) {
    requireLogin();
}


/* =========================================================
   HEADER
   ========================================================= */

header('Content-Type: application/json; charset=utf-8');


/* =========================================================
   CONFIGURAÇÃO IXC
   ========================================================= */

$ixcConfig = getIxcConfig();
$ixcBaseUrl = $ixcConfig['base_url'];
$ixcToken = $ixcConfig['token'];


/* =========================================================
   RESPOSTA JSON
   ========================================================= */

function jrOpticalJson(
    array $data,
    int $status = 200
): void {

    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
   NORMALIZAR SERIAL
   ========================================================= */

function jrNormalizeOpticalSerial(
    string $value
): string {

    return strtoupper(
        preg_replace(
            '/[^A-Z0-9]/i',
            '',
            trim($value)
        )
    );
}


/* =========================================================
   ALIASES DE SERIAL (TR-069 <-> IXC)
   ========================================================= */

function jrOpticalSerialAliases(string $value): array
{
    return CPEProfiles::serialAliases($value);
}


/* =========================================================
   CONVERTER NUMERO IXC
   ========================================================= */

function jrOpticalNumber(
    mixed $value
): ?float {

    if (
        $value === null ||
        $value === ''
    ) {
        return null;
    }


    $value =
        str_replace(
            ',',
            '.',
            trim(
                (string)$value
            )
        );


    if (!is_numeric($value)) {
        if (!preg_match('/-?\d+(?:\.\d+)?/', $value, $match)) {
            return null;
        }
        $value = $match[0];
    }


    $number = (float)$value;

    // Alguns firmwares entregam centésimos de dBm, por exemplo -2432.
    if ($number > 1000 || $number < -1000) {
        $number = $number / 100;
    }

    return $number;
}


/* =========================================================
   CONSULTAR GENIEACS
   ========================================================= */

function jrGetGenieDevice(
    string $deviceId
): array {

    if (!function_exists('curl_init')) {

        throw new RuntimeException(
            'Extensão PHP cURL não instalada.'
        );
    }


    $query =
        json_encode(
            [
                '_id' =>
                    $deviceId
            ]
        );


    if ($query === false) {

        throw new RuntimeException(
            'Erro ao gerar query para GenieACS.'
        );
    }


    $url =
        'http://127.0.0.1:7557/devices/?query=' .
        rawurlencode($query);


    $ch =
        curl_init();


    if ($ch === false) {

        throw new RuntimeException(
            'Não foi possível iniciar cURL para GenieACS.'
        );
    }


    curl_setopt_array(
        $ch,
        [
            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                5,

            CURLOPT_TIMEOUT =>
                15,

            CURLOPT_HTTPHEADER =>
                [
                    'Accept: application/json'
                ]
        ]
    );


    $response =
        curl_exec($ch);


    if ($response === false) {

        $error =
            curl_error($ch);


        curl_close($ch);


        throw new RuntimeException(
            'Erro ao consultar GenieACS: ' .
            $error
        );
    }


    $httpCode =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close($ch);


    if ($httpCode !== 200) {

        throw new RuntimeException(
            'GenieACS retornou HTTP ' .
            $httpCode
        );
    }


    $json =
        json_decode(
            $response,
            true
        );


    if (
        !is_array($json) ||
        empty($json[0])
    ) {

        throw new RuntimeException(
            'Equipamento não encontrado no GenieACS.'
        );
    }


    return $json[0];
}


/* =========================================================
   EXTRAIR SERIAL DO DEVICE
   ========================================================= */

function jrGetSerialFromDevice(
    array $device
): string {

    $candidates =
        [
            $device
                ['InternetGatewayDevice']
                ['DeviceInfo']
                ['SerialNumber']
                ['_value']
                ?? null,

            $device
                ['Device']
                ['DeviceInfo']
                ['SerialNumber']
                ['_value']
                ?? null,

            $device
                ['_deviceId']
                ['_SerialNumber']
                ?? null
        ];


    foreach ($candidates as $serial) {

        if (
            is_string($serial) &&
            trim($serial) !== ''
        ) {

            return jrNormalizeOpticalSerial(
                $serial
            );
        }
    }


    throw new RuntimeException(
        'Serial Number não encontrado no TR-069.'
    );
}


/* =========================================================
   EXTRAIR FABRICANTE
   ========================================================= */

function jrGetManufacturerFromDevice(
    array $device
): string {

    $candidates =
        [
            $device
                ['InternetGatewayDevice']
                ['DeviceInfo']
                ['Manufacturer']
                ['_value']
                ?? null,

            $device
                ['Device']
                ['DeviceInfo']
                ['Manufacturer']
                ['_value']
                ?? null,

            $device
                ['_deviceId']
                ['_Manufacturer']
                ?? null
        ];


    foreach ($candidates as $manufacturer) {

        if (
            is_string($manufacturer) &&
            trim($manufacturer) !== ''
        ) {

            return trim($manufacturer);
        }
    }


    return '';
}


/* =========================================================
   REQUEST IXC
   ========================================================= */

function jrIxcOpticalRequest(
    string $baseUrl,
    string $token,
    string $endpoint,
    array $params
): array {

    if (!function_exists('curl_init')) {

        throw new RuntimeException(
            'Extensão PHP cURL não instalada.'
        );
    }


    $url =
        rtrim($baseUrl, '/') .
        '/webservice/v1/' .
        ltrim($endpoint, '/');


    /*
     * IXC usa:
     *
     * Authorization: Basic BASE64(TOKEN_BRUTO)
     */

    $authorization =
        'Basic ' .
        base64_encode($token);


    $body =
        json_encode(
            $params,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );


    if ($body === false) {

        throw new RuntimeException(
            'Falha ao gerar JSON da consulta IXC.'
        );
    }


    $ch =
        curl_init();


    if ($ch === false) {

        throw new RuntimeException(
            'Não foi possível iniciar cURL para IXC.'
        );
    }


    curl_setopt_array(
        $ch,
        [
            CURLOPT_URL =>
                $url,

            CURLOPT_POST =>
                true,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                5,

            CURLOPT_TIMEOUT =>
                15,

            CURLOPT_FOLLOWLOCATION =>
                false,

            CURLOPT_HTTPHEADER =>
                [
                    'Content-Type: application/json',

                    'Accept: application/json',

                    'ixcsoft: listar',

                    'Authorization: ' .
                    $authorization
                ],

            CURLOPT_POSTFIELDS =>
                $body
        ]
    );


    $response =
        curl_exec($ch);


    if ($response === false) {

        $errno =
            curl_errno($ch);

        $error =
            curl_error($ch);

        curl_close($ch);


        throw new RuntimeException(
            'Erro cURL IXC (' .
            $errno .
            '): ' .
            $error
        );
    }


    $httpCode =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close($ch);


    $json =
        json_decode(
            $response,
            true
        );


    return [
        'http_code' =>
            $httpCode,

        'raw' =>
            $response,

        'json' =>
            is_array($json)
                ? $json
                : null
    ];
}


/* =========================================================
   HELPERS DE REGISTROS IXC
   ========================================================= */

function jrIxcRecords(array $result): array
{
    if (($result['http_code'] ?? 0) < 200 || ($result['http_code'] ?? 0) >= 300) {
        return [];
    }

    $json = $result['json'] ?? null;
    if (!is_array($json)) {
        return [];
    }

    $records = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : []);
    return is_array($records)
        ? array_values(array_filter($records, 'is_array'))
        : [];
}

function jrIxcPick(array $record, array $keys): mixed
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $record)) {
            continue;
        }

        $value = $record[$key];
        if ($value === null) {
            continue;
        }

        if (is_string($value) && trim($value) === '') {
            continue;
        }

        return $value;
    }

    return null;
}


/* =========================================================
   DEFINIR STATUS OPTICO
   =========================================================
 *
 * Por enquanto:
 *
 * Se o IXC devolver valor numérico,
 * usamos "Normal".
 *
 * Depois podemos evoluir para
 * níveis:
 *
 * Normal
 * Atenção
 * Crítico
 *
 * conforme faixa RX.
 * =========================================================
 */

function jrOpticalStatus(
    ?float $value
): ?string {

    if ($value === null) {
        return null;
    }

    return 'Normal';
}

function jrRxOpticalStatus(?float $value): ?string
{
    if ($value === null) {
        return null;
    }

    // Política JR CONECT:
    // > -25 dBm = Normal
    // -25 até acima de -27 dBm = Atenção
    // -27 dBm ou pior = Crítico
    if ($value <= -27.0) {
        return 'Crítico';
    }

    if ($value <= -25.0) {
        return 'Atenção';
    }

    return 'Normal';
}


/* =========================================================
   EXECUÇÃO PRINCIPAL
   ========================================================= */

try {

    /* =====================================================
       DEVICE ID
       ===================================================== */

    $deviceId =
        trim(
            (string)(
                $_GET['device_id']
                ?? ''
            )
        );


    if ($deviceId === '') {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'message' =>
                    'device_id não informado.'
            ],
            400
        );
    }


    /* =====================================================
       VALIDAR CONFIG IXC
       ===================================================== */

    if (
        $ixcToken === '' ||
        str_contains(
            $ixcToken,
            'COLE_AQUI'
        )
    ) {

        throw new RuntimeException(
            'Configure $ixcToken com o token bruto do IXC.'
        );
    }


    /* =====================================================
       CONSULTAR GENIEACS
       ===================================================== */

    $device =
        jrGetGenieDevice(
            $deviceId
        );


    $serial =
        jrGetSerialFromDevice(
            $device
        );


    $serialAliases =
        jrOpticalSerialAliases(
            $serial
        );


    $manufacturer =
        jrGetManufacturerFromDevice(
            $device
        );

    // Perfil CPE: permite usar dados ópticos diretamente do TR-069
    // quando o IXC não possui leitura para este modelo.
    $profileOptical = CPEProfiles::optical($device);
    $hasProfileOptical =
        (($profileOptical['rx_power'] ?? null) !== null) ||
        (($profileOptical['tx_power'] ?? null) !== null) ||
        (($profileOptical['temperature'] ?? null) !== null) ||
        (($profileOptical['voltage'] ?? null) !== null);


    /* =====================================================
       CONSULTAR IXC
       ===================================================== */

    $endpoint =
        'radpop_radio_cliente_fibra';


    $result = null;
    $records = [];
    $matchedSerialQuery = null;

    foreach ($serialAliases as $serialQuery) {
        $params = [
            'qtype' => 'radpop_radio_cliente_fibra.mac',
            'query' => $serialQuery,
            'oper' => '=',
            'page' => '1',
            'rp' => '20',
            'sortname' => 'radpop_radio_cliente_fibra.id',
            'sortorder' => 'desc'
        ];

        $candidateResult = jrIxcOpticalRequest(
            $ixcBaseUrl,
            $ixcToken,
            $endpoint,
            $params
        );

        if (($candidateResult['http_code'] ?? 0) === 401) {
            $result = $candidateResult;
            break;
        }

        if (
            ($candidateResult['http_code'] ?? 0) < 200 ||
            ($candidateResult['http_code'] ?? 0) >= 300
        ) {
            $result = $candidateResult;
            continue;
        }

        $candidateJson = $candidateResult['json'] ?? null;
        if (
            is_array($candidateJson) &&
            isset($candidateJson['type']) &&
            $candidateJson['type'] === 'error'
        ) {
            $result = $candidateResult;
            continue;
        }

        $candidateRecords = jrIxcRecords($candidateResult);
        $result = $candidateResult;

        if ($candidateRecords) {
            $records = $candidateRecords;
            $matchedSerialQuery = $serialQuery;
            break;
        }
    }

    if ($result === null) {
        throw new RuntimeException('Não foi possível executar a consulta IXC.');
    }


    /* =====================================================
       VALIDAR HTTP
       ===================================================== */

    if (
        $result['http_code'] === 401
    ) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'authentication',

                'message' =>
                    'IXC recusou autenticação.'
            ],
            401
        );
    }


    if (
        $result['http_code'] < 200 ||
        $result['http_code'] >= 300
    ) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'http',

                'http_code' =>
                    $result['http_code'],

                'message' =>
                    'IXC retornou HTTP inesperado.'
            ],
            502
        );
    }


    /* =====================================================
       VALIDAR JSON
       ===================================================== */

    if ($result['json'] === null) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'json',

                'message' =>
                    'IXC respondeu, mas a resposta não é JSON válido.'
            ],
            502
        );
    }


    $json =
        $result['json'];


    /* =====================================================
       ERRO IXC
       ===================================================== */

    if (
        isset($json['type']) &&
        $json['type'] === 'error'
    ) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'ixc',

                'message' =>
                    $json['message']
                    ?? 'IXC retornou erro.'
            ],
            422
        );
    }


    /* =====================================================
       REGISTROS
       ===================================================== */

    if (!$records) {
        $records = jrIxcRecords($result);
    }


    /* =====================================================
       ONU NÃO ENCONTRADA
       ===================================================== */

    if (count($records) === 0) {

        if ($hasProfileOptical) {
            jrOpticalJson(
                [
                    'success' => true,
                    'source' => 'TR-069 / Perfil CPE',
                    'source_policy' => [
                        'network' => 'IXC principal / TR-069 fallback',
                        'optical' => 'IXC principal / TR-069 fallback',
                        'equipment' => 'TR-069 / GenieACS',
                        'wifi_write' => 'TR-069 / GenieACS',
                    ],
                    'device_id' => $deviceId,
                    'serial' => $serial,
                    'manufacturer' => $manufacturer,
                    'profile' => $profileOptical['profile'] ?? null,
                    'ixc_id' => null,
                    'nome' => null,
                    'id_login' => null,
                    'id_contrato' => null,
                    'id_transmissor' => null,
                    'network' => [
                        'source' => 'TR-069',
                        'login_lookup_ok' => false,
                    ],
                    'pon_id' => null,
                    'onu_number' => null,
                    'slot' => null,
                    'pon' => null,
                    'optical' => [
                        'rx_power' => $profileOptical['rx_power'] ?? null,
                        'rx_status' => jrRxOpticalStatus($profileOptical['rx_power'] ?? null),
                        'tx_power' => $profileOptical['tx_power'] ?? null,
                        'tx_status' => jrOpticalStatus($profileOptical['tx_power'] ?? null),
                        'temperature' => $profileOptical['temperature'] ?? null,
                        'temperature_status' => jrOpticalStatus($profileOptical['temperature'] ?? null),
                        'voltage' => $profileOptical['voltage'] ?? null,
                        'voltage_status' => jrOpticalStatus($profileOptical['voltage'] ?? null),
                        'last_update' => $profileOptical['last_update'] ?? null,
                    ],
                ]
            );
        }

        jrOpticalJson(
            [
                'success' => false,
                'source' => 'IXC',
                'stage' => 'search',
                'device_id' => $deviceId,
                'serial' => $serial,
                'manufacturer' => $manufacturer,
                'message' => 'ONU não encontrada no Cliente Fibra do IXC e sem leitura óptica no perfil CPE.'
            ],
            404
        );
    }


    /* =====================================================
       LOCALIZAR REGISTRO EXATO
       ===================================================== */

    $record =
        null;


    foreach ($records as $item) {

        if (!is_array($item)) {
            continue;
        }


        $itemMac =
            jrNormalizeOpticalSerial(
                (string)(
                    $item['mac']
                    ?? ''
                )
            );


        if (
            in_array(
                $itemMac,
                $serialAliases,
                true
            )
        ) {

            $record =
                $item;

            break;
        }
    }


    /*
     * Se IXC já filtrou e só devolveu um registro,
     * usamos ele.
     */

    if (
        $record === null &&
        count($records) === 1
    ) {

        $record =
            $records[0];
    }


    if ($record === null) {

        jrOpticalJson(
            [
                'success' =>
                    false,

                'source' =>
                    'IXC',

                'stage' =>
                    'match',

                'device_id' =>
                    $deviceId,

                'serial' =>
                    $serial,

                'message' =>
                    'IXC retornou registros, mas nenhum corresponde ao serial da ONU.'
            ],
            404
        );
    }


    /* =====================================================
       FALLBACK DE REDE / LOGIN VIA IXC
       ===================================================== */

    $loginRecord = [];
    $loginLookupOk = false;
    $idLogin = trim((string)($record['id_login'] ?? ''));

    if ($idLogin !== '') {
        try {
            $loginResult = jrIxcOpticalRequest(
                $ixcBaseUrl,
                $ixcToken,
                'radusuarios',
                [
                    'qtype' => 'radusuarios.id',
                    'query' => $idLogin,
                    'oper' => '=',
                    'page' => '1',
                    'rp' => '1',
                    'sortname' => 'radusuarios.id',
                    'sortorder' => 'desc',
                ]
            );

            $loginRows = jrIxcRecords($loginResult);
            if (!empty($loginRows[0])) {
                $loginRecord = $loginRows[0];
                $loginLookupOk = true;
            }
        } catch (Throwable $ignored) {
            // A leitura óptica não deve falhar só porque o cadastro de Login
            // está temporariamente indisponível.
        }
    }

    $ixcNetwork = [
        'source' => $loginLookupOk ? 'IXC Cliente Fibra + Login' : 'IXC Cliente Fibra',
        'login_lookup_ok' => $loginLookupOk,
        'login' => jrIxcPick($loginRecord, ['login']),
        'ip' => jrIxcPick($loginRecord, ['ip', 'ip_aux']),
        'ipv6' => jrIxcPick($loginRecord, [
            'ipv6',
            'ip_v6',
            'framed_ipv6',
            'framed_pd_ipv6'
        ]),
        'ipv6_pd' => jrIxcPick($loginRecord, [
            'pd_ipv6',
            'delegation_ipv6',
            'ipv6_pd'
        ]),
        'online' => jrIxcPick($loginRecord, ['online']),
        'vlan' => jrIxcPick($record, [
            'vlan_pppoe',
            'vlan',
            'vlan_uplink'
        ]) ?? jrIxcPick($loginRecord, ['vlan']),
        'vlan_tr69' => jrIxcPick($record, ['vlan_tr69']),
        'last_error' => jrIxcPick($record, [
            'causa_ultima_queda',
            'motivo_ultima_queda'
        ]),
        'last_connection_start' => jrIxcPick($loginRecord, ['ultima_conexao_inicial']),
        'last_connection_end' => jrIxcPick($loginRecord, ['ultima_conexao_final']),
        'connected_time' => jrIxcPick($loginRecord, ['tempo_conectado', 'tempo_conexao']),
        'disconnect_count_today' => jrIxcPick($loginRecord, ['count_desconexao']),
        'concentrator_id' => jrIxcPick($loginRecord, ['id_concentrador']),
        'transmitter_id' => jrIxcPick($record, ['id_transmissor']),
        'fiber_reference' => jrIxcPick($record, ['referencia']),
        'management_ip' => jrIxcPick($record, ['ip_gerencia']),
    ];


    /* =====================================================
       EXTRAIR DADOS OPTICOS
       ===================================================== */

    $rx =
        jrOpticalNumber(
            $record['sinal_rx']
            ?? null
        );


    $tx =
        jrOpticalNumber(
            $record['sinal_tx']
            ?? null
        );


    $temperature =
        jrOpticalNumber(
            $record['temperatura']
            ?? null
        );


    $voltage =
        jrOpticalNumber(
            $record['voltagem']
            ?? null
        );

    // Completa campos ausentes do IXC com o perfil TR-069 do equipamento.
    // Zero exato no IXC não é uma leitura óptica; o valor do CPE entra no lugar.
    $profileRx = $profileOptical['rx_power'] ?? null;
    $profileTx = $profileOptical['tx_power'] ?? null;
    $profileTemperature = $profileOptical['temperature'] ?? null;
    if ($rx === null || ($rx == 0.0 && $profileRx !== null && (float)$profileRx != 0.0)) $rx = $profileRx;
    if ($tx === null || ($tx == 0.0 && $profileTx !== null && (float)$profileTx != 0.0)) $tx = $profileTx;
    if ($temperature === null || ($temperature == 0.0 && $profileTemperature !== null && (float)$profileTemperature != 0.0)) $temperature = $profileTemperature;
    if ($voltage !== null && $voltage <= 0) $voltage = null;
    if ($voltage === null) $voltage = $profileOptical['voltage'] ?? null;
    if ($voltage !== null && $voltage <= 0) $voltage = null;


    /* =====================================================
       PON
       ===================================================== */

    $ponId =
        $record['ponid']
        ?? null;


    $onuNumber =
        $record['onu_numero']
        ?? null;


    $slot =
        $record['slotno']
        ?? null;


    $pon =
        $record['ponno']
        ?? null;


    $lastUpdate =
        $record['data_sinal']
        ?? $profileOptical['last_update']
        ?? null;


    /* =====================================================
       RESPOSTA FINAL
       ===================================================== */

    jrOpticalJson(
        [
            'success' =>
                true,

            'source' =>
                ($hasProfileOptical ? 'IXC + TR-069 / Perfil CPE' : 'IXC'),

            'source_policy' => [
                'network' => 'IXC principal / TR-069 fallback',
                'optical' => 'IXC principal / TR-069 fallback',
                'equipment' => 'TR-069 / GenieACS',
                'wifi_write' => 'TR-069 / GenieACS',
            ],

            'device_id' =>
                $deviceId,

            'manufacturer' =>
                $manufacturer,

            'serial' =>
                $serial,

            'ixc_serial_match' =>
                $matchedSerialQuery,

            'ixc_id' =>
                $record['id']
                ?? null,

            'nome' =>
                $record['nome']
                ?? null,

            'id_login' =>
                $record['id_login']
                ?? null,

            'id_contrato' =>
                $record['id_contrato']
                ?? null,

            'id_transmissor' =>
                $record['id_transmissor']
                ?? null,

            'network' =>
                $ixcNetwork,

            'pon_id' =>
                $ponId,

            'onu_number' =>
                $onuNumber,

            'slot' =>
                $slot,

            'pon' =>
                $pon,

            'optical' =>
                [
                    'rx_power' =>
                        $rx,

                    'rx_status' =>
                        jrRxOpticalStatus($rx),

                    'tx_power' =>
                        $tx,

                    'tx_status' =>
                        jrOpticalStatus($tx),

                    'temperature' =>
                        $temperature,

                    'temperature_status' =>
                        jrOpticalStatus($temperature),

                    'voltage' =>
                        $voltage,

                    'voltage_status' =>
                        jrOpticalStatus($voltage),

                    'last_update' =>
                        $lastUpdate
                ]
        ]
    );


} catch (Throwable $e) {

    jrOpticalJson(
        [
            'success' =>
                false,

            'source' =>
                'IXC',

            'stage' =>
                'php',

            'error_type' =>
                get_class($e),

            'message' =>
                $e->getMessage(),

            'line' =>
                $e->getLine()
        ],
        500
    );
}