<?php
declare(strict_types=1);

namespace App;

use mysqli;
use RuntimeException;

final class DeviceInventoryCache
{
    public const TABLE = 'jrc_device_inventory';
    public const META_TABLE = 'jrc_device_inventory_meta';

    public static function ensureSchema(mysqli $db): void
    {
        $db->query("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                device_id VARCHAR(255) NOT NULL,
                serial_number VARCHAR(128) NOT NULL DEFAULT '',
                mac_address VARCHAR(64) NOT NULL DEFAULT '',
                manufacturer VARCHAR(128) NOT NULL DEFAULT '',
                oui VARCHAR(32) NOT NULL DEFAULT '',
                product_class VARCHAR(128) NOT NULL DEFAULT '',
                hardware_version VARCHAR(128) NOT NULL DEFAULT '',
                software_version VARCHAR(128) NOT NULL DEFAULT '',
                ip_tr069 VARCHAR(512) NOT NULL DEFAULT '',
                ip_address VARCHAR(64) NOT NULL DEFAULT '',
                wifi_ssid VARCHAR(255) NOT NULL DEFAULT '',
                wifi_password VARCHAR(255) NOT NULL DEFAULT '',
                pppoe_username VARCHAR(255) NOT NULL DEFAULT '',
                rx_power DECIMAL(9,2) NULL,
                temperature DECIMAL(9,2) NULL,
                connected_devices_count INT UNSIGNED NOT NULL DEFAULT 0,
                uptime BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_inform DATETIME NULL,
                tags_json LONGTEXT NULL,
                sync_generation VARCHAR(40) NOT NULL DEFAULT '',
                synced_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (device_id),
                KEY idx_jrc_inv_serial (serial_number),
                KEY idx_jrc_inv_mac (mac_address),
                KEY idx_jrc_inv_pppoe (pppoe_username),
                KEY idx_jrc_inv_ip (ip_address),
                KEY idx_jrc_inv_last_inform (last_inform),
                KEY idx_jrc_inv_rx (rx_power),
                KEY idx_jrc_inv_generation (sync_generation)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS " . self::META_TABLE . " (
                id TINYINT UNSIGNED NOT NULL,
                last_sync_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_full_sync_at DATETIME NULL,
                last_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                device_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_mode VARCHAR(20) NOT NULL DEFAULT '',
                last_error TEXT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            INSERT IGNORE INTO " . self::META_TABLE . " (id, last_error)
            VALUES (1, NULL)
        ");
    }

    public static function isReady(mysqli $db): bool
    {
        try {
                $row = $db->query(
                "SELECT device_count, last_success_at FROM " . self::META_TABLE . " WHERE id = 1"
            )->fetch_assoc();

            if (!$row || empty($row['last_success_at'])) return false;

            $lastSuccess = strtotime((string)$row['last_success_at']);
            if ($lastSuccess === false || (time() - $lastSuccess) > 300) {
                return false;
            }

            $count = (int)$db->query(
                "SELECT COUNT(*) AS c FROM " . self::TABLE
            )->fetch_assoc()['c'];

            return $count > 0 || (int)$row['device_count'] === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function meta(mysqli $db): array
    {
        $row = $db->query(
            "SELECT * FROM " . self::META_TABLE . " WHERE id = 1"
        )->fetch_assoc();

        return $row ?: [];
    }

    private static function statusFromLastInform(?string $lastInform): string
    {
        if (!$lastInform) return 'offline';
        $ts = strtotime($lastInform);
        if ($ts === false) return 'offline';
        return (time() - $ts) < 300 ? 'online' : 'offline';
    }

    private static function rowToDevice(array $row): array
    {
        $rx = $row['rx_power'];
        $temperature = $row['temperature'];

        $tags = [];
        if (!empty($row['tags_json'])) {
            $decoded = json_decode((string)$row['tags_json'], true);
            if (is_array($decoded)) $tags = array_values($decoded);
        }

        $ipTr069 = (string)($row['ip_tr069'] ?? '');
        if ($ipTr069 === '') {
            $ipTr069 = (string)($row['ip_address'] ?? '');
        }

        return [
            'device_id' => (string)$row['device_id'],
            'serial_number' => (string)$row['serial_number'],
            'mac_address' => (string)$row['mac_address'],
            'manufacturer' => (string)$row['manufacturer'],
            'oui' => (string)$row['oui'],
            'product_class' => (string)$row['product_class'],
            'hardware_version' => (string)($row['hardware_version'] ?: 'N/A'),
            'software_version' => (string)($row['software_version'] ?: 'N/A'),
            'ip_tr069' => $ipTr069 !== '' ? $ipTr069 : 'N/A',
            'ip_address' => (string)($row['ip_address'] ?: 'N/A'),
            'wifi_ssid' => (string)($row['wifi_ssid'] ?: 'N/A'),
            'wifi_password' => (string)($row['wifi_password'] ?: 'N/A'),
            'pppoe_username' => (string)($row['pppoe_username'] ?: 'N/A'),
            'rx_power' => $rx !== null ? number_format((float)$rx, 2, '.', '') : 'N/A',
            'temperature' => $temperature !== null ? number_format((float)$temperature, 1, '.', '') : 'N/A',
            'connected_devices_count' => (int)$row['connected_devices_count'],
            'uptime' => (int)$row['uptime'],
            'last_inform' => $row['last_inform'] ?: 'N/A',
            'status' => self::statusFromLastInform($row['last_inform'] ?? null),
            'ping' => null,
            'tags' => $tags,
        ];
    }

    public static function stats(mysqli $db): array
    {
        $row = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN last_inform >= (NOW() - INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS online
            FROM " . self::TABLE
        )->fetch_assoc();

        $total = (int)($row['total'] ?? 0);
        $online = (int)($row['online'] ?? 0);

        return [
            'total' => $total,
            'online' => $online,
            'offline' => max(0, $total - $online),
        ];
    }

    public static function opticalStats(mysqli $db): array
    {
        $row = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN rx_power > -20 THEN 1 ELSE 0 END) AS excellent,
                SUM(CASE WHEN rx_power <= -20 AND rx_power >= -25 THEN 1 ELSE 0 END) AS good,
                SUM(CASE WHEN rx_power < -25 AND rx_power >= -28 THEN 1 ELSE 0 END) AS fair,
                SUM(CASE WHEN rx_power < -28 THEN 1 ELSE 0 END) AS poor,
                SUM(CASE WHEN rx_power IS NULL THEN 1 ELSE 0 END) AS no_signal
            FROM " . self::TABLE
        )->fetch_assoc();

        return [
            'excellent' => (int)($row['excellent'] ?? 0),
            'good' => (int)($row['good'] ?? 0),
            'fair' => (int)($row['fair'] ?? 0),
            'poor' => (int)($row['poor'] ?? 0),
            'no_signal' => (int)($row['no_signal'] ?? 0),
            'total' => (int)($row['total'] ?? 0),
        ];
    }

    public static function recent(mysqli $db, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $result = $db->query(
            "SELECT * FROM " . self::TABLE . "
             ORDER BY last_inform DESC, device_id ASC
             LIMIT " . $limit
        );

        $devices = [];
        while ($row = $result->fetch_assoc()) {
            $devices[] = self::rowToDevice($row);
        }
        return $devices;
    }

    private static function buildFilter(
        mysqli $db,
        string $term,
        array $serials,
        array &$params,
        string &$types
    ): string {
        $conditions = [];

        $term = trim($term);
        if ($term !== '') {
            $like = '%' . $term . '%';
            $fields = [
                'device_id',
                'serial_number',
                'mac_address',
                'manufacturer',
                'product_class',
                'ip_address',
                'wifi_ssid',
                'pppoe_username',
                'tags_json',
            ];
            $local = [];
            foreach ($fields as $field) {
                $local[] = $field . ' LIKE ?';
                $params[] = $like;
                $types .= 's';
            }
            $conditions[] = '(' . implode(' OR ', $local) . ')';
        }

        $cleanSerials = [];
        foreach ($serials as $serial) {
            $serial = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$serial) ?? '');
            if ($serial !== '') $cleanSerials[$serial] = true;
        }

        if ($cleanSerials) {
            $serialList = array_keys($cleanSerials);
            $placeholders = implode(',', array_fill(0, count($serialList), '?'));
            $conditions[] = 'UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(serial_number, ":", ""), "-", ""), ".", ""), " ", ""), "/", "")) IN (' . $placeholders . ')';
            foreach ($serialList as $serial) {
                $params[] = $serial;
                $types .= 's';
            }
        }

        if (!$conditions) return '';
        return ' WHERE (' . implode(' OR ', $conditions) . ')';
    }

    private static function bindDynamic(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($types === '') return;
        $refs = [];
        $refs[] = $types;
        foreach ($params as $k => $value) {
            $refs[] = &$params[$k];
        }
        $stmt->bind_param(...$refs);
    }

    public static function paged(
        mysqli $db,
        int $page,
        int $limit,
        string $term = '',
        array $serials = []
    ): array {

        $limit = in_array($limit, [20, 50, 100], true) ? $limit : 20;
        $page = max(1, $page);

        $params = [];
        $types = '';
        $where = self::buildFilter($db, $term, $serials, $params, $types);

        $countStmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM " . self::TABLE . $where
        );
        if (!$countStmt) throw new RuntimeException($db->error);
        self::bindDynamic($countStmt, $types, $params);
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['c'];
        $countStmt->close();

        $pages = max(1, (int)ceil($total / $limit));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare(
            "SELECT * FROM " . self::TABLE . $where .
            " ORDER BY last_inform DESC, device_id ASC LIMIT ? OFFSET ?"
        );
        if (!$stmt) throw new RuntimeException($db->error);

        $listParams = $params;
        $listTypes = $types . 'ii';
        $listParams[] = $limit;
        $listParams[] = $offset;
        self::bindDynamic($stmt, $listTypes, $listParams);
        $stmt->execute();

        $devices = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $devices[] = self::rowToDevice($row);
        }
        $stmt->close();

        return [
            'devices' => $devices,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
            'hasMore' => $page < $pages,
        ];
    }

    public static function legacy(
        mysqli $db,
        int $limit,
        int $skip
    ): array {

        $limit = max(1, min(500, $limit));
        $skip = max(0, $skip);

        $total = (int)$db->query(
            "SELECT COUNT(*) AS c FROM " . self::TABLE
        )->fetch_assoc()['c'];

        $stmt = $db->prepare(
            "SELECT * FROM " . self::TABLE . "
             ORDER BY last_inform DESC, device_id ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $limit, $skip);
        $stmt->execute();

        $devices = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $devices[] = self::rowToDevice($row);
        }
        $stmt->close();

        return [
            'devices' => $devices,
            'total' => $total,
            'hasMore' => ($skip + count($devices)) < $total,
        ];
    }
}
