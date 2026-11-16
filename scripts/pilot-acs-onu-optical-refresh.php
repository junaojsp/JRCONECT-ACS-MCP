<?php
declare(strict_types=1);
/**
 * One-ONU optical refresh pilot. Default: read-only plan.
 * Explicit --execute --confirm-serial=... submits exactly ONE refreshObject
 * on an already-discovered optical RX parameter. No retries, no CPE writes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/JrcTr069OpticalTelemetry.php';

function pilotFail(string $reason): never {
    fwrite(STDERR, "PILOTO_BLOQUEADO=$reason\n"); exit(1);
}
function pilotArgs(array $args): array {
    $out = ['serial'=>'', 'execute'=>false, 'confirm'=>''];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--serial=')) $out['serial'] = substr($arg, 9);
        elseif ($arg === '--execute') $out['execute'] = true;
        elseif (str_starts_with($arg, '--confirm-serial=')) $out['confirm'] = substr($arg, 17);
        else pilotFail('ARGUMENTO_DESCONHECIDO');
    }
    if (!preg_match('/^[A-Za-z0-9_.:-]{4,80}$/D', $out['serial'])) pilotFail('SERIAL_INVALIDO');
    if ($out['execute'] && $out['confirm'] !== $out['serial']) pilotFail('CONFIRMACAO_SERIAL_OBRIGATORIA');
    return $out;
}
function pilotChoose(array $profile): array {
    if ($profile['optical_path_truncated']) pilotFail('DESCOBERTA_INCOMPLETA');
    if (($profile['last_inform_age_min'] ?? 99999) > 15) pilotFail('ONU_SEM_INFORM_RECENTE');
    if ($profile['status'] === 'LEITURA_RECENTE') pilotFail('RX_JA_ATUALIZADO');
    if (!in_array($profile['status'], ['LEITURA_DESATUALIZADA','PARAMETRO_SEM_VALOR','VALOR_NAO_NUMERICO','SEM_LEITURA'],true)) pilotFail('SEM_CAMINHO_RX_CONFIRMADO');
    $paths = array_values(array_filter($profile['rx_paths'],static fn(array $x):bool =>
        preg_match('/^(?:InternetGatewayDevice|Device)\\.[A-Za-z0-9_.-]+$/D',$x['path']) === 1 &&
        !str_contains($x['path'],'..') && $x['age_min'] !== null));
    if (count($paths) !== 1) pilotFail('CAMINHO_RX_AMBIGUO_OU_SEM_TIMESTAMP');
    $path = $paths[0]['path'];
    $parent = substr($path, 0, strrpos($path,'.'));
    return ['rx_path'=>$path,'object_name'=>$parent.'.'];
}
function pilotNbi(int $port,string $user,string $pass,string $method,string $uri,?array $body=null): array {
    $ch=curl_init('http://127.0.0.1:'.$port.$uri);
    if (!$ch) pilotFail('CURL_INDISPONIVEL');
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
      CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json']];
    if ($user!=='' && $pass!=='') $opts[CURLOPT_USERPWD]=$user.':'.$pass;
    if ($method==='POST') { $opts[CURLOPT_POST]=true; $opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR); }
    curl_setopt_array($ch,$opts);
    $res=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if (!is_string($res)) pilotFail('NBI_SEM_RESPOSTA');
    return ['status'=>$status,'data'=>json_decode($res,true)];
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'') !== __FILE__) pilotFail('EXECUCAO_DIRETA_OBRIGATORIA');
$args=pilotArgs(array_slice($argv,1));
$root='/var/www/gacs';
if (!is_file($root.'/config/config.php')) pilotFail('CONFIG_AUSENTE');
require_once $root.'/config/config.php';
$db=getDBConnection();
$r=$db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1');
$c=$r?$r->fetch_assoc():null;
if (!$c || !in_array(trim((string)$c['host']),['127.0.0.1','localhost','::1'],true)) pilotFail('NBI_NAO_LOCAL');
$port=(int)$c['port'];
if ($port<1 || $port>65535) pilotFail('PORTA_INVALIDA');
$user=(string)$c['username'];$pass=(string)$c['password'];
unset($c,$r,$db);
$query=rawurlencode(json_encode(['_deviceId._SerialNumber'=>$args['serial']],JSON_THROW_ON_ERROR));
$get=pilotNbi($port,$user,$pass,'GET','/devices/?query='.$query.'&limit=3');
if ($get['status']!==200 || !is_array($get['data'])) pilotFail('NBI_CONSULTA_FALHOU');
$matches=array_values(array_filter($get['data'],static fn($x):bool=>is_array($x) &&
    strcasecmp((string)($x['_deviceId']['_SerialNumber']??''),$args['serial'])===0));
if (count($matches)!==1) pilotFail('SERIAL_NAO_UNICO');
$device=$matches[0];$id=(string)($device['_id']??'');
if ($id==='' || strlen($id)>180 || preg_match('/[\\x00-\\x1f]/',$id)) pilotFail('ID_INVALIDO');
$profile=JrcTr069OpticalTelemetry::inspect($device);
$choice=pilotChoose($profile);
echo 'PILOTO_SERIAL='.$args['serial']."\n";
echo 'MODELO='.$profile['fingerprint']['model'].' FIRMWARE='.$profile['fingerprint']['firmware']."\n";
echo 'ESTADO_ANTES='.$profile['status'].' INFORM_IDADE_MIN='.$profile['last_inform_age_min']."\n";
echo 'CAMINHO_RX='.$choice['rx_path']."\n";
echo 'REFRESH_OBJECT='.$choice['object_name']."\n";
if (!$args['execute']) { echo "MODO=PLANEJAMENTO_SEM_COMANDO\n"; exit(0); }
// Refresh only the discovered optical object; no connection_request and no retry.
// A 202 means queued, NOT that the CPE delivered a fresh reading.
$result=pilotNbi($port,$user,$pass,'POST','/devices/'.rawurlencode($id).'/tasks',
  ['name'=>'refreshObject','objectName'=>$choice['object_name']]);
if (!in_array($result['status'],[200,202],true)) pilotFail('NBI_TASK_HTTP_'.$result['status']);
echo 'TAREFA_ENVIADA_HTTP='.$result['status']."\n";
echo "RESULTADO=AGUARDAR_NOVO_INFORM_E_CONFERIR_TIMESTAMP_RX\n";
