<?php
declare(strict_types=1);

require_once __DIR__ . '/JrcEquipmentClassification.php';

/**
 * Projection for the read-only "Roteadores" report.
 *
 * Reuses exactly the classifier used by the dashboard's router count.
 * Does not perform IXC or GenieACS requests, device actions or any DB writes.
 */
final class JrcRouterReportRows
{
    /** @return array<string,string>|null */
    public static function project(array $device): ?array
    {
        if (JrcEquipmentClassification::type($device) !== 'roteador') {
            return null;
        }

        // Explicitly require no optical RX, consistently with the badge.
        if (JrcEquipmentClassification::signal($device) !== 'roteador') {
            return null;
        }

        $manufacturer = self::field($device['manufacturer'] ?? null);
        $product = self::field($device['product_class'] ?? null);
        $model = self::field($device['model'] ?? $device['model_name'] ?? null);
        if ($model === '') {
            $model = trim($manufacturer . ' ' . $product);
        }
        if ($model === '' || $model === 'N/A') $model = 'Roteador';

        return [
            'equipment' => $model,
            'pppoe' => self::field($device['pppoe_username'] ?? null),
            'customer' => self::field($device['ixc_customer_name'] ?? null),
            'serial' => self::field($device['serial_number'] ?? null),
            'ip' => self::field($device['ip_address'] ?? null),
            'last_inform' => self::field($device['last_inform'] ?? null),
        ];
    }

    /** Simple filter across safe, normalized report fields. */
    public static function matches(array $row, string $query): bool
    {
        if (trim($query) === '') return true;
        foreach (['equipment', 'pppoe', 'customer', 'serial', 'ip'] as $key) {
            if (stripos((string)($row[$key] ?? ''), $query) !== false) return true;
        }
        return false;
    }

    private static function field(mixed $value): string
    {
        if (!is_scalar($value) || is_bool($value)) return '';
        $text = trim((string)$value);
        if (in_array(strtoupper($text), ['N/A', 'NA', 'NULL', '-'], true)) return '';
        return substr($text, 0, 250);
    }
}
