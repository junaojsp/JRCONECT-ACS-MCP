<?php
declare(strict_types=1);
require_once __DIR__ . '/JrcDeviceResources.php';

/** Transport receives method/path/JSON; refresh mode can enqueue one read of DeviceInfo only. */
function jrcProbeDeviceResources(callable $transport, string $serial, bool $refresh = false): array {
    $params = http_build_query(['query'=>json_encode(['_deviceId._SerialNumber'=>$serial]),
        'projection'=>'_id,Device.DeviceInfo,InternetGatewayDevice.DeviceInfo','limit'=>1], '', '&', PHP_QUERY_RFC3986);
    $getDevice = static function () use ($transport,$params): array {
        $response = $transport('GET', '/devices/?' . $params, null);
        if ($response['status'] !== 200 || !is_array($response['data']) || !isset($response['data'][0]['_id'])) {
            throw new RuntimeException('ONU não localizada na consulta NBI.');
        }
        return $response['data'][0];
    };
    $raw = $getDevice();
    $normalized = jrcDeviceResources($raw);
    $taskState = 'NAO_SOLICITADA'; $taskId = null; $object = null;
    if ($refresh && (!array_key_exists('cpu_usage_percent',$normalized) || !array_key_exists('memory_usage_percent',$normalized))) {
        if (isset($raw['Device']['DeviceInfo'])) $object = 'Device.DeviceInfo';
        elseif (isset($raw['InternetGatewayDevice']['DeviceInfo'])) $object = 'InternetGatewayDevice.DeviceInfo';
        if ($object === null) {
            $taskState = 'SEM_RAIZ_DEVICEINFO_NO_CACHE';
        } else {
            $query = http_build_query(['query'=>json_encode(['device'=>$raw['_id'],'name'=>'refreshObject',
                'objectName'=>['$in'=>[$object,$object . '.']]]),'projection'=>'_id,fault','limit'=>1], '', '&', PHP_QUERY_RFC3986);
            $pending = $transport('GET','/tasks/?' . $query,null);
            if ($pending['status'] !== 200 || !is_array($pending['data'])) throw new RuntimeException('Não foi possível verificar tarefas existentes.');
            if ($pending['data']) {
                $taskId = $pending['data'][0]['_id'] ?? null;
                $taskState = !empty($pending['data'][0]['fault']) ? 'TAREFA_EXISTENTE_COM_FALHA' : 'JA_AGENDADA';
            } else {
                $task = $transport('POST','/devices/' . rawurlencode((string)$raw['_id']) . '/tasks?timeout=8000&connection_request',
                    ['name'=>'refreshObject','objectName'=>$object]);
                if (!in_array($task['status'],[200,202],true)) throw new RuntimeException('A ONU não confirmou a solicitação de leitura.');
                $taskId = $task['data']['_id'] ?? null;
                $taskState = $task['status'] === 200 ? 'EXECUTADA' : 'AGUARDANDO_INFORM';
                // 202 is queued, never reported as collected. No loop/repeated POST.
                $raw = $getDevice();
                $normalized = jrcDeviceResources($raw);
            }
        }
    }
    $observed=[];
    $walk=static function ($node,string $path) use (&$walk,&$observed): void {
        if (!is_array($node)) return;
        if (array_key_exists('_value',$node)) {
            if (preg_match('/CPU|Memory|RAM|MemUsage/i',$path) && is_numeric($node['_value'])) {
                $observed[]=['parameter'=>$path,'value'=>0+$node['_value'],'type'=>$node['_type'] ?? null];
            }
            return;
        }
        foreach ($node as $key=>$child) {
            if (str_starts_with((string)$key,'_')) continue;
            $walk($child,$path===''?(string)$key:$path . '.' . $key);
        }
    };
    $walk($raw,'');
    return ['serial'=>$serial,'source'=>'GenieACS','refresh_state'=>$taskState,'object'=>$object,'task_id'=>$taskId,
        'cpu_status'=>array_key_exists('cpu_usage_percent',$normalized)?'DISPONIVEL':'AUSENTE_OU_NAO_MAPEADO',
        'memory_status'=>array_key_exists('memory_usage_percent',$normalized)?'DISPONIVEL':'AUSENTE_OU_NAO_MAPEADO',
        'normalized'=>$normalized,'observed_resource_parameters'=>$observed];
}
