<?php
declare(strict_types=1);
/** CLI-only: persist completed ACS inventory totals for true historical chart. */
if(PHP_SAPI!=='cli'){http_response_code(403);return;}
require_once __DIR__.'/../config/config.php';
try {
 $db=getDBConnection();
 $state=$db->query('SELECT snapshot_id,total,completed_at FROM jrc_device_inventory_state WHERE id=1')->fetch_assoc();
 if(!$state || (int)$state['total']<1)throw new RuntimeException('Snapshot ACS indisponivel');
 $db->query("CREATE TABLE IF NOT EXISTS jrc_dashboard_inventory_history (
   snapshot_id CHAR(32) NOT NULL PRIMARY KEY,
   completed_at DATETIME NOT NULL,
   total INT UNSIGNED NOT NULL,
   KEY idx_completed_at(completed_at)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $stmt=$db->prepare('INSERT IGNORE INTO jrc_dashboard_inventory_history (snapshot_id,completed_at,total) VALUES (?,?,?)');
 $count=(int)$state['total'];
 $stmt->bind_param('ssi',$state['snapshot_id'],$state['completed_at'],$count);
 $stmt->execute();
 $added=$stmt->affected_rows;
 $stmt->close();
 echo 'HISTORICO_OK snapshot='.substr((string)$state['snapshot_id'],0,8).' registros_novos='.$added.PHP_EOL;
} catch(Throwable $e) {
 error_log('JRC dashboard inventory history: '.get_class($e).' '.$e->getMessage());
 fwrite(STDERR,'HISTORICO_BLOQUEADO: '.$e->getMessage().PHP_EOL);
}
