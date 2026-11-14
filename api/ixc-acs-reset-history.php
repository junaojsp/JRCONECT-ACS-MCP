<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

function jrResetOut(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {

    $logFile = dirname(__DIR__) . '/logs/sims-api.log';

    if (!is_file($logFile)) {
        jrResetOut([
            'success' => true,
            'available' => true,
            'source' => 'JR CONECT ACS',
            'read_only' => true,
            'route' => 'logs/sims-api.log',
            'today' => 0,
            'last_7_days' => [],
            'total_7_days' => 0,
            'average_7_days' => 0,
            'recent_events' => [],
        ]);
    }

    $tz = new DateTimeZone('America/Sao_Paulo');

    $today = new DateTimeImmutable(
        'today',
        $tz
    );

    /*
     * Montar os últimos 7 dias.
     */
    $days = [];

    for ($i = 6; $i >= 0; $i--) {

        $date = $today->modify("-{$i} days");

        $key = $date->format('Y-m-d');

        $days[$key] = [
            'date' => $key,
            'count' => 0,
            'reboots' => 0,
            'resets' => 0,
        ];
    }

    $recentEvents = [];

    /*
     * Lê o log linha por linha para não carregar
     * o arquivo inteiro na memória.
     */
    $file = new SplFileObject($logFile, 'r');

    while (!$file->eof()) {

        $line = trim(
            (string)$file->fgets()
        );

        if ($line === '') {
            continue;
        }

        $record = json_decode(
            $line,
            true
        );

        if (!is_array($record)) {
            continue;
        }

        /*
         * Contabilizamos somente reboot executado com sucesso.
         * reboot_failed não entra nas estatísticas.
         */
        if (($record['event'] ?? '') !== 'reboot') {
            continue;
        }

        $at = $record['at'] ?? null;

        if (!is_string($at) || $at === '') {
            continue;
        }

        try {

            $date = new DateTimeImmutable($at);

            $date = $date->setTimezone($tz);

        } catch (Throwable $e) {

            continue;
        }

        $dayKey = $date->format('Y-m-d');

        /*
         * Estatísticas dos últimos 7 dias.
         */
        if (isset($days[$dayKey])) {

            $days[$dayKey]['count']++;
            $days[$dayKey]['reboots']++;
        }

        /*
         * Eventos recentes.
         */
        $data = is_array($record['data'] ?? null)
            ? $record['data']
            : [];

        $recentEvents[] = [
            'date_time' => $date->format(DateTimeInterface::ATOM),
            'kind' => 'reboot',
            'status' => 'success',
            'serial' => $data['serial'] ?? null,
            'id_login' => $data['id_login'] ?? null,
            'id_contrato' => $data['id_contrato'] ?? null,
            'http_code' => $data['http_code'] ?? null,
            'remote_ip' => $record['remote_ip'] ?? null,
        ];
    }

    /*
     * Mais recentes primeiro.
     */
    usort(
        $recentEvents,
        static function (array $a, array $b): int {

            return strcmp(
                (string)$b['date_time'],
                (string)$a['date_time']
            );
        }
    );

    $recentEvents = array_slice(
        $recentEvents,
        0,
        25
    );

    $total7 = array_sum(
        array_column(
            $days,
            'count'
        )
    );

    $todayKey = $today->format('Y-m-d');

    jrResetOut([
        'success' => true,
        'available' => true,

        'source' => 'JR CONECT ACS',
        'read_only' => true,

        'route' => 'logs/sims-api.log',

        'today' => (int)(
            $days[$todayKey]['count']
            ?? 0
        ),

        'last_7_days' => array_values($days),

        'total_7_days' => (int)$total7,

        'average_7_days' => round(
            $total7 / 7,
            1
        ),

        'recent_events' => $recentEvents,

        'operation_types' => [
            'reboot' => 'Reinício da ONU',
        ],
    ]);

} catch (Throwable $e) {

    error_log(
        '[JR ACS RESET HISTORY] ' .
        $e->getMessage() .
        ' | ' .
        $e->getFile() .
        ':' .
        $e->getLine()
    );

    jrResetOut([
        'success' => false,
        'available' => false,
        'source' => 'JR CONECT ACS',
        'read_only' => true,
        'message' => 'Falha ao consultar o histórico de reboots do ACS.',
    ], 500);
}