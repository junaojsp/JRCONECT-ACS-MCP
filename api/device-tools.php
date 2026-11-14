<?php
/**
 * JR CONECT ACS - ferramentas da aba "Ações" do dispositivo.
 *
 * Endpoint novo: /var/www/gacs/api/device-tools.php (nao substitui nenhum arquivo).
 * Requer PHP >= 8.1, cURL e o config/config.php existente (requireLogin/getDBConnection).
 *
 * Todas as chamadas sao POST JSON {action, device_id, ...}. Nada aqui e simulado: cada
 * ferramenta cria uma tarefa real no GenieACS (NBI) e o resultado so aparece quando o
 * proprio CPE devolve o valor. Acoes destrutivas exigem perfil admin e confirmacao digitada.
 */
declare(strict_types=1);

final class JrToolsUserError extends RuntimeException {}
final class JrToolsForbidden extends RuntimeException {}

final class JrDeviceTools
{
    public const VERSION = '1.0.0';

    private const ALL = ['admin', 'noc', 'atendimento'];
    private const OPS = ['admin', 'noc'];
    private const ADMIN = ['admin'];

    private const ACTION_ROLES = [
        'history' => self::ALL,
        'history_add' => self::ALL,
        'ping_start' => self::ALL,
        'ping_status' => self::ALL,
        'trace_start' => self::ALL,
        'trace_status' => self::ALL,
        'dns_start' => self::ALL,
        'dns_status' => self::ALL,
        'wifi_list' => self::ALL,
        'wifi_set' => self::ALL,
        'wan_list' => self::ALL,
        'wan_reconnect' => self::OPS,
        'inform_get' => self::ALL,
        'inform_set' => self::OPS,
        'stun_get' => self::ALL,
        'stun_set' => self::OPS,
        'pppoe_set' => self::ADMIN,
        'capabilities' => self::ALL,
        'capabilities_probe' => self::OPS,
        'backup' => self::OPS,
        'restore_preview' => self::ADMIN,
        'restore_apply' => self::ADMIN,
        'files_list' => self::ADMIN,
        'firmware_start' => self::ADMIN,
        'factory_reset' => self::ADMIN,
    ];

    private const DIAG = [
        'ping' => [
            'roots' => ['InternetGatewayDevice.IPPingDiagnostics', 'Device.IP.Diagnostics.IPPing'],
            'results' => ['SuccessCount', 'FailureCount', 'AverageResponseTime', 'MinimumResponseTime', 'MaximumResponseTime'],
        ],
        'trace' => [
            'roots' => ['InternetGatewayDevice.TraceRouteDiagnostics', 'Device.IP.Diagnostics.TraceRoute'],
            'results' => ['ResponseTime', 'NumberOfRouteHops'],
        ],
        'dns' => [
            'roots' => ['InternetGatewayDevice.NSLookupDiagnostics', 'Device.DNS.Diagnostics.NSLookupDiagnostics'],
            'results' => ['SuccessCount'],
        ],
    ];

    private const REFRESH_ROOTS = [
        'InternetGatewayDevice.IPPingDiagnostics',
        'InternetGatewayDevice.TraceRouteDiagnostics',
        'InternetGatewayDevice.NSLookupDiagnostics',
        'InternetGatewayDevice.DownloadDiagnostics',
        'InternetGatewayDevice.UploadDiagnostics',
        'InternetGatewayDevice.ManagementServer',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration',
        'InternetGatewayDevice.WANDevice',
        'Device.IP.Diagnostics',
        'Device.DNS.Diagnostics',
        'Device.ManagementServer',
        'Device.WiFi',
        'Device.PPP',
        'Device.IP.Interface',
    ];

    private const BACKUP_EXCLUDE = [
        '.ManagementServer.', '.DeviceInfo.', '.Time.', '.IPPingDiagnostics.', '.TraceRouteDiagnostics.',
        '.NSLookupDiagnostics.', '.DownloadDiagnostics.', '.UploadDiagnostics.', '.IP.Diagnostics.',
        '.DNS.Diagnostics.', '.Diagnostics.', '.Hosts.', '.Stats.',
    ];

    private const TYPES = [
        'xsd:string', 'xsd:int', 'xsd:unsignedInt', 'xsd:boolean', 'xsd:long', 'xsd:unsignedLong',
        'xsd:dateTime', 'xsd:base64', 'xsd:hexBinary',
    ];

    private string $base;
    private string $user;
    private string $pass;
    /** @var callable|null */
    private $transport;

    public function __construct(array $acs, ?callable $transport = null)
    {
        $host = trim((string)($acs['host'] ?? '127.0.0.1'));
        $port = (int)($acs['port'] ?? 7557);
        $this->base = preg_match('~^https?://~i', $host) ? rtrim($host, '/') : 'http://' . $host . ':' . $port;
        $parts = parse_url($this->base);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Endereço do GenieACS inválido. Confira a configuração do painel.');
        }
        $this->user = (string)($acs['username'] ?? '');
        $this->pass = (string)($acs['password'] ?? '');
        $this->transport = $transport;
    }

    public static function rolesFor(string $action): ?array
    {
        return self::ACTION_ROLES[$action] ?? null;
    }

    /* ------------------------------------------------------------------ NBI */

    private function http(string $method, string $url, ?array $body = null): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $body);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensão PHP cURL não está disponível.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar a conexão HTTP.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Accept-Encoding: identity'],
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($this->user !== '' || $this->pass !== '') {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $this->user . ':' . $this->pass;
        }
        try {
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            if ($raw === false) {
                throw new RuntimeException('Falha ao falar com o GenieACS (' . curl_errno($ch) . '): ' . curl_error($ch));
            }
            return [
                'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'raw' => (string)$raw,
                'json' => json_decode((string)$raw, true),
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
            throw new RuntimeException('Resposta inválida do GenieACS em ' . $name . ' (HTTP ' . $r['code'] . ').');
        }
        return $r['json'];
    }

    private function device(string $id, string $projection = ''): array
    {
        $rows = $this->collection('devices', ['_id' => $id], $projection);
        if (count($rows) !== 1 || ($rows[0]['_id'] ?? '') !== $id) {
            throw new JrToolsUserError('Equipamento não encontrado no GenieACS.');
        }
        return $rows[0];
    }

    /** @return array{id:string,applied:bool} */
    private function enqueue(string $id, array $task, int $waitMs = 0, bool $connect = true): array
    {
        $url = $this->base . '/devices/' . rawurlencode($id) . '/tasks';
        $query = [];
        if ($connect) {
            if ($waitMs > 0) {
                $query[] = 'timeout=' . $waitMs;
            }
            $query[] = 'connection_request';
        }
        if ($query) {
            $url .= '?' . implode('&', $query);
        }
        $r = $this->http('POST', $url, $task);
        if (!in_array($r['code'], [200, 202], true) || !is_array($r['json']) || empty($r['json']['_id'])) {
            throw new RuntimeException('O GenieACS não confirmou a tarefa (HTTP ' . $r['code'] . '). Confira a fila do equipamento antes de repetir.');
        }
        return ['id' => (string)$r['json']['_id'], 'applied' => $r['code'] === 200];
    }

    private function pendingTasks(string $id): array
    {
        return $this->collection('tasks', ['device' => $id]);
    }

    private function taskIsPending(string $id, string $taskId): bool
    {
        return count($this->collection('tasks', ['_id' => $taskId, 'device' => $id])) > 0;
    }

    private function assertNoPendingTouching(string $id, string $needle, string $message): void
    {
        foreach ($this->pendingTasks($id) as $task) {
            foreach (($task['parameterValues'] ?? []) as $pv) {
                if (str_contains((string)($pv[0] ?? ''), $needle)) {
                    throw new JrToolsUserError($message);
                }
            }
        }
    }

    /* -------------------------------------------------------------- helpers */

    public static function node(array $tree, string $path): array
    {
        foreach (explode('.', $path) as $part) {
            if ($part === '') {
                continue;
            }
            if (!isset($tree[$part]) || !is_array($tree[$part])) {
                return [];
            }
            $tree = $tree[$part];
        }
        return $tree;
    }

    public static function val(array $node, string $name, mixed $default = null): mixed
    {
        return is_array($node[$name] ?? null) && array_key_exists('_value', $node[$name]) ? $node[$name]['_value'] : $default;
    }

    public static function has(array $node, string $name): bool
    {
        return is_array($node[$name] ?? null) && array_key_exists('_value', $node[$name]);
    }

    /** @return array<string,array> instancias numericas ordenadas */
    public static function instances(array $node): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if (is_array($v) && ctype_digit((string)$k)) {
                $out[(string)$k] = $v;
            }
        }
        uksort($out, static fn($a, $b) => (int)$a <=> (int)$b);
        return $out;
    }

    private static function model(array $dev): string
    {
        if (isset($dev['InternetGatewayDevice']) && is_array($dev['InternetGatewayDevice'])) {
            return 'InternetGatewayDevice';
        }
        if (isset($dev['Device']) && is_array($dev['Device'])) {
            return 'Device';
        }
        throw new JrToolsUserError('O equipamento ainda não tem o modelo de dados coletado. Use "Atualizar coleta" e tente de novo.');
    }

    private static function assertDeviceId(string $id): void
    {
        if ($id === '' || strlen($id) > 512 || preg_match('/[\x00-\x1f]/', $id)) {
            throw new JrToolsUserError('Equipamento inválido.');
        }
    }

    private static function pv(string $path, mixed $value, string $type): array
    {
        return [$path, $value, $type];
    }

    private static function ts(array $node, string $name): ?int
    {
        $t = is_array($node[$name] ?? null) ? ($node[$name]['_timestamp'] ?? null) : null;
        if (!is_string($t)) {
            return null;
        }
        $s = strtotime($t);
        return $s === false ? null : $s;
    }

    public static function cleanHost(mixed $host): string
    {
        $host = trim((string)$host);
        $ok = false;
        if ($host !== '' && strlen($host) <= 253) {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $ok = true;
            } elseif (preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/', $host)) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new JrToolsUserError('Informe um IP ou nome de host válido (somente letras, números, ponto e hífen).');
        }
        return $host;
    }

    private static function secretName(string $path): bool
    {
        return (bool)preg_match('/(password|passwd|passphrase|presharedkey|preshared|secret|wepkey)[^.]*$/i', $path)
            || (bool)preg_match('/\.(KeyPassphrase|PreSharedKey|Password|Key)$/', $path);
    }

    /* ----------------------------------------------------------- diagnostics */

    /** @return array{0:array,1:string,2:array} */
    private function revealDiag(string $id, array $dev, array $roots): array
    {
        /* JRC_DIAG_REVEAL_V1 */
        $model = self::model($dev);
        $targets = array_values(array_filter($roots, static fn(string $r): bool => str_starts_with($r, $model . '.')));
        if ($targets === []) {
            throw new JrToolsUserError('Este modelo de dados não tem esse diagnóstico.');
        }
        foreach ($this->pendingTasks($id) as $task) {
            if (($task['name'] ?? '') !== 'refreshObject') {
                continue;
            }
            $obj = (string)($task['objectName'] ?? '');
            foreach ($targets as $target) {
                if ($obj === $target || str_starts_with($target, rtrim($obj, '.') . '.')) {
                    throw new JrToolsUserError('A leitura desse diagnóstico já foi pedida. Aguarde o equipamento responder e execute de novo.');
                }
            }
        }
        $first = true;
        foreach ($targets as $target) {
            $this->enqueue($id, ['name' => 'refreshObject', 'objectName' => $target], $first ? 8000 : 0, $first);
            $first = false;
        }
        $dev = $this->device($id);
        foreach ($roots as $candidate) {
            $n = self::node($dev, $candidate);
            if (isset($n['DiagnosticsState'])) {
                return [$dev, $candidate, $n];
            }
        }
        throw new JrToolsUserError('Pedi a leitura ao equipamento, mas ele ainda não informou esse diagnóstico. Aguarde uns 20 segundos e execute de novo. Se repetir, o modelo não suporta este teste.');
    }

    private function diagStart(string $tool, string $id, array $in): array
    {
        $cfg = self::DIAG[$tool];
        $dev = $this->device($id);
        $root = '';
        $node = [];
        foreach ($cfg['roots'] as $candidate) {
            $n = self::node($dev, $candidate);
            if (isset($n['DiagnosticsState'])) {
                $root = $candidate;
                $node = $n;
                break;
            }
        }
        if ($root === '') {
            [$dev, $root, $node] = $this->revealDiag($id, $dev, $cfg['roots']);
        }
        $this->assertNoPendingTouching($id, $root . '.', 'Já há um comando desse diagnóstico na fila do equipamento. Aguarde terminar.');

        $set = static function (array &$pv, array $node, string $root, string $name, mixed $value, string $type): void {
            if (JrDeviceTools::has($node, $name) && (($node[$name]['_writable'] ?? true) !== false)) {
                $pv[] = [$root . '.' . $name, $value, $type];
            }
        };
        $pv = [];
        $target = '';
        if ($tool === 'ping') {
            $target = self::cleanHost($in['host'] ?? '');
            $count = max(1, min(10, (int)($in['count'] ?? 4)));
            $set($pv, $node, $root, 'Host', $target, 'xsd:string');
            $set($pv, $node, $root, 'NumberOfRepetitions', $count, 'xsd:unsignedInt');
            $set($pv, $node, $root, 'Timeout', 3000, 'xsd:unsignedInt');
            $set($pv, $node, $root, 'DataBlockSize', 64, 'xsd:unsignedInt');
        } elseif ($tool === 'trace') {
            $target = self::cleanHost($in['host'] ?? '');
            $set($pv, $node, $root, 'Host', $target, 'xsd:string');
            $set($pv, $node, $root, 'NumberOfTries', 2, 'xsd:unsignedInt');
            $set($pv, $node, $root, 'Timeout', 4000, 'xsd:unsignedInt');
            $set($pv, $node, $root, 'MaxHopCount', 20, 'xsd:unsignedInt');
            $set($pv, $node, $root, 'DataBlockSize', 38, 'xsd:unsignedInt');
        } else {
            $target = self::cleanHost($in['host'] ?? '');
            $set($pv, $node, $root, 'HostName', $target, 'xsd:string');
            $server = trim((string)($in['server'] ?? ''));
            if ($server !== '') {
                if (filter_var($server, FILTER_VALIDATE_IP) === false) {
                    throw new JrToolsUserError('O servidor DNS precisa ser um IP válido.');
                }
                $set($pv, $node, $root, 'DNSServer', $server, 'xsd:string');
            }
            $set($pv, $node, $root, 'NumberOfRepetitions', 1, 'xsd:unsignedInt');
            $set($pv, $node, $root, 'Timeout', 3000, 'xsd:unsignedInt');
        }
        if (!self::hasHostParam($pv, $root)) {
            throw new JrToolsUserError('O equipamento não permite configurar o destino deste diagnóstico (parâmetro somente leitura).');
        }
        $pv[] = [$root . '.DiagnosticsState', 'Requested', 'xsd:string'];
        $started = time();
        $task = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => $pv], 3000);
        return [
            'success' => true,
            'tool' => $tool,
            'target' => $target,
            'root' => $root,
            'task_id' => $task['id'],
            'started_at' => $started,
            'applied' => $task['applied'],
            'state' => $task['applied'] ? 'Requested' : 'Queued',
            'message' => $task['applied']
                ? 'Comando aplicado. Aguardando o equipamento executar o teste.'
                : 'Comando enfileirado. O equipamento ainda não confirmou (pode estar offline).',
            'poll_after_ms' => 4000,
        ];
    }

    private static function hasHostParam(array $pv, string $root): bool
    {
        foreach ($pv as $p) {
            if ($p[0] === $root . '.Host' || $p[0] === $root . '.HostName') {
                return true;
            }
        }
        return false;
    }

    private function diagStatus(string $tool, string $id, array $in): array
    {
        $cfg = self::DIAG[$tool];
        $root = (string)($in['root'] ?? '');
        if (!in_array($root, $cfg['roots'], true)) {
            throw new JrToolsUserError('Diagnóstico inválido.');
        }
        $started = (int)($in['started_at'] ?? 0);
        $taskId = (string)($in['task_id'] ?? '');
        if ($started <= 0 || $started > time() + 5) {
            throw new JrToolsUserError('Início do teste inválido.');
        }
        $elapsed = time() - $started;
        $running = static fn(string $m): array => [
            'success' => true, 'completed' => false, 'status' => 'running', 'message' => $m, 'poll_after_ms' => 4000,
        ];
        if ($taskId !== '' && $this->taskIsPending($id, $taskId)) {
            if ($elapsed > 150) {
                return ['success' => false, 'completed' => true, 'status' => 'failed', 'message' => 'Tempo esgotado: o equipamento não recebeu o comando (offline ou sem resposta ao connection request). A tarefa continua na fila do GenieACS.'];
            }
            return $running('Aguardando o equipamento receber o comando.');
        }
        $dev = $this->device($id, $root);
        $node = self::node($dev, $root);
        $state = (string)self::val($node, 'DiagnosticsState', '');
        $stamp = self::ts($node, 'DiagnosticsState');
        $fresh = $stamp === null ? $elapsed >= 8 : $stamp >= $started - 2;
        if ($fresh && str_starts_with($state, 'Error')) {
            return ['success' => false, 'completed' => true, 'status' => 'failed', 'state' => $state, 'message' => 'O equipamento retornou ' . $state . '.'];
        }
        if ($fresh && in_array($state, ['Complete', 'Completed'], true)) {
            return ['success' => true, 'completed' => true, 'status' => 'done'] + $this->diagResult($tool, $node);
        }
        if ($elapsed > 120) {
            $detail = $state !== '' ? ' Último estado: ' . $state . '.' : '';
            return ['success' => false, 'completed' => true, 'status' => 'failed', 'message' => 'Tempo esgotado: o equipamento não devolveu o resultado do diagnóstico.' . $detail];
        }
        $alreadyReading = false;
        foreach ($this->pendingTasks($id) as $t) {
            $taskName = (string)($t['name'] ?? '');
            if ($taskName === 'refreshObject' && str_starts_with((string)($t['objectName'] ?? ''), $root)) {
                $alreadyReading = true;
            }
            if ($taskName === 'getParameterValues') {
                foreach (($t['parameterNames'] ?? []) as $pn) {
                    if (str_starts_with((string)$pn, $root)) {
                        $alreadyReading = true;
                    }
                }
            }
        }
        if (!$alreadyReading) {
            /* JRC_DIAG_REFRESH_V1 — a ONU FiberHome só publica o ping quando o objeto inteiro é relido. */
            $this->enqueue($id, ['name' => 'refreshObject', 'objectName' => $root], 2500);
        }
        return $running($state === 'Requested' ? 'O equipamento está executando o teste.' : 'Aguardando o resultado do equipamento.');
    }

    private static function firstVal(array $node, array $names): string
    {
        foreach ($names as $name) {
            if (!self::has($node, $name)) {
                continue;
            }
            $v = self::val($node, $name);
            if ($v === null) {
                continue;
            }
            $s = trim((string)$v);
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    /**
     * JRC_TRACE_HOPS_V1 — TR-098 (HopHost/HopHostAddress), TR-181 (Host/HostAddress)
     * e prefixos de fabricante (X_FH_, X_HW_, X_CT-COM_).
     *
     * @return list<array{n:int,host:string,address:string,rtt:string,error:string}>
     */
    public static function traceHops(array $node): array
    {
        $hops = [];
        foreach (self::instances(self::node($node, 'RouteHops')) as $i => $h) {
            if (!is_array($h)) {
                continue;
            }
            $host = self::firstVal($h, ['HopHost', 'Host', 'HopHostName', 'HostName']);
            $address = self::firstVal($h, ['HopHostAddress', 'HostAddress', 'HopIPAddress', 'IPAddress', 'HopIP']);
            $rtt = self::firstVal($h, ['HopRTTimes', 'RTTimes', 'HopResponseTime']);
            $error = self::firstVal($h, ['HopErrorCode', 'ErrorCode']);
            if ($address === '' || $host === '' || $rtt === '') {
                foreach ($h as $k => $v) {
                    if (!is_array($v) || !array_key_exists('_value', $v)) {
                        continue;
                    }
                    $name = (string)$k;
                    $raw = trim((string)$v['_value']);
                    if ($raw === '') {
                        continue;
                    }
                    if ($address === '' && preg_match('/address|ipaddress|hostip/i', $name) && !preg_match('/error/i', $name)) {
                        $address = $raw;
                    } elseif ($host === '' && preg_match('/host/i', $name) && !preg_match('/address|error|ip/i', $name)) {
                        $host = $raw;
                    } elseif ($rtt === '' && preg_match('/rtt|responsetime/i', $name) && !preg_match('/error|stamp/i', $name)) {
                        $rtt = $raw;
                    }
                }
            }
            if ($address === '' && filter_var($host, FILTER_VALIDATE_IP)) {
                $address = $host;
                $host = '';
            }
            $hops[] = [
                'n' => (int)$i,
                'host' => $host,
                'address' => $address,
                'rtt' => $rtt,
                'error' => $error,
            ];
        }
        return $hops;
    }

    private function diagResult(string $tool, array $node): array
    {
        if ($tool === 'ping') {
            $ok = (int)self::val($node, 'SuccessCount', 0);
            $fail = (int)self::val($node, 'FailureCount', 0);
            $avg = self::val($node, 'AverageResponseTime');
            $min = self::val($node, 'MinimumResponseTime');
            $max = self::val($node, 'MaximumResponseTime');
            $summary = $ok . ($ok === 1 ? ' resposta, ' : ' respostas, ') . $fail . ($fail === 1 ? ' perda' : ' perdas');
            if ($ok > 0 && $avg !== null) {
                $summary .= ', média ' . (int)$avg . ' ms';
            }
            return [
                'summary' => $summary,
                'result_status' => $ok > 0 ? 'ok' : 'fail',
                'data' => ['success' => $ok, 'failure' => $fail, 'avg_ms' => $avg, 'min_ms' => $min, 'max_ms' => $max],
            ];
        }
        if ($tool === 'trace') {
            $hops = self::traceHops($node);
            $declared = self::val($node, 'NumberOfRouteHops');
            if ($declared === null) {
                $declared = self::val($node, 'RouteHopsNumberOfEntries');
            }
            $n = $declared !== null ? (int)$declared : count($hops);
            if ($n === 0) {
                $n = count($hops);
            }
            $rt = self::val($node, 'ResponseTime');
            return [
                'summary' => $n . ($n === 1 ? ' salto' : ' saltos') . ($rt !== null ? ' · ' . (int)$rt . ' ms no destino' : ''),
                'result_status' => $hops ? 'ok' : 'fail',
                'data' => ['hops' => $hops],
            ];
        }
        $rows = [];
        foreach (self::instances(self::node($node, 'Result')) as $r) {
            $rows[] = [
                'status' => (string)self::val($r, 'Status', ''),
                'answer' => (string)self::val($r, 'AnswerType', ''),
                'name' => (string)self::val($r, 'HostNameReturned', ''),
                'ips' => (string)self::val($r, 'IPAddresses', ''),
                'server' => (string)self::val($r, 'DNSServerIP', ''),
                'ms' => self::val($r, 'ResponseTime'),
            ];
        }
        $good = array_values(array_filter($rows, static fn($r) => stripos($r['status'], 'Success') === 0 && $r['ips'] !== ''));
        if ($good) {
            return [
                'summary' => 'Resolvido: ' . $good[0]['ips'] . ($good[0]['ms'] !== null ? ' em ' . (int)$good[0]['ms'] . ' ms' : '') . ($good[0]['server'] !== '' ? ' via ' . $good[0]['server'] : ''),
                'result_status' => 'ok',
                'data' => ['results' => $rows],
            ];
        }
        $status = $rows[0]['status'] ?? 'sem resposta';
        return ['summary' => 'Falha na resolução (' . $status . ')', 'result_status' => 'fail', 'data' => ['results' => $rows]];
    }

    /* ----------------------------------------------------------------- Wi-Fi */

    private function wifiNetworks(array $dev): array
    {
        $model = self::model($dev);
        $nets = [];
        if ($model === 'InternetGatewayDevice') {
            $lan = self::instances(self::node($dev, 'InternetGatewayDevice.LANDevice'));
            foreach ($lan as $li => $l) {
                foreach (self::instances(self::node($l, 'WLANConfiguration')) as $wi => $w) {
                    if (!self::has($w, 'SSID')) {
                        continue;
                    }
                    $band = (string)(self::val($w, 'OperatingFrequencyBand') ?? self::val($w, 'X_HW_FrequencyBand') ?? '');
                    $channel = self::val($w, 'Channel');
                    if ($band === '' && $channel !== null && (int)$channel > 14) {
                        $band = '5GHz';
                    }
                    $psk = self::node($w, 'PreSharedKey.1');
                    $nets[] = [
                        'key' => $li . '.' . $wi,
                        'label' => 'WLAN ' . $wi,
                        'ssid' => (string)self::val($w, 'SSID', ''),
                        'enabled' => filter_var(self::val($w, 'Enable', true), FILTER_VALIDATE_BOOLEAN),
                        'band' => self::detectLegacyWifiBand($w, (string)$wi, $channel, (string)self::val($w, 'SSID', ''), (string)$band),
                        'channel' => $channel === null ? null : (int)$channel,
                        'auto_channel' => self::has($w, 'AutoChannelEnable') ? filter_var(self::val($w, 'AutoChannelEnable'), FILTER_VALIDATE_BOOLEAN) : null,
                        'can_password' => self::has($psk, 'KeyPassphrase') || self::has($psk, 'PreSharedKey'),
                        'can_channel' => self::has($w, 'Channel'),
                    ];
                }
            }
            return $nets;
        }
        $wifi = self::node($dev, 'Device.WiFi');
        $radios = self::instances(self::node($wifi, 'Radio'));
        $aps = self::instances(self::node($wifi, 'AccessPoint'));
        foreach (self::instances(self::node($wifi, 'SSID')) as $si => $s) {
            if (!self::has($s, 'SSID')) {
                continue;
            }
            $radio = '';
            if (preg_match('/Device\.WiFi\.Radio\.(\d+)/', (string)self::val($s, 'LowerLayers', ''), $m)) {
                $radio = $m[1];
            }
            $r = $radio !== '' ? ($radios[$radio] ?? []) : [];
            $apIndex = '';
            foreach ($aps as $ai => $ap) {
                if (rtrim((string)self::val($ap, 'SSIDReference', ''), '.') === 'Device.WiFi.SSID.' . $si) {
                    $apIndex = (string)$ai;
                    break;
                }
            }
            $sec = $apIndex !== '' ? self::node($aps[$apIndex], 'Security') : [];
            $nets[] = [
                'key' => $si . '.' . $radio . '.' . $apIndex,
                'label' => 'SSID ' . $si,
                'ssid' => (string)self::val($s, 'SSID', ''),
                'enabled' => filter_var(self::val($r, 'Enable', self::val($s, 'Enable', true)), FILTER_VALIDATE_BOOLEAN),
                'band' => self::bandLabel((string)self::val($r, 'OperatingFrequencyBand', '')),
                'channel' => self::has($r, 'Channel') ? (int)self::val($r, 'Channel') : null,
                'auto_channel' => self::has($r, 'AutoChannelEnable') ? filter_var(self::val($r, 'AutoChannelEnable'), FILTER_VALIDATE_BOOLEAN) : null,
                'can_password' => self::has($sec, 'KeyPassphrase'),
                'can_channel' => self::has($r, 'Channel'),
            ];
        }
        return $nets;
    }

    private static function detectLegacyWifiBand(array $w, string $index, ?int $channel, string $ssid, string $existing = ''): string
    {
        // 1) Respeita banda explicita quando o equipamento realmente a fornece.
        $explicit = [
            $existing,
            self::has($w, 'OperatingFrequencyBand') ? (string)self::val($w, 'OperatingFrequencyBand', '') : '',
            self::has($w, 'FrequencyBand') ? (string)self::val($w, 'FrequencyBand', '') : '',
            self::has($w, 'Band') ? (string)self::val($w, 'Band', '') : '',
        ];
        foreach ($explicit as $candidate) {
            $label = self::bandLabel(trim((string)$candidate));
            if ($label === '2.4 GHz' || $label === '5 GHz') {
                return $label;
            }
        }

        // 2) Canal e o indicador mais confiavel quando disponivel.
        if ($channel !== null && $channel > 0) {
            if ($channel >= 36 && $channel <= 196) {
                return '5 GHz';
            }
            if ($channel >= 1 && $channel <= 14) {
                return '2.4 GHz';
            }
        }

        // 3) PossibleChannels costuma existir em TR-098 mesmo quando Channel=0/auto.
        $possible = self::has($w, 'PossibleChannels')
            ? (string)self::val($w, 'PossibleChannels', '')
            : '';
        if ($possible !== '') {
            preg_match_all('/\d+/', $possible, $matches);
            $numbers = array_values(array_filter(array_map('intval', $matches[0] ?? [])));
            if ($numbers) {
                foreach ($numbers as $number) {
                    if ($number >= 36 && $number <= 196) {
                        return '5 GHz';
                    }
                }
                $all24 = true;
                foreach ($numbers as $number) {
                    if ($number < 1 || $number > 14) {
                        $all24 = false;
                        break;
                    }
                }
                if ($all24) {
                    return '2.4 GHz';
                }
            }
        }

        // 4) Standards: 802.11a/ac sao exclusivos/fortes indicios de 5 GHz.
        $standard = '';
        foreach (['Standard', 'OperatingStandards', 'SupportedStandards'] as $field) {
            if (self::has($w, $field)) {
                $standard .= ' ' . strtolower((string)self::val($w, $field, ''));
            }
        }
        if ($standard !== '') {
            if (
                preg_match('/(^|[^a-z0-9])802\.11a([^a-z0-9]|$)/i', $standard) ||
                preg_match('/(^|[^a-z0-9])a([^a-z0-9]|$)/i', $standard) ||
                str_contains($standard, '802.11ac') ||
                preg_match('/(^|[^a-z0-9])ac([^a-z0-9]|$)/i', $standard)
            ) {
                return '5 GHz';
            }
            if (
                preg_match('/(^|[^a-z0-9])802\.11b([^a-z0-9]|$)/i', $standard) ||
                preg_match('/(^|[^a-z0-9])802\.11g([^a-z0-9]|$)/i', $standard)
            ) {
                return '2.4 GHz';
            }
        }

        // 5) Ultimo fallback sem depender do fabricante: sufixos como -5G/_5G/5GHz.
        if (preg_match('/(^|[-_.\s])(5g|5ghz|5\.8g)([-_.\s]|$)/i', $ssid)) {
            return '5 GHz';
        }
        if (preg_match('/(^|[-_.\s])(2g|2\.4g|2\.4ghz)([-_.\s]|$)/i', $ssid)) {
            return '2.4 GHz';
        }

        // 6) Fallback legado comum em ONUs TR-098 dual-band (ex.: FiberHome):
        // WLANConfiguration.1 = 2.4 GHz e WLANConfiguration.5 = 5 GHz.
        $idx = (int)$index;
        if ($idx === 5) {
            return '5 GHz';
        }
        if ($idx === 1) {
            return '2.4 GHz';
        }

        return self::bandLabel($existing);
    }

    private static function bandLabel(string $band): string
    {
        if (str_contains($band, '2.4')) {
            return '2.4 GHz';
        }
        if (str_contains($band, '5')) {
            return '5 GHz';
        }
        return $band !== '' ? $band : '';
    }

    private function wifiList(string $id): array
    {
        $dev = $this->device($id, 'InternetGatewayDevice.LANDevice,Device.WiFi');
        $nets = $this->wifiNetworks($dev);
        if (!$nets) {
            throw new JrToolsUserError('Nenhuma rede Wi-Fi foi coletada neste equipamento. Use "Atualizar coleta" ou "Sondar capacidades".');
        }
        return ['success' => true, 'networks' => $nets];
    }

    private function wifiSet(string $id, array $in): array
    {
        $key = (string)($in['key'] ?? '');
        $dev = $this->device($id, 'InternetGatewayDevice.LANDevice,Device.WiFi');
        $net = null;
        foreach ($this->wifiNetworks($dev) as $n) {
            if ($n['key'] === $key) {
                $net = $n;
                break;
            }
        }
        if ($net === null) {
            throw new JrToolsUserError('Rede Wi-Fi não encontrada neste equipamento.');
        }
        $pv = [];
        $what = [];
        $model = self::model($dev);
        $parts = explode('.', $key);

        if (array_key_exists('ssid', $in) && $in['ssid'] !== null) {
            $ssid = (string)$in['ssid'];
            if ($ssid === '' || strlen($ssid) > 32 || preg_match('/[\x00-\x1f\x7f]/', $ssid)) {
                throw new JrToolsUserError('O nome da rede (SSID) precisa ter de 1 a 32 caracteres.');
            }
            $pv[] = $model === 'InternetGatewayDevice'
                ? ["InternetGatewayDevice.LANDevice.{$parts[0]}.WLANConfiguration.{$parts[1]}.SSID", $ssid, 'xsd:string']
                : ["Device.WiFi.SSID.{$parts[0]}.SSID", $ssid, 'xsd:string'];
            $what[] = 'SSID';
        }
        if (array_key_exists('password', $in) && $in['password'] !== null && $in['password'] !== '') {
            $pass = (string)$in['password'];
            if (strlen($pass) < 8 || strlen($pass) > 63 || preg_match('/[^\x20-\x7e]/', $pass)) {
                throw new JrToolsUserError('A senha Wi-Fi precisa ter de 8 a 63 caracteres ASCII.');
            }
            if (!$net['can_password']) {
                throw new JrToolsUserError('Este equipamento não expõe o campo de senha desta rede para escrita.');
            }
            if ($model === 'InternetGatewayDevice') {
                $psk = self::node($dev, "InternetGatewayDevice.LANDevice.{$parts[0]}.WLANConfiguration.{$parts[1]}.PreSharedKey.1");
                $field = self::has($psk, 'KeyPassphrase') ? 'KeyPassphrase' : 'PreSharedKey';
                $pv[] = ["InternetGatewayDevice.LANDevice.{$parts[0]}.WLANConfiguration.{$parts[1]}.PreSharedKey.1.$field", $pass, 'xsd:string'];
            } else {
                $pv[] = ["Device.WiFi.AccessPoint.{$parts[2]}.Security.KeyPassphrase", $pass, 'xsd:string'];
            }
            $what[] = 'senha';
        }
        if (array_key_exists('channel', $in) && $in['channel'] !== null && $in['channel'] !== '') {
            if (!$net['can_channel']) {
                throw new JrToolsUserError('Este equipamento não expõe o canal desta rede para escrita.');
            }
            $auto = $in['channel'] === 'auto' || (string)$in['channel'] === '0';
            $base = $model === 'InternetGatewayDevice'
                ? "InternetGatewayDevice.LANDevice.{$parts[0]}.WLANConfiguration.{$parts[1]}"
                : "Device.WiFi.Radio.{$parts[1]}";
            if ($model === 'Device' && $parts[1] === '') {
                throw new JrToolsUserError('Rádio desta rede não identificado.');
            }
            $node = self::node($dev, $base);
            if ($auto) {
                if (self::has($node, 'AutoChannelEnable')) {
                    $pv[] = [$base . '.AutoChannelEnable', true, 'xsd:boolean'];
                } else {
                    $pv[] = [$base . '.Channel', 0, 'xsd:unsignedInt'];
                }
                $what[] = 'canal automático';
            } else {
                $channel = (int)$in['channel'];
                $band = $net['band'];
                $valid = $band === '2.4 GHz' ? ($channel >= 1 && $channel <= 13)
                    : ($band === '5 GHz' ? ($channel >= 36 && $channel <= 165) : ($channel >= 1 && $channel <= 165));
                if (!$valid) {
                    throw new JrToolsUserError($band === '5 GHz' ? 'Canal 5 GHz inválido (use 36 a 165).' : 'Canal 2.4 GHz inválido (use 1 a 13).');
                }
                if (self::has($node, 'AutoChannelEnable')) {
                    $pv[] = [$base . '.AutoChannelEnable', false, 'xsd:boolean'];
                }
                $pv[] = [$base . '.Channel', $channel, 'xsd:unsignedInt'];
                $what[] = 'canal ' . $channel;
            }
        }
        if (array_key_exists('enabled', $in) && $in['enabled'] !== null) {
            $enabled = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN);
            if ($model === 'InternetGatewayDevice') {
                $pv[] = ["InternetGatewayDevice.LANDevice.{$parts[0]}.WLANConfiguration.{$parts[1]}.Enable", $enabled, 'xsd:boolean'];
            } else {
                if ($parts[1] === '') {
                    throw new JrToolsUserError('Rádio desta rede não identificado.');
                }
                $pv[] = ["Device.WiFi.Radio.{$parts[1]}.Enable", $enabled, 'xsd:boolean'];
            }
            $what[] = $enabled ? 'rádio ligado' : 'rádio desligado';
        }
        if (!$pv) {
            throw new JrToolsUserError('Nada para alterar.');
        }
        $task = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => $pv], 4000);
        return $this->taskReply($task, 'Wi-Fi (' . $net['label'] . '): ' . implode(', ', $what), $net['ssid']);
    }

    private function taskReply(array $task, string $summary, string $target = ''): array
    {
        return [
            'success' => true,
            'applied' => $task['applied'],
            'task_id' => $task['id'],
            'status' => $task['applied'] ? 'done' : 'queued',
            'target' => $target,
            'summary' => $task['applied'] ? $summary . ' — aplicado' : $summary . ' — enfileirado, aguardando o equipamento',
            'message' => $task['applied'] ? 'Comando aplicado pelo equipamento.' : 'Comando enfileirado no GenieACS. Será aplicado quando o equipamento comunicar.',
        ];
    }

    /* ------------------------------------------------------------------- WAN */

    private function wanConnections(array $dev): array
    {
        $model = self::model($dev);
        $mgmtUrl = (string)self::val(self::node($dev, $model . '.ManagementServer'), 'ConnectionRequestURL', '');
        $mgmtHost = (string)parse_url($mgmtUrl, PHP_URL_HOST);
        $out = [];
        if ($model === 'InternetGatewayDevice') {
            foreach (self::instances(self::node($dev, 'InternetGatewayDevice.WANDevice')) as $di => $d) {
                foreach (self::instances(self::node($d, 'WANConnectionDevice')) as $ci => $c) {
                    foreach (['WANPPPConnection' => 'PPPoE', 'WANIPConnection' => 'IP'] as $kind => $label) {
                        foreach (self::instances(self::node($c, $kind)) as $pi => $p) {
                            $ip = (string)self::val($p, 'ExternalIPAddress', '');
                            $out[] = [
                                'path' => "InternetGatewayDevice.WANDevice.$di.WANConnectionDevice.$ci.$kind.$pi",
                                'type' => $label,
                                'name' => (string)self::val($p, 'Name', "$kind.$pi"),
                                'username' => $label === 'PPPoE' ? (string)self::val($p, 'Username', '') : '',
                                'status' => (string)self::val($p, 'ConnectionStatus', ''),
                                'ip' => $ip,
                                'enabled' => filter_var(self::val($p, 'Enable', true), FILTER_VALIDATE_BOOLEAN),
                                'management' => $mgmtHost !== '' && $ip !== '' && $ip === $mgmtHost,
                                'can_credentials' => $label === 'PPPoE' && self::has($p, 'Username'),
                            ];
                        }
                    }
                }
            }
            return $out;
        }
        $ipByPpp = [];
        foreach (self::instances(self::node($dev, 'Device.IP.Interface')) as $i => $row) {
            $ipByPpp[rtrim((string)self::val($row, 'LowerLayers', ''), '.')] = (string)self::val(self::node($row, 'IPv4Address.1'), 'IPAddress', '');
        }
        foreach (self::instances(self::node($dev, 'Device.PPP.Interface')) as $i => $p) {
            $ip = '';
            foreach ($ipByPpp as $lower => $addr) {
                if ($lower === 'Device.PPP.Interface.' . $i) {
                    $ip = $addr;
                }
            }
            $out[] = [
                'path' => "Device.PPP.Interface.$i",
                'type' => 'PPPoE',
                'name' => (string)self::val($p, 'Name', "PPP.$i"),
                'username' => (string)self::val($p, 'Username', ''),
                'status' => (string)self::val($p, 'Status', ''),
                'ip' => $ip,
                'enabled' => filter_var(self::val($p, 'Enable', true), FILTER_VALIDATE_BOOLEAN),
                'management' => $mgmtHost !== '' && $ip !== '' && $ip === $mgmtHost,
                'can_credentials' => self::has($p, 'Username'),
            ];
        }
        return $out;
    }

    private function wanList(string $id): array
    {
        $dev = $this->device($id, 'InternetGatewayDevice.WANDevice,InternetGatewayDevice.ManagementServer,Device.PPP,Device.IP.Interface,Device.ManagementServer');
        $conns = $this->wanConnections($dev);
        if (!$conns) {
            throw new JrToolsUserError('Nenhuma conexão WAN foi coletada neste equipamento.');
        }
        return ['success' => true, 'connections' => $conns];
    }

    private function pickWan(string $id, string $path): array
    {
        $dev = $this->device($id, 'InternetGatewayDevice.WANDevice,InternetGatewayDevice.ManagementServer,Device.PPP,Device.IP.Interface,Device.ManagementServer');
        foreach ($this->wanConnections($dev) as $c) {
            if ($c['path'] === $path) {
                return $c;
            }
        }
        throw new JrToolsUserError('Conexão WAN não encontrada neste equipamento.');
    }

    private function wanReconnect(string $id, array $in): array
    {
        $c = $this->pickWan($id, (string)($in['path'] ?? ''));
        if ($c['management']) {
            throw new JrToolsUserError('Esta é a WAN de gerência (o ACS alcança o equipamento por ela). Derrubar essa conexão faria o equipamento sumir do ACS.');
        }
        $off = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => [[$c['path'] . '.Enable', false, 'xsd:boolean']]], 0, false);
        $on = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => [[$c['path'] . '.Enable', true, 'xsd:boolean']]], 3000, true);
        return [
            'success' => true,
            'applied' => $on['applied'],
            'task_ids' => [$off['id'], $on['id']],
            'status' => $on['applied'] ? 'done' : 'queued',
            'target' => $c['name'],
            'summary' => 'WAN ' . $c['name'] . ' desligada e religada' . ($on['applied'] ? ' — aplicado' : ' — enfileirado, aguardando o equipamento'),
            'message' => 'A conexão cai por alguns segundos e é restabelecida pelo equipamento.',
        ];
    }

    private function pppoeSet(string $id, array $in): array
    {
        if (($in['confirm'] ?? '') !== 'CONFIRMAR') {
            throw new JrToolsUserError('Digite CONFIRMAR para alterar a credencial PPPoE.');
        }
        $c = $this->pickWan($id, (string)($in['path'] ?? ''));
        if ($c['type'] !== 'PPPoE' || !$c['can_credentials']) {
            throw new JrToolsUserError('Esta conexão não é PPPoE ou não expõe usuário e senha.');
        }
        if ($c['management']) {
            throw new JrToolsUserError('Esta é a WAN de gerência; a credencial não pode ser alterada por aqui.');
        }
        $user = (string)($in['username'] ?? '');
        $pass = (string)($in['password'] ?? '');
        if ($user === '' || strlen($user) > 64 || preg_match('/[\x00-\x1f\x7f\s]/', $user)) {
            throw new JrToolsUserError('Usuário PPPoE inválido (1 a 64 caracteres, sem espaços).');
        }
        if ($pass === '' || strlen($pass) > 64 || preg_match('/[\x00-\x1f\x7f]/', $pass)) {
            throw new JrToolsUserError('Senha PPPoE inválida (1 a 64 caracteres).');
        }
        $task = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => [
            [$c['path'] . '.Username', $user, 'xsd:string'],
            [$c['path'] . '.Password', $pass, 'xsd:string'],
        ]], 4000);
        return $this->taskReply($task, 'Credencial PPPoE de ' . $c['name'] . ' alterada para o usuário ' . $user, $user);
    }

    /* ----------------------------------------------- inform / STUN / mgmt */

    private function mgmt(string $id): array
    {
        $dev = $this->device($id, 'InternetGatewayDevice.ManagementServer,Device.ManagementServer');
        $model = self::model($dev);
        return [$model . '.ManagementServer', self::node($dev, $model . '.ManagementServer')];
    }

    private function informGet(string $id): array
    {
        [, $m] = $this->mgmt($id);
        return [
            'success' => true,
            'enabled' => self::has($m, 'PeriodicInformEnable') ? filter_var(self::val($m, 'PeriodicInformEnable'), FILTER_VALIDATE_BOOLEAN) : null,
            'interval' => self::has($m, 'PeriodicInformInterval') ? (int)self::val($m, 'PeriodicInformInterval') : null,
            'writable' => self::has($m, 'PeriodicInformInterval') && (($m['PeriodicInformInterval']['_writable'] ?? true) !== false),
        ];
    }

    private function informSet(string $id, array $in): array
    {
        [$root, $m] = $this->mgmt($id);
        if (!self::has($m, 'PeriodicInformInterval')) {
            throw new JrToolsUserError('O equipamento não informou o intervalo de Inform. Use "Sondar capacidades".');
        }
        $seconds = (int)($in['interval'] ?? 0);
        if ($seconds < 30 || $seconds > 86400) {
            throw new JrToolsUserError('Use um intervalo entre 30 e 86400 segundos.');
        }
        $pv = [];
        if (self::has($m, 'PeriodicInformEnable')) {
            $pv[] = [$root . '.PeriodicInformEnable', true, 'xsd:boolean'];
        }
        $pv[] = [$root . '.PeriodicInformInterval', $seconds, 'xsd:unsignedInt'];
        $task = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => $pv], 4000);
        return $this->taskReply($task, 'Intervalo de Inform definido para ' . $seconds . ' s', (string)$seconds . ' s');
    }

    private function stunGet(string $id): array
    {
        [, $m] = $this->mgmt($id);
        return [
            'success' => true,
            'supported' => self::has($m, 'STUNEnable'),
            'enabled' => self::has($m, 'STUNEnable') ? filter_var(self::val($m, 'STUNEnable'), FILTER_VALIDATE_BOOLEAN) : null,
            'server' => (string)self::val($m, 'STUNServerAddress', ''),
        ];
    }

    private function stunSet(string $id, array $in): array
    {
        [$root, $m] = $this->mgmt($id);
        if (!self::has($m, 'STUNEnable')) {
            throw new JrToolsUserError('Este equipamento não suporta STUN (parâmetro STUNEnable ausente).');
        }
        $enable = filter_var($in['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enable === null) {
            throw new JrToolsUserError('Informe se o STUN deve ser habilitado ou desabilitado.');
        }
        $pv = [];
        $server = trim((string)($in['server'] ?? ''));
        if ($enable && $server !== '') {
            self::cleanHost($server);
            if (self::has($m, 'STUNServerAddress')) {
                $pv[] = [$root . '.STUNServerAddress', $server, 'xsd:string'];
            }
        }
        $pv[] = [$root . '.STUNEnable', $enable, 'xsd:boolean'];
        $task = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => $pv], 4000);
        return $this->taskReply($task, 'STUN ' . ($enable ? 'habilitado' : 'desabilitado'));
    }

    /* ---------------------------------------------------------- capabilities */

    private function capabilities(string $id): array
    {
        $dev = $this->device($id);
        $model = self::model($dev);
        $diag = static function (array $roots) use ($dev): bool {
            foreach ($roots as $r) {
                if (isset(JrDeviceTools::node($dev, $r)['DiagnosticsState'])) {
                    return true;
                }
            }
            return false;
        };
        $dl = $diag(['InternetGatewayDevice.DownloadDiagnostics', 'Device.IP.Diagnostics.DownloadDiagnostics']);
        $ul = $diag(['InternetGatewayDevice.UploadDiagnostics', 'Device.IP.Diagnostics.UploadDiagnostics']);
        $m = self::node($dev, $model . '.ManagementServer');
        $di = self::node($dev, $model . '.DeviceInfo');
        $wifi = $this->wifiNetworks($dev);
        $bands = array_values(array_unique(array_filter(array_map(static fn($n) => $n['band'], $wifi))));
        $wan = $this->wanConnections($dev);
        $features = [
            ['key' => 'ping', 'label' => 'Ping (IPPing)', 'ok' => $diag(self::DIAG['ping']['roots'])],
            ['key' => 'trace', 'label' => 'TraceRoute', 'ok' => $diag(self::DIAG['trace']['roots'])],
            ['key' => 'dns', 'label' => 'DNS lookup (NSLookup)', 'ok' => $diag(self::DIAG['dns']['roots'])],
            ['key' => 'download', 'label' => 'Velocidade ↓ (TR-143 download)', 'ok' => $dl],
            ['key' => 'upload', 'label' => 'Velocidade ↑ (TR-143 upload)', 'ok' => $ul],
            ['key' => 'wifi', 'label' => 'Wi-Fi (' . count($wifi) . ' redes' . ($bands ? ': ' . implode(', ', $bands) : '') . ')', 'ok' => count($wifi) > 0],
            ['key' => 'wifi_password', 'label' => 'Senha Wi-Fi gravável', 'ok' => (bool)array_filter($wifi, static fn($n) => $n['can_password'])],
            ['key' => 'wifi_channel', 'label' => 'Canal Wi-Fi gravável', 'ok' => (bool)array_filter($wifi, static fn($n) => $n['can_channel'])],
            ['key' => 'wan', 'label' => 'Conexões WAN (' . count($wan) . ')', 'ok' => count($wan) > 0],
            ['key' => 'pppoe', 'label' => 'Credencial PPPoE gravável', 'ok' => (bool)array_filter($wan, static fn($c) => $c['can_credentials'])],
            ['key' => 'inform', 'label' => 'Intervalo de Inform', 'ok' => self::has($m, 'PeriodicInformInterval')],
            ['key' => 'stun', 'label' => 'STUN', 'ok' => self::has($m, 'STUNEnable')],
        ];
        return [
            'success' => true,
            'data_model' => $model === 'Device' ? 'TR-181 (Device.*)' : 'TR-098 (InternetGatewayDevice.*)',
            'manufacturer' => (string)($dev['_deviceId']['_Manufacturer'] ?? ''),
            'product_class' => (string)($dev['_deviceId']['_ProductClass'] ?? ''),
            'software_version' => (string)self::val($di, 'SoftwareVersion', ''),
            'last_inform' => (string)($dev['_lastInform'] ?? ''),
            'features' => $features,
        ];
    }

    private function capabilitiesProbe(string $id): array
    {
        $queued = 0;
        $first = true;
        $dev = $this->device($id, 'InternetGatewayDevice,Device');
        $model = self::model($dev);
        foreach (self::REFRESH_ROOTS as $root) {
            if (!str_starts_with($root, $model . '.')) {
                continue;
            }
            $this->enqueue($id, ['name' => 'refreshObject', 'objectName' => $root], $first ? 6000 : 0, $first);
            $first = false;
            $queued++;
        }
        return [
            'success' => true,
            'queued' => $queued,
            'summary' => 'Sondagem solicitada: ' . $queued . ' áreas do modelo de dados serão relidas',
            'message' => 'O equipamento relê os objetos na próxima comunicação. Use "Ler resultado" em alguns segundos.',
        ] + $this->capabilities($id);
    }

    /* ---------------------------------------------------------- backup/restore */

    private static function excludedFromBackup(string $path): bool
    {
        foreach (self::BACKUP_EXCLUDE as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function collectWritable(array $tree, string $prefix, array &$out): void
    {
        foreach ($tree as $k => $v) {
            $k = (string)$k;
            if (!is_array($v) || $k === '' || $k[0] === '_') {
                continue;
            }
            $path = $prefix === '' ? $k : $prefix . '.' . $k;
            if (array_key_exists('_value', $v)) {
                if (($v['_writable'] ?? false) === true && $v['_value'] !== null && !self::excludedFromBackup($path . '.')) {
                    $out[$path] = ['v' => $v['_value'], 't' => (string)($v['_type'] ?? 'xsd:string')];
                }
                continue;
            }
            $this->collectWritable($v, $path, $out);
        }
    }

    private function backup(string $id, string $role): array
    {
        $dev = $this->device($id);
        $model = self::model($dev);
        $flat = [];
        $this->collectWritable([$model => $dev[$model]], '', $flat);
        $includeSecrets = $role === 'admin';
        $params = [];
        $omitted = 0;
        foreach ($flat as $path => $row) {
            if (!in_array($row['t'], self::TYPES, true)) {
                continue;
            }
            if (!$includeSecrets && self::secretName($path)) {
                $omitted++;
                continue;
            }
            $params[] = ['p' => $path, 'v' => $row['v'], 't' => $row['t']];
        }
        if (!$params) {
            throw new JrToolsUserError('Nenhum parâmetro gravável foi coletado neste equipamento.');
        }
        $meta = [
            'format' => 'jrc-acs-config-backup',
            'version' => 1,
            'device_id' => $id,
            'manufacturer' => (string)($dev['_deviceId']['_Manufacturer'] ?? ''),
            'product_class' => (string)($dev['_deviceId']['_ProductClass'] ?? ''),
            'serial' => (string)($dev['_deviceId']['_SerialNumber'] ?? ''),
            'data_model' => $model,
            'created_at' => gmdate('c'),
            'secrets_included' => $includeSecrets,
        ];
        return [
            'success' => true,
            'summary' => count($params) . ' parâmetros gravados no arquivo' . ($omitted ? ' (' . $omitted . ' segredos omitidos para o seu perfil)' : ''),
            'count' => count($params),
            'omitted_secrets' => $omitted,
            'backup' => ['meta' => $meta, 'parameters' => $params],
        ];
    }

    private function prepareRestore(string $id, array $in): array
    {
        $b = $in['backup'] ?? null;
        if (!is_array($b) || ($b['meta']['format'] ?? '') !== 'jrc-acs-config-backup' || !is_array($b['parameters'] ?? null)) {
            throw new JrToolsUserError('Arquivo de backup inválido. Use um arquivo gerado por "Backup de config".');
        }
        if (($b['meta']['device_id'] ?? '') !== $id) {
            throw new JrToolsUserError('Este backup pertence a outro equipamento. A restauração só é permitida no mesmo equipamento.');
        }
        if (count($b['parameters']) > 4000) {
            throw new JrToolsUserError('Backup grande demais.');
        }
        $dev = $this->device($id);
        $model = self::model($dev);
        $current = [];
        $this->collectWritable([$model => $dev[$model]], '', $current);
        $changes = [];
        $unchanged = 0;
        $missing = 0;
        $skipped = 0;
        foreach ($b['parameters'] as $row) {
            $path = (string)($row['p'] ?? '');
            $type = (string)($row['t'] ?? '');
            if (!preg_match('/^(InternetGatewayDevice|Device)\.[A-Za-z0-9_.\-]+$/', $path) || !str_starts_with($path, $model . '.')
                || self::excludedFromBackup($path . '.') || !in_array($type, self::TYPES, true)) {
                $skipped++;
                continue;
            }
            if (!isset($current[$path])) {
                $missing++;
                continue;
            }
            $value = self::coerce($row['v'] ?? null, $type);
            if ($value === null) {
                $skipped++;
                continue;
            }
            if (self::coerce($current[$path]['v'], $type) === $value) {
                $unchanged++;
                continue;
            }
            $changes[] = ['p' => $path, 'v' => $value, 't' => $type, 'secret' => self::secretName($path), 'from' => $current[$path]['v']];
        }
        return ['changes' => $changes, 'unchanged' => $unchanged, 'missing' => $missing, 'skipped' => $skipped];
    }

    private static function coerce(mixed $v, string $type): mixed
    {
        if (in_array($type, ['xsd:boolean'], true)) {
            $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $b;
        }
        if (in_array($type, ['xsd:int', 'xsd:unsignedInt', 'xsd:long', 'xsd:unsignedLong'], true)) {
            return is_numeric($v) ? (int)$v : null;
        }
        return is_scalar($v) ? (string)$v : null;
    }

    private function restorePreview(string $id, array $in): array
    {
        $r = $this->prepareRestore($id, $in);
        $list = array_map(static fn($c) => [
            'p' => $c['p'],
            'from' => $c['secret'] ? '••••' : $c['from'],
            'to' => $c['secret'] ? '••••' : $c['v'],
        ], array_slice($r['changes'], 0, 200));
        return [
            'success' => true,
            'will_change' => count($r['changes']),
            'unchanged' => $r['unchanged'],
            'missing' => $r['missing'],
            'skipped' => $r['skipped'],
            'changes' => $list,
            'summary' => count($r['changes']) . ' parâmetros seriam alterados, ' . $r['unchanged'] . ' já estão iguais',
        ];
    }

    private function restoreApply(string $id, array $in): array
    {
        if (($in['confirm'] ?? '') !== 'RESTAURAR') {
            throw new JrToolsUserError('Digite RESTAURAR para aplicar o backup.');
        }
        $r = $this->prepareRestore($id, $in);
        if (!$r['changes']) {
            return ['success' => true, 'status' => 'done', 'applied' => true, 'summary' => 'Nada a restaurar: o equipamento já está igual ao backup', 'message' => 'Nenhum parâmetro difere do backup.'];
        }
        $chunks = array_chunk($r['changes'], 25);
        $ids = [];
        $applied = false;
        foreach ($chunks as $i => $chunk) {
            $pv = array_map(static fn($c) => [$c['p'], $c['v'], $c['t']], $chunk);
            $last = $i === count($chunks) - 1;
            $task = $this->enqueue($id, ['name' => 'setParameterValues', 'parameterValues' => $pv], $last ? 4000 : 0, $last);
            $ids[] = $task['id'];
            $applied = $task['applied'];
        }
        return [
            'success' => true,
            'applied' => $applied,
            'status' => $applied ? 'done' : 'queued',
            'task_ids' => $ids,
            'summary' => count($r['changes']) . ' parâmetros enviados em ' . count($chunks) . ' tarefas' . ($applied ? ' — aplicado' : ' — enfileirado, aguardando o equipamento'),
            'message' => 'Restauração enviada. O equipamento pode perder a conexão momentaneamente.',
        ];
    }

    /* ------------------------------------------------------ firmware/factory */

    private function files(string $id): array
    {
        $dev = $this->device($id, '_id,_deviceId');
        $oui = strtoupper((string)($dev['_deviceId']['_OUI'] ?? ''));
        $pc = strtolower((string)($dev['_deviceId']['_ProductClass'] ?? ''));
        $out = [];
        foreach ($this->collection('files', []) as $f) {
            $meta = is_array($f['metadata'] ?? null) ? $f['metadata'] : [];
            $type = (string)($f['fileType'] ?? $meta['fileType'] ?? '');
            if (!str_starts_with($type, '1 ')) {
                continue;
            }
            $fo = strtoupper((string)($meta['oui'] ?? $f['oui'] ?? ''));
            $fp = strtolower((string)($meta['productClass'] ?? $f['productClass'] ?? ''));
            $compatible = ($fo === '' || $fo === $oui) && ($fp === '' || $fp === $pc);
            $out[] = [
                'name' => (string)($f['_id'] ?? ''),
                'version' => (string)($meta['version'] ?? $f['version'] ?? ''),
                'size' => (int)($f['length'] ?? 0),
                'oui' => $fo,
                'product_class' => (string)($meta['productClass'] ?? $f['productClass'] ?? ''),
                'compatible' => $compatible,
                'file_type' => $type,
            ];
        }
        return ['files' => $out, 'device_oui' => $oui, 'device_product_class' => (string)($dev['_deviceId']['_ProductClass'] ?? '')];
    }

    private function filesList(string $id): array
    {
        return ['success' => true] + $this->files($id);
    }

    private function firmwareStart(string $id, array $in): array
    {
        if (($in['confirm'] ?? '') !== 'ATUALIZAR') {
            throw new JrToolsUserError('Digite ATUALIZAR para iniciar a atualização de firmware.');
        }
        $name = (string)($in['file'] ?? '');
        $match = null;
        foreach ($this->files($id)['files'] as $f) {
            if ($f['name'] === $name) {
                $match = $f;
            }
        }
        if ($match === null) {
            throw new JrToolsUserError('Arquivo de firmware não encontrado no GenieACS.');
        }
        if (!$match['compatible']) {
            throw new JrToolsUserError('Este firmware declara OUI/modelo diferentes do equipamento. Envio bloqueado.');
        }
        foreach ($this->pendingTasks($id) as $t) {
            if (($t['name'] ?? '') === 'download') {
                throw new JrToolsUserError('Já existe um download/firmware na fila deste equipamento.');
            }
        }
        $task = $this->enqueue($id, ['name' => 'download', 'file' => $name, 'fileType' => $match['file_type']], 0, true);
        return [
            'success' => true,
            'applied' => $task['applied'],
            'task_id' => $task['id'],
            'status' => 'queued',
            'target' => $name,
            'summary' => 'Firmware ' . $name . ($match['version'] !== '' ? ' (' . $match['version'] . ')' : '') . ' enviado ao equipamento',
            'message' => 'O equipamento baixa e instala o arquivo e reinicia. Não desligue a ONU.',
        ];
    }

    private function factoryReset(string $id, array $in): array
    {
        if (($in['confirm'] ?? '') !== 'RESTAURAR') {
            throw new JrToolsUserError('Digite RESTAURAR para executar a restauração de fábrica.');
        }
        $task = $this->enqueue($id, ['name' => 'factoryReset'], 0, true);
        return [
            'success' => true,
            'applied' => $task['applied'],
            'task_id' => $task['id'],
            'status' => $task['applied'] ? 'done' : 'queued',
            'summary' => 'Restauração de fábrica solicitada',
            'message' => 'O equipamento volta ao padrão de fábrica e perde Wi-Fi, PPPoE e provisionamento.',
        ];
    }

    /* -------------------------------------------------------------- dispatch */

    public function handle(string $action, string $id, array $in, string $role): array
    {
        self::assertDeviceId($id);
        return match ($action) {
            'ping_start' => $this->diagStart('ping', $id, $in),
            'ping_status' => $this->diagStatus('ping', $id, $in),
            'trace_start' => $this->diagStart('trace', $id, $in),
            'trace_status' => $this->diagStatus('trace', $id, $in),
            'dns_start' => $this->diagStart('dns', $id, $in),
            'dns_status' => $this->diagStatus('dns', $id, $in),
            'wifi_list' => $this->wifiList($id),
            'wifi_set' => $this->wifiSet($id, $in),
            'wan_list' => $this->wanList($id),
            'wan_reconnect' => $this->wanReconnect($id, $in),
            'inform_get' => $this->informGet($id),
            'inform_set' => $this->informSet($id, $in),
            'stun_get' => $this->stunGet($id),
            'stun_set' => $this->stunSet($id, $in),
            'pppoe_set' => $this->pppoeSet($id, $in),
            'capabilities' => $this->capabilities($id),
            'capabilities_probe' => $this->capabilitiesProbe($id),
            'backup' => $this->backup($id, $role),
            'restore_preview' => $this->restorePreview($id, $in),
            'restore_apply' => $this->restoreApply($id, $in),
            'files_list' => $this->filesList($id),
            'firmware_start' => $this->firmwareStart($id, $in),
            'factory_reset' => $this->factoryReset($id, $in),
            default => throw new JrToolsUserError('Ação não suportada.'),
        };
    }
}

/* ------------------------------------------------------------ history store */

final class JrToolsHistory
{
    private mysqli $db;
    private bool $ready = false;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    private function ensure(): bool
    {
        if ($this->ready) {
            return true;
        }
        try {
            $this->db->query(
                'CREATE TABLE IF NOT EXISTS jrc_device_tool_runs (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    device_id VARCHAR(255) NOT NULL,
                    tool VARCHAR(40) NOT NULL,
                    title VARCHAR(80) NOT NULL,
                    target VARCHAR(160) NOT NULL DEFAULT \'\',
                    status VARCHAR(16) NOT NULL,
                    summary VARCHAR(500) NOT NULL DEFAULT \'\',
                    username VARCHAR(80) NOT NULL DEFAULT \'\',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_device (device_id, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $this->ready = true;
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }

    private static function cut(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length) : substr($text, $start, $length);
    }

    public function add(string $deviceId, array $row, string $username): bool
    {
        if (!$this->ensure()) {
            return false;
        }
        $tool = self::cut((string)($row['tool'] ?? ''), 0, 40);
        $title = self::cut((string)($row['title'] ?? ''), 0, 80);
        $target = self::cut((string)($row['target'] ?? ''), 0, 160);
        $status = in_array($row['status'] ?? '', ['ok', 'fail', 'queued', 'running'], true) ? (string)$row['status'] : 'ok';
        $summary = self::cut((string)($row['summary'] ?? ''), 0, 500);
        if (!preg_match('/^[a-z0-9_\-]{1,40}$/', $tool) || $title === '') {
            throw new JrToolsUserError('Registro de histórico inválido.');
        }
        $stmt = $this->db->prepare('INSERT INTO jrc_device_tool_runs (device_id, tool, title, target, status, summary, username) VALUES (?,?,?,?,?,?,?)');
        $stmt->bind_param('sssssss', $deviceId, $tool, $title, $target, $status, $summary, $username);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    public function list(string $deviceId, int $limit = 50): ?array
    {
        if (!$this->ensure()) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, tool, title, target, status, summary, username, UNIX_TIMESTAMP(created_at) AS ts FROM jrc_device_tool_runs WHERE device_id = ? ORDER BY created_at DESC, id DESC LIMIT ?');
        $stmt->bind_param('si', $deviceId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['ts'] = (int)$r['ts'];
        }
        return $rows;
    }
}

/* -------------------------------------------------------------- controller */

if (!defined('JR_DEVICE_TOOLS_LIB_ONLY')) {
    require_once __DIR__ . '/../config/config.php';
    requireLogin();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $jrUserId = (int)($_SESSION['user_id'] ?? 0);
        session_write_close();
    } else {
        $jrUserId = (int)($_SESSION['user_id'] ?? 0);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $jrReply = static function (array $data, int $code = 200): never {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $jrReply(['success' => false, 'message' => 'Método não permitido.'], 405);
        }
        if (!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $jrReply(['success' => false, 'message' => 'O corpo precisa ser application/json.'], 400);
        }
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            $jrReply(['success' => false, 'message' => 'Requisição de outra origem recusada.'], 400);
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = getDBConnection();
        $stmt = $db->prepare('SELECT username, role, active FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $jrUserId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user || (int)$user['active'] !== 1) {
            $jrReply(['success' => false, 'message' => 'Usuário sem permissão.'], 403);
        }
        $role = strtolower((string)$user['role']);
        $in = json_decode((string)file_get_contents('php://input'), true, 64);
        if (!is_array($in)) {
            $jrReply(['success' => false, 'message' => 'JSON inválido.'], 400);
        }
        $action = (string)($in['action'] ?? '');
        $deviceId = trim((string)($in['device_id'] ?? ''));
        $allowed = JrDeviceTools::rolesFor($action);
        if ($allowed === null) {
            $jrReply(['success' => false, 'message' => 'Ação não suportada.'], 400);
        }
        if (!in_array($role, $allowed, true)) {
            $jrReply(['success' => false, 'message' => 'Seu perfil não tem permissão para esta ação.'], 403);
        }
        if ($deviceId === '' || strlen($deviceId) > 255 || preg_match('/[\x00-\x1f]/', $deviceId)) {
            $jrReply(['success' => false, 'message' => 'Equipamento inválido.'], 400);
        }

        $history = new JrToolsHistory($db);
        if ($action === 'history') {
            $rows = $history->list($deviceId);
            $jrReply(['success' => true, 'role' => $role, 'username' => (string)$user['username'], 'history_enabled' => $rows !== null, 'history' => $rows ?? []]);
        }
        if ($action === 'history_add') {
            $stored = $history->add($deviceId, (array)($in['entry'] ?? []), (string)$user['username']);
            $jrReply(['success' => true, 'stored' => $stored]);
        }

        $credentials = $db->query('SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
        $acs = $credentials ? $credentials->fetch_assoc() : null;
        if (!$acs) {
            $jrReply(['success' => false, 'message' => 'GenieACS não está conectado.'], 503);
        }
        $tools = new JrDeviceTools($acs);
        $reply = $tools->handle($action, $deviceId, $in, $role);
        if (!empty($reply['success']) && in_array($action, ['backup'], true) === false) {
            // Historico das acoes que alteram o equipamento e nao passam pelo polling do navegador.
            $log = [
                'wifi_set' => ['wifi', 'Wi-Fi'], 'wan_reconnect' => ['wan', 'Reconectar WAN'], 'inform_set' => ['inform', 'Intervalo de Inform'],
                'stun_set' => ['stun', 'STUN'], 'pppoe_set' => ['pppoe', 'Credencial PPPoE'], 'restore_apply' => ['restore', 'Restaurar config'],
                'firmware_start' => ['firmware', 'Atualizar firmware'], 'factory_reset' => ['factory', 'Restaurar de fábrica'],
            ][$action] ?? null;
            if ($log !== null) {
                try {
                    $history->add($deviceId, [
                        'tool' => $log[0], 'title' => $log[1], 'target' => (string)($reply['target'] ?? ''),
                        'status' => ($reply['status'] ?? '') === 'queued' ? 'queued' : 'ok', 'summary' => (string)($reply['summary'] ?? ''),
                    ], (string)$user['username']);
                } catch (Throwable $e) {
                }
            }
        }
        $jrReply($reply);
    } catch (JrToolsUserError $e) {
        $jrReply(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (JrToolsForbidden $e) {
        $jrReply(['success' => false, 'message' => $e->getMessage()], 403);
    } catch (Throwable $e) {
        $jrReply(['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Falha ao executar a ferramenta.'], 502);
    }
}
