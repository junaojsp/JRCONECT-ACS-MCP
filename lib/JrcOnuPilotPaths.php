<?php
declare(strict_types=1);

/**
 * Inspect optical parameter NAMES and value freshness in stored GenieACS JSON.
 * No live TR-069 tasks. Never prints/returns customer or CPE secrets.
 * Exposes numeric optical samples ONLY from an allowlist of RX/temperature paths,
 * without assuming units or converting them to dBm/Celsius.
 */
final class JrcOnuPilotPaths
{
    /** The paths currently recognized by the installed fast reader. */
    private const READER_PATHS = [
        'VirtualParameters.RXPower' => true,
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower' => true,
        'Device.Optical.Interface.1.RxPower' => true,
        'VirtualParameters.gettemp' => true,
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature' => true,
        'VirtualParameters.Temperature' => true,
        'InternetGatewayDevice.DeviceInfo.Temperature' => true,
    ];

    /**
     * Report the raw numeric value ONLY for explicitly known optical paths.
     * This must not act as a generic scanner dumping CPE parameter values.
     */
    private static function numericOpticalSample(string $path, mixed $value): ?string
    {
        if (!preg_match(
            '~^InternetGatewayDevice\\.WANDevice\\.[0-9]+\\.X_(?:FH_)?GponInterfaceConfig\\.(?:RXPower|TransceiverTemperature)$~D',
            $path
        )) return null;
        if (!is_scalar($value) || is_bool($value)) return null;
        $raw = str_replace(',', '.', trim((string)$value));
        if ($raw === '' || strlen($raw) > 32 || !is_numeric($raw)) return null;
        $number = (float)$raw;
        if (!is_finite($number) || abs($number) > 1000000) return null;
        return $raw;
    }

    /** @return array<int,array{path:string,state:string,age_minutes:?int,mapped:bool,raw_optical_sample:?string}> */
    public static function inspect(array $raw, int $now, int $limit = 24): array
    {
        $results = [];
        $nodes = 0;
        $walk = static function(array $tree, string $path, int $depth)
            use (&$walk, &$results, &$nodes, $limit, $now): void {
            if (++$nodes > 60000 || $depth > 22 || count($results) >= $limit) return;

            $optical = preg_match(
                '/gpon|epon|optic|transceiver|rxpower|rxpwr|rxsignal|temperature|gettemp|laser|signalpower/i',
                $path
            ) === 1;
            $hasChildren = false;
            foreach ($tree as $k => $v) {
                if (!str_starts_with((string)$k, '_') && is_array($v)) {
                    $hasChildren = true;
                    break;
                }
            }
            // Container objects often have _timestamp too. Do NOT stop at
            // the container and hide nested RXPower/Temperature leaves.
            $isLeaf = array_key_exists('_value', $tree) ||
                      (!$hasChildren && array_key_exists('_timestamp', $tree));
            if ($optical && $isLeaf) {
                $present = array_key_exists('_value', $tree) &&
                           $tree['_value'] !== null && $tree['_value'] !== '';
                $numeric = $present && is_scalar($tree['_value']) &&
                           !is_bool($tree['_value']) &&
                           is_numeric(str_replace(',', '.', (string)$tree['_value']));
                $timeValue = $tree['_timestamp'] ?? null;
                $epoch = is_string($timeValue) ? strtotime($timeValue) : false;
                $age = $epoch === false ? null : max(0, (int) floor(($now - $epoch) / 60));
                $results[] = [
                    'path' => $path,
                    'state' => !$present ? 'VAZIO' : ($numeric ? 'NUMERICO' : 'NAO_NUMERICO'),
                    'age_minutes' => $age,
                    'mapped' => isset(self::READER_PATHS[$path]),
                    'raw_optical_sample' => $numeric
                        ? self::numericOpticalSample($path, $tree['_value'])
                        : null,
                ];
                return;
            }
            foreach ($tree as $key => $child) {
                if (!is_array($child)) continue;
                $segment = (string)$key;
                if ($segment === '' || $segment[0] === '_' ||
                    !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $segment) ||
                    preg_match('/password|passphrase|keypass|pre.?shared|secret|token|auth|private|ssid|bssid|macaddress|username|sip|serial|imei|ipaddress|pppoe/i', $segment)) {
                    continue;
                }
                $sub = $path === '' ? $segment : $path . '.' . $segment;
                $walk($child, $sub, $depth + 1);
                if ($nodes > 60000 || count($results) >= $limit) return;
            }
        };

        foreach (['InternetGatewayDevice', 'Device', 'VirtualParameters'] as $root) {
            if (isset($raw[$root]) && is_array($raw[$root])) {
                $walk($raw[$root], $root, 0);
            }
        }
        return $results;
    }
}
