<?php
declare(strict_types=1);

/**
 * Read-only, unattended IXC product-74 monitor. No MCP secret crosses into
 * PHP and no IXC or VoiceCopy mutation occurs. Persist only IDs / raw status,
 * never the IXC customer names present in the transient MCP response.
 */
final class VoipIxcMonitor
{
    public const STATE_FILE = '/var/lib/jrconect-voip/ixc-monitor.json';

    public static function consume(string $snapshot, ?array $previous, string $now): array
    {
        if (!class_exists(VoipReconcile::class)) {
            throw new RuntimeException('Validador IXC não disponível.');
        }
        $contracts = VoipReconcile::parseSnapshot($snapshot);
        if (strlen($now) > 40 || strtotime($now) === false) {
            throw new RuntimeException('Horário inválido.');
        }
        $current = [];
        foreach ($contracts as $row) {
            $current[$row['contract_id']] = [
                'status' => $row['status'],
                'product_record_ids' => $row['product_ids'],
                'cancel_requested' => (bool)($row['cancel_requested'] ?? false),
            ];
        }
        ksort($current, SORT_NATURAL);
        $old = $previous['contracts'] ?? [];
        $events = $previous['events'] ?? [];
        if (!is_array($old) || !is_array($events)) {
            throw new RuntimeException('Estado anterior inválido.');
        }
        $newEvents = 0;
        // First successful read establishes a baseline, not 13 false 'new'.
        if ($previous !== null) {
            foreach ($current as $id => $item) {
                if (!array_key_exists($id, $old)) {
                    $events[] = ['type'=>'new_product_74','contract_id'=>(string)$id,
                        'at'=>$now, 'status'=>$item['status']];
                    $newEvents++;
                } else {
                    if (!isset($old[$id]['status']) || !is_string($old[$id]['status'])) {
                        throw new RuntimeException('Estado anterior de contrato inválido.');
                    }
                    if ($old[$id]['status'] !== $item['status']) {
                        $events[] = ['type'=>'status_changed','contract_id'=>(string)$id,
                            'at'=>$now, 'previous_status'=>$old[$id]['status'],
                            'status'=>$item['status']];
                        $newEvents++;
                    }
                }
            }
            foreach ($old as $id => $oldItem) {
                if (!isset($current[$id])) {
                    $events[] = ['type'=>'product_74_absent','contract_id'=>(string)$id,
                        'at'=>$now];
                    $newEvents++;
                }
            }
        }
        // Keep a bounded, non-PII history of recent events.
        $events = array_slice($events, -200);
        return [
            'version'=>1,
            'generated_at'=>$now,
            'baseline_at'=>$previous['baseline_at'] ?? $now,
            'contracts'=>$current,
            'events'=>$events,
            'new_events'=>$newEvents,
            'mode'=>'read_only',
        ];
    }

    public static function read(string $path = self::STATE_FILE): ?array
    {
        if (!is_file($path)) return null;
        if (is_link($path)) throw new RuntimeException('Monitor inválido.');
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) > 150000) throw new RuntimeException('Monitor indisponível.');
        try { $state = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new RuntimeException('Monitor JSON inválido.'); }
        if (!is_array($state) || ($state['version'] ?? null) !== 1 ||
            ($state['mode'] ?? null) !== 'read_only' ||
            !is_string($state['generated_at'] ?? null) ||
            !is_array($state['contracts'] ?? null) ||
            !is_array($state['events'] ?? null)) {
            throw new RuntimeException('Estado de monitor inválido.');
        }
        return $state;
    }

    public static function ingest(string $snapshot, string $stateFile = self::STATE_FILE): array
    {
        $dir = dirname($stateFile);
        if (!is_dir($dir) || is_link($dir) || is_link($stateFile)) {
            throw new RuntimeException('Diretório de monitor inválido.');
        }
        $lock = fopen($dir . '/ixc-monitor.lock', 'c');
        if (!$lock) throw new RuntimeException('Não foi possível bloquear o monitor.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Monitor ocupado.');
            $old = self::read($stateFile);
            $state = self::consume($snapshot, $old, gmdate('c'));
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $tmp = tempnam($dir, '.ixc-monitor-');
            if ($tmp === false) throw new RuntimeException('Não foi possível gravar monitor.');
            try {
                if (file_put_contents($tmp, $encoded, LOCK_EX) !== strlen($encoded) ||
                    !chmod($tmp, 0640)) throw new RuntimeException('Falha ao gravar estado.');
                // root writes, www-data can read via the dedicated file group.
                $group = posix_getgrnam('www-data');
                if (!is_array($group) || !chgrp($tmp, $group['gid'])) {
                    throw new RuntimeException('Grupo web inválido.');
                }
                if (!rename($tmp, $stateFile)) throw new RuntimeException('Falha ao salvar monitor.');
            } finally {
                if (is_file($tmp)) unlink($tmp);
            }
            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function overview(?array $state, array $stockRows, int $staleAfter = 2700): array
    {
        if ($state === null) return ['status'=>'not_configured','pending'=>[], 'changed'=>[], 'unlinked'=>[], 'stale'=>true];
        $time = strtotime($state['generated_at'] ?? '');
        if ($time === false) throw new RuntimeException('Data inválida no monitor.');
        $stale = $time > time() + 300 || time() - $time > $staleAfter;
        $linked = [];
        foreach ($stockRows as $row) {
            if (!empty($row['contract_id'])) $linked[(string)$row['contract_id']] = true;
        }
        $unlinked = [];
        foreach ($state['contracts'] as $id => $row) {
            if (!isset($linked[(string)$id])) $unlinked[] = (string)$id;
        }
        sort($unlinked, SORT_NATURAL);
        return [
            'status'=>$stale ? 'stale' : 'fresh',
            'last_checked'=>$state['generated_at'],
            'baseline_at'=>$state['baseline_at'],
            'total_contracts'=>count($state['contracts']),
            'pending'=>array_slice(array_reverse($state['events']), 0, 30),
            'unlinked'=>$unlinked,
            'stale'=>$stale,
        ];
    }
}
