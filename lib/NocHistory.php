<?php
namespace App;

/** Records successful ACS observations. Gaps are not downtime and no old points are invented. */
class NocHistory
{
    public static function record(array $credentials, array $summary, int $now): array
    {
        $identity = substr(hash('sha256', json_encode($credentials) . __DIR__), 0, 32);
        $parent = getenv('NOC_CACHE_DIR') ?: sys_get_temp_dir();
        $dir = rtrim($parent, '/\\') . '/jrc-noc-history-' . $identity;
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
            throw new \RuntimeException('NOC history directory unavailable.');
        }
        $path = $dir . '/observations.json';
        if (is_link($path) || is_link($dir . '/write.lock')) throw new \RuntimeException('Invalid NOC history path.');
        $lock = fopen($dir . '/write.lock', 'c');
        if (!$lock) throw new \RuntimeException('NOC history lock unavailable.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('NOC history update in progress.');
            $raw = is_file($path) ? file_get_contents($path, false, null, 0, 512 * 1024) : '';
            $rows = json_decode($raw ?: '[]', true);
            $rows = is_array($rows) ? $rows : [];
            $points = [];
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['at'], $row['total'], $row['online'])
                    && is_int($row['at']) && is_int($row['total']) && is_int($row['online'])
                    && $row['at'] >= $now - 86400 && $row['at'] <= $now
                    && $row['total'] >= $row['online'] && $row['online'] >= 0) {
                    $points[] = $row;
                }
            }
            usort($points, static fn($a, $b) => $a['at'] <=> $b['at']);
            $last = $points ? $points[count($points) - 1]['at'] : 0;
            if ($now - $last >= 60) {
                $points[] = ['at' => $now, 'total' => $summary['total'], 'online' => $summary['online']];
            }
            $points = array_slice($points, -1441);
            $temp = tempnam($dir, 'observations-');
            try {
                if ($temp === false || file_put_contents($temp, json_encode($points, JSON_THROW_ON_ERROR)) === false
                    || !rename($temp, $path)) throw new \RuntimeException('NOC history write failed.');
            } finally {
                if ($temp !== false && is_file($temp)) unlink($temp);
            }
            return ['status' => 'ok', 'window_seconds' => 86400, 'points' => $points];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
