<?php
require __DIR__ . '/../lib/FirmwareUpdates.php';
use App\FirmwareUpdates;
$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function rejects(callable $fn, string $message) {
    try { $fn(); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), $message), $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function fixture(): array {
    $dir = sys_get_temp_dir() . '/fw-test-' . bin2hex(random_bytes(8)); mkdir($dir);
    $id = str_repeat('a', 32); $content = 'official-test-image';
    file_put_contents($dir . '/' . $id . '.blob', $content);
    $f = ['id'=>$id, 'manufacturer'=>'Vendor', 'model'=>'ONU-1', 'hw_revision'=>'V1', 'version'=>'2.0',
        'sha256'=>hash('sha256', $content), 'storage_name'=>$id.'.blob', 'bytes'=>strlen($content), 'original_name'=>'firmware.bin', 'status'=>'nao_homologado'];
    file_put_contents($dir.'/catalog.json', json_encode([$f]));
    $state = (object)['writes'=>[], 'tasks'=>[], 'faults'=>[], 'postStatus'=>202, 'throwPost'=>false];
    $state->device = ['_id'=>'ABC-ONU-S%201', '_deviceId'=>['_OUI'=>'ABCDEF','_ProductClass'=>'ONU-1'], '_lastInform'=>gmdate('c'),
        'Device'=>['DeviceInfo'=>[]]];
    foreach (['Manufacturer'=>'Vendor', 'ModelName'=>'ONU-1', 'HardwareVersion'=>'V1', 'SoftwareVersion'=>'1.0', 'SerialNumber'=>'S1'] as $k=>$v) {
        $state->device['Device']['DeviceInfo'][$k] = ['_value'=>$v, '_timestamp'=>gmdate('c', time()-60)];
    }
    $transport = static function ($method, $path, $body, $file, $headers) use ($state) {
        if ($method === 'GET') {
            parse_str(parse_url($path, PHP_URL_QUERY), $params);
            $query = json_decode($params['query'], true);
            if (str_starts_with($path, '/devices/')) {
                check($query === ['_id'=>$state->device['_id']], 'exact device query');
                return ['status'=>200,'data'=>[$state->device]];
            }
            if (str_starts_with($path, '/tasks/')) return ['status'=>200,'data'=>$state->tasks];
            if (str_starts_with($path, '/faults/')) return ['status'=>200,'data'=>$state->faults];
            throw new RuntimeException('Unexpected read: '.$path);
        }
        $state->writes[] = [$method,$path,$body,$file,$headers];
        if ($method === 'PUT') { check(file_get_contents($file) === 'official-test-image', 'binary content preserved'); return ['status'=>201]; }
        if ($state->throwPost) throw new RuntimeException('simulated timeout after POST');
        return ['status'=>$state->postStatus, 'data'=>['_id'=>'task1']];
    };
    $service = new FirmwareUpdates($dir, $transport);
    $ticket = ['id'=>bin2hex(random_bytes(16)), 'device_id'=>$state->device['_id'], 'firmware_id'=>$id, 'bench'=>true,
        'previous_version'=>'1.0', 'serial'=>'S1', 'sha256'=>$f['sha256'], 'expires'=>time()+300];
    return [$service,$state,$ticket,$dir];
}
function cleanup($dir) { foreach (glob($dir.'/*') as $f) unlink($f); rmdir($dir); }

rejects(fn()=>FirmwareUpdates::authorize(['user_id'=>1,'role'=>'atendimento']), 'perfil');
rejects(fn()=>FirmwareUpdates::authorize(['role'=>'admin']), 'perfil');
FirmwareUpdates::authorize(['user_id'=>1,'role'=>'noc']);
[$s,$state,$t,$dir] = fixture();
try {
    rejects(fn()=>$s->preflight($t['firmware_id'],$t['device_id'],false), 'não homologada');
    $state->device['Device']['DeviceInfo']['HardwareVersion']['_value']='V2';
    rejects(fn()=>$s->preflight($t['firmware_id'],$t['device_id'],true), 'incompatível');
    $state->device['Device']['DeviceInfo']['HardwareVersion']['_value']='V1';
    $state->device['_lastInform']=gmdate('c',time()-3600);
    rejects(fn()=>$s->preflight($t['firmware_id'],$t['device_id'],true), 'comunicação recente');
    $state->device['_lastInform']=gmdate('c');
    $state->tasks=[['_id'=>'other']];
    rejects(fn()=>$s->preflight($t['firmware_id'],$t['device_id'],true), 'Já existe');
    $state->tasks=[];
    file_put_contents($dir.'/'.$t['firmware_id'].'.blob','corrupted');
    rejects(fn()=>$s->preflight($t['firmware_id'],$t['device_id'],true), 'integridade');
    check(count($state->writes)===0, 'validation never mutates NBI');
} finally { cleanup($dir); }

[$s,$state,$t,$dir] = fixture();
try {
    $expired = $t; $expired['expires']=time()-1;
    rejects(fn()=>$s->execute($expired,'1:admin'), 'expirada');
    $changed = $t; $changed['previous_version']='0.5';
    rejects(fn()=>$s->execute($changed,'1:admin'), 'mudou');
    check(count($state->writes)===0, 'expired/changed ticket never sends');
    $job = $s->execute($t,'1:admin');
    check($job['state']==='aguardando_confirmacao', '202 is not installed');
    check(count($state->writes)===2, 'one upload and one task');
    check(str_contains($state->writes[1][1], 'S%25201/tasks'), 'encoded device ID preserved');
    check($state->writes[1][2]['name']==='download', 'firmware download task');
    check(in_array('fileType: 1 Firmware Upgrade Image',$state->writes[0][4]), 'firmware metadata');
    $s->execute($t,'1:admin'); check(count($state->writes)===2, 'replay is idempotent');
    $another=$t; $another['id']=bin2hex(random_bytes(16));
    rejects(fn()=>$s->execute($another,'1:admin'), 'execução anterior');
    check($s->refresh($job['id'])['state']==='aguardando_confirmacao', 'old version cannot confirm');
    rejects(fn()=>$s->approve($job['id'],'1:admin'), 'teste de bancada concluído');
    // Simulate later Inform and new SoftwareVersion timestamp without a real CPE.
    $state->device['Device']['DeviceInfo']['SoftwareVersion']['_value']='2.0';
    check($s->refresh($job['id'])['state']==='aguardando_confirmacao', 'stale version timestamp cannot confirm');
    $state->device['Device']['DeviceInfo']['SoftwareVersion']['_timestamp']=gmdate('c',time()+2);
    $state->device['_lastInform']=gmdate('c',time()+2);
    $state->tasks=[['_id'=>'task1']];
    check($s->refresh($job['id'])['state']==='aguardando_confirmacao', 'pending task prevents success');
    $state->tasks=[];
    $state->faults=[['_id'=>$t['device_id'].':task_task1']];
    check($s->refresh($job['id'])['state']==='falha_dispositivo', 'task fault visible');
    $state->faults=[];
    check($s->refresh($job['id'])['state']==='concluido', 'fresh target version confirms result');
    $s->approve($job['id'],'1:admin');
    check($s->read('catalog')[0]['status']==='homologado', 'explicit bench approval');
    check($s->read('catalog')[0]['approval_job']===$job['id'], 'approval audit link');
    check(count($state->writes)===2, 'refresh and approval never resend');
    $state->device['Device']['DeviceInfo']['SoftwareVersion']['_value']='1.0';
    check($s->preflight($t['firmware_id'],$t['device_id'],false)['bench']===false, 'approved image eligible for individual');
} finally { cleanup($dir); }

foreach ([true,false] as $timeout) {
    [$s,$state,$t,$dir] = fixture();
    try {
        $state->throwPost=$timeout; $state->postStatus=500;
        $job=$s->execute($t,'1:admin');
        check($job['state']==='envio_incerto', 'ambiguous POST retained');
        $s->execute($t,'1:admin');
        check(count($state->writes)===2, 'ambiguous request not retried');
        $new=$t; $new['id']=bin2hex(random_bytes(16));
        rejects(fn()=>$s->execute($new,'1:admin'), 'execução anterior');
    } finally { cleanup($dir); }
}
// TR-098 fallback is supported as well as Device (TR-181).
$id=FirmwareUpdates::identity(['_id'=>'x','_deviceId'=>['_SerialNumber'=>'S','_Manufacturer'=>'V','_ProductClass'=>'P'],
    'InternetGatewayDevice'=>['DeviceInfo'=>['HardwareVersion'=>['_value'=>'H'],'SoftwareVersion'=>['_value'=>'F','_timestamp'=>'2026-01-01T00:00:00Z']]]]);
check($id['model']==='P' && $id['serial']==='S' && $id['version']==='F' && $id['versionTime']!=='', 'TR-098 identity');
echo "OK: $checks checks; no real ACS requests.\n";
