<?php
declare(strict_types=1);

namespace JRC\Collector;

/**
 * Candidate only. Does not touch jrc_device_inventory_* or production flags.
 * Table creation is a separate DBA step (collector/schema.sql).
 */
final class ShadowRepository
{
    public static function publish(
        \mysqli $db, array $devices, array $summary, int $ixcMatched, string $note
    ): array {
        if (!$devices) throw new \RuntimeException('Coleta vazia recusada');
        $lockResult = $db->query("SELECT GET_LOCK('jrc-collector-shadow-v1', 0) AS granted");
        if (!$lockResult || (int)$lockResult->fetch_assoc()['granted'] !== 1) {
            throw new \RuntimeException('Outra publicacao shadow em andamento');
        }
        try {
            $result = $db->query('SELECT snapshot_id, total FROM jrc_collector_shadow_state WHERE id = 1');
            $previous = $result ? $result->fetch_assoc() : null;
            if ($previous && count($devices) < (int)$previous['total'] * 0.75) {
                throw new \RuntimeException('Queda >25% na frota; snapshot anterior preservado');
            }
            $generation = bin2hex(random_bytes(16));
            $oldGeneration = $previous['snapshot_id'] ?? $generation;
            $metrics = json_encode($summary, JSON_THROW_ON_ERROR);
            $note = substr($note, 0, 255);
            $count = count($devices);

            $db->begin_transaction();
            try {
                $insert = $db->prepare('INSERT INTO jrc_collector_shadow_rows
                    (snapshot_id, device_id, payload_json)
                    VALUES (?, ?, ?)');
                $seen = [];
                foreach ($devices as $device) {
                    $id = (string)($device['device_id'] ?? '');
                    if ($id === '' || strlen($id) > 255 || isset($seen[$id])) {
                        throw new \RuntimeException('ID ausente/duplicado no snapshot');
                    }
                    $seen[$id] = true;
                    unset($device['wifi_password'], $device['telecom_password'], $device['ping']);
                    $payload = json_encode($device, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    $insert->bind_param('sss', $generation, $id, $payload);
                    $insert->execute();
                }
                $insert->close();
                $state = $db->prepare("INSERT INTO jrc_collector_shadow_state
                    (id, snapshot_id, total, ixc_matched, metrics_json, note, completed_at)
                    VALUES (1, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE
                    snapshot_id=VALUES(snapshot_id), total=VALUES(total),
                    ixc_matched=VALUES(ixc_matched), metrics_json=VALUES(metrics_json),
                    note=VALUES(note), completed_at=UTC_TIMESTAMP()");
                $state->bind_param('siiss', $generation, $count, $ixcMatched, $metrics, $note);
                $state->execute();
                $state->close();
                $db->commit();
            } catch (\Throwable $error) {
                $db->rollback();
                throw $error;
            }

            // Keep the previous generation for concurrent readers.
            try {
                $cleanup = $db->prepare('DELETE FROM jrc_collector_shadow_rows
                    WHERE snapshot_id NOT IN (?, ?)');
                $cleanup->bind_param('ss', $generation, $oldGeneration);
                $cleanup->execute();
                $cleanup->close();
            } catch (\Throwable $error) {
                error_log('JRC shadow cleanup failed');
            }
            return ['total' => $count, 'ixc_matched' => $ixcMatched,
                'snapshot' => $generation, 'metrics' => $summary];
        } finally {
            $db->query("SELECT RELEASE_LOCK('jrc-collector-shadow-v1')");
        }
    }
}
