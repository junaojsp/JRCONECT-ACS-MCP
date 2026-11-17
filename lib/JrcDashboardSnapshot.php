<?php
declare(strict_types=1);

/**
 * Read-only summaries of the background GenieACS inventory snapshot.
 * Used only by authenticated dashboard endpoints, never by device task routes.
 * A stale snapshot remains available with explicit age metadata; no NBI scan
 * is triggered just because a user navigates to the overview.
 */
final class JrcDashboardSnapshot
{
    /** @return ?array{devices:array,state:array} */
    public static function load(mysqli $db): ?array
    {
        $result = $db->query("SELECT snapshot_id, total, completed_at,
                                    TIMESTAMPDIFF(SECOND, completed_at, NOW()) AS age_seconds
                               FROM jrc_device_inventory_state WHERE id = 1");
        if (!$result) return null;
        $state = $result->fetch_assoc();
        if (!$state || (int)$state['total'] < 1) return null;
        $expected = (int)$state['total'];
        if ($expected > 100000) throw new RuntimeException('Snapshot acima do limite de seguranca');

        $stmt = $db->prepare('SELECT payload_json FROM jrc_device_inventory_rows
                              WHERE snapshot_id = ? ORDER BY device_id');
        $id = $state['snapshot_id'];
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $rows = $stmt->get_result();
        $devices = [];
        while ($row = $rows->fetch_assoc()) {
            $item = json_decode($row['payload_json'], true);
            if (!is_array($item)) throw new RuntimeException('Snapshot corrompido');
            require_once __DIR__ . '/JrcSnmpTelemetry.php';
            $devices[] = JrcSnmpTelemetry::apply($item);
        }
        $stmt->close();
        if (count($devices) !== $expected) {
            throw new RuntimeException('Snapshot trocado/incompleto durante leitura');
        }
        return ['devices' => $devices, 'state' => $state];
    }

    public static function validRx($raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        if (is_string($raw)) $raw = str_replace(',', '.', trim($raw));
        if (!is_numeric($raw)) return null;
        $rx = (float)$raw;
        // Ignore absent/placeholder values such as 0, -999 or positive sentinels.
        return ($rx < 0 && $rx >= -80) ? $rx : null;
    }

    /** Pure calculation: may be tested offline without a live ACS or IXC. */
    public static function calculate(array $devices, string $kind): array
    {
        if ($kind === 'stats') {
            $online = 0;
            foreach ($devices as $device) {
                if (($device['status'] ?? '') === 'online') $online++;
            }
            return ['total' => count($devices), 'online' => $online,
                    'offline' => count($devices) - $online];
        }

        if ($kind === 'uplink') {
            $counts = [
                'excellent' => 0, 'good' => 0, 'fair' => 0,
                'poor' => 0, 'no_signal' => 0, 'total' => count($devices)
            ];
            foreach ($devices as $device) {
                $rx = self::validRx($device['ixc_rx_power'] ?? null);
                if ($rx === null) $rx = self::validRx($device['rx_power'] ?? null);
                if ($rx === null) {
                    $counts['no_signal']++;
                } elseif ($rx > -20) {
                    $counts['excellent']++;
                } elseif ($rx >= -25) {
                    $counts['good']++;
                } elseif ($rx >= -28) {
                    $counts['fair']++;
                } else {
                    $counts['poor']++;
                }
            }
            return $counts;
        }

        if ($kind === 'recent') {
            // Sorting is restricted to the already-collected rows; no ACS scan.
            usort($devices, static function (array $a, array $b): int {
                $ta = (int)($a['last_inform_timestamp'] ?? 0);
                $tb = (int)($b['last_inform_timestamp'] ?? 0);
                return ($tb <=> $ta) ?: strcmp((string)($a['device_id'] ?? ''), (string)($b['device_id'] ?? ''));
            });
            return array_slice($devices, 0, 10);
        }
        throw new InvalidArgumentException('Resumo desconhecido');
    }

    /**
     * APCu is optional. Cache only aggregated statistics (not recent-device rows) for 8 seconds,
     * never raw device records or credentials. Version by inventory generation
     * AND SNMP overlay files to avoid cross-source or old-generation leakage.
     */
    private static function cacheKey(array $state, string $kind): string
    {
        $root = JrcSnmpTelemetry::ROOT;
        $snmpEnabled = JrcSnmpTelemetry::enabled();
        $version = $snmpEnabled ? [
            @filemtime($root . '/snmp-fleet.json') ?: 0,
            @filesize($root . '/snmp-fleet.json') ?: 0,
            @filemtime($root . '/snmp-fleet-status.json') ?: 0,
            @filesize($root . '/snmp-fleet-status.json') ?: 0,
        ] : [];
        return 'jrc:overview:v2:' . hash('sha256', json_encode([
            (string)$state['snapshot_id'], $kind, $snmpEnabled, $version
        ], JSON_THROW_ON_ERROR));
    }

    private static function canUseCache(): bool
    {
        return function_exists('apcu_fetch') && function_exists('apcu_store') &&
            (!function_exists('apcu_enabled') || apcu_enabled());
    }

    public static function response(mysqli $db, string $kind): ?array
    {
        if (!in_array($kind, ['stats', 'uplink', 'recent'], true)) {
            throw new InvalidArgumentException('Resumo desconhecido');
        }

        // One cheap metadata query on every request, rather than full inventory
        // decoding if another request has just calculated these same results.
        $query = $db->query("SELECT snapshot_id, total, completed_at,
                            TIMESTAMPDIFF(SECOND, completed_at, NOW()) AS age_seconds
                            FROM jrc_device_inventory_state WHERE id = 1");
        $state = $query ? $query->fetch_assoc() : null;
        if (!$state || (int)$state['total'] < 1) return null;
        if ((int)$state['total'] > 100000) {
            throw new RuntimeException('Snapshot acima do limite de seguranca');
        }
        require_once __DIR__ . '/JrcSnmpTelemetry.php';
        $key = self::cacheKey($state, $kind);
        $rows = null;
        if ($kind !== 'recent' && self::canUseCache()) {
            $success = false;
            $hit = apcu_fetch($key, $success);
            if ($success && is_array($hit)) $rows = $hit;
        }
        if ($rows === null) {
            // Reading the full inventory is the safe fallback when APCu is off.
            $snapshot = self::load($db);
            if ($snapshot === null) return null;
            $rows = self::calculate($snapshot['devices'], $kind);
            // A concurrent collection may have replaced the generation.
            // Only cache when the computed generation matches the initial one.
            if ($kind !== 'recent' && self::canUseCache() &&
                $snapshot['state']['snapshot_id'] === $state['snapshot_id']) {
                apcu_store($key, $rows, 8);
            }
            $state = $snapshot['state'];
        }
        $age = max(0, (int)$state['age_seconds']);
        $meta = [
            'source' => 'jrc_background_inventory',
            'cached_at' => $state['completed_at'],
            'cache_age_seconds' => $age,
            'cache_stale' => $age > 900
        ];
        if ($kind === 'stats') return array_merge(['success' => true, 'stats' => $rows], $meta);
        if ($kind === 'uplink') return array_merge(['success' => true, 'data' => $rows], $meta);
        return array_merge(['success' => true, 'devices' => $rows], $meta);
    }
}
