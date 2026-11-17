<?php
declare(strict_types=1);
/**
 * Offline-only client coverage calculation. No contracts, no changes to CPEs.
 * Counts DISTINCT IXC clients owning PPPoE logins (not all raw customer records).
 * Never publishes partial IXC pages or inferred client associations.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/config.php';

function ixcRows(string $base, string $token, string $table): array {
    $rows=[]; $seen=[]; $limit=100;
    for ($page=1; $page<=200; $page++) {
        $payload=json_encode([
            'qtype'=>$table.'.id','query'=>'0','oper'=>'>','page'=>(string)$page,
            'rp'=>(string)$limit,'sortname'=>$table.'.id','sortorder'=>'asc'
        ]);
        $ch=curl_init(rtrim($base,'/').'/webservice/v1/'.$table);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json',
                'ixcsoft: listar','Authorization: Basic '.base64_encode($token)]
        ]);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response===false || $status!==200) throw new RuntimeException('Falha HTTP IXC em '.$table);
        $json=json_decode($response,true);
        if (!is_array($json) || ($json['type']??'')==='error') throw new RuntimeException('Resposta IXC invalida');
        $batch=$json['registros']??$json['records']??(array_is_list($json)?$json:null);
        if (!is_array($batch)) throw new RuntimeException('Paginacao IXC invalida');
        if (!$batch) {
            if ($page===1) throw new RuntimeException('Tabela IXC vazia');
            break;
        }
        foreach($batch as $record) {
            if (!is_array($record)) throw new RuntimeException('Registro IXC invalido');
            $id=(string)($record['id']??'');
            if ($id==='' || isset($seen[$id])) throw new RuntimeException('Paginacao IXC duplicada');
            $seen[$id]=true;$rows[]=$record;
        }
        if(count($batch)<$limit) {
            if(isset($json['total']) && is_numeric($json['total']) && count($rows)<(int)$json['total'])
                throw new RuntimeException('IXC paginacao incompleta');
            break;
        }
        if($page===200) throw new RuntimeException('Limite IXC atingido');
    }
    return $rows;
}
function loginKey($value): string {
    return is_scalar($value) ? strtolower(trim((string)$value)) : '';
}
$lock=fopen(sys_get_temp_dir().'/jrc-client-tr069-coverage.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { fwrite(STDERR,"Coleta ja em andamento\n"); return; }
try {
    $url=trim((string)getenv('JRC_IXC_URL'));
    $token=trim((string)getenv('JRC_IXC_TOKEN'));
    if(!preg_match('~^https://[^/]+~i',$url) || $token==='') throw new RuntimeException('Credenciais IXC ausentes');
    $db=getDBConnection();
    $state=$db->query('SELECT snapshot_id,total FROM jrc_device_inventory_state WHERE id=1')->fetch_assoc();
    if(!$state || (int)$state['total']<1) throw new RuntimeException('Sem snapshot ACS');
    $generation=(string)$state['snapshot_id'];
    $clients=ixcRows($url,$token,'cliente');
    $users=ixcRows($url,$token,'radusuarios');
    $validClients=[];
    foreach($clients as $row) {
        $id=trim((string)($row['id']??''));
        if($id!=='') $validClients[$id]=true;
    }
    $loginOwner=[];$ambiguous=[];$eligible=[];
    foreach($users as $row) {
        $id=trim((string)($row['id_cliente']??''));
        $login=loginKey($row['login']??null);
        if($id==='' || !isset($validClients[$id]) || $login==='') continue;
        $eligible[$id]=true;
        if(isset($loginOwner[$login]) && $loginOwner[$login]!==$id) $ambiguous[$login]=true;
        else $loginOwner[$login]=$id;
    }
    if(!$eligible || !$loginOwner) throw new RuntimeException('IXC sem relacao cliente-login verificavel');
    // Read only the published snapshot; no scans of GenieACS in HTTP handlers.
    $stmt=$db->prepare('SELECT payload_json FROM jrc_device_inventory_rows WHERE snapshot_id=?');
    $stmt->bind_param('s',$generation);$stmt->execute();$result=$stmt->get_result();
    $seenDevices=0;$matched=[];
    while($row=$result->fetch_assoc()) {
        $seenDevices++;
        $dev=json_decode((string)$row['payload_json'],true);
        if(!is_array($dev)) throw new RuntimeException('Snapshot ACS invalido');
        $ids=[];
        foreach(['ixc_pppoe_username','pppoe_username'] as $field) {
            $login=loginKey($dev[$field]??null);
            if($login!=='' && !isset($ambiguous[$login]) && isset($loginOwner[$login]))
                $ids[$loginOwner[$login]]=true;
        }
        if(count($ids)===1) $matched[array_key_first($ids)]=true;
    }
    $stmt->close();
    if($seenDevices!==(int)$state['total']) throw new RuntimeException('Snapshot ACS alterado durante coleta');
    $total=count($eligible);$yes=count($matched);$no=$total-$yes;
    if($yes<0 || $no<0 || $yes>$total) throw new RuntimeException('Contagens invalidas');
    $db->query("CREATE TABLE IF NOT EXISTS jrc_client_tr069_coverage (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        snapshot_id CHAR(32) NOT NULL,
        total INT UNSIGNED NOT NULL,
        with_tr069 INT UNSIGNED NOT NULL,
        without_tr069 INT UNSIGNED NOT NULL,
        collected_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt=$db->prepare('INSERT INTO jrc_client_tr069_coverage
        (id,snapshot_id,total,with_tr069,without_tr069,collected_at)
        VALUES(1,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE
        snapshot_id=VALUES(snapshot_id),total=VALUES(total),
        with_tr069=VALUES(with_tr069),without_tr069=VALUES(without_tr069),
        collected_at=NOW()');
    $stmt->bind_param('siii',$generation,$total,$yes,$no);$stmt->execute();$stmt->close();
    echo 'COVERAGE_OK clientes_com_login='.$total.' com_tr069='.$yes.' sem_tr069='.$no.PHP_EOL;
} catch(Throwable $e) {
    error_log('JRC client coverage: '.get_class($e).' '.$e->getMessage());
    fwrite(STDERR,'COVERAGE_BLOQUEADA: '.$e->getMessage().PHP_EOL);
} finally { flock($lock,LOCK_UN);fclose($lock); }
