<?php
declare(strict_types=1);

/**
 * Distinguish known routers from optical CPEs without guessing from RX absence.
 * This is display-only: neither IXC/TR-069 requests nor ONU tasks are changed.
 */
final class JrcEquipmentClassification
{
    public static function validOpticalRx(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || is_bool($raw)) return null;
        $string = trim(str_replace(',', '.', (string)$raw));
        if (!is_numeric($string)) return null;
        $value = (float)$string;
        return is_finite($value) && $value < 0.0 && $value >= -80.0
            ? $value : null;
    }

    /**
     * Returns 'roteador', 'onu' or 'desconhecido'.
     *
     * Optical markers or real optical measurements take precedence over
     * the word "router" because many ONTs provide built-in router functions.
     * No signal alone is never used to infer a router.
     */
    public static function type(array $device): string
    {
        $raw = implode(' ', array_map(static fn($value): string =>
            is_scalar($value) ? (string)$value : '', [
                $device['product_class'] ?? '',
                $device['model'] ?? '',
                $device['model_name'] ?? '',
                $device['device_type'] ?? '',
            ]
        ));
        $name = strtoupper($raw);

        if (self::validOpticalRx($device['ixc_rx_power'] ?? null) !== null ||
            self::validOpticalRx($device['rx_power'] ?? null) !== null ||
            !empty($device['ixc_is_optical']) ||
            preg_match('/(?:^|[^A-Z0-9])(ONU|ONT|GPON|EPON|XGPON|XGSPON|FTTH|FIBERHOME|OPTICAL)(?:[^A-Z0-9]|$)/', $name) ||
            preg_match('/(?:^|[^A-Z0-9])(?:HG8[0-9]{3}[A-Z0-9]*|HG6[0-9]{3}[A-Z0-9]*|EG8[0-9]{3}[A-Z0-9]*|DPN-[A-Z0-9-]+)(?:[^A-Z0-9]|$)/', $name)) {
            return 'onu';
        }

        // Strong explicit label, e.g. "D-LINK Router" from the installed ACS.
        if (preg_match('/(?:^|[^A-Z0-9])(ROUTER|ROTEADOR|ROUTERBOARD)(?:[^A-Z0-9]|$)/', $name)) {
            return 'roteador';
        }
        return 'desconhecido';
    }

    /** Optical reading status, never classify a normal router as missing RX. */
    public static function signal(array $device): string
    {
        if (self::type($device) === 'roteador') return 'roteador';
        $rx = self::validOpticalRx($device['ixc_rx_power'] ?? null);
        if ($rx === null) $rx = self::validOpticalRx($device['rx_power'] ?? null);
        if ($rx === null) return 'sem_leitura';
        if ($rx < -28) return 'critico';
        if ($rx <= -25) return 'fraco';
        return 'normal';
    }

    public static function summarize(array $devices): array
    {
        $out = [
            'total' => 0, 'roteadores' => 0, 'roteadores_sem_rx' => 0,
            'onus' => 0, 'desconhecidos' => 0,
        ];
        foreach ($devices as $device) {
            if (!is_array($device)) throw new InvalidArgumentException('Dispositivo invalido');
            $out['total']++;
            $type = self::type($device);
            if ($type === 'roteador') {
                $out['roteadores']++;
                $out['roteadores_sem_rx']++;
            } elseif ($type === 'onu') {
                $out['onus']++;
            } else {
                $out['desconhecidos']++;
            }
        }
        return $out;
    }
}
