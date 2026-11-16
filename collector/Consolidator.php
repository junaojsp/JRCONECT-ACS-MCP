<?php
declare(strict_types=1);

namespace JRC\Collector;

use App\GenieACS_Fast;

/**
 * Convert the selected NBI paths plus optional IXC fiber data to the same
 * read-only device fields used by the current dashboard.
 */
final class Consolidator
{
    /**
     * Only a measurement parameter's own _timestamp proves measurement age.
     * LastInform means communication, not a fresh optical/temperature sample.
     * Return 0 when the actual measurement date cannot be verified.
     */
    private static function measurementTimestamp(array $raw, array $paths, mixed $parsed, int $now): int
    {
        if (!is_numeric((string)($parsed ?? '')) || (float)$parsed === 0.0) return 0;
        foreach ($paths as $parts) {
            $node = $raw;
            foreach ($parts as $segment) {
                if (!is_array($node) || !array_key_exists($segment, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$segment];
            }
            if (!is_array($node) || !isset($node['_value'], $node['_timestamp']) ||
                !is_numeric((string)$node['_value']) || !is_string($node['_timestamp'])) continue;
            if (abs((float)$node['_value'] - (float)$parsed) > 0.011) continue;
            $timestamp = strtotime($node['_timestamp']);
            if ($timestamp === false || $timestamp > $now + 300 || $timestamp < 1) continue;
            return $timestamp;
        }
        return 0;
    }

    private const RX_TIMESTAMP_PATHS = [
        ['VirtualParameters', 'RXPower'],
        ['InternetGatewayDevice','WANDevice','1','X_CT-COM_EponInterfaceConfig','RXPower'],
        ['Device','Optical','Interface','1','RxPower'],
        ['InternetGatewayDevice','WANDevice','1','X_GponInterfaceConfig','RXPower'],
        ['InternetGatewayDevice','WANDevice','1','X_FH_GponInterfaceConfig','RXPower'],
    ];
    private const TEMP_TIMESTAMP_PATHS = [
        ['VirtualParameters','gettemp'],
        ['VirtualParameters','Temperature'],
        ['InternetGatewayDevice','DeviceInfo','Temperature'],
        ['InternetGatewayDevice','WANDevice','1','X_GponInterfaceConfig','TransceiverTemperature'],
        ['InternetGatewayDevice','WANDevice','1','X_FH_GponInterfaceConfig','TransceiverTemperature'],
    ];

    public static function fleet(array $nbi, array $ixc, int $now, ?float $tempWarn = null): array
    {
        $seen = [];
        $devices = [];
        $matched = 0;

        foreach ($nbi as $raw) {
            $data = GenieACS_Fast::parseDeviceDataFast($raw);
            $id = (string)($data['device_id'] ?? '');
            if ($id === '' || $id === 'N/A' || isset($seen[$id])) {
                throw new \RuntimeException('ACS com ID de dispositivo ausente/duplicado');
            }
            $seen[$id] = true;

            // Never persist ONU secrets or pseudo ping values.
            unset($data['wifi_password'], $data['ping'], $data['telecom_password']);

            $last = strtotime((string)($raw['_lastInform'] ?? ''));
            $data['last_inform_timestamp'] = $last === false ? 0 : $last;
            $data['rx_source'] = 'tr069';
            $data['rx_measured_at_epoch'] = self::measurementTimestamp(
                $raw, self::RX_TIMESTAMP_PATHS, $data['rx_power'] ?? null, $now
            );
            $data['temperature_source'] = 'tr069';
            $data['temperature_measured_at_epoch'] = self::measurementTimestamp(
                $raw, self::TEMP_TIMESTAMP_PATHS, $data['temperature'] ?? null, $now
            );

            $serial = IxcReadOnly::serial((string)($data['serial_number'] ?? ''));
            if ($serial !== '' && isset($ixc[$serial])) {
                $matched++;
                $fiber = $ixc[$serial];
                // IXC fields are only preferred when the actual measurement has a timestamp.
                $measured = (int)($fiber['measured_at_epoch'] ?? 0);
                $recent = $measured > 0 && $measured <= $now + 300 &&
                    $now - $measured <= 900;
                $data['ixc_match'] = true;
                $data['ixc_customer_name'] = $fiber['customer_name'] ?: null;
                $data['ixc_id_login'] = $fiber['id_login'] ?: null;
                $data['ixc_last_measurement_epoch'] = $measured ?: null;
                $login = trim((string)($fiber['pppoe_username'] ?? ''));
                if (($data['pppoe_username'] ?? 'N/A') === 'N/A' && $login !== '') {
                    $data['pppoe_username'] = $login;
                }
                if ($recent && $fiber['rx'] !== null) {
                    $data['rx_power'] = $fiber['rx'];
                    $data['rx_source'] = 'ixc';
                    $data['rx_measured_at_epoch'] = $measured;
                }
                if ($recent && $fiber['temperature'] !== null) {
                    $data['temperature'] = $fiber['temperature'];
                    $data['temperature_source'] = 'ixc';
                    $data['temperature_measured_at_epoch'] = $measured;
                }
                if ($recent && $fiber['tx'] !== null) {
                    $data['tx_power'] = $fiber['tx'];
                    $data['tx_source'] = 'ixc';
                    $data['tx_measured_at_epoch'] = $measured;
                }
                if ($recent && $fiber['voltage'] !== null) {
                    $data['voltage'] = $fiber['voltage'];
                    $data['voltage_source'] = 'ixc';
                    $data['voltage_measured_at_epoch'] = $measured;
                }
            } else {
                $data['ixc_match'] = false;
            }
            $data['diagnostics'] = Diagnostics::analyze($data, $now, $tempWarn);
            // Ensure offline status is not shown as fresh from old inform data.
            $data['status'] = $data['diagnostics']['status'];
            $devices[] = $data;
        }

        return ['devices' => $devices, 'ixc_matched' => $matched,
            'summary' => Diagnostics::summary($devices)];
    }
}
