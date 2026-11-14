<?php
namespace App;

/** Read-only, bounded queries for the NOC. Keeps the legacy client unchanged. */
class NocGenieACS
{
    private $baseUrl;
    private $username;
    private $password;

    public function __construct($host, $port, $username, $password)
    {
        $this->baseUrl = "http://{$host}:{$port}";
        $this->username = $username;
        $this->password = $password;
    }

    public function getDevices(int $limit, int $skip, int $timeout): array
    {
        return $this->query($limit, $skip, $timeout, [
            '_id', '_deviceId', '_lastInform', 'VirtualParameters.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
            'Device.Optical.Interface.1.RxPower',
        ]);
    }

    public function getInventory(int $limit, int $skip, int $timeout, ?string $id = null): array
    {
        require_once __DIR__ . '/NocInventory.php';
        return $this->query($id === null ? $limit : 1, $id === null ? $skip : 0,
            $timeout, NocInventory::projection($id !== null), $id);
    }

    private function query(int $limit, int $skip, int $timeout, array $projection, ?string $id = null): array
    {
        // Do not fetch entire TR-069 trees (Wi-Fi/PPPoE passwords included).
        $params = [
            'projection' => implode(',', $projection),
            'limit' => max(1, min(100, $limit)),
            'skip' => max(0, $skip),
            'sort' => json_encode(['_id' => 1]),
        ];
        if ($id !== null) $params['query'] = json_encode(['_id' => $id], JSON_THROW_ON_ERROR);
        $ch = curl_init($this->baseUrl . '/devices/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, min(15, $timeout)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // Bound even an upstream that ignores projection/limit to 4 MiB.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($this->username && $this->password) {
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        }
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false || $code < 200 || $code >= 300) {
            error_log('[JRC NOC] GenieACS query failed; HTTP=' . $code . '; curl=' . $errno);
            return ['success' => false];
        }
        try {
            $devices = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['success' => false];
        }
        if (substr(ltrim($body), 0, 1) !== '[' || !is_array($devices)
            || count($devices) > $params['limit']
            || ($devices !== [] && array_keys($devices) !== range(0, count($devices) - 1))) {
            return ['success' => false];
        }
        return ['success' => true, 'data' => $devices];
    }
}
