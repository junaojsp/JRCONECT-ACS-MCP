<?php
declare(strict_types=1);

namespace JRC\Collector;

/** Pure metric classification; unknown data is never treated as normal. */
final class Diagnostics
{
    public static function number(mixed $value, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) return null;
        $number = str_replace(',', '.', trim((string)$value));
        if (!is_numeric($number)) return null;
        $float = (float)$number;
        return is_finite($float) && $float >= $minimum && $float <= $maximum ? $float : null;
    }

    public static function analyze(array $device, int $now, ?float $tempWarnC = null): array
    {
        $lastInform = (int)($device['last_inform_timestamp'] ?? 0);
        $online = $lastInform > 0 && $now - $lastInform <= 300 && $now >= $lastInform - 300;
        $status = $online ? 'online' : 'offline';

        $rx = self::number($device['rx_power'] ?? null, -80, -0.00001);
        $source = (string)($device['rx_source'] ?? 'tr069');
        $sampleAt = (int)($device['rx_measured_at_epoch'] ?? 0);
        // Data from offline or ancient ONUs is not a current signal measurement.
        $fresh = $online && $rx !== null && $sampleAt > 0 &&
                 $now - $sampleAt <= 900 && $sampleAt <= $now + 300;
        $signal = 'sem_dados';
        if ($fresh) {
            $signal = $rx < -28 ? 'critico' : ($rx <= -25 ? 'fraco' : 'normal');
        }

        // Distinguish an absent RX parameter from a stale or unverified sample.
        // This is diagnostic metadata; no unavailable measurement becomes "normal".
        $rxIssue = null;
        if ($signal === 'sem_dados') {
            if (!$online) $rxIssue = 'sem_comunicacao_recente';
            elseif ($device['rx_power'] === null || ($device['rx_power'] ?? '') === '') {
                $rxIssue = 'parametro_ou_valor_ausente';
            } elseif ($rx === null) $rxIssue = 'valor_invalido';
            elseif ($sampleAt <= 0) $rxIssue = 'sem_timestamp_da_medicao';
            elseif ($sampleAt > $now + 300) $rxIssue = 'timestamp_futuro';
            else $rxIssue = 'leitura_desatualizada';
        }

        $temp = self::number($device['temperature'] ?? null, -30, 130);
        $tempSource = (string)($device['temperature_source'] ?? 'tr069');
        $tempMeasured = (int)($device['temperature_measured_at_epoch'] ?? 0);
        $tempFresh = $online && $temp !== null && $tempMeasured > 0 &&
                     $now - $tempMeasured <= 900 && $tempMeasured <= $now + 300;
        $temperature = !$tempFresh ? 'sem_dados'
            : ($tempWarnC !== null && $temp >= $tempWarnC ? 'elevada' : 'normal');

        $warnings = [];
        if ($signal === 'critico' || $signal === 'fraco') {
            $warnings[] = [
                'type' => 'sinal', 'severity' => $signal,
                'value' => $rx, 'source' => $source, 'measured_at' => $sampleAt
            ];
        }
        if ($temperature === 'elevada') {
            $warnings[] = [
                'type' => 'temperatura', 'severity' => 'alerta',
                'value' => $temp, 'source' => $tempSource, 'measured_at' => $tempMeasured
            ];
        }
        return [
            'status' => $status,
            'rx_status' => $signal,
            'rx_issue' => $rxIssue,
            'temperature_status' => $temperature,
            'warnings' => $warnings
        ];
    }

    public static function summary(array $devices): array
    {
        $out = [
            'total' => count($devices), 'online' => 0, 'offline' => 0,
            'sinal_normal' => 0, 'sinal_fraco' => 0,
            'sinal_critico' => 0, 'sinal_sem_dados' => 0,
            'temperatura_elevada' => 0, 'temperatura_sem_dados' => 0
        ];
        foreach ($devices as $device) {
            $a = $device['diagnostics'] ?? null;
            if (!is_array($a)) throw new \InvalidArgumentException('Diagnostico ausente');
            $online = ($a['status'] ?? 'offline') === 'online';
            $out[$online ? 'online' : 'offline']++;
            $sig = $a['rx_status'] ?? 'sem_dados';
            if (!in_array($sig, ['normal', 'fraco', 'critico', 'sem_dados'], true)) {
                throw new \InvalidArgumentException('Classe RX invalida');
            }
            $out['sinal_' . $sig]++;
            if (($a['temperature_status'] ?? '') === 'elevada') $out['temperatura_elevada']++;
            if (($a['temperature_status'] ?? '') === 'sem_dados') $out['temperatura_sem_dados']++;
        }
        return $out;
    }
}
