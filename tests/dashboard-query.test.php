<?php
require __DIR__ . '/../lib/JrcRecentDevices.php';
function check($value) { if (!$value) throw new RuntimeException('Test failed'); }
$url = JrcRecentDevices::url(['host' => 'localhost', 'port' => 7557]);
parse_str(parse_url($url, PHP_URL_QUERY), $params);
check($params['limit'] === '10');
check(json_decode($params['sort'], true) === ['_lastInform' => -1]);
check(JrcRecentDevices::decode('[]', 200) === ['success' => true, 'data' => []]);
check(JrcRecentDevices::decode('[{"_id":"test"}]', 200)['data'][0]['_id'] === 'test');
foreach ([['{}', 200], ['null', 200], ['<html>error</html>', 200], ['[]', 500], ['[null]', 200], ['[{"foo":1}]', 200]] as [$body, $status]) {
    $failed = false;
    try { JrcRecentDevices::decode($body, $status); } catch (RuntimeException $e) { $failed = true; }
    check($failed);
}
echo "dashboard query: OK\n";
