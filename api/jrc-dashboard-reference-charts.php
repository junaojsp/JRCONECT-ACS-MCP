<?php
declare(strict_types=1);
/**
 * Only inventory snapshot aggregates. No GenieACS, IXC or ONU requests.
 * "Clientes com/sem TR-069" needs distinct eligible-client totals from IXC;
 * do not mistake inventory device count or ixc_matched for that denominator.
 */
require_once __DIR__.'/../config/config.php';
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

try {
    $db=getDBConnection();
    $result=$db->query('SELECT snapshot_id,total,completed_at FROM jrc_device_inventory_state WHERE id=1');
    $state=$result?$result->fetch_assoc():null;
    if (!$state || (int)$state['total'] < 1 || (int)$state['total']>100000) {
        jsonResponse(['success'=>false,'message'=>'Inventario indisponivel'],503);
    }
    $generation=(string)$state['snapshot_id'];
    $cacheKey='jrc:overview:reference:v1:'.$generation;
    $cached=null;
    if (function_exists('apcu_fetch') && (!function_exists('apcu_enabled') || apcu_enabled())) {
        $hit=false;
        $candidate=apcu_fetch($cacheKey,$hit);
        if ($hit && is_array($candidate)) $cached=$candidate;
    }
    if ($cached===null) {
        $sql="SELECT
            COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.manufacturer'))),''),
                     'Nao identificado') AS manufacturer,
            COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.product_class'))),''),
                     'Nao identificado') AS model,
            COUNT(*) AS n
            FROM jrc_device_inventory_rows WHERE snapshot_id=?
            GROUP BY manufacturer, model ORDER BY n DESC";
        $stmt=$db->prepare($sql);
        $stmt->bind_param('s',$generation);
        $stmt->execute();
        $rows=$stmt->get_result();
        $makers=[];$models=[];$counted=0;
        while($row=$rows->fetch_assoc()){
            $n=(int)$row['n'];
            if($n<1)throw new RuntimeException('Contagem invalida');
            $manufacturer=mb_substr((string)$row['manufacturer'],0,100);
            $model=mb_substr((string)$row['model'],0,100);
            $makers[$manufacturer]=($makers[$manufacturer]??0)+$n;
            $models[$model]=($models[$model]??0)+$n;
            $counted+=$n;
        }
        $stmt->close();
        if ($counted!==(int)$state['total']) {
            throw new RuntimeException('Snapshot incompleto durante agregacao');
        }
        arsort($makers,SORT_NUMERIC);arsort($models,SORT_NUMERIC);
        $cached=[
            'manufacturers'=>array_map(static fn($name,$n)=>['name'=>$name,'count'=>$n],
                array_keys($makers),array_values($makers)),
            'models'=>array_map(static fn($name,$n)=>['name'=>$name,'count'=>$n],
                array_keys($models),array_values($models)),
        ];
        if (function_exists('apcu_store') && (!function_exists('apcu_enabled') || apcu_enabled())) {
            apcu_store($cacheKey,$cached,300);
        }
    }
    $age=time()-strtotime((string)$state['completed_at']);
    jsonResponse([
        'success'=>true,
        'source'=>'jrc_background_inventory',
        'snapshot'=>$generation,
        'cached_at'=>$state['completed_at'],
        'stale'=>$age<0||$age>900,
        'total'=>(int)$state['total'],
        'manufacturers'=>$cached['manufacturers'],
        'models'=>$cached['models'],
        'client_tr069_coverage'=>[
            'ready'=>false,
            'reason'=>'ixc_distinct_eligible_clients_not_integrated',
            'with_tr069'=>null,
            'without_tr069'=>null
        ]
    ]);
} catch (Throwable $e) {
    error_log('JRC dashboard chart snapshot: '.get_class($e));
    jsonResponse(['success'=>false,'message'=>'Indicadores indisponiveis'],503);
}
