<?php
declare(strict_types=1);

/** Bounded, read-only fallback for the overview; never downloads the whole fleet. */
final class JrcRecentDevices
{
    public static function url(array $config): string
    {
        return 'http://' . $config['host'] . ':' . (int)$config['port'] . '/devices/?' .
            http_build_query(['limit' => 10, 'sort' => json_encode(['_lastInform' => -1])]);
    }

    public static function decode(string $body, int $status): array
    {
        $rows = json_decode($body, true);
        if (substr(ltrim($body), 0, 1) !== '[' || $status < 200 || $status >= 300 || !is_array($rows) || array_values($rows) !== $rows) {
            throw new RuntimeException('Resposta invalida do inventario ACS');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['_id'])) {
                throw new RuntimeException('Dispositivo invalido na resposta ACS');
            }
        }
        return ['success' => true, 'data' => array_slice($rows, 0, 10)];
    }

    public static function fetch(array $config): array
    {
        $ch = curl_init(self::url($config));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
        ]);
        if (!empty($config['username']) && !empty($config['password'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
        }
        try {
            $body = curl_exec($ch);
            if ($body === false) throw new RuntimeException('Consulta ACS indisponivel: ' . curl_errno($ch));
            return self::decode($body, (int)curl_getinfo($ch, CURLINFO_HTTP_CODE));
        } finally {
            curl_close($ch);
        }
    }
}
