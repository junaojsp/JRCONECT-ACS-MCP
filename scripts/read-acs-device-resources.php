<?php
declare(strict_types=1);
/** Cached audit; --refresh explicitly requests one DeviceInfo read, never sets parameters/reboots. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$serial=trim((string)($argv[1] ?? ''));
$refresh=in_array('--refresh',array_slice($argv,2),true);
if ($serial==='' || str_starts_with($serial,'--') || strlen($serial)>128 ||
    count(array_diff(array_slice($argv,2),['--refresh']))>0) {
    fwrite(STDERR,"Uso: php scripts/read-acs-device-resources.php SERIAL_DA_ONU [--refresh]\n");exit(1);
}
try {
    require_once '/var/www/gacs/config/config.php';
    require_once __DIR__ . '/../lib/JrcDeviceResourceProbe.php';
    $db=getDBConnection();
    $q=$db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1');
    $cred=$q?$q->fetch_assoc():null;
    if (!$cred || !in_array(trim((string)$cred['host']),['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('A auditoria exige NBI local configurado.');
    $port=(int)$cred['port'];if($port<1 || $port>65535) throw new RuntimeException('Porta NBI inválida.');
    $transport=static function(string $method,string $path,?array $payload) use ($port,$cred): array {
        $ch=curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json']]);
        if (($cred['username'] ?? '')!=='') curl_setopt($ch,CURLOPT_USERPWD,$cred['username'].':'.($cred['password'] ?? ''));
        if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if (!is_string($body)) throw new RuntimeException('NBI local não respondeu.');
        return ['status'=>$status,'data'=>json_decode($body,true,512,JSON_THROW_ON_ERROR)];
    };
    if($refresh) {fwrite(STDERR,"Consultando cache e solicitando no máximo uma leitura DeviceInfo, se necessária...\n");fflush(STDERR);}
    $result=jrcProbeDeviceResources($transport,$serial,$refresh);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
} catch(Throwable $error) {
    $safe=['A auditoria exige NBI local configurado.','Porta NBI inválida.','ONU não localizada na consulta NBI.',
        'Não foi possível verificar tarefas existentes.','A ONU não confirmou a solicitação de leitura.','NBI local não respondeu.'];
    $message=in_array($error->getMessage(),$safe,true)?$error->getMessage():'Não foi possível consultar os recursos no NBI local.';
    fwrite(STDERR,'AUDITORIA_FALHOU: '.$message.PHP_EOL);exit(1);
}
