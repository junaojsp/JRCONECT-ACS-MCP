<?php
/* JRC_MCP_CADASTRO_V1 */
/* JRC_PABX_STATUS_V1 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

requireLogin();

$storeDir = __DIR__ . '/../storage/mcp';
$storeFile = $storeDir . '/instances.json';

function jrcMcpReadInstances(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $rows = $data['instances'] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function jrcMcpWriteInstances(string $dir, string $file, array $instances): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Diretório de armazenamento MCP indisponível.');
    }

    $payload = json_encode([
        'version' => 1,
        'updated_at' => gmdate('c'),
        'instances' => array_values($instances),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        throw new RuntimeException('Falha ao serializar instâncias MCP.');
    }

    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Falha ao gravar cadastro MCP.');
    }

    @chmod($tmp, 0660);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Falha ao finalizar cadastro MCP.');
    }
}

function jrcMcpRequireCsrf(): void
{
    $expected = $_SESSION['mcp_instance_csrf'] ?? '';
    $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($expected) ||
        $expected === '' ||
        !is_string($received) ||
        $received === '' ||
        !hash_equals($expected, $received)
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Token de segurança inválido. Atualize a página.',
        ], 403);
    }
}


function jrcMcpLocalHealth(): array
{
    $ch = curl_init('http://127.0.0.1:3100/health');
    if ($ch === false) {
        return ['ok' => false];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $data = is_string($response) && $code === 200 && stripos($contentType, 'application/json') !== false
        ? json_decode($response, true)
        : null;
    if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
        return ['ok' => false];
    }
    return ['ok' => true, 'data' => $data];
}

function jrcMcpPabxAdvertised(?array $healthData): array
{
    $services = is_array($healthData['services'] ?? null) ? $healthData['services'] : [];
    $tools = [];
    $online = false;
    foreach ($services as $svc) {
        if (!is_array($svc)) {
            continue;
        }
        $hay = strtolower((string) ($svc['id'] ?? '') . ' ' . (string) ($svc['name'] ?? ''));
        if (!str_contains($hay, 'pabx') && !str_contains($hay, 'issabel')) {
            continue;
        }
        $online = (($svc['status'] ?? 'online') === 'online');
        if (is_array($svc['tools'] ?? null)) {
            foreach ($svc['tools'] as $tool) {
                $tools[] = (string) $tool;
            }
        }
    }
    if ($tools === [] && is_array($healthData['tools'] ?? null)) {
        foreach ($healthData['tools'] as $tool) {
            if (str_starts_with(strtolower((string) $tool), 'pabx_')) {
                $tools[] = (string) $tool;
                $online = true;
            }
        }
    }
    return ['online' => $online || $tools !== [], 'tools' => $tools];
}

function jrcMcpManagedPabxState(): array
{
    $probe = jrcMcpLocalHealth();
    $pabx = jrcMcpPabxAdvertised($probe['ok'] ? ($probe['data'] ?? []) : null);
    if ($probe['ok']) {
        return [
            'connection_status' => 'connected',
            'last_test_at' => gmdate('c'),
            'tool_count' => count($pabx['tools']),
            'pabx_advertised' => $pabx['online'],
        ];
    }
    return [
        'connection_status' => 'error',
        'last_test_at' => gmdate('c'),
        'tool_count' => 0,
        'pabx_advertised' => false,
    ];
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    jsonResponse([
        'success' => true,
        'instances' => array_map(static function (array $row): array {
            $endpoint = rtrim((string)($row['endpoint'] ?? ''), '/');
            $type = (string)($row['type'] ?? '');
            if ($type === '' && $endpoint === 'https://cda.jrconect.com/services/mcp') {
                // Normalize the legacy IXC row for the UI without rewriting instances.json.
                $row['type'] = 'external';
                $row['auth_type'] = 'oauth2';
                $type = 'external';
            }
            if ($type === 'external') {
                $id = (string)($row['id'] ?? '');
                $validId = preg_match('/^mcp-[a-f0-9]{12}$/', $id) === 1;
                $row['credentials_saved'] = $validId
                    && is_file('/var/lib/jrconect-mcp-secrets/' . $id . '.json');
                $statusPath = '/var/lib/jrconect-mcp-secrets/' . $id . '-status.json';
                $status = $validId && !is_link($statusPath) && is_file($statusPath)
                    ? json_decode((string)@file_get_contents($statusPath), true) : null;
                if (is_array($status)) {
                    $row['connection_status'] = ($status['connection_status'] ?? '') === 'connected'
                        ? 'connected' : 'error';
                    $row['last_test_at'] = (string)($status['last_test_at'] ?? '');
                    $row['tool_count'] = max(0, (int)($status['tool_count'] ?? 0));
                    $row['server_tools_capability'] = (bool)($status['server_tools_capability'] ?? false);
                } else {
                    $row['connection_status'] = 'untested';
                    $row['last_test_at'] = '';
                    $row['tool_count'] = null;
                    $row['server_tools_capability'] = null;
                }
            }
            if ($type === 'managed' && (($row['service'] ?? 'pabx') === 'pabx')) {
                $row = array_merge($row, jrcMcpManagedPabxState());
            }
            return $row;
        }, jrcMcpReadInstances($storeFile)),
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}

jrcMcpRequireCsrf();

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);

if (!is_array($body)) {
    jsonResponse(['success' => false, 'message' => 'JSON inválido.'], 400);
}

// Edit existing instance metadata without changing credentials or provisioning services.
if (($body['action'] ?? '') === 'update_instance') {
    $id = (string)($body['id'] ?? '');
    if (!preg_match('/^mcp-[a-f0-9]{12}$/', $id)) {
        jsonResponse(['success' => false, 'message' => 'ID de instância inválido.'], 422);
    }
    $instances = jrcMcpReadInstances($storeFile);
    $index = null;
    foreach ($instances as $i => $row) {
        if (($row['id'] ?? '') === $id) { $index = $i; break; }
    }
    if ($index === null) {
        jsonResponse(['success' => false, 'message' => 'Instância não encontrada.'], 404);
    }
    $name = trim((string)($body['name'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || mb_strlen($description) > 240) {
        jsonResponse(['success' => false, 'message' => 'Nome ou descrição inválidos.'], 422);
    }
    $instances[$index]['name'] = $name;
    $instances[$index]['description'] = $description;
    $instances[$index]['updated_at'] = gmdate('c');
    try {
        jrcMcpWriteInstances($storeDir, $storeFile, $instances);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Falha ao editar instância.'], 500);
    }
    jsonResponse(['success' => true, 'instance' => $instances[$index]]);
}

// Read-only reachability check for a managed integration. This never claims that Issabel was authenticated.
if (($body['action'] ?? '') === 'test_managed') {
    $id = (string)($body['id'] ?? '');
    $found = false;
    foreach (jrcMcpReadInstances($storeFile) as $row) {
        if (($row['id'] ?? '') === $id && ($row['type'] ?? '') === 'managed' && ($row['service'] ?? '') === 'pabx') {
            $found = true;
            break;
        }
    }
    if (!$found) jsonResponse(['success' => false, 'message' => 'Integração PABX não encontrada.'], 404);
    // O token PABX pertence exclusivamente ao container MCP.
    // Este endpoint verifica somente a disponibilidade do MCP local;
    // nao deve declarar o Issabel autenticado nem expor credenciais.
    $ch = curl_init('http://127.0.0.1:3100/health');
    if ($ch === false) {
        jsonResponse(['success' => false, 'pabx_verified' => false,
            'mcp_reachable' => false, 'message' => 'Falha ao iniciar verificacao do MCP.'], 500);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $data = is_string($response) && strlen($response) <= 4096
        && $code === 200 && stripos($contentType, 'application/json') !== false
        ? json_decode($response, true) : null;
    if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
        jsonResponse(['success' => false, 'pabx_verified' => false,
            'mcp_reachable' => false,
            'message' => 'MCP principal indisponivel; nao foi possivel verificar a integracao PABX.'], 503);
    }
    $state = jrcMcpManagedPabxState();
    jsonResponse([
        'success' => true,
        'pabx_verified' => false,
        'mcp_reachable' => true,
        'connection_status' => $state['connection_status'],
        'checked_at' => $state['last_test_at'],
        'tool_count' => $state['tool_count'],
        'message' => 'MCP principal online. A conexao autenticada com o Issabel ainda nao foi verificada.',
    ]);

}

// Read-only local MCP connectivity check (does not validate Issabel itself).
if (($body['action'] ?? '') === 'test_local') {
    $id = (string)($body['id'] ?? '');
    $found = false;
    foreach (jrcMcpReadInstances($storeFile) as $row) {
        if (($row['id'] ?? '') === $id && ($row['type'] ?? 'local') === 'local') {
            $found = true; break;
        }
    }
    if (!$found) jsonResponse(['success' => false, 'message' => 'Instância local não encontrada.'], 404);
    $ch = curl_init('http://127.0.0.1:3100/health');
    if ($ch === false) jsonResponse(['success' => false, 'message' => 'Falha ao iniciar teste.'], 500);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
    $result = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    jsonResponse(['success' => $result !== false && $http === 200,
        'message' => $http === 200 ? 'MCP principal acessível. Este teste não verifica o PABX.' : 'MCP principal indisponível.',
        'http_status' => $http], $http === 200 ? 200 : 502);
}

// Existing external connections: credentials are stored OUTSIDE the web root.
// Provision the directory for the PHP worker before using this operation.
if (($body['action'] ?? '') === 'set_external_credentials') {
    $id = (string)($body['id'] ?? '');
    $clientId = trim((string)($body['client_id'] ?? ''));
    $secret = (string)($body['client_secret'] ?? '');
    if (!preg_match('/^mcp-[a-f0-9]{12}$/', $id)
        || $clientId === '' || strlen($clientId) > 512
        || $secret === '' || strlen($secret) > 2048) {
        jsonResponse(['success' => false, 'message' => 'Credenciais inválidas.'], 422);
    }
    $instances = jrcMcpReadInstances($storeFile);
    $found = false;
    foreach ($instances as $instance) {
        $endpoint = rtrim((string)($instance['endpoint'] ?? ''), '/');
        $type = (string)($instance['type'] ?? '');
        $isLegacyIxc = $type === '' && $endpoint === 'https://cda.jrconect.com/services/mcp';
        if (($instance['id'] ?? '') === $id && ($type === 'external' || $isLegacyIxc)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        jsonResponse(['success' => false, 'message' => 'MCP externo não encontrado.'], 404);
    }
    $secretDir = '/var/lib/jrconect-mcp-secrets';
    if (!is_dir($secretDir) || !is_writable($secretDir)
        || is_link($secretDir)
        || (fileperms($secretDir) & 0077) !== 0) {
        jsonResponse(['success' => false, 'message' => 'Diretório protegido de credenciais não configurado.'], 503);
    }
    $target = $secretDir . '/' . $id . '.json';
    if (is_link($target)) {
        jsonResponse(['success' => false, 'message' => 'Destino de credenciais inválido.'], 500);
    }
    $encoded = json_encode(['client_id' => $clientId, 'client_secret' => $secret],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp = tempnam($secretDir, '.ixc-');
    if ($tmp === false) {
        jsonResponse(['success' => false, 'message' => 'Falha ao preparar arquivo protegido.'], 500);
    }
    chmod($tmp, 0600);
    try {
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false || !rename($tmp, $target)) {
            throw new RuntimeException('Falha ao salvar credenciais.');
        }
        chmod($target, 0600);
    } catch (Throwable $e) {
        @unlink($tmp);
        jsonResponse(['success' => false, 'message' => 'Falha ao salvar credenciais.'], 500);
    }
    jsonResponse(['success' => true, 'credentials_saved' => true]);
}


if (($body['action'] ?? '') === 'add_server' || ($body['type'] ?? '') === 'server') {
    $name = trim((string) ($body['name'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $transport = (string) ($body['transport'] ?? 'streamable_http');
    $authType = (string) ($body['auth_type'] ?? 'none');
    $endpoint = trim((string) ($body['endpoint'] ?? ''));
    $command = trim((string) ($body['command'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || mb_strlen($description) > 240) {
        jsonResponse(['success' => false, 'message' => 'Informe um nome entre 2 e 100 caracteres.'], 422);
    }
    if (!in_array($transport, ['streamable_http', 'sse', 'stdio'], true)) {
        jsonResponse(['success' => false, 'message' => 'Transporte MCP inválido.'], 422);
    }
    if (!in_array($authType, ['none', 'bearer', 'oauth2', 'api_key'], true)) {
        jsonResponse(['success' => false, 'message' => 'Autenticação inválida.'], 422);
    }
    $instance = [
        'id' => 'mcp-' . bin2hex(random_bytes(6)),
        'name' => $name,
        'description' => $description,
        'auth_type' => $authType,
        'transport' => $transport,
        'status' => 'registered',
        'created_at' => gmdate('c'),
    ];
    if ($transport === 'stdio') {
        if (!preg_match('/^[a-zA-Z0-9_.\\/-]{2,200}$/', $command) || str_contains($command, '..')) {
            jsonResponse(['success' => false, 'message' => 'Informe um comando STDIO válido, sem argumentos.'], 422);
        }
        $instance['type'] = 'stdio';
        $instance['command'] = $command;
    } else {
        if ($endpoint === '' || mb_strlen($endpoint) > 500 || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            jsonResponse(['success' => false, 'message' => 'Informe o endpoint HTTP ou HTTPS do servidor MCP.'], 422);
        }
        $parts = parse_url($endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            jsonResponse(['success' => false, 'message' => 'O endpoint deve ser http/https, sem usuário e senha na URL.'], 422);
        }
        if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            jsonResponse(['success' => false, 'message' => 'HTTP só é aceito em 127.0.0.1. Servidor remoto usa HTTPS.'], 422);
        }
        $instances = jrcMcpReadInstances($storeFile);
        foreach ($instances as $row) {
            if (strcasecmp((string) ($row['endpoint'] ?? ''), $endpoint) === 0) {
                jsonResponse(['success' => false, 'message' => 'Esse endpoint já está cadastrado.'], 409);
            }
        }
        $instance['type'] = 'external';
        $instance['endpoint'] = $endpoint;
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($host === '127.0.0.1' || $host === 'localhost' || $host === '::1') {
            $instance['local_address'] = '127.0.0.1:' . $port;
        }
        $instances[] = $instance;
        try {
            jrcMcpWriteInstances($storeDir, $storeFile, $instances);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => 'Falha ao cadastrar o servidor MCP.'], 500);
        }
        jsonResponse(['success' => true, 'instance' => $instance], 201);
    }
    $instances = jrcMcpReadInstances($storeFile);
    $instances[] = $instance;
    try {
        jrcMcpWriteInstances($storeDir, $storeFile, $instances);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Falha ao cadastrar o servidor MCP.'], 500);
    }
    jsonResponse(['success' => true, 'instance' => $instance], 201);
}

$type = (string)($body['type'] ?? 'local');
if (!in_array($type, ['local', 'external', 'managed', 'stdio'], true)) {
    jsonResponse(['success' => false, 'message' => 'Tipo MCP inválido.'], 422);
}

if ($type === 'managed' || $type === 'stdio') {
    $name = trim((string)($body['name'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $service = trim((string)($body['service'] ?? ''));
    $command = trim((string)($body['command'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || mb_strlen($description) > 240) {
        jsonResponse(['success' => false, 'message' => 'Nome ou descrição inválidos.'], 422);
    }
    if ($type === 'managed' && $service !== 'pabx') {
        jsonResponse(['success' => false, 'message' => 'Integração gerenciada não suportada.'], 422);
    }
    if ($type === 'stdio' && (!preg_match('/^[a-zA-Z0-9_.\\/-]{2,200}$/', $command) || str_contains($command, '..'))) {
        jsonResponse(['success' => false, 'message' => 'Informe um executável STDIO válido, sem argumentos.'], 422);
    }
    $instances = jrcMcpReadInstances($storeFile);
    foreach ($instances as $row) {
        if ($type === 'managed' && ($row['type'] ?? '') === 'managed' && ($row['service'] ?? '') === $service) {
            jsonResponse(['success' => false, 'message' => 'Integração já cadastrada.'], 409);
        }
    }
    $instance = [
        'id' => 'mcp-' . bin2hex(random_bytes(6)),
        'type' => $type, 'name' => $name, 'description' => $description,
        'status' => 'registered', 'created_at' => gmdate('c')
    ];
    if ($type === 'managed') $instance['service'] = $service;
    else $instance['command'] = $command;
    $instances[] = $instance;
    try { jrcMcpWriteInstances($storeDir, $storeFile, $instances); }
    catch (Throwable $e) { jsonResponse(['success' => false, 'message' => 'Falha ao cadastrar instância.'], 500); }
    jsonResponse(['success' => true, 'instance' => $instance], 201);
}

if ($type === 'external') {
    $name = trim((string)($body['name'] ?? ''));
    $endpoint = trim((string)($body['endpoint'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $authType = (string)($body['auth_type'] ?? 'oauth2');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || mb_strlen($description) > 240) {
        jsonResponse(['success' => false, 'message' => 'Nome ou descrição inválidos.'], 422);
    }
    // Registration only: this endpoint never makes outbound requests or stores credentials.
    if (!in_array($authType, ['oauth2', 'none', 'bearer', 'api_key'], true) || mb_strlen($endpoint) > 500 || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
        jsonResponse(['success' => false, 'message' => 'Informe uma URL HTTPS válida e OAuth2.'], 422);
    }
    $parts = parse_url($endpoint);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment']) || isset($parts['query'])) {
        jsonResponse(['success' => false, 'message' => 'O endpoint externo deve ser HTTPS, sem credenciais ou parâmetros.'], 422);
    }
    $instances = jrcMcpReadInstances($storeFile);
    foreach ($instances as $instance) {
        if (strcasecmp((string)($instance['endpoint'] ?? ''), $endpoint) === 0) {
            jsonResponse(['success' => false, 'message' => 'Esse endpoint já está cadastrado.'], 409);
        }
    }
    $newInstance = [
        'id' => 'mcp-' . bin2hex(random_bytes(6)),
        'type' => 'external',
        'name' => $name,
        'endpoint' => $endpoint,
        'description' => $description,
        'auth_type' => $authType,
        'transport' => in_array(($body['transport'] ?? ''), ['streamable_http', 'sse'], true) ? $body['transport'] : 'streamable_http',
        'status' => 'registered',
        'created_at' => gmdate('c'),
    ];
    $instances[] = $newInstance;
    try {
        jrcMcpWriteInstances($storeDir, $storeFile, $instances);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Falha ao registrar MCP externo.'], 500);
    }
    jsonResponse(['success' => true, 'instance' => $newInstance], 201);
}

$name = trim((string)($body['name'] ?? ''));
$container = trim((string)($body['container'] ?? ''));
$port = trim((string)($body['port'] ?? ''));
$endpoint = trim((string)($body['endpoint'] ?? ''));
$description = trim((string)($body['description'] ?? ''));

if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    jsonResponse(['success' => false, 'message' => 'Informe um nome entre 2 e 100 caracteres.'], 422);
}

if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{1,79}$/', $container)) {
    jsonResponse(['success' => false, 'message' => 'Nome do container inválido.'], 422);
}

if (!preg_match('/^[0-9]{2,5}$/', $port)) {
    jsonResponse(['success' => false, 'message' => 'Porta local inválida.'], 422);
}

$portNumber = (int)$port;
if ($portNumber < 1024 || $portNumber > 65535) {
    jsonResponse(['success' => false, 'message' => 'Use uma porta entre 1024 e 65535.'], 422);
}

if ($endpoint === '' || mb_strlen($endpoint) > 500) {
    jsonResponse(['success' => false, 'message' => 'Informe o endpoint MCP.'], 422);
}

$parts = parse_url($endpoint);
$scheme = strtolower((string)($parts['scheme'] ?? ''));
$host = (string)($parts['host'] ?? '');

if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
    jsonResponse(['success' => false, 'message' => 'Endpoint deve ser uma URL http/https sem credenciais embutidas.'], 422);
}

if (mb_strlen($description) > 240) {
    jsonResponse(['success' => false, 'message' => 'Descrição deve ter no máximo 240 caracteres.'], 422);
}

$instances = jrcMcpReadInstances($storeFile);

foreach ($instances as $instance) {
    if (strcasecmp((string)($instance['container'] ?? ''), $container) === 0) {
        jsonResponse(['success' => false, 'message' => 'Esse container já está cadastrado.'], 409);
    }

    if ((int)($instance['port'] ?? 0) === $portNumber) {
        jsonResponse(['success' => false, 'message' => 'Essa porta local já está cadastrada.'], 409);
    }

    if (strcasecmp((string)($instance['endpoint'] ?? ''), $endpoint) === 0) {
        jsonResponse(['success' => false, 'message' => 'Esse endpoint já está cadastrado.'], 409);
    }
}

$newInstance = [
    'id' => 'mcp-' . bin2hex(random_bytes(6)),
    'name' => $name,
    'container' => $container,
    'port' => $portNumber,
    'local_address' => '127.0.0.1:' . $portNumber,
    'endpoint' => $endpoint,
    'description' => $description,
    'status' => 'registered',
    'created_at' => gmdate('c'),
];

$instances[] = $newInstance;

try {
    jrcMcpWriteInstances($storeDir, $storeFile, $instances);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Falha ao salvar instância MCP.',
        'detail' => $e->getMessage(),
    ], 500);
}

jsonResponse([
    'success' => true,
    'instance' => $newInstance,
], 201);