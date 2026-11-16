<?php
declare(strict_types=1);

/**
 * Passive optical compatibility fallback for the inventory snapshot only.
 * Never requests CPE refresh, changes presets, or overrides a valid value.
 * Numeric readings are accepted only when they are already in plausible
 * physical units; unknown scales are deliberately not guessed.
 */
final class JrcAdaptiveOpticalRead
{
    private const RX_PATHS = [
        ['InternetGatewayDevice','WANDevice','1','X_GponInterfaceConfig','RXPower'],
        ['InternetGatewayDevice','WANDevice','1','X_FH_GponInterfaceConfig','RXPower'],
    ];
    private const TEMP_PATHS = [
        ['InternetGatewayDevice','WANDevice','1','X_GponInterfaceConfig','TransceiverTemperature'],
        ['InternetGatewayDevice','WANDevice','1','X_FH_GponInterfaceConfig','TransceiverTemperature'],
    ];

    private static function node(array $raw, array $parts): ?array
    {
        $current = $raw;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) return null;
            $current = $current[$part];
        }
        return is_array($current) ? $current : null;
    }

    private static function reading(array $raw, array $paths, int $now, float $min, float $max): ?array
    {
        foreach ($paths as $path) {
            $node = self::node($raw, $path);
            if ($node === null) continue;
            $value = $node['_value'] ?? null;
            $timestamp = $node['_timestamp'] ?? null;
            if (!is_scalar($value) || is_bool($value) || !is_numeric($value) ||
                !is_string($timestamp) || $timestamp === '') continue;
            $epoch = strtotime($timestamp);
            $number = (float)$value;
            if ($epoch === false || $epoch > $now + 300 || $now - $epoch > 900 ||
                !is_finite($number) || $number < $min || $number > $max) continue;
            return ['value'=>$number, 'epoch'=>$epoch, 'path'=>implode('.', $path)];
        }
        return null;
    }

    private static function existingGood(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 'N/A' &&
            is_numeric((string)$value) && is_finite((float)$value);
    }

    public static function enrich(array $raw, array $parsed, ?int $now = null): array
    {
        $now ??= time();
        $rx = self::reading($raw, self::RX_PATHS, $now, -45.0, -1.0);
        if (!self::existingGood($parsed['rx_power'] ?? null) && $rx !== null) {
            $parsed['rx_power'] = number_format($rx['value'], 2, '.', '');
            $parsed['rx_source'] = 'tr069';
            $parsed['rx_measured_at_epoch'] = $rx['epoch'];
            $parsed['rx_path'] = $rx['path'];
        }
        $temp = self::reading($raw, self::TEMP_PATHS, $now, -20.0, 120.0);
        if (!self::existingGood($parsed['temperature'] ?? null) && $temp !== null) {
            $parsed['temperature'] = number_format($temp['value'], 1, '.', '');
            $parsed['temperature_source'] = 'tr069';
            $parsed['temperature_measured_at_epoch'] = $temp['epoch'];
            $parsed['temperature_path'] = $temp['path'];
        }
        return $parsed;
    }
}
