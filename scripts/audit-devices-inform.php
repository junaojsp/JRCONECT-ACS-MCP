<?php
declare(strict_types=1);
/** Read-only NBI sample. Does not trigger connection requests or TR-069 tasks. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
try {
    require_once '/var/www/gacs/config/config.php';
    $db = getDBConnection();
    $result = $db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1');
    $cred = $result ? $result->fetch_assoc() : null;
    if (!$cred || !in_array(trim((string)$cred['host']), ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('A auditoria exige NBI local configurado.');
    }
    $port = (int)$cred['port'];
    if ($port < 1 || $port > 65535) throw new RuntimeException('Porta inválida.');
    $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400);
    $read = static function(array $query, int $limit) use ($cred, $port): array {
        $params = http_build_query([
            'query' => json_encode($query, JSON_THROW_ON_ERROR),
            'projection' => '_id,_lastInform,_registered,_lastBootstrap,_lastBoot',
            'sort' => json_encode(['_lastInform' => -1, '_id' => 1], JSON_THROW_ON_ERROR),
            'limit' => $limit,
        ]);
        $ch = curl_init('http://127.0.0.1:' . $port . '/devices/?' . $params);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15]);
        if (($cred['username'] ?? '') !== '') curl_setopt($ch, CURLOPT_USERPWD, $cred['username'] . ':' . ($cred['password'] ?? ''));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !is_string($body)) throw new RuntimeException('Consulta NBI falhou.');
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    };
    $sample = [];
    foreach (['online' => ['$gte' => $cutoff], 'offline' => ['$lt' => $cutoff]] as $state => $condition) {
        foreach ($read(['_lastInform' => $condition], $state === 'online' ? 5 : 2) as $device) {
            $inform = (string)($device['_lastInform'] ?? '');
            $when = $inform !== '' ? new DateTimeImmutable($inform) : null;
            $sample[] = ['id' => $device['_id'], 'status_24h' => $state,
                '_lastInform' => $inform,
                'esperado_na_tela' => $when ? $when->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y, H:i') : '—',
                '_registered' => $device['_registered'] ?? null,
                '_lastBootstrap' => $device['_lastBootstrap'] ?? null,
                '_lastBoot' => $device['_lastBoot'] ?? null];
        }
    }
    echo json_encode(['observado_em' => gmdate('c'), 'fonte' => 'GenieACS NBI direto; somente GET',
        'comparacao_visual' => 'PENDENTE: confrontar estes IDs com Última conexão e Visto no navegador',
        'amostra' => $sample], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (count($sample) !== 7) fwrite(STDERR, "AMOSTRA_INCOMPLETA: não há 5 online e 2 offline com _lastInform.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "AUDITORIA_FALHOU: consulta somente leitura não concluída; confira NBI local.\n");
    exit(1);
}
