<?php
declare(strict_types=1);

require_once __DIR__ . '/JrcTr069ProfileCompare.php';

/**
 * Read-only classification of optical telemetry in the GenieACS NBI.
 * No CPE tasks, no automatic path registration and no assumed dBm units.
 */
final class JrcTr069OpticalTelemetry
{
    public static function inspect(array $device, ?int $now = null, int $maxAgeMinutes = 15): array
    {
        if ($maxAgeMinutes < 1) {
            throw new InvalidArgumentException('Idade maxima invalida');
        }
        $profile = JrcTr069ProfileCompare::profile($device, $now ?? time());
        $rx = $profile['rx'];
        $numeric = array_values(array_filter($rx, static fn(array $p): bool =>
            $p['state'] === 'NUMERICO'));
        $recent = array_values(array_filter($numeric, static fn(array $p): bool =>
            $p['age_min'] !== null && $p['age_min'] <= $maxAgeMinutes));
        $missingValue = array_values(array_filter($rx, static fn(array $p): bool =>
            $p['state'] === 'SEM_VALOR'));
        $invalidValue = array_values(array_filter($rx, static fn(array $p): bool =>
            $p['state'] === 'NAO_NUMERICO'));
        $unmapped = array_values(array_filter($rx, static fn(array $p): bool =>
            !$p['reader_mapped']));
        if ($profile['optical_path_truncated']) {
            $status = 'DESCOBERTA_INCOMPLETA';
        } elseif ($rx === []) {
            $status = 'CAMINHO_AUSENTE';
        } elseif ($recent !== []) {
            $status = 'LEITURA_RECENTE';
        } elseif ($numeric !== []) {
            $status = 'LEITURA_DESATUALIZADA';
        } elseif ($missingValue !== []) {
            $status = 'PARAMETRO_SEM_VALOR';
        } elseif ($invalidValue !== []) {
            $status = 'VALOR_NAO_NUMERICO';
        } else {
            $status = 'SEM_LEITURA';
        }
        return [
            'status' => $status,
            'fingerprint' => [
                'oui' => $profile['oui'],
                'model' => $profile['model'],
                'firmware' => $profile['firmware'],
            ],
            'last_inform_age_min' => $profile['last_inform_age_min'],
            'rx_paths_found' => count($rx),
            'rx_paths_unmapped' => array_column($unmapped, 'path'),
            'rx_paths' => array_map(static fn(array $p): array => [
                'path' => $p['path'],
                'state' => $p['state'],
                'age_min' => $p['age_min'],
                'reader_mapped' => $p['reader_mapped'],
                'unit_verified' => $p['unit_verified'],
            ], $rx),
            'optical_path_truncated' => $profile['optical_path_truncated'],
            'can_display_dbm' => false,
            'requires_validation' => $unmapped !== [] || $recent !== [],
        ];
    }
}
