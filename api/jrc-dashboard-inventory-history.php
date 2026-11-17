<?php
declare(strict_types=1);
/** Read-only ACS inventory history. Does not poll ACS, CPE or IXC. */
require_once __DIR__.'/../config/config.php';
requireLogin();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try{
 $db=getDBConnection();
 $points=[];
 $state=$db->query('SELECT snapshot_id,total,completed_at FROM jrc_device_inventory_state WHERE id=1')->fetch_assoc();
 if(!$state)throw new RuntimeException('Sem snapshot');
 try{
  $stmt=$db->prepare('SELECT completed_at,total FROM jrc_dashboard_inventory_history WHERE completed_at >= DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY completed_at ASC LIMIT 1500');
  $stmt->execute();
  foreach($stmt->get_result() as $row){
   $n=(int)$row['total'];
   if($n>=0 && $n<=100000)$points[]=['at'=>(string)$row['completed_at'],'total'=>$n];
  }
  $stmt->close();
 }catch(Throwable $unused){
  // No history table yet: do not fabricate previous samples.
 }
 $current=['at'=>(string)$state['completed_at'],'total'=>(int)$state['total']];
 if($current['total']<0||$current['total']>100000)throw new RuntimeException('Total invalido');
 $found=false;
 foreach($points as $point){if($point['at']===$current['at'] && $point['total']===$current['total']){$found=true;break;}}
 if(!$found)$points[]=$current;
 usort($points,static fn($a,$b)=>strcmp($a['at'],$b['at']));
 $points=array_slice($points,-720);
 jsonResponse(['success'=>true,'basis'=>'genieacs_completed_inventory_snapshots',
  'points'=>$points,'available'=>count($points)>1,'current'=>$current,
  'description'=>'Equipamentos cadastrados por coleta; nao sao conexoes online']);
}catch(Throwable $e){
 error_log('JRC inventory timeline: '.get_class($e));
 jsonResponse(['success'=>false,'message'=>'Historico indisponivel'],503);
}
