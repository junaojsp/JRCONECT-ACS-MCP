<?php
/**
 * JR CONECT ACS - TR-143, revisao de controle 2.0.0
 * Substitui SOMENTE /var/www/gacs/api/speedtest-onu.php.
 * Requer PHP >= 8.1, curl e o config.php existente.
 * Estado privado: /var/lib/jrconect-acs/speedtest (usuario do PHP-FPM).
 *
 * Perfil de validacao: arquivo de 100 MiB, UMA conexao por etapa.
 * Nao muda o plano, Wi-Fi, PPPoE, VLAN ou configuracao da OLT.
 * Nao inventa velocidade parcial; Completed so sai com resultado validado.
 * A verificacao de estado usa leituras limitadas, NAO um webhook do evento 8.
 */

final class JrOnuSpeedtestV2
{
    public const VERSION = '2.0.0';
    public const DIRECTORY = '/var/lib/jrconect-acs/speedtest';
    public const DOWNLOAD_URL = 'http://teste.jrconect.com/teste-100mb.bin';
    public const UPLOAD_URL = 'http://teste.jrconect.com/upload.php';
    public const FILE_BYTES = 104857600;
    private const READ_INTERVAL = 8.0;
    private const INITIAL_WAIT = 6.0;
    private const JOB_LIMIT = 150.0;

    private string $base;
    private string $user;
    private string $pass;
    private string $directory;
    private string $owner;
    private $transport;

    public function __construct(array $acs, string $owner, string $directory = self::DIRECTORY, ?callable $transport = null)
    {
        $host = trim((string)($acs['host'] ?? '127.0.0.1'));
        $port = (int)($acs['port'] ?? 7557);
        $this->base = preg_match('~^https?://~i', $host)
            ? rtrim($host, '/') : 'http://' . $host . ':' . $port;
        $parts = parse_url($this->base);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Endereco do GenieACS invalido. Confira a configuracao do painel.');
        }
        $this->user = (string)($acs['username'] ?? '');
        $this->pass = (string)($acs['password'] ?? '');
        $this->owner = $owner;
        $this->directory = $directory;
        $this->transport = $transport;
    }

    private function http(string $method, string $url, ?array $body = null, bool $authenticate = true): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $body, $authenticate);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensao PHP cURL nao esta disponivel.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nao foi possivel iniciar a conexao HTTP.');
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Accept-Encoding: identity'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($authenticate && ($this->user !== '' || $this->pass !== '')) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $this->user . ':' . $this->pass;
        }
        try {
            if (!curl_setopt_array($ch, $options)) {
                throw new RuntimeException('Falha ao configurar a conexao HTTP.');
            }
            $raw = curl_exec($ch);
            if ($raw === false) {
                throw new RuntimeException('Falha HTTP/cURL ' . curl_errno($ch) . ': ' . curl_error($ch));
            }
            return [
                'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'raw' => (string)$raw,
                'json' => json_decode((string)$raw, true),
                'length' => (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD),
                'content_type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)
            ];
        } finally {
            curl_close($ch);
        }
    }

    private function collection(string $name, array $query, string $projection = ''): array
    {
        $url = $this->base . '/' . $name . '/?query=' . rawurlencode(json_encode($query, JSON_THROW_ON_ERROR));
        if ($projection !== '') {
            $url .= '&projection=' . rawurlencode($projection);
        }
        $r = $this->http('GET', $url);
        if ($r['code'] !== 200 || !is_array($r['json']) || !array_is_list($r['json'])) {
            throw new RuntimeException('Resposta invalida do GenieACS em ' . $name . ' (HTTP ' . $r['code'] . ').');
        }
        return $r['json'];
    }

    private function device(string $id): array
    {
        $projection = '_id,_lastInform,InternetGatewayDevice.DownloadDiagnostics,InternetGatewayDevice.UploadDiagnostics,Device.IP.Diagnostics.DownloadDiagnostics,Device.IP.Diagnostics.UploadDiagnostics';
        $rows = $this->collection('devices', ['_id' => $id], $projection);
        if (count($rows) !== 1 || ($rows[0]['_id'] ?? '') !== $id) {
            throw new RuntimeException('ONU nao encontrada no GenieACS.');
        }
        return $rows[0];
    }

    private static function path(array $node, string $path): array
    {
        foreach (explode('.', $path) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                return [];
            }
            $node = $node[$part];
        }
        return $node;
    }

    private static function value(array $node, string $name, mixed $default = null): mixed
    {
        return is_array($node[$name] ?? null) ? ($node[$name]['_value'] ?? $default) : $default;
    }

    private static function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) ? $number : null;
    }

    private static function date(mixed $value): ?float
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T/', $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
                return null;
            }
            return (float)$date->format('U.u');
        } catch (Throwable) {
            return null;
        }
    }

    private function readState(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Nao foi possivel ler o estado privado do teste.');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    private function writeState(string $file, array $data): void
    {
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Nao foi possivel salvar o estado privado do teste.');
        }
        chmod($temp, 0600);
        if (!rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('Falha ao atualizar o estado privado do teste.');
        }
    }

    private function enqueue(string $id, array $task, int $timeout = 1500): array
    {
        $url = $this->base . '/devices/' . rawurlencode($id) . '/tasks?timeout=' . $timeout . '&connection_request';
        $r = $this->http('POST', $url, $task);
        if (!in_array($r['code'], [200, 202], true) || !is_array($r['json']) || empty($r['json']['_id'])) {
            throw new RuntimeException('GenieACS nao confirmou a tarefa (HTTP ' . $r['code'] . '). Nao repita imediatamente: confira a fila da ONU.');
        }
        return ['id' => (string)$r['json']['_id'], 'applied' => $r['code'] === 200];
    }

    private function taskPending(string $id, string $taskId): bool
    {
        $rows = $this->collection('tasks', ['_id' => $taskId, 'device' => $id]);
        return count($rows) > 0;
    }

    private function statusResponse(array $job, string $message): array
    {
        return [
            'success' => true, 'version' => self::VERSION,
            'phase' => $job['phase'] ?? null, 'state' => 'Running',
            'completed' => false, 'measurement_valid' => false,
            'download_mbps' => null, 'upload_mbps' => null,
            'message' => $message, 'run_id' => $job['run_id'] ?? null,
            'poll_after_ms' => 3000
        ];
    }

    public function handle(string $action, string $id): array
    {
        if (!in_array($action, ['start_download', 'status_download', 'start_upload', 'status_upload'], true)) {
            throw new InvalidArgumentException('Acao invalida.');
        }
        if ($id === '' || strlen($id) > 512 || preg_match('/[\x00-\x1f]/', $id)) {
            throw new InvalidArgumentException('ID da ONU invalido.');
        }
        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new RuntimeException('Crie a pasta privada /var/lib/jrconect-acs/speedtest com permissao para o usuario do PHP-FPM.');
        }
        $phase = str_ends_with($action, 'download') ? 'download' : 'upload';
        $isStart = str_starts_with($action, 'start_');
        $prefix = $this->directory . '/' . hash('sha256', $id);
        $file = $prefix . '.json';
        $lock = fopen($prefix . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Nao foi possivel proteger o teste contra concorrencia.');
        }
        chmod($prefix . '.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            if ($isStart) {
                throw new RuntimeException('Ja existe uma operacao em andamento para esta ONU. Aguarde.');
            }
            return $this->statusResponse(['phase' => $phase], 'Consulta anterior em andamento. Aguardando sem criar outra tarefa.');
        }
        try {
            $state = $this->readState($file);
            if ($isStart) {
                return $this->start($id, $phase, $state, $file);
            }
            return $this->status($id, $phase, $state, $file);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }


    private function revealSpeedDiag(string $id, array $dev, string $suffix): string
    {
        if (isset($dev['InternetGatewayDevice']) && is_array($dev['InternetGatewayDevice'])) {
            $root = 'InternetGatewayDevice.' . $suffix;
        } elseif (isset($dev['Device']) && is_array($dev['Device'])) {
            $root = 'Device.IP.Diagnostics.' . $suffix;
        } else {
            throw new RuntimeException('O equipamento ainda nao tem o modelo de dados coletado. Use Atualizar coleta e tente de novo.');
        }
        foreach ($this->collection('tasks', ['device' => $id]) as $task) {
            if (($task['name'] ?? '') !== 'refreshObject') {
                continue;
            }
            $obj = (string)($task['objectName'] ?? '');
            if ($obj === $root || str_starts_with($root, rtrim($obj, '.') . '.')) {
                throw new RuntimeException('A leitura desta etapa ja foi pedida. Aguarde uns 20 segundos e execute de novo.');
            }
        }
        $this->enqueue($id, ['name' => 'refreshObject', 'objectName' => $root], 8000);
        return $root;
    }

    private function start(string $id, string $phase, array $state, string $file): array
    {
        $previous = $state['job'] ?? [];
        if (($previous['active'] ?? false) && microtime(true) - ($previous['started_at'] ?? 0) < self::JOB_LIMIT) {
            throw new RuntimeException('Ja existe um teste em andamento nesta ONU. Aguarde a conclusao.');
        }
        $dev = $this->device($id);
        $suffix = $phase === 'download' ? 'DownloadDiagnostics' : 'UploadDiagnostics';
        $root = '';
        $diag = [];
        foreach (['InternetGatewayDevice.' . $suffix, 'Device.IP.Diagnostics.' . $suffix] as $candidate) {
            $node = self::path($dev, $candidate);
            if (isset($node['DiagnosticsState'])) {
                $root = $candidate;
                $diag = $node;
                break;
            }
        }
        if ($root === '') {
            /* JRC_SPEED_REVEAL_V1 — a ONU FiberHome so publica o teste depois que o objeto e relido. */
            $root = $this->revealSpeedDiag($id, $dev, $suffix);
            $diag = self::path($this->device($id), $root);
            if (!isset($diag['DiagnosticsState'])) {
                throw new RuntimeException('Pedi a leitura desta etapa ao equipamento, mas ele ainda nao informou o teste de velocidade. Aguarde uns 20 segundos e execute de novo. Se repetir, este modelo nao faz esta etapa.');
            }
        }
        // Impede sobrepor comandos de testes anteriores; nao apaga tarefas de terceiros.
        foreach ($this->collection('tasks', ['device' => $id]) as $task) {
            foreach (($task['parameterValues'] ?? []) as $parameter) {
                $name = (string)($parameter[0] ?? '');
                if (str_contains($name, 'DownloadDiagnostics.') || str_contains($name, 'UploadDiagnostics.')) {
                    throw new RuntimeException('Ha comandos TR-143 pendentes na fila desta ONU. Revise-os antes de iniciar outro teste.');
                }
            }
        }
        if ($phase === 'download') {
            // HEAD nao transfere 100 MiB ao ACS, nem envia credenciais do ACS ao servidor de teste.
            $head = $this->http('HEAD', self::DOWNLOAD_URL, null, false);
            if ($head['code'] !== 200 || $head['length'] !== self::FILE_BYTES) {
                throw new RuntimeException('Arquivo de download invalido: esperado HTTP 200 e Content-Length 104857600. Recebido HTTP ' . $head['code'] . ', tamanho ' . $head['length'] . '.');
            }
            if (preg_match('~(?:text/|json|xml)~i', $head['content_type'])) {
                throw new RuntimeException('O endereco de download retornou texto/HTML em vez do arquivo binario.');
            }
        }
        $urlKey = $phase === 'download' ? 'DownloadURL' : 'UploadURL';
        $url = $phase === 'download' ? self::DOWNLOAD_URL : self::UPLOAD_URL;
        $parameters = [[$root . '.' . $urlKey, $url, 'xsd:string']];
        if ($phase === 'upload') {
            $parameters[] = [$root . '.TestFileLength', self::FILE_BYTES, 'xsd:unsignedInt'];
        }
        // Primeiro perfil de validacao: 1 conexao. Nao configura campos ausentes.
        foreach (['NumberOfConnections' => 1, 'TimeBasedTestDuration' => 0] as $name => $value) {
            if (isset($diag[$name])) {
                if (($diag[$name]['_writable'] ?? true) === false) {
                    if ((int)self::value($diag, $name, -1) !== $value) {
                        throw new RuntimeException('Nao foi possivel aplicar perfil seguro: ' . $name . ' nao e gravavel.');
                    }
                } else {
                    $parameters[] = [$root . '.' . $name, $value, 'xsd:unsignedInt'];
                }
            }
        }
        $parameters[] = [$root . '.DiagnosticsState', 'Requested', 'xsd:string'];
        $names = ['DiagnosticsState', $urlKey, 'BOMTime', 'EOMTime', 'ROMTime'];
        foreach (['TestBytesReceived', 'TestBytesSent', 'TestFileLength', 'TotalBytesReceived', 'TotalBytesSent', 'NumberOfConnections', 'TimeBasedTestDuration'] as $name) {
            if (isset($diag[$name])) {
                $names[] = $name;
            }
        }
        $job = [
            'active' => true, 'phase' => $phase, 'owner' => $this->owner,
            'run_id' => bin2hex(random_bytes(12)), 'started_at' => microtime(true),
            'root' => $root, 'expected_url' => $url, 'url_key' => $urlKey,
            'previous_eom' => (string)self::value($diag, 'EOMTime', ''),
            'read_names' => array_map(static fn($name) => $root . '.' . $name, array_unique($names)),
            'start_task' => null, 'start_applied' => false,
            'read_task' => null, 'last_read_at' => 0.0
        ];
        // Grava antes do POST: timeout HTTP nao significa que a tarefa nao foi criada.
        $state['job'] = $job;
        $this->writeState($file, $state);
        $task = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => $parameters], 3000);
        $job['start_task'] = $task['id'];
        $job['start_applied'] = $task['applied'];
        $state['job'] = $job;
        $this->writeState($file, $state);
        return [
            'success' => true, 'version' => self::VERSION, 'phase' => $phase,
            'state' => $task['applied'] ? 'Requested' : 'Queued', 'completed' => false,
            'task_id' => $task['id'], 'run_id' => $job['run_id'], 'connections' => 1,
            'message' => $task['applied'] ? 'Comando aplicado. Aguardando o diagnostico da ONU.' : 'Comando enfileirado. Ainda nao confirmado pela ONU.'
        ];
    }

    private function status(string $id, string $phase, array $state, string $file): array
    {
        $job = $state['job'] ?? [];
        if (($job['owner'] ?? '') !== $this->owner || ($job['phase'] ?? '') !== $phase) {
            throw new RuntimeException('Nao ha teste desta etapa iniciado por esta sessao. Inicie pelo botao do painel.');
        }
        if (isset($job['result'])) {
            return $job['result'];
        }
        if (microtime(true) - $job['started_at'] > self::JOB_LIMIT) {
            return $this->fail($state, $file, 'Tempo limite do diagnostico. Verifique a comunicacao e as tarefas desta ONU.');
        }
        if (empty($job['start_task'])) {
            return $this->fail($state, $file, 'O envio inicial nao foi confirmado. Confira a fila da ONU antes de repetir.');
        }
        if (!$job['start_applied']) {
            if ($this->taskPending($id, $job['start_task'])) {
                return $this->statusResponse($job, 'Aguardando a execucao da tarefa inicial no GenieACS.');
            }
            $job['start_applied'] = true;
        }
        $shouldRead = false;
        if (!empty($job['read_task'])) {
            if ($this->taskPending($id, $job['read_task'])) {
                return $this->statusResponse($job, 'Leitura ja enfileirada. Nenhuma leitura adicional foi criada.');
            }
            $job['read_task'] = null;
            $shouldRead = true;
        } elseif (microtime(true) - $job['started_at'] >= self::INITIAL_WAIT && microtime(true) - $job['last_read_at'] >= self::READ_INTERVAL) {
            // Uma leitura dirigida por vez. Nada de refreshObject completo a cada polling.
            $task = $this->enqueue($id, ['name' => 'getParameterValues', 'parameterNames' => $job['read_names']]);
            $job['last_read_at'] = microtime(true);
            $job['read_task'] = $task['applied'] ? null : $task['id'];
            $shouldRead = $task['applied'];
        }
        $state['job'] = $job;
        $this->writeState($file, $state);
        if (!$shouldRead) {
            return $this->statusResponse($job, 'Aguardando a proxima leitura dos parametros do diagnostico.');
        }
        $diag = self::path($this->device($id), $job['root']);
        $realState = (string)self::value($diag, 'DiagnosticsState', '');
        $eom = (string)self::value($diag, 'EOMTime', '');
        if (str_starts_with($realState, 'Error')) {
            return $this->fail($state, $file, 'A ONU retornou ' . $realState . '.', $realState);
        }
        if ($realState !== 'Completed' || $eom === '' || $eom === $job['previous_eom']) {
            return $this->statusResponse($job, 'Aguardando resultado novo. Valores anteriores nao sao usados.');
        }
        try {
            $measurement = self::validate($diag, $job);
        } catch (Throwable $e) {
            return $this->fail($state, $file, $e->getMessage());
        }
        $result = array_merge([
            'success' => true, 'version' => self::VERSION, 'phase' => $phase,
            'state' => 'Completed', 'completed' => true, 'measurement_valid' => true,
            'run_id' => $job['run_id'], 'previous_eom' => $job['previous_eom'],
            'multiple_streams' => false, 'connections' => 1,
            'validation_profile' => 'single_connection_fixed_file',
            'partial_result' => false
        ], $measurement);
        $state['job']['active'] = false;
        $state['job']['result'] = $result;
        $this->writeState($file, $state);
        return $result;
    }

    private function fail(array $state, string $file, string $message, string $error = 'Error_InvalidMeasurement'): array
    {
        $result = [
            'success' => false, 'version' => self::VERSION,
            'phase' => $state['job']['phase'] ?? null,
            'state' => $error, 'completed' => false, 'measurement_valid' => false,
            'download_mbps' => null, 'upload_mbps' => null, 'message' => $message,
            'run_id' => $state['job']['run_id'] ?? null
        ];
        $state['job']['active'] = false;
        $state['job']['result'] = $result;
        $this->writeState($file, $state);
        return $result;
    }

    public static function validate(array $diag, array $job): array
    {
        $actualUrl = (string)self::value($diag, $job['url_key'], '');
        if ($actualUrl !== $job['expected_url']) {
            throw new RuntimeException('Resultado recusado: a URL lida da ONU difere da URL enviada. Verifique presets, scripts e tarefas concorrentes no GenieACS.');
        }
        if ((int)self::value($diag, 'NumberOfConnections', 1) !== 1 || (int)self::value($diag, 'TimeBasedTestDuration', 0) !== 0) {
            throw new RuntimeException('Resultado recusado: o perfil de uma conexao e arquivo fixo nao permaneceu aplicado.');
        }
        $bom = self::value($diag, 'BOMTime');
        $eom = self::value($diag, 'EOMTime');
        $start = self::date($bom);
        $end = self::date($eom);
        if ($start === null || $end === null || $end <= $start) {
            throw new RuntimeException('Resultado recusado: BOMTime/EOMTime ausentes, invalidos ou invertidos.');
        }
        $duration = $end - $start;
        $isDownload = $job['phase'] === 'download';
        $counter = $isDownload ? 'TestBytesReceived' : 'TestBytesSent';
        $bytes = self::number(self::value($diag, $counter));
        if (!$isDownload) {
            $length = self::number(self::value($diag, 'TestFileLength'));
            if ($length !== (float)self::FILE_BYTES) {
                throw new RuntimeException('Resultado recusado: tamanho do arquivo de upload diferente do solicitado.');
            }
            // Compatibilidade TR-143 antigo: so usa TestFileLength se TestBytesSent NAO existe.
            // Um contador existente que informa zero NAO e substituido por bytes inventados.
            if (!array_key_exists('TestBytesSent', $diag)) {
                $bytes = $length;
                $counter = 'TestFileLength (single-connection completed diagnostic)';
            }
        }
        if ($bytes === null || $bytes < self::FILE_BYTES * 0.95 || $bytes > self::FILE_BYTES * 1.05) {
            throw new RuntimeException('Resultado inconclusivo: volume transferido incompativel com o arquivo de 100 MiB. Confira o arquivo, resposta HTTP e contadores da ONU.');
        }
        $speed = ($bytes * 8.0) / $duration / 1000000.0;
        if (!is_finite($speed)) {
            throw new RuntimeException('Resultado recusado: calculo de velocidade invalido.');
        }
        return [
            $isDownload ? 'download_mbps' : 'upload_mbps' => round($speed, 2),
            'duration_seconds' => round($duration, 6), 'bytes' => (int)round($bytes),
            'counter_used' => $counter, 'test_file_length' => self::FILE_BYTES,
            'bom_time' => $bom, 'eom_time' => $eom,
            'url_verified' => true,
            'timing_warning' => $duration < 1.0 ? 'Amostra curta; nao use isoladamente para avaliar o plano.' : null
        ];
    }
}

// Login obrigatorio. O token IXC NAO pertence a este arquivo.
require_once __DIR__ . '/../config/config.php';
requireLogin();
$jrOwner = hash('sha256', session_id());
// Libera a sessao ANTES de qualquer espera HTTP; o estado do teste fica separado.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new InvalidArgumentException('Use POST JSON pelo botao do painel.');
    }
    if (!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        throw new InvalidArgumentException('O corpo da requisicao precisa ser application/json.');
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        throw new InvalidArgumentException('Requisicao de outra origem recusada.');
    }
    $input = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['action'] ?? null) || !is_string($input['device_id'] ?? null)) {
        throw new InvalidArgumentException('Informe action e device_id como texto.');
    }
    $db = getDBConnection();
    $query = $db->query('SELECT * FROM genieacs_credentials LIMIT 1');
    if (!$query || !($acs = $query->fetch_assoc())) {
        throw new RuntimeException('GenieACS nao configurado no painel.');
    }
    $engine = new JrOnuSpeedtestV2($acs, $jrOwner);
    $reply = $engine->handle(trim($input['action']), trim($input['device_id']));
    http_response_code(($reply['success'] ?? false) ? 200 : 422);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException || $e instanceof JsonException ? 400 : 502);
    $reply = ['success' => false, 'version' => JrOnuSpeedtestV2::VERSION, 'completed' => false, 'message' => $e->getMessage()];
}
echo json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
