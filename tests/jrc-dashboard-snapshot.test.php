<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/JrcDashboardSnapshot.php';

function same($actual, $expected, string $message): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) .
                                   ' actual=' . var_export($actual, true));
    }
}
$rows = [
    ['device_id'=>'D1','serial_number'=>'A','status'=>'online','rx_power'=>'-18.55',
     'last_inform_timestamp'=>100, 'pppoe_username'=>'clientA'],
    ['device_id'=>'D2','serial_number'=>'B','status'=>'offline','rx_power'=>'-24,2',
     'ixc_rx_power'=>-27.2,'last_inform_timestamp'=>300],
    ['device_id'=>'D3','serial_number'=>'C','status'=>'online','rx_power'=>'0',
     'last_inform_timestamp'=>200],
    ['device_id'=>'D4','serial_number'=>'D','status'=>'offline','rx_power'=>'-29.0',
     'last_inform_timestamp'=>400],
    ['device_id'=>'D5','serial_number'=>'E','status'=>'online','rx_power'=>'-21.0',
     'last_inform_timestamp'=>500],
];
same(JrcDashboardSnapshot::calculate($rows,'stats'), [
    'total'=>5,'online'=>3,'offline'=>2
], 'fleet stats');
same(JrcDashboardSnapshot::calculate($rows,'uplink'),[
    'excellent'=>1,'good'=>1,'fair'=>1,'poor'=>1,'no_signal'=>1,'total'=>5
], 'signal categories');
same(JrcDashboardSnapshot::validRx('-999'),null,'sentinel');
same(JrcDashboardSnapshot::validRx('0'),null,'zero is unknown');
same(JrcDashboardSnapshot::validRx('-25,6'),-25.6,'comma decimal');
same(JrcDashboardSnapshot::calculate($rows,'recent')[0]['device_id'], 'D5','recent order');
same(count(JrcDashboardSnapshot::calculate($rows,'recent')),5,'recent size');

$many = [];
for ($i=0;$i<14;$i++) $many[]=['device_id'=>(string)$i,'last_inform_timestamp'=>$i];
$top = JrcDashboardSnapshot::calculate($many,'recent');
same(count($top),10,'recent capped at 10');
same($top[0]['device_id'],'13','recent highest first');
same($top[9]['device_id'],'4','recent last');
echo "DASHBOARD_SNAPSHOT_TESTS_OK\n";
