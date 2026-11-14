<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
requireLogin();

const IXC_MCP_URL = 'https://cda.jrconect.com/services/mcp';
const IXC_AUTHORIZE = 'https://cda.jrconect.com/api-module/oauth/authorize';
const IXC_TOKEN = 'https://cda.jrconect.com/api-module/oauth/token';
const IXC_SECRET_DIR = '/var/lib/jrconect-mcp-secrets';
const IXC_INSTANCES = __DIR__ . '/../storage/mcp/instances.json';

function ixcReply(array $payload, int $status = 200): never {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function ixcInstance(string $id): array {
    if (!preg_match('/^mcp-[a-f0-9]{12}$/', $id)) ixcReply(['success'=>false,'message'=>'ID inválido.'],422);
    $data = json_decode((string)@file_get_contents(IXC_INSTANCES), true);
    foreach (($data['instances'] ?? []) as $item) {
        $endpoint = rtrim((string)($item['endpoint'] ?? ''), '/');
        $type = (string)($item['type'] ?? '');
        $isLegacyIxc = $type === '' && $endpoint === IXC_MCP_URL;
        if (($item['id'] ?? '') === $id && ($type === 'external' || $isLegacyIxc)
          && $endpoint === IXC_MCP_URL) {
            $item['type'] = 'external';
            $item['auth_type'] = 'oauth2';
            return $item;
        }
    }
    ixcReply(['success'=>false,'message'=>'Integração IXC não encontrada.'],404);
}
function ixcSecretFile(string $id, string $suffix = ''): string {
    return IXC_SECRET_DIR . '/' . $id . $suffix . '.json';
}
function ixcReadFile(string $path): array {
    if (is_link($path) || !is_file($path)) return [];
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function ixcStoreFile(string $path, array $data): void {
    if (!is_dir(IXC_SECRET_DIR) || !is_writable(IXC_SECRET_DIR) || is_link(IXC_SECRET_DIR)
      || (fileperms(IXC_SECRET_DIR) & 0077) !== 0 || is_link($path)) {
        ixcReply(['success'=>false,'message'=>'Diretório seguro indisponível.'],503);
    }
    $tmp = tempnam(IXC_SECRET_DIR, '.oauth-');
    if ($tmp === false) ixcReply(['success'=>false,'message'=>'Falha de armazenamento.'],500);
    @chmod($tmp,0600);
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp,$json,LOCK_EX) === false || !@rename($tmp,$path)) {
        @unlink($tmp);
        ixcReply(['success'=>false,'message'=>'Falha ao salvar token.'],500);
    }
    @chmod($path,0600);
}
function ixcCallbackUri(): string {
    // Derive URI from actual HTTPS host of the ACS panel, not the IXC MCP endpoint.
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off'
        || !preg_match('/^[a-zA-Z0-9.-]+(?::443)?$/', $host)) {
        ixcReply(['success'=>false,'message'=>'Acesse o ACS por HTTPS para autorizar.'],400);
    }
    return 'https://' . $host . '/api/mcp-ixc-oauth.php?action=callback';
}
function ixcHttp(string $url, array $headers, ?string $body = null): array {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Falha HTTP.');
    $headersOut = [];
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CUSTOMREQUEST=>$body === null ? 'GET' : 'POST',
        CURLOPT_HEADERFUNCTION=>static function($ch,$line) use (&$headersOut) {
            $parts = explode(':',$line,2);
            if (count($parts)===2) $headersOut[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('Falha de comunicação: ' . $error);
    return [$status,(string)$response,$headersOut];
}
function ixcExchange(array $params, array $creds): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded','Accept: application/json'];
    $params['client_id'] = (string)$creds['client_id'];
    // IXC advertises client_secret_post; secret never enters URLs or browser.
    $params['client_secret'] = (string)$creds['client_secret'];
    [$status,$raw] = ixcHttp(IXC_TOKEN,$headers,http_build_query($params,'','&',PHP_QUERY_RFC3986));
    $data = json_decode($raw,true);
    if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        throw new RuntimeException('IXC recusou a autorização (HTTP '.$status.').');
    }
    return $data;
}
function ixcSaveToken(string $id, array $token, array $previous = []): void {
    $updated = [
        'access_token'=>(string)$token['access_token'],
        'refresh_token'=>(string)($token['refresh_token'] ?? $previous['refresh_token'] ?? ''),
        'token_type'=>(string)($token['token_type'] ?? 'Bearer'),
        'expires_at'=>time()+max(0,(int)($token['expires_in'] ?? 3600))-60,
    ];
    ixcStoreFile(ixcSecretFile($id,'-tokens'),$updated);
}
function ixcToken(string $id, array $creds): string {
    $existing = ixcReadFile(ixcSecretFile($id,'-tokens'));
    if (!empty($existing['access_token']) && (int)($existing['expires_at'] ?? 0)>time()) {
        return (string)$existing['access_token'];
    }
    if (empty($existing['refresh_token'])) throw new RuntimeException('Autorize o IXC antes de testar.');
    $new = ixcExchange(['grant_type'=>'refresh_token','refresh_token'=>(string)$existing['refresh_token']],$creds);
    ixcSaveToken($id,$new,$existing);
    return (string)$new['access_token'];
}
function ixcRpcPayload(string $method, int $id, array $params = []): string {
    return json_encode(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>(object)$params],JSON_THROW_ON_ERROR);
}
function ixcMcpRequest(
    string $token,
    string $method,
    int $id,
    ?string $sessionId = null,
    array $params = [],
    ?string $protocolVersion = null
): array {
    $headers = [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream'
    ];
    if ($protocolVersion !== null && $protocolVersion !== '') {
        $headers[] = 'MCP-Protocol-Version: '.$protocolVersion;
    }
    if ($sessionId !== null) $headers[] = 'Mcp-Session-Id: '.$sessionId;
    if ($method === 'initialize') {
        $params = [
            'protocolVersion'=>'2025-03-26',
            'capabilities'=>new stdClass(),
            'clientInfo'=>['name'=>'JR Conect ACS','version'=>'1.0.0']
        ];
    }
    [$status,$raw,$responseHeaders] = ixcHttp(
        IXC_MCP_URL,
        $headers,
        ixcRpcPayload($method,$id,$params)
    );
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('MCP IXC retornou HTTP '.$status.'.');
    }

    $messages = [];
    $direct = json_decode($raw,true);
    if (is_array($direct)) {
        $messages = isset($direct[0]) ? $direct : [$direct];
    } else {
        // Streamable HTTP may return one or more JSON-RPC messages as SSE data fields.
        $dataLines = [];
        foreach (preg_split('/\r?\n/',$raw) as $line) {
            if (str_starts_with($line,'data:')) {
                $dataLines[] = ltrim(substr($line,5));
            } elseif ($line === '' && $dataLines !== []) {
                $event = json_decode(implode("\n",$dataLines),true);
                if (is_array($event)) {
                    $messages = array_merge($messages,isset($event[0]) ? $event : [$event]);
                }
                $dataLines = [];
            }
        }
        if ($dataLines !== []) {
            $event = json_decode(implode("\n",$dataLines),true);
            if (is_array($event)) {
                $messages = array_merge($messages,isset($event[0]) ? $event : [$event]);
            }
        }
    }

    $json = null;
    foreach ($messages as $message) {
        if (!is_array($message)) continue;
        if (isset($message['id']) && (string)$message['id'] === (string)$id) {
            $json = $message;
            break;
        }
    }
    if (!is_array($json)) throw new RuntimeException('Resposta MCP sem resposta JSON-RPC correspondente.');
    if (isset($json['error'])) {
        $m = is_array($json['error']) ? (string)($json['error']['message'] ?? 'Erro MCP') : 'Erro MCP';
        throw new RuntimeException('MCP IXC: '.substr($m,0,160));
    }
    if (!array_key_exists('result',$json) || !is_array($json['result'])) {
        throw new RuntimeException('Resposta MCP sem objeto result válido.');
    }
    return [$json,$responseHeaders];
}

$action = (string)($_GET['action'] ?? 'status');
$id = (string)($_GET['id'] ?? '');
if ($action === 'callback') {
    $flow = $_SESSION['ixc_oauth_flow'] ?? null;
    unset($_SESSION['ixc_oauth_flow']);
    if (!is_array($flow) || time() - (int)($flow['time'] ?? 0) > 600
      || !hash_equals((string)($flow['state'] ?? ''),(string)($_GET['state'] ?? ''))) {
        ixcReply(['success'=>false,'message'=>'Autorização expirada ou estado inválido.'],400);
    }
    $id = (string)$flow['id'];
    ixcInstance($id);
    if (isset($_GET['error']) || empty($_GET['code'])) {
        ixcReply(['success'=>false,'message'=>'Autorização recusada pelo IXC.'],400);
    }
    try {
        $creds = ixcReadFile(ixcSecretFile($id));
        if (empty($creds['client_id']) || empty($creds['client_secret'])) throw new RuntimeException('Credenciais não cadastradas.');
        $token = ixcExchange([
            'grant_type'=>'authorization_code',
            'code'=>(string)$_GET['code'],
            'redirect_uri'=>(string)$flow['redirect_uri'],
            'code_verifier'=>(string)$flow['verifier'],
        ],$creds);
        ixcSaveToken($id,$token);
        header('Location: '.(string)$flow['return_path'].'?ixc_oauth=success',true,303);
        exit;
    } catch (Throwable $e) {
        ixcReply(['success'=>false,'message'=>$e->getMessage()],502);
    }
}
if ($action === 'start') {
    ixcInstance($id);
    $creds = ixcReadFile(ixcSecretFile($id));
    if (empty($creds['client_id']) || empty($creds['client_secret'])) {
        ixcReply(['success'=>false,'message'=>'Cadastre Client ID e Client Secret primeiro.'],409);
    }
    $callback = ixcCallbackUri();
    $state = bin2hex(random_bytes(24));
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $returnPath = (string)($_GET['return'] ?? '/');
    if (!preg_match('#^/[a-zA-Z0-9/_\\.-]*$#',$returnPath) || str_starts_with($returnPath,'//')) $returnPath='/';
    $_SESSION['ixc_oauth_flow'] = [
        'id'=>$id,'state'=>$state,'verifier'=>$verifier,
        'time'=>time(),'redirect_uri'=>$callback,'return_path'=>$returnPath
    ];
    $url = IXC_AUTHORIZE.'?'.http_build_query([
        'response_type'=>'code','client_id'=>$creds['client_id'],
        'redirect_uri'=>$callback,'state'=>$state,
        'code_challenge'=>$challenge,'code_challenge_method'=>'S256'
    ],'','&',PHP_QUERY_RFC3986);
    header('Location: '.$url,true,302);
    exit;
}
if ($action === 'status') {
    ixcInstance($id);
    $token = ixcReadFile(ixcSecretFile($id,'-tokens'));
    ixcReply(['success'=>true,'authorized'=>!empty($token['refresh_token']) ||
        (!empty($token['access_token']) && (int)($token['expires_at'] ?? 0)>time()),
        'redirect_uri'=>ixcCallbackUri()]);
}
if ($action !== 'test' || $_SERVER['REQUEST_METHOD'] !== 'POST') ixcReply(['success'=>false,'message'=>'Ação inválida.'],405);
$expected = $_SESSION['mcp_instance_csrf'] ?? '';
$received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($expected) || $expected === '' || !is_string($received)
  || !hash_equals($expected,$received)) ixcReply(['success'=>false,'message'=>'CSRF inválido.'],403);
ixcInstance($id);
ixcStoreFile(ixcSecretFile($id,'-status'),[
    'connection_status'=>'checking',
    'last_test_at'=>gmdate('c'),
    'tool_count'=>0,
    'server_tools_capability'=>false
]);
try {
    $creds = ixcReadFile(ixcSecretFile($id));
    $token = ixcToken($id,$creds);
    [$hello,$headers] = ixcMcpRequest($token,'initialize',1);
    $sid = $headers['mcp-session-id'] ?? null;
    $protocolVersion = (string)($hello['result']['protocolVersion'] ?? '');
    if ($protocolVersion === '') {
        throw new RuntimeException('Resposta initialize sem protocolVersion. Verifique a compatibilidade MCP.');
    }
    // MCP requires notifications/initialized after initialize and before tools/list.
    $initHeaders = [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'MCP-Protocol-Version: '.$protocolVersion
    ];
    if ($sid !== null) $initHeaders[] = 'Mcp-Session-Id: '.$sid;
    $notice = json_encode(['jsonrpc'=>'2.0','method'=>'notifications/initialized'], JSON_THROW_ON_ERROR);
    [$noticeStatus] = ixcHttp(IXC_MCP_URL,$initHeaders,$notice);
    if ($noticeStatus !== 202 && ($noticeStatus < 200 || $noticeStatus >= 300)) {
        throw new RuntimeException('MCP IXC não confirmou initialized (HTTP '.$noticeStatus.').');
    }

    $serverCapabilities = $hello['result']['capabilities'] ?? [];
    $advertisesTools = is_array($serverCapabilities) && array_key_exists('tools',$serverCapabilities);
    $names = [];
    $cursor = null;
    $seenCursors = [];
    $pageCount = 0;
    do {
        if (++$pageCount > 50) {
            throw new RuntimeException('tools/list excedeu o limite seguro de 50 páginas.');
        }
        $params = $cursor === null ? [] : ['cursor'=>$cursor];
        [$tools] = ixcMcpRequest($token,'tools/list',$pageCount + 1,$sid,$params,$protocolVersion);
        $page = $tools['result']['tools'] ?? null;
        if (!is_array($page)) {
            throw new RuntimeException('tools/list não retornou uma lista válida de ferramentas.');
        }
        foreach ($page as $tool) {
            if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
                $names[] = $tool['name'];
            }
        }
        $next = $tools['result']['nextCursor'] ?? null;
        if ($next !== null && (!is_string($next) || $next === '')) {
            throw new RuntimeException('tools/list retornou um cursor inválido.');
        }
        if ($next !== null) {
            if (isset($seenCursors[$next])) {
                throw new RuntimeException('tools/list repetiu um cursor; paginação interrompida.');
            }
            $seenCursors[$next] = true;
        }
        $cursor = $next;
    } while ($cursor !== null);

    $emptyMessage = !$advertisesTools
        ? 'IXC respondeu sem anunciar a capacidade tools e sem ferramentas listadas.'
        : 'IXC anunciou tools, mas a lista retornada está vazia. Confira permissões, módulos e escopos no IXC.';
    // A successful MCP handshake does not guarantee that tools are available.
    $hasTools = count($names) > 0;
    $connectionStatus = $hasTools ? 'connected' : 'connected_no_tools';
    ixcStoreFile(ixcSecretFile($id,'-status'),[
        'connection_status'=>$connectionStatus,
        'last_test_at'=>gmdate('c'),
        'tool_count'=>count($names),
        'server_tools_capability'=>$advertisesTools
    ]);
    ixcReply([
        'success'=>true,
        'connected'=>true,
        'ready'=>$hasTools,
        'connection_status'=>$connectionStatus,
        'server_tools_capability'=>$advertisesTools,
        'tool_count'=>count($names),
        'tool_pages'=>$pageCount,
        'tools'=>array_slice($names,0,50),
        'message'=>$hasTools ? 'Ferramentas consultadas.' : $emptyMessage
    ]);
} catch (Throwable $e) {
    try {
        ixcStoreFile(ixcSecretFile($id,'-status'),[
            'connection_status'=>'error',
            'last_test_at'=>gmdate('c'),
            'tool_count'=>0,
            'server_tools_capability'=>false
        ]);
    } catch (Throwable $ignored) {
        // Keep the original MCP error as the API response.
    }
    ixcReply(['success'=>false,'message'=>$e->getMessage()],502);
}