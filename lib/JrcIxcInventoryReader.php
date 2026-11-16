<?php
declare(strict_types=1);

/** Read-only IXC fleet enrichment. Only environment-provided credentials. */
final class JrcIxcInventoryReader
{
    public static function normalSerial(string $value): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    public static function rx($raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $value = str_replace(',', '.', trim((string)$raw));
        if (!is_numeric($value)) return null;
        $number = (float)$value;
        // Optical RX dBm range; 0 and IXC sentinels are not real measurements.
        return ($number < 0 && $number >= -80) ? $number : null;
    }

    private static function records(string $url, string $token, string $table): array
    {
        if (!preg_match('~^https://[^/]+~i', $url)) {
            throw new RuntimeException('JRC_IXC_URL deve usar HTTPS');
        }
        $results = [];
        $firstIds = [];
        $maxPages = 200;
        $perPage = 100;
        for ($page = 1; $page <= $maxPages; $page++) {
            $body = json_encode([
                'qtype' => $table . '.id', 'query' => '0', 'oper' => '>',
                'page' => (string)$page, 'rp' => (string)$perPage,
                'sortname' => $table . '.id', 'sortorder' => 'asc'
            ]);
            $ch = curl_init(rtrim($url, '/') . '/webservice/v1/' . $table);
            if ($ch === false) throw new RuntimeException('Erro inicializando IXC');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json', 'Accept: application/json',
                    'ixcsoft: listar', 'Authorization: Basic ' . base64_encode($token)
                ]
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false || $code !== 200) {
                throw new RuntimeException('IXC HTTP inesperado na tabela ' . $table . ': ' . $code);
            }
            $json = json_decode($raw, true);
            if (!is_array($json) || (isset($json['type']) && $json['type'] === 'error')) {
                throw new RuntimeException('IXC retornou resposta invalida na tabela ' . $table);
            }
            $batch = $json['registros'] ?? $json['records'] ?? (array_is_list($json) ? $json : null);
            if (!is_array($batch)) throw new RuntimeException('IXC sem lista de registros em ' . $table);
            if ($batch === []) break;
            $firstId = (string)($batch[0]['id'] ?? '');
            if ($firstId !== '' && isset($firstIds[$firstId])) {
                throw new RuntimeException('Paginacao IXC repetida em ' . $table);
            }
            $firstIds[$firstId] = true;
            foreach ($batch as $item) {
                if (is_array($item)) $results[] = $item;
            }
            if (count($batch) < $perPage) {
                // Some IXC installations report total; do not silently truncate.
                $total = isset($json['total']) && is_numeric($json['total']) ? (int)$json['total'] : null;
                if ($total !== null && count($results) < $total) {
                    throw new RuntimeException('Paginacao IXC incompleta em ' . $table);
                }
                break;
            }
            if ($page === $maxPages) throw new RuntimeException('IXC excedeu limite de paginas');
        }
        return $results;
    }

    /** Returns map by exact normalized ONU serial/MAC. */
    public static function load(string $url, string $token): array
    {
        $fibers = self::records($url, $token, 'radpop_radio_cliente_fibra');
        $users = self::records($url, $token, 'radusuarios');
        $loginById = [];
        foreach ($users as $row) {
            if (isset($row['id'], $row['login'])) $loginById[(string)$row['id']] = (string)$row['login'];
        }
        $output = [];
        foreach ($fibers as $fiber) {
            $serial = self::normalSerial((string)($fiber['mac'] ?? ''));
            if ($serial === '') continue;
            $rx = self::rx($fiber['sinal_rx'] ?? null);
            $signalUpdatedAt = (string)($fiber['data_sinal'] ?? '');
            // Old IXC measurements must not appear as live signal.
            if ($rx !== null && $signalUpdatedAt !== '') {
                $when = strtotime($signalUpdatedAt);
                if ($when === false || time() - $when > 86400 || $when > time() + 300) $rx = null;
            }
            $loginId = (string)($fiber['id_login'] ?? '');
            $login = $loginById[$loginId] ?? null;
            $entry = [
                'ixc_rx_power' => $rx,
                'ixc_pppoe_username' => $login,
                'ixc_customer_name' => $fiber['nome'] ?? null,
                'ixc_last_signal_update' => $signalUpdatedAt ?: null,
                'ixc_source' => 'IXC background collector'
            ];
            if (!isset($output[$serial]) ||
                ($output[$serial]['ixc_rx_power'] === null && $rx !== null)) {
                $output[$serial] = $entry;
            }
        }
        return $output;
    }
}
