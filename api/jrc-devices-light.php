<?php
declare(strict_types=1);
/**
 * JR Conect - lightweight inventory endpoint. Database snapshot ONLY.
 * No NBI, IXC, SNMP, telemetry, commands or CPE access on page loads.
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

try {
    $db = getDBConnection();
    $state = $db->query("SELECT snapshot_id,total,completed_at FROM jrc_device_inventory_state WHERE id=1");
    $meta = $state ? $state->fetch_assoc() : null;
    if (!$meta || (int)$meta['total'] < 1) {
        jsonResponse(['success'=>false,'message'=>'Inventario ainda nao coletado'],503);
    }
    $age = time() - strtotime((string)$meta['completed_at']);
    // This is a directory, not a live status monitor. Allow a stale
    // directory snapshot with conspicuous stale labeling; never claim
    // that status, RX or last inform is current.
    $stale = ($age < 0 || $age > 900);
    $search = trim((string)($_GET['q'] ?? ''));
    $manufacturer = trim((string)($_GET['manufacturer'] ?? ''));
    $model = trim((string)($_GET['model'] ?? ''));
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    if ($page === false || $page < 1 || $page > 10000 ||
        mb_strlen($search) > 100 || mb_strlen($manufacturer) > 70 ||
        mb_strlen($model) > 70) {
        jsonResponse(['success'=>false,'message'=>'Filtro invalido'],400);
    }
    $limit = 10;
    $offset = ($page - 1) * $limit;
    // Fields are indexed only by serial; JSON fields are filtered on request,
    // never computed at dashboard opening. Bound parameters prevent injection.
    $where = 'snapshot_id = ?';
    $params = [(string)$meta['snapshot_id']];
    if ($search !== '') {
        $where .= " AND (serial LIKE ? OR device_id LIKE ? OR
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.pppoe_username')) LIKE ? OR
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.customer_name')) LIKE ? OR
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.client_name')) LIKE ? OR
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.mac_address')) LIKE ? OR
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.ip_address')) LIKE ? OR
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.ip_wan')) LIKE ?)";
        $q = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $search) . '%';
        array_push($params,$q,$q,$q,$q,$q,$q,$q,$q);
    }
    if ($manufacturer !== '') {
        $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.manufacturer')) = ?";
        $params[] = $manufacturer;
    }
    if ($model !== '') {
        $where .= " AND (JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.product_class')) = ? OR
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.model')) = ?)";
        $params[] = $model;
        $params[] = $model;
    }
    $count = $db->prepare("SELECT COUNT(*) AS n FROM jrc_device_inventory_rows WHERE $where");
    $count->bind_param(str_repeat('s',count($params)),...$params);
    $count->execute();
    $total = (int)$count->get_result()->fetch_assoc()['n'];
    $count->close();

    $stmt=$db->prepare("SELECT device_id,serial,payload_json FROM jrc_device_inventory_rows
                          WHERE $where ORDER BY device_id LIMIT ? OFFSET ?");
    $types=str_repeat('s',count($params)).'ii';
    $paramsWithPage=array_merge($params,[$limit,$offset]);
    $stmt->bind_param($types,...$paramsWithPage);
    $stmt->execute();
    $result=$stmt->get_result();
    $items=[];
    while ($row=$result->fetch_assoc()) {
        $raw=json_decode((string)$row['payload_json'],true);
        if (!is_array($raw)) throw new RuntimeException('Snapshot invalido');
        $items[]=[
            'id'=>(string)$row['device_id'],
            'serial'=>(string)$row['serial'],
            'model'=>(string)($raw['product_class'] ?? $raw['model'] ?? ''),
            'manufacturer'=>(string)($raw['manufacturer'] ?? ''),
            'client'=>(string)($raw['customer_name'] ?? $raw['client_name'] ?? ''),
            'pppoe'=>(string)($raw['pppoe_username'] ?? ''),
            'mac'=>(string)($raw['mac_address'] ?? ''),
            'ip'=>(string)($raw['ip_wan'] ?? $raw['ip_address'] ?? ''),
            'last_inform'=>(string)($raw['last_inform'] ?? ''),
        ];
    }
    $stmt->close();
    jsonResponse([
        'success'=>true,'source'=>'jrc_background_inventory','snapshot'=>$meta['snapshot_id'],
        'cached_at'=>$meta['completed_at'],'stale'=>$stale,'total'=>$total,'page'=>$page,'per_page'=>$limit,
        'pages'=>(int)ceil($total/$limit),'items'=>$items
    ]);
} catch (Throwable $error) {
    error_log('JRC light inventory: '.get_class($error));
    jsonResponse(['success'=>false,'message'=>'Inventario indisponivel'],503);
}
