<?php
declare(strict_types=1);

/** Local snapshot reader only. No SNMP, IXC or CPE calls in HTTP requests. */
final class JrcSnmpTelemetry
{
    public const ROOT = '/var/lib/jrc-collector';
    public static function enabled(): bool
    {
        return is_file('/etc/jrc-collector/snmp-source.enabled');
    }

    public static function serial(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        if (str_starts_with($raw, 'HEX12:')) $raw = substr($raw, 6);
        if (preg_match('/^[0-9A-F]{16}$/D', $raw)) {
            $vendor = hex2bin(substr($raw, 0, 8));
            if ($vendor === false) return '';
            $raw = $vendor . substr($raw, 8);
        }
        if (in_array($raw, ['000000000000', 'FFFFFFFFFFFF'], true)) return '';
        if (preg_match('/^[A-Z]{4}[0-9A-F]{8}$/D', $raw)) return $raw;
        if (preg_match('/^[0-9A-F]{12}$/D', $raw) &&
            !in_array($raw, ['000000000000', 'FFFFFFFFFFFF'], true)) return 'HEX12:' . $raw;
        return '';
    }

    public static function load(string $root = self::ROOT): ?array
    {
        try {
            $path = $root . '/snmp-fleet.json';
            if (!is_readable($path) || !is_readable($root . '/snmp-fleet-status.json') || filesize($path) > 16 * 1024 * 1024) return null;
            $report = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $state = json_decode((string)file_get_contents($root . '/snmp-fleet-status.json'), true, 16, JSON_THROW_ON_ERROR);
            if (($report['schema'] ?? null) !== 2 || !is_array($report['devices'] ?? null) ||
                count($report['devices']) !== ($report['total'] ?? -1)) return null;
            $report['last_attempt'] = $state;
            return $report;
        } catch (Throwable $error) {
            return null;
        }
    }

    public static function apply(array $device, ?array $snapshot = null, ?int $now = null, bool $force = false): array
    {
        if (!$force && !self::enabled()) return $device;
        $now ??= time();
        static $cached = false;
        if ($snapshot === null) {
            if ($cached === false) $cached = self::load();
            $snapshot = is_array($cached) ? $cached : [];
        }
        // Once selected, no old TR-069 or IXC measurement may leak through.
        foreach (['rx_power', 'tx_power', 'temperature', 'voltage', 'bias_current',
                  'ixc_rx_power', 'ixc_tx_power', 'ixc_temperature', 'ixc_voltage'] as $key) {
            $device[$key] = null;
        }
        foreach (['rx', 'tx', 'temperature', 'voltage'] as $key) {
            $device[$key . '_source'] = 'olt_snmp';
            $device[$key . '_measured_at_epoch'] = null;
        }
        $device['management_status'] = $device['status'] ?? null;
        $device['status'] = 'unknown';
        $device['diagnostics'] = ['status'=>'unknown', 'rx_status'=>'sem_dados',
            'rx_issue'=>'sem_coleta_snmp', 'temperature_status'=>'sem_dados', 'warnings'=>[]];
        $device['telemetry_source'] = 'olt_snmp';
        $device['measurement_age_known'] = false;
        $device['telemetry_unit_validation'] = 'pending_unm';
        $device['telemetry_issue'] = 'onu_sem_correspondencia_snmp';
        $device['telemetry_polled_at_epoch'] = null;
        $device['snmp_state'] = 'desconhecido';
        $device['rx_status'] = 'sem_dados';
        $serial = self::serial((string)($device['serial_number'] ?? ''));
        $row = $serial === '' ? null : ($snapshot['devices'][$serial] ?? null);
        if (!is_array($row) || ($row['serial'] ?? '') !== $serial) return $device;
        $device['snmp_location'] = $row['location'] ?? null;
        $device['snmp_state'] = $row['state'] ?? 'desconhecido';
        $completed = (int)($snapshot['completed_at_epoch'] ?? 0);
        $attempt = $snapshot['last_attempt'] ?? [];
        $recent = $completed > 0 && $completed <= $now + 60 && $now - $completed <= 900 &&
            ($attempt['success'] ?? false) === true &&
            (int)($attempt['attempted_at_epoch'] ?? 0) <= $completed &&
            (int)($attempt['finished_at_epoch'] ?? 0) >= $completed;
        if (!$recent) {
            $device['telemetry_issue'] = 'coleta_antiga_ou_ultima_tentativa_falhou';
            $device['status'] = 'unknown';
            return $device;
        }
        $device['telemetry_polled_at_epoch'] = $completed;
        $state = (string)($row['state'] ?? 'desconhecido');
        $device['status'] = $state === 'online' ? 'online' :
            (in_array($state, ['offline', 'sem_energia', 'sem_sinal'], true) ? 'offline' : 'unknown');
        $device['telemetry_issue'] = $state === 'online' ? 'idade_da_medicao_nao_informada' : 'onu_nao_online';
        if ($state !== 'online') return $device;
        $mapping = ['rx'=>'rx_power', 'tx'=>'tx_power', 'temperature'=>'temperature',
                    'voltage'=>'voltage', 'bias_current'=>'bias_current'];
        foreach ($mapping as $key => $field) {
            $metric = $row['metrics'][$key] ?? [];
            $value = $metric['candidate_value'] ?? null;
            $polled = (int)($metric['polled_at_epoch'] ?? 0);
            if (!is_int($value) && !is_float($value)) continue;
            if (!is_finite((float)$value) || $polled <= 0 || $polled > $now + 60 || $now - $polled > 900) continue;
            $device[$field] = $value;
            $device[$key . '_polled_at_epoch'] = $polled;
        }
        $rx = $device['rx_power'];
        if (is_numeric($rx)) $device['rx_status'] = $rx < -28 ? 'critico' : ($rx <= -25 ? 'fraco' : 'normal');
        // An inherited diagnosis based on LastInform must not contradict SNMP.
        $device['diagnostics'] = ['status'=>$device['status'], 'rx_status'=>$device['rx_status'],
            'rx_issue'=>$device['telemetry_issue'], 'temperature_status'=>'nao_homologada', 'warnings'=>[]];
        return $device;
    }

    public static function optical(array $device): array
    {
        $location = (string)($device['snmp_location'] ?? '');
        preg_match('/^PON (\d+)\/(\d+)\/(\d+)$/D', $location, $parts);
        return ['success'=>true, 'source'=>'OLT SNMP — idade da medição desconhecida',
            'serial'=>$device['serial_number'] ?? '', 'slot'=>$parts[1] ?? null,
            'pon'=>$parts[2] ?? null, 'onu_number'=>$parts[3] ?? null,
            'pon_id'=>isset($parts[1], $parts[2]) ? $parts[1].'/'.$parts[2] : null,
            'state'=>$device['snmp_state'] ?? 'desconhecido',
            'issue'=>$device['telemetry_issue'] ?? 'sem_snapshot',
            'polled_at_epoch'=>$device['telemetry_polled_at_epoch'] ?? null,
            'measurement_age_known'=>false, 'unit_validation'=>'pending_unm',
            'optical'=>['rx_power'=>$device['rx_power'] ?? null, 'tx_power'=>$device['tx_power'] ?? null,
                'temperature'=>$device['temperature'] ?? null, 'voltage'=>$device['voltage'] ?? null,
                'rx_status'=>$device['rx_status'] ?? 'sem_dados', 'last_update'=>null]];
    }
}
