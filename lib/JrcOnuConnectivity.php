<?php
declare(strict_types=1);

/**
 * Read-only, fail-closed optical connectivity classification.
 * A stale TR-069 Inform is NOT evidence of optical disconnection.
 * OLT status is trusted only with exact identity, complete snapshot and
 * successful collection, so an unknown ONU is never relabeled offline.
 */
final class JrcOnuConnectivity
{
    private static function rx(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) return null;
        $text = trim(str_replace(',', '.', (string)$value));
        if (!is_numeric($text)) return null;
        $rx = (float)$text;
        return is_finite($rx) && $rx < 0 && $rx >= -80 ? $rx : null;
    }

    public static function classify(array $device, ?array $snapshot, int $now): array
    {
        $firmware = substr((string)($device['software_version'] ?? 'N/A'), 0, 120);
        $model = substr((string)($device['product_class'] ?? 'N/A'), 0, 120);
        $serial = (string)($device['serial_number'] ?? '');
        $lastInform = (int)($device['last_inform_timestamp'] ?? 0);
        $informRecent = $lastInform > 0 && $lastInform <= $now + 300 &&
            $now - $lastInform <= 900;
        $rx = self::rx($device['rx_power'] ?? null);
        $rxEpoch = (int)($device['rx_measured_at_epoch'] ?? 0);
        $rxRecent = $rx !== null && $rxEpoch > 0 &&
            $rxEpoch <= $now + 300 && $now - $rxEpoch <= 900;
        $base = [
            'state' => 'nao_confirmado',
            'source' => null,
            'state_checked_at_epoch' => null,
            'rx_valid' => $rxRecent,
            'rx_issue' => $rx === null ? 'parametro_ou_valor_ausente' :
                ($rxEpoch <= 0 ? 'sem_timestamp_da_medicao' :
                    ($rxRecent ? null : 'leitura_rx_desatualizada')),
            'diagnosis' => null,
            'model' => $model,
            'firmware' => $firmware,
            'inform_recent' => $informRecent,
        ];
        if (!is_array($snapshot) || ($snapshot['schema'] ?? null) !== 2 ||
            !is_array($snapshot['devices'] ?? null) ||
            count($snapshot['devices']) !== (int)($snapshot['total'] ?? -1)) {
            return $base + ['reason' => 'snapshot_olt_indisponivel'];
        }
        $last = $snapshot['last_attempt'] ?? null;
        $finished = (int)($snapshot['completed_at_epoch'] ?? 0);
        if (!is_array($last) || ($last['success'] ?? false) !== true ||
            $finished <= 0 || $finished > $now + 60 ||
            $now - $finished > 900 ||
            (int)($last['attempted_at_epoch'] ?? 0) > $finished ||
            (int)($last['finished_at_epoch'] ?? 0) < $finished) {
            return $base + ['reason' => 'coleta_olt_antiga_ou_falhou'];
        }
        // Reuse exactly the conservative identity normalization already used
        // by JrcSnmpTelemetry; no fuzzy match by client, MAC or login.
        $key = JrcSnmpTelemetry::serial($serial);
        $row = $key === '' ? null : ($snapshot['devices'][$key] ?? null);
        if (!is_array($row) || ($row['serial'] ?? null) !== $key) {
            return $base + ['reason' => 'serial_nao_localizado_na_olt'];
        }
        $polled = (int)($row['state_polled_at_epoch'] ?? 0);
        if ($polled <= 0 || $polled > $now + 60 || $now - $polled > 900) {
            return $base + ['reason' => 'estado_olt_sem_timestamp_recente'];
        }
        $state = (string)($row['state'] ?? 'desconhecido');
        $base['source'] = 'olt_snmp';
        $base['state_checked_at_epoch'] = $polled;
        if (in_array($state, ['offline', 'sem_energia', 'sem_sinal'], true)) {
            $base['state'] = 'offline_confirmado';
            $base['reason'] = $state;
            $base['diagnosis'] = null;
        } elseif ($state === 'online') {
            $base['state'] = $rxRecent ? 'online_com_rx' : 'online_sem_rx';
            $base['reason'] = $rxRecent ? 'rx_recente' : $base['rx_issue'];
            $base['diagnosis'] = $rxRecent ? null :
                'verificar_parametro_rx_modelo_firmware_presets_e_unidade';
        } else {
            $base['reason'] = 'estado_olt_nao_reconhecido';
        }
        return $base;
    }
}
