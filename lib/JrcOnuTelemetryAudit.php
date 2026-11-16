<?php
declare(strict_types=1);

/**
 * Pure analysis of already-stored GenieACS records.
 *
 * This class never talks to a CPE, never queues CWMP tasks, and never
 * reads credentials. Device identifiers/passwords are deliberately
 * excluded from its aggregate output.
 */
final class JrcOnuTelemetryAudit
{
    private const RX_KEYS = '/(?:^|\.)(?:rxpower|rxpwr|rxopticalpower|opticalrxpower|opticalpowerrx|receivedopticalpower|rxsignallevel|rxsignal|signalrx|opticalrx|rxoptical|rxlevel)(?:$|\.)/i';
    private const TEMP_KEYS = '/(?:^|\.)(?:temperature|transceivertemperature|optictemperature|opticaltemperature|gettemp|tempc|temperaturec)(?:$|\.)/i';

    /** Numeric power is acceptable only if it is an actual dBm reading. */
    public static function validRx(mixed $v): bool
    {
        if ($v === null || is_bool($v) || !is_scalar($v)) return false;
        $text = trim(str_replace(',', '.', (string)$v));
        if ($text === '' || !is_numeric($text)) return false;
        $n = (float)$text;
        return is_finite($n) && $n >= -80.0 && $n < 0.0;
    }

    public static function validTemp(mixed $v): bool
    {
        if ($v === null || is_bool($v) || !is_scalar($v)) return false;
        $text = trim(str_replace(',', '.', (string)$v));
        if ($text === '' || !is_numeric($text)) return false;
        $n = (float)$text;
        return is_finite($n) && $n >= -30 && $n <= 130;
    }

    /** Return only observed parameter *paths* and value-state flags, never values. */
    public static function candidatePaths(array $device, string $kind): array
    {
        if (!in_array($kind, ['rx', 'temperature'], true)) {
            throw new InvalidArgumentException('Tipo de parametro invalido');
        }
        $pattern = $kind === 'rx' ? self::RX_KEYS : self::TEMP_KEYS;
        $out = [];
        $visited = 0;

        $scan = static function(array $node, string $path, int $depth)
            use (&$scan, &$out, &$visited, $pattern): void {
            if (++$visited > 100000 || $depth > 24 || count($out) >= 50) return;
            if (preg_match($pattern, $path)) {
                // Use only parameter path metadata, never CPE values in reports.
                $raw = $node['_value'] ?? null;
                $present = $raw !== null && $raw !== '';
                $out[$path] = [
                    'has_value' => $present,
                    'valid_numeric' => $present &&
                        is_scalar($raw) && !is_bool($raw) &&
                        is_numeric(str_replace(',', '.', trim((string)$raw))),
                ];
                return;
            }
            foreach ($node as $key => $child) {
                $k = (string)$key;
                if (!is_array($child) || str_starts_with($k, '_') ||
                    preg_match('/password|passphrase|pre.?shared|secret|token|auth|private.?key|sip/i', $k)) {
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_-]{1,85}$/', $k)) continue;
                $sub = $path === '' ? $k : $path . '.' . $k;
                $scan($child, $sub, $depth + 1);
                if ($visited > 100000 || count($out) >= 50) return;
            }
        };
        foreach (['InternetGatewayDevice', 'Device', 'VirtualParameters'] as $root) {
            if (isset($device[$root]) && is_array($device[$root])) {
                $scan($device[$root], $root, 0);
            }
        }
        return $out;
    }

    /** One device -> diagnosis without identifiers, network data or subscriber data. */
    public static function inspect(array $raw, array $parsed, int $now): array
    {
        $maker = self::clean((string)($parsed['manufacturer'] ?? $raw['_deviceId']['_Manufacturer'] ?? 'Nao informado'), 60);
        $model = self::clean((string)($parsed['product_class'] ?? $raw['_deviceId']['_ProductClass'] ?? 'Nao informado'), 75);
        $fw = self::clean((string)($parsed['software_version'] ?? 'Nao informado'), 60);

        $type = self::deviceType($model);
        $lastInform = isset($raw['_lastInform']) && is_string($raw['_lastInform'])
            ? strtotime($raw['_lastInform']) : false;
        $age = $lastInform === false || $lastInform === null ? null : $now - $lastInform;
        $recent = $age !== null && $age >= -300 && $age <= 900;

        $rx = self::validRx($parsed['rx_power'] ?? null);
        $temp = self::validTemp($parsed['temperature'] ?? null);
        $paths = self::candidatePaths($raw, 'rx');
        $tempPaths = self::candidatePaths($raw, 'temperature');
        // Actual optical evidence overrides a generic "Router" label.
        if ($rx || ($type === 'desconhecido' && $paths !== []) ||
            ($type === 'roteador' && self::anyPathWithValue($paths))) $type = 'onu';

        if ($type === 'roteador') {
            $status = 'ROTEADOR_SEM_RX_ESPERADO';
        } elseif ($type === 'desconhecido') {
            $status = 'TIPO_A_CONFIRMAR';
        } elseif ($rx) {
            $status = $recent ? 'RX_PRESENTE_INFORM_RECENTE'
                              : 'RX_CADASTRADO_INFORM_ANTIGO';
        } elseif ($age === null || $age > 7200 || $age < -300) {
            $status = 'INFORM_ANTIGO_OU_AUSENTE';
        } elseif (!$recent) {
            $status = 'INFORM_NAO_RECENTE';
        } elseif ($paths === []) {
            $status = 'CAMINHO_RX_NAO_DESCOBERTO';
        } elseif (self::anyPathWithValue($paths)) {
            $status = 'CAMINHO_OU_FORMATO_RX_NAO_MAPEADO';
        } else {
            $status = 'PARAMETRO_RX_SEM_VALOR';
        }

        return [
            'manufacturer' => $maker,
            'model' => $model,
            'firmware' => $fw,
            'type' => $type,
            'status' => $status,
            'recent_inform' => $recent,
            'old_inform' => $age === null || $age > 7200 || $age < -300,
            'rx_valid' => $rx,
            'temp_valid' => $temp,
            'rx_paths' => array_keys($paths),
            'temp_paths' => array_keys($tempPaths),
            'rx_paths_have_value' => self::anyPathWithValue($paths),
        ];
    }

    public static function summarize(array $observations): array
    {
        $totals = ['analisados' => 0, 'onus' => 0, 'roteadores' => 0,
                   'tipo_a_confirmar' => 0, 'onus_sem_rx' => 0,
                   'onus_sem_temperatura' => 0, 'onus_sem_rx_e_temperatura' => 0,
                   'onus_sem_rx_com_inform_recente' => 0];
        $groups = [];
        foreach ($observations as $r) {
            $totals['analisados']++;
            if ($r['type'] === 'roteador') $totals['roteadores']++;
            if ($r['type'] === 'desconhecido') $totals['tipo_a_confirmar']++;
            if ($r['type'] === 'onu') {
                $totals['onus']++;
                if (!$r['temp_valid']) $totals['onus_sem_temperatura']++;
                if (!$r['rx_valid']) {
                    $totals['onus_sem_rx']++;
                    if (!$r['temp_valid']) $totals['onus_sem_rx_e_temperatura']++;
                    if ($r['recent_inform']) $totals['onus_sem_rx_com_inform_recente']++;
                }
            }

            $key = $r['manufacturer'] . ' / ' . $r['model'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'fabricante' => $r['manufacturer'],
                    'modelo' => $r['model'],
                    'tipo' => $r['type'],
                    'total' => 0, 'rx_ok' => 0,
                    'sem_rx' => 0, 'sem_rx_com_inform_recente' => 0,
                    'temperatura_sem_dado' => 0,
                    'causas' => [], 'firmwares' => [], 'caminhos_rx' => []
                ];
            }
            $group =& $groups[$key];
            $group['total']++;
            if ($r['rx_valid']) $group['rx_ok']++;
            if (!$r['rx_valid'] && $r['type'] === 'onu') {
                $group['sem_rx']++;
                if ($r['recent_inform']) $group['sem_rx_com_inform_recente']++;
            }
            if (!$r['temp_valid'] && $r['type'] === 'onu') $group['temperatura_sem_dado']++;
            $group['causas'][$r['status']] = ($group['causas'][$r['status']] ?? 0) + 1;
            $group['firmwares'][$r['firmware']] = ($group['firmwares'][$r['firmware']] ?? 0) + 1;
            foreach ($r['rx_paths'] as $path) {
                $group['caminhos_rx'][$path] = ($group['caminhos_rx'][$path] ?? 0) + 1;
            }
            unset($group);
        }
        uasort($groups, static function(array $a, array $b): int {
            return ($b['sem_rx_com_inform_recente'] <=> $a['sem_rx_com_inform_recente'])
                ?: ($b['sem_rx'] <=> $a['sem_rx'])
                ?: strcmp($a['modelo'], $b['modelo']);
        });
        return ['totais' => $totals, 'modelos' => array_values($groups)];
    }

    private static function anyPathWithValue(array $paths): bool
    {
        foreach ($paths as $row) if (!empty($row['has_value'])) return true;
        return false;
    }

    private static function deviceType(string $model): string
    {
        $name = strtoupper($model);
        if (preg_match('/(?:ONU|ONT|GPON|EPON|XGPON|XGSPON|FTTH|FIBERHOME|HG[0-9]{3,}[A-Z0-9]*|EG[0-9]{3,}[A-Z0-9]*|AN5506[A-Z0-9-]*|ZXHN[ _-]*F6[0-9A-Z-]*)/i', $name)) {
            return 'onu';
        }
        if (preg_match('/(?:ROUTER|ROTEADOR|ROUTERBOARD)/i', $name)) {
            return 'roteador';
        }
        return 'desconhecido';
    }

    private static function clean(string $raw, int $limit): string
    {
        $raw = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $raw) ?? '');
        if ($raw === '' || $raw === 'N/A') return 'Nao informado';
        // Keep output anonymized to vendor/model descriptors; no MAC/serial.
        return substr($raw, 0, $limit);
    }
}
