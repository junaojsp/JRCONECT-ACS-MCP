<?php
declare(strict_types=1);

/**
 * Read-only TR-069 compatibility analysis.
 *
 * No changes to CPE, no automatic activation of optical aliases, no
 * cross-source assumptions about IXC. Suitable for unknown vendor/model/
 * firmware. Output contains only optical path names, value-presence and
 * parameter age (never Wi-Fi/PPP credentials or raw parameters).
 */
final class JrcTr069ProfileCompare
{
    private const MAPPED_RX = [
        'VirtualParameters.RXPower' => true,
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower' => true,
        'Device.Optical.Interface.1.RxPower' => true,
    ];
    private const MAPPED_TEMP = [
        'VirtualParameters.gettemp' => true,
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature' => true,
        'VirtualParameters.Temperature' => true,
        'InternetGatewayDevice.DeviceInfo.Temperature' => true,
    ];

    private const SENSITIVE_SEGMENT =
        '/password|passphrase|secret|token|keypass|preshared|pre.?shared|private|auth|ssid|bssid|pppoe|username|serial|macaddress|ipaddress|sip|imei/i';

    public static function isHuawei(array $device): bool
    {
        $vendor = (string)($device['_deviceId']['_Manufacturer'] ?? '');
        $model = (string)($device['_deviceId']['_ProductClass'] ?? '');
        return stripos($vendor, 'Huawei') !== false ||
            preg_match('/^(?:EG8|HG8)[A-Z0-9-]{3,}$/i', $model) === 1;
    }

    public static function profile(array $raw, int $now): array
    {
        $serial = trim((string)($raw['_deviceId']['_SerialNumber'] ?? ''));
        $model = self::clean((string)($raw['_deviceId']['_ProductClass'] ?? ''), 64);
        $firmware = (string)(
            $raw['InternetGatewayDevice']['DeviceInfo']['SoftwareVersion']['_value'] ??
            $raw['Device']['DeviceInfo']['SoftwareVersion']['_value'] ??
            ''
        );
        $firmware = self::clean($firmware, 72);
        $vendor = self::clean((string)($raw['_deviceId']['_Manufacturer'] ?? ''), 64);
        $oui = self::clean((string)($raw['_deviceId']['_OUI'] ?? ''), 20);
        $inform = self::ageMinutes($raw['_lastInform'] ?? null, $now);
        $optical = self::opticalPaths($raw, $now);
        $rx = array_values(array_filter($optical['paths'],
            static fn(array $p): bool => $p['kind'] === 'RX'));
        $temp = array_values(array_filter($optical['paths'],
            static fn(array $p): bool => $p['kind'] === 'TEMPERATURA'));

        $freshRx = array_values(array_filter($rx, static fn(array $p): bool =>
            $p['state'] === 'NUMERICO' && $p['fresh']));
        $numericRx = array_values(array_filter($rx, static fn(array $p): bool =>
            $p['state'] === 'NUMERICO'));

        return [
            'serial' => preg_match('/^[a-zA-Z0-9._:-]{4,80}$/D', $serial)
                ? $serial : 'SEM_SERIAL',
            'model' => $model,
            'firmware' => $firmware,
            'vendor' => $vendor,
            'oui' => $oui,
            'last_inform_age_min' => $inform,
            'rx' => $rx,
            'temperature' => $temp,
            'rx_numeric' => $numericRx !== [],
            'rx_fresh_numeric' => $freshRx !== [],
            'optical_path_truncated' => $optical['truncated'],
        ];
    }

    /**
     * For a NEW model, capture candidate paths without registering a model
     * by hand. No path becomes trusted until units, refresh behavior and
     * cross-device repeatability have been independently validated.
     */
    public static function opticalPaths(array $raw, int $now): array
    {
        $out = [];
        $visited = 0;
        $truncated = false;

        $walk = static function(array $node, string $path, int $depth)
            use (&$walk, &$out, &$visited, &$truncated, $now): void {
            if (++$visited > 75000 || $depth > 28 || count($out) >= 80) {
                $truncated = true;
                return;
            }
            $keys = array_keys($node);
            $last = strrpos($path, '.');
            $leaf = $last === false ? $path : substr($path, $last + 1);
            $isRx = preg_match(
                '/^(?:rxpower|rxpwr|rxopticalpower|opticalrxpower|opticalreceivepower|receivedopticalpower|rxsignal|rxsignallevel|rxlevel)$/i',
                $leaf
            ) === 1;
            $isTemp = preg_match(
                '/^(?:transceivertemperature|opticaltemperature|optictemperature|temperature|gettemp|temperaturec)$/i',
                $leaf
            ) === 1;
            // Do not confuse Wi-Fi signal, packet counters or an arbitrary
            // parameter containing "RX" with ONU optical receive power.
            $opticalContext = preg_match(
                '/gpon|epon|optical|optic|poninterface|transceiver|virtualparameters|deviceinfo/i',
                $path
            ) === 1;
            $isParameter = array_key_exists('_value', $node) ||
                array_key_exists('_timestamp', $node);
            if (($isRx || $isTemp) && $opticalContext && $isParameter) {
                $value = $node['_value'] ?? null;
                $present = $value !== null && $value !== '';
                $numeric = $present && is_scalar($value) && !is_bool($value) &&
                    is_numeric(str_replace(',', '.', trim((string)$value)));
                $age = self::ageMinutes($node['_timestamp'] ?? null, $now);
                $kind = $isRx ? 'RX' : 'TEMPERATURA';
                $out[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'state' => !$present ? 'SEM_VALOR' : ($numeric ? 'NUMERICO' : 'NAO_NUMERICO'),
                    'age_min' => $age,
                    'fresh' => $age !== null && $age <= 15,
                    'reader_mapped' => $isRx
                        ? isset(self::MAPPED_RX[$path])
                        : isset(self::MAPPED_TEMP[$path]),
                    'unit_verified' => false,
                ];
                return;
            }
            foreach ($keys as $k) {
                if (!is_string($k) || $k === '' || $k[0] === '_' ||
                    strlen($k) > 90 ||
                    preg_match('/^[a-zA-Z0-9_-]+$/D', $k) !== 1 ||
                    preg_match(self::SENSITIVE_SEGMENT, $k) === 1) continue;
                $child = $node[$k];
                if (!is_array($child)) continue;
                $walk($child, $path === '' ? $k : $path . '.' . $k, $depth + 1);
                if ($truncated) return;
            }
        };

        foreach (['InternetGatewayDevice', 'Device', 'VirtualParameters'] as $root) {
            if (isset($raw[$root]) && is_array($raw[$root])) {
                $walk($raw[$root], $root, 0);
            }
        }
        return ['paths' => array_values($out), 'truncated' => $truncated];
    }

    /** Prefer a genuine, recent TR-069 RX observation over a stale sample. */
    public static function chooseComparator(array $target, array $profiles): ?array
    {
        $eligible = array_values(array_filter($profiles, static function(array $p) use ($target): bool {
            return $p['serial'] !== $target['serial'] &&
                $p['rx_numeric'] && $p['optical_path_truncated'] === false;
        }));
        usort($eligible, static function(array $a, array $b) use ($target): int {
            $rank = static function(array $x) use ($target): array {
                return [
                    (int)$x['rx_fresh_numeric'],
                    (int)($x['model'] === $target['model']),
                    (int)($x['firmware'] === $target['firmware']),
                    (int)($x['last_inform_age_min'] !== null &&
                        $x['last_inform_age_min'] <= 15),
                ];
            };
            return ($rank($b) <=> $rank($a))
                ?: strcmp($a['model'], $b['model'])
                ?: strcmp($a['serial'], $b['serial']);
        });
        return $eligible[0] ?? null;
    }

    /** Structure-only, non-destructive comparison; does not label a CPE incompatible. */
    public static function differences(array $a, array $b): array
    {
        $aPaths = array_column(array_merge($a['rx'], $a['temperature']), 'state', 'path');
        $bPaths = array_column(array_merge($b['rx'], $b['temperature']), 'state', 'path');
        $shared = array_values(array_intersect(array_keys($aPaths), array_keys($bPaths)));
        sort($shared);
        $onlyA = array_values(array_diff(array_keys($aPaths), array_keys($bPaths)));
        $onlyB = array_values(array_diff(array_keys($bPaths), array_keys($aPaths)));
        sort($onlyA);
        sort($onlyB);
        $changedState = [];
        foreach ($shared as $path) {
            if ($aPaths[$path] !== $bPaths[$path]) {
                $changedState[] = $path . ': ' . $aPaths[$path] . ' vs ' . $bPaths[$path];
            }
        }

        return [
            'same_model' => $a['model'] === $b['model'],
            'same_firmware' => $a['firmware'] === $b['firmware'],
            'paths_only_target' => $onlyA,
            'paths_only_reference' => $onlyB,
            'paths_in_both' => $shared,
            'different_value_states' => $changedState,
        ];
    }

    private static function ageMinutes(mixed $timestamp, int $now): ?int
    {
        if (!is_string($timestamp) || $timestamp === '') return null;
        $epoch = strtotime($timestamp);
        if ($epoch === false || $epoch > $now + 300) return null;
        return max(0, (int)floor(($now - $epoch) / 60));
    }

    private static function clean(string $s, int $max): string
    {
        $s = trim(preg_replace('/[\x00-\x1F\x7f]+/u', ' ', $s) ?? '');
        if ($s === '') return 'NAO_INFORMADO';
        return substr($s, 0, $max);
    }
}
