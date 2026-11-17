<?php
declare(strict_types=1);
/**
 * Authenticated bulk read-only classification for the optical report.
 * Does not trigger GenieACS/IXC requests or any ONU command.
 */
require_once __DIR__.'/../config/config.php';
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success'=>false,'message'=>'Metodo nao permitido'],405);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) {
    jsonResponse(['success'=>false,'message'=>'Solicitacao excessiva'],413);
}
$payload=json_decode((string)file_get_contents('php://input'),true);
$serials=$payload['serials'] ?? null;
if (!is_array($serials) || !array_is_list($serials) ||
    count($serials) < 1 || count($serials)>100) {
    jsonResponse(['success'=>false,'message'=>'Lista invalida'],400);
}
$unique=[];
foreach ($serials as $serial) {
    if (!is_string($serial) ||
        preg_match('/^[A-Za-z0-9:-]{8,40}$/D',$serial)!==1) {
        jsonResponse(['success'=>false,'message'=>'Serial invalido'],400);
    }
    $unique[$serial]=true;
}
try {
    require_once __DIR__.'/../lib/JrcSnmpTelemetry.php';
    require_once __DIR__.'/../lib/JrcOnuConnectivity.php';
    $db=getDBConnection();
    $state=$db->query("SELECT snapshot_id,total,completed_at FROM jrc_device_inventory_state WHERE id=1");
    $info=$state ? $state->fetch_assoc() : null;
    if (!$info || (int)$info['total']<1) {
        jsonResponse(['success'=>false,'message'=>'Inventario indisponivel'],503);
    }
    $age=time()-strtotime((string)$info['completed_at']);
    if ($age<0 || $age>900) {
        jsonResponse(['success'=>false,'message'=>'Inventario desatualizado'],503);
    }
    $values=array_keys($unique);
    $placeholders=implode(',',array_fill(0,count($values),'?'));
    $sql="SELECT serial,payload_json FROM jrc_device_inventory_rows
           WHERE snapshot_id=? AND serial IN ($placeholders)";
    $stmt=$db->prepare($sql);
    $params=array_merge([(string)$info['snapshot_id']],$values);
    $stmt->bind_param(str_repeat('s',count($params)), ...$params);
    $stmt->execute();
    $rows=$stmt->get_result();
    $inventory=[];
    while ($row=$rows->fetch_assoc()) {
        $key=(string)$row['serial'];
        $decoded=json_decode((string)$row['payload_json'],true);
        if (!is_array($decoded) || isset($inventory[$key])) {
            throw new RuntimeException('Inventario duplicado ou invalido');
        }
        $inventory[$key]=$decoded;
    }
    $stmt->close();
    $snapshot=JrcSnmpTelemetry::enabled() ? JrcSnmpTelemetry::load() : null;
    $now=time();
    $classified=[];
    foreach ($values as $serial) {
        $device=$inventory[$serial] ?? null;
        if ($device===null) {
            $classified[$serial]=[
                'state'=>'nao_confirmado',
                'reason'=>'onu_nao_encontrada_no_inventario'
            ];
            continue;
        }
        $classified[$serial]=JrcOnuConnectivity::classify($device,$snapshot,$now);
    }
    jsonResponse([
        'success'=>true,
        'source'=>'jrc_inventory_plus_olt_snmp',
        'inventory_at'=>$info['completed_at'],
        'states'=>$classified,
    ]);
} catch (Throwable $e) {
    error_log('JRC ONU status classification: '.get_class($e));
    jsonResponse(['success'=>false,'message'=>'Classificacao indisponivel'],503);
}
