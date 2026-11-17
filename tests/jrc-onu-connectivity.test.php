<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/JrcSnmpTelemetry.php';
require_once dirname(__DIR__).'/lib/JrcOnuConnectivity.php';
function expectEqual($actual,$expected,$case): void {
    if ($actual !== $expected) throw new RuntimeException($case . ': '.var_export($actual,true));
}
$now = 1800000000;
$serial='FHTT1234ABCD';
$row=['serial'=>$serial,'state'=>'offline','state_polled_at_epoch'=>$now-60];
$snapshot=['schema'=>2,'total'=>1,'devices'=>[$serial=>$row],
 'completed_at_epoch'=>$now-60,
 'last_attempt'=>['success'=>true,'attempted_at_epoch'=>$now-90,
                  'finished_at_epoch'=>$now-50]];
$device=['serial_number'=>$serial,'product_class'=>'HG6143D',
 'software_version'=>'V1','last_inform_timestamp'=>$now-30,
 'rx_power'=>null,'rx_measured_at_epoch'=>0];
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['state'],
 'offline_confirmado','OLT offline even with Inform recent');
$snapshot['devices'][$serial]['state']='online';
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['state'],
 'online_sem_rx','OLT online without RX');
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['diagnosis'],
 'verificar_parametro_rx_modelo_firmware_presets_e_unidade','diagnostic firmware');
$device['rx_power']='-22.40';
$device['rx_measured_at_epoch']=$now-35;
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['state'],
 'online_com_rx','OLT online and fresh rx');
$device['rx_measured_at_epoch']=$now-86400;
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['state'],
 'online_sem_rx','old RX cannot be current');
$snapshot['completed_at_epoch']=$now-1200;
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['state'],
 'nao_confirmado','old OLT snapshot no offline claim');
$snapshot['completed_at_epoch']=$now-60;
$snapshot['last_attempt']['success']=false;
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['state'],
 'nao_confirmado','failed OLT collection no offline claim');
$snapshot['last_attempt']['success']=true;
$device['serial_number']='FHTT8765DCBA';
expectEqual(JrcOnuConnectivity::classify($device,$snapshot,$now)['state'],
 'nao_confirmado','unmatched serial cannot be assumed offline');
echo "JRC_ONU_CONNECTIVITY_TESTS_OK\n";
