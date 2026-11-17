<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/JrcDeviceResourceProbe.php';
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function device(bool $values=false): array {
    $info=['ModelName'=>['_value'=>'TestModel']];
    if($values)$info+=['ProcessStatus'=>['CPUUsage'=>['_value'=>0]],'MemoryStatus'=>['Total'=>['_value'=>1000],'Free'=>['_value'=>250]]];
    return ['_id'=>'000-Test-serial','InternetGatewayDevice'=>['DeviceInfo'=>$info]];
}
function simulate(array $responses,bool $refresh): array {
    $calls=[];
    $transport=static function($method,$path,$payload)use(&$calls,&$responses){$calls[]=[$method,$path,$payload];if(!$responses)throw new RuntimeException('Unexpected request');return array_shift($responses);};
    $result=jrcProbeDeviceResources($transport,'serial',$refresh);
    check(!$responses,'Unused responses');return [$result,$calls];
}
function response(array $data,int $status=200):array{return ['status'=>$status,'data'=>$data];}
[$result,$calls]=simulate([response([device()])],false);
check(count($calls)===1 && $result['refresh_state']==='NAO_SOLICITADA','Default audit must be read-only');
[$result,$calls]=simulate([response([device()]),response([]),response(['_id'=>'task'],202),response([device()])],true);
check($result['refresh_state']==='AGUARDANDO_INFORM' && $result['cpu_status']!=='DISPONIVEL','202 cannot mean collected');
check($calls[2][2]===['name'=>'refreshObject','objectName'=>'InternetGatewayDevice.DeviceInfo'],'Task scope must be DeviceInfo read only');
check(count(array_filter($calls,fn($c)=>$c[0]==='POST'))===1,'Only one POST allowed');
[$result,$calls]=simulate([response([device()]),response([['_id'=>'existing']])],true);
check($result['refresh_state']==='JA_AGENDADA' && count($calls)===2,'Pending task must not duplicate');
[$result,$calls]=simulate([response([device()]),response([]),response(['_id'=>'done']),response([device(true)])],true);
check($result['normalized']['cpu_usage_percent']===0.0,'Real CPU zero must survive');
check($result['normalized']['memory_usage_percent']===75.0,'RAM percent must use total/free');
[$result,$calls]=simulate([response([device(true)])],true);
check(count($calls)===1,'No refresh when both readings exist');
echo "PASS cached audit, queued response, one scoped read, no duplicate, CPU zero, memory calculation\n";
