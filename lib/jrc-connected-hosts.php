<?php
/* JRC_HOSTS_DISPOSITIVOS_V3 — hosts Wi-Fi e cabo no card Dispositivos (TR-181 + TR-098). */

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos((string) $haystack, (string) $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, 0, strlen($needle)) === $needle;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }
        return substr((string) $haystack, -strlen($needle)) === $needle;
    }
}

function jrcCwmpValue($node, $key = null)
{
    if ($key !== null) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return null;
        }
        $node = $node[$key];
    }
    if ($node === null || $node === '') {
        return null;
    }
    if (is_array($node) && array_key_exists('_value', $node)) {
        return $node['_value'];
    }
    if (is_array($node)) {
        return null;
    }
    return $node;
}

function jrcCwmpFirst($node, array $keys)
{
    foreach ($keys as $key) {
        $value = jrcCwmpValue($node, $key);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return null;
}

function jrcCwmpTimestamp($node)
{
    if (!is_array($node)) {
        return null;
    }
    if (!empty($node['_timestamp'])) {
        return $node['_timestamp'];
    }
    foreach (['IPAddress', 'PhysAddress', 'MACAddress', 'Active', 'HostName'] as $key) {
        if (is_array($node[$key] ?? null) && !empty($node[$key]['_timestamp'])) {
            return $node[$key]['_timestamp'];
        }
    }
    return null;
}

function jrcCwmpChildren($node)
{
    if (!is_array($node)) {
        return [];
    }
    $out = [];
    foreach ($node as $key => $child) {
        if (!is_array($child)) {
            continue;
        }
        if (is_string($key) && str_starts_with($key, '_')) {
            continue;
        }
        $out[$key] = $child;
    }
    return $out;
}

function jrcCwmpTruthy($value)
{
    if ($value === true || $value === 1 || $value === 1.0) {
        return true;
    }
    $text = strtolower(trim((string) $value));
    return in_array($text, ['1', 'true', 'yes', 'on', 'enabled', 'up', 'associated', 'connected', 'active'], true);
}

function jrcCwmpFalsy($value)
{
    if ($value === false || $value === 0 || $value === 0.0) {
        return true;
    }
    $text = strtolower(trim((string) $value));
    return in_array($text, ['0', 'false', 'no', 'off', 'disabled', 'down', 'nolink', 'disconnected', 'inactive'], true);
}

function jrcNormalizeMac($value)
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $value));
    if (strlen($hex) !== 12) {
        return '';
    }
    if ($hex === '000000000000' || $hex === 'FFFFFFFFFFFF') {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

function jrcMacKey($mac)
{
    return strtolower(str_replace(':', '', $mac));
}

function jrcLooksLikeIp($value)
{
    $text = trim((string) $value);
    if ($text === '' || $text === '0.0.0.0' || $text === '::' || strcasecmp($text, 'N/A') === 0) {
        return '';
    }
    if (filter_var($text, FILTER_VALIDATE_IP)) {
        return $text;
    }
    return '';
}

function jrcWifiBandFromText($value)
{
    $text = strtolower((string) $value);
    if ($text === '') {
        return '';
    }
    if (str_contains($text, '2.4') || str_contains($text, '2,4') || str_contains($text, '2g') || str_contains($text, '2400')) {
        return '2.4';
    }
    if (preg_match('/(?:^|[^0-9])5(?:\.0)?(?:\s*)g/', $text) || str_contains($text, '5ghz') || str_contains($text, '5g ') || $text === '5' || str_contains($text, '5800') || str_contains($text, '5ghz')) {
        return '5';
    }
    return '';
}

function jrcClassifyInterface($interfaceType, $layer1)
{
    $hay = strtolower(trim((string) $interfaceType . ' ' . (string) $layer1));
    if ($hay === '') {
        return '';
    }
    if (preg_match('/wifi|wlan|802\.11|ssid|radio|wireless|associateddevice/', $hay)) {
        return 'WiFi';
    }
    if (preg_match('/ethernet|lanethernet|eth\.|cable|rj45|bridging|lan\s*\d/', $hay)) {
        return 'Ethernet';
    }
    if (strcasecmp((string) $interfaceType, 'Ethernet') === 0 || strcasecmp((string) $interfaceType, 'LAN') === 0) {
        return 'Ethernet';
    }
    return '';
}

function jrcLanPortFromPath($path)
{
    $text = (string) $path;
    if (preg_match('/LANEthernetInterfaceConfig\.(\d+)/i', $text, $match)) {
        return (int) $match[1];
    }
    if (preg_match('/Ethernet\.Interface\.(\d+)/i', $text, $match)) {
        return (int) $match[1];
    }
    if (preg_match('/\bLAN\s*([1-8])\b/i', $text, $match)) {
        return (int) $match[1];
    }
    if (preg_match('/Bridging\.Bridge\.\d+\.Port\.(\d+)/i', $text, $match)) {
        $port = (int) $match[1];
        return $port > 1 ? $port - 1 : $port;
    }
    return 0;
}

function jrcSetNestedValue(array &$root, array $parts, $leaf)
{
    $cur =& $root;
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (!isset($cur[$part]) || !is_array($cur[$part])) {
            $cur[$part] = [];
        }
        $cur =& $cur[$part];
    }
    if (is_array($leaf) && array_key_exists('_value', $leaf)) {
        $cur['_value'] = $leaf['_value'];
        if (!empty($leaf['_timestamp'])) {
            $cur['_timestamp'] = $leaf['_timestamp'];
        }
        if (!empty($leaf['_type'])) {
            $cur['_type'] = $leaf['_type'];
        }
        return;
    }
    if (!is_array($leaf)) {
        $cur['_value'] = $leaf;
        return;
    }
    foreach ($leaf as $key => $value) {
        $cur[$key] = $value;
    }
}

function jrcHydrateDottedPaths($device)
{
    if (!is_array($device)) {
        return [];
    }
    $dotted = [];
    foreach ($device as $key => $node) {
        if (!is_string($key) || !str_contains($key, '.')) {
            continue;
        }
        if (!preg_match('/^(Device|InternetGatewayDevice)\./', $key)) {
            continue;
        }
        $dotted[$key] = $node;
    }
    foreach ($dotted as $key => $node) {
        jrcSetNestedValue($device, explode('.', $key), $node);
    }
    return $device;
}

function jrcEthernetPortMap($device)
{
    $map = [];
    $legacy = $device['InternetGatewayDevice']['LANDevice']['1']['LANEthernetInterfaceConfig'] ?? [];
    foreach (jrcCwmpChildren($legacy) as $index => $iface) {
        $n = (int) $index;
        if ($n >= 1 && $n <= 8) {
            $map['InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.' . $index] = $n;
        }
    }
    $down = [];
    foreach (jrcCwmpChildren($device['Device']['Ethernet']['Interface'] ?? []) as $index => $iface) {
        if (jrcCwmpTruthy(jrcCwmpValue($iface, 'Upstream'))) {
            continue;
        }
        $name = (string) (jrcCwmpValue($iface, 'Name') ?? jrcCwmpValue($iface, 'Alias') ?? '');
        if (preg_match('/wan|pon|veip|optical|xpon/i', $name)) {
            continue;
        }
        $down[] = ['index' => (string) $index, 'name' => $name];
    }
    usort($down, static function ($a, $b) {
        return ((int) $a['index']) <=> ((int) $b['index']);
    });
    $seq = 1;
    foreach ($down as $row) {
        $port = 0;
        if (preg_match('/LAN\s*([1-8])/i', $row['name'], $match)) {
            $port = (int) $match[1];
        }
        if ($port < 1 || $port > 8) {
            $port = $seq;
        }
        $map['Device.Ethernet.Interface.' . $row['index']] = $port;
        $seq++;
    }
    return $map;
}

function jrcLanPortFromLayer($layer1, array $portMap)
{
    $path = trim((string) $layer1);
    if ($path !== '' && isset($portMap[$path])) {
        return (int) $portMap[$path];
    }
    foreach ($portMap as $key => $port) {
        if ($key !== '' && $path !== '' && (str_starts_with($path, $key) || str_contains($path, (string) $key))) {
            return (int) $port;
        }
    }
    return jrcLanPortFromPath($path);
}

function jrcVendorName($mac, $hostname)
{
    $hostname = trim((string) $hostname);
    return $hostname !== '' ? $hostname : 'Dispositivo';
}

function jrcOwnMacs($device)
{
    $macs = [];
    $paths = [
        $device['InternetGatewayDevice']['LANDevice']['1']['LANEthernetInterfaceConfig']['1']['MACAddress'] ?? null,
        $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration']['1']['BSSID'] ?? null,
        $device['Device']['Ethernet']['Interface']['1']['MACAddress'] ?? null,
        $device['Device']['WiFi']['SSID']['1']['BSSID'] ?? null,
        $device['_deviceId']['_MACAddress'] ?? null,
    ];
    foreach ($paths as $node) {
        $mac = jrcNormalizeMac(is_array($node) ? jrcCwmpValue($node) : $node);
        if ($mac !== '') {
            $macs[jrcMacKey($mac)] = true;
        }
    }
    foreach (jrcCwmpChildren($device['Device']['WiFi']['SSID'] ?? []) as $ssid) {
        $bssid = jrcNormalizeMac(jrcCwmpValue($ssid, 'BSSID') ?? jrcCwmpValue($ssid, 'MACAddress'));
        if ($bssid !== '') {
            $macs[jrcMacKey($bssid)] = true;
        }
    }
    foreach (jrcCwmpChildren($device['Device']['Ethernet']['Interface'] ?? []) as $iface) {
        $mac = jrcNormalizeMac(jrcCwmpValue($iface, 'MACAddress'));
        if ($mac !== '') {
            $macs[jrcMacKey($mac)] = true;
        }
    }
    return $macs;
}

function jrcWifiBandMap($device)
{
    $map = [];
    $wifi = $device['Device']['WiFi'] ?? [];
    $radios = [];
    foreach (jrcCwmpChildren($wifi['Radio'] ?? []) as $index => $radio) {
        $band = jrcWifiBandFromText(
            jrcCwmpValue($radio, 'OperatingFrequencyBand')
            ?? jrcCwmpValue($radio, 'OperatingStandards')
            ?? jrcCwmpValue($radio, 'X_HW_FrequencyBand')
            ?? $index
        );
        if ($band === '' && ((string) $index === '1' || (string) $index === '2')) {
            $band = (string) $index === '1' ? '2.4' : '5';
        }
        $radios[(string) $index] = $band;
        $map['Device.WiFi.Radio.' . $index] = $band;
    }
    foreach (jrcCwmpChildren($wifi['SSID'] ?? []) as $index => $ssid) {
        $lower = (string) (jrcCwmpValue($ssid, 'LowerLayers') ?? '');
        $band = '';
        if (preg_match('/Device\.WiFi\.Radio\.(\d+)/i', $lower, $match)) {
            $band = $radios[$match[1]] ?? '';
        }
        if ($band === '') {
            $band = jrcWifiBandFromText(jrcCwmpValue($ssid, 'OperatingFrequencyBand') ?? $lower);
        }
        if ($band === '' && ((string) $index === '1' || (string) $index === '2')) {
            $band = (string) $index === '1' ? '2.4' : '5';
        }
        $map['Device.WiFi.SSID.' . $index] = $band;
        $map[(string) $index] = $band;
    }
    foreach (jrcCwmpChildren($wifi['AccessPoint'] ?? []) as $index => $ap) {
        $ref = (string) (jrcCwmpValue($ap, 'SSIDReference') ?? '');
        $band = $map[$ref] ?? '';
        if ($band === '' && preg_match('/SSID\.(\d+)/i', $ref, $match)) {
            $band = $map[$match[1]] ?? '';
        }
        if ($band === '' && ((string) $index === '1' || (string) $index === '2')) {
            $band = (string) $index === '1' ? '2.4' : '5';
        }
        $map['Device.WiFi.AccessPoint.' . $index] = $band;
    }
    foreach (jrcCwmpChildren($device['InternetGatewayDevice']['LANDevice'] ?? []) as $lanId => $lan) {
        foreach (jrcCwmpChildren($lan['WLANConfiguration'] ?? []) as $wlanId => $wlan) {
            $band = jrcWifiBandFromText(
                jrcCwmpValue($wlan, 'OperatingFrequencyBand')
                ?? jrcCwmpValue($wlan, 'X_HW_FrequencyBand')
                ?? jrcCwmpValue($wlan, 'X_CT-COM_FrequencyBand')
            );
            if ($band === '') {
                $channel = (int) (jrcCwmpValue($wlan, 'Channel') ?? 0);
                if ($channel >= 36) {
                    $band = '5';
                } elseif ($channel >= 1 && $channel <= 14) {
                    $band = '2.4';
                }
            }
            if ($band === '' && ((string) $wlanId === '1' || (string) $wlanId === '2')) {
                $band = (string) $wlanId === '1' ? '2.4' : '5';
            }
            $path = 'InternetGatewayDevice.LANDevice.' . $lanId . '.WLANConfiguration.' . $wlanId;
            $map[$path] = $band;
        }
    }
    return $map;
}

function jrcLookupWifiBand($layer1, $bandMap)
{
    $path = trim((string) $layer1);
    if ($path === '') {
        return '';
    }
    if (!empty($bandMap[$path])) {
        return $bandMap[$path];
    }
    if (preg_match('/(?:SSID|AccessPoint|Radio|WLANConfiguration)\.(\d+)/i', $path, $match)) {
        if (!empty($bandMap[$match[1]])) {
            return $bandMap[$match[1]];
        }
        foreach ($bandMap as $key => $band) {
            if ($band !== '' && is_string($key) && str_contains((string) $key, $path)) {
                return $band;
            }
        }
    }
    return jrcWifiBandFromText($path);
}

function jrcNewHost($mac, $ip, $hostname, $interfaceType, $active, $lanPort, $wifiBand)
{
    $hostname = trim((string) $hostname);
    $vendor = jrcVendorName($mac, $hostname !== '' ? $hostname : 'Dispositivo');
    if ($hostname === '') {
        $hostname = $vendor;
    }
    return [
        'hostname' => $hostname,
        'vendor' => $vendor,
        'ip_address' => $ip !== '' ? $ip : '-',
        'mac_address' => $mac,
        'interface_type' => $interfaceType !== '' ? $interfaceType : 'Ethernet',
        'active' => $active,
        'lan_port' => $lanPort > 0 ? $lanPort : null,
        'wifi_band' => $wifiBand,
    ];
}

function jrcMergeHost(array &$byMac, array $row, $prefer)
{
    $key = jrcMacKey($row['mac_address']);
    if ($key === '') {
        return;
    }
    if (!isset($byMac[$key])) {
        $row['_prefer'] = $prefer;
        $byMac[$key] = $row;
        return;
    }
    $cur = $byMac[$key];
    if (($cur['ip_address'] === '-' || $cur['ip_address'] === '') && $row['ip_address'] !== '-' && $row['ip_address'] !== '') {
        $cur['ip_address'] = $row['ip_address'];
    }
    if (($cur['hostname'] === '' || $cur['hostname'] === 'Dispositivo' || $cur['hostname'] === $cur['vendor']) && $row['hostname'] !== '' && $row['hostname'] !== 'Dispositivo') {
        $cur['hostname'] = $row['hostname'];
    }
    if (empty($cur['wifi_band']) && !empty($row['wifi_band'])) {
        $cur['wifi_band'] = $row['wifi_band'];
    }
    if (empty($cur['lan_port']) && !empty($row['lan_port'])) {
        $cur['lan_port'] = $row['lan_port'];
    }
    if ($cur['interface_type'] !== 'WiFi' && $row['interface_type'] === 'WiFi') {
        $cur['interface_type'] = 'WiFi';
    }
    if ($row['interface_type'] === 'Ethernet' && $cur['interface_type'] !== 'WiFi') {
        $cur['interface_type'] = 'Ethernet';
    }
    if (!empty($row['active'])) {
        $cur['active'] = true;
    }
    $byMac[$key] = $cur;
}

function jrcReadHostNode($host, $bandMap, $fallbackType, array $portMap = [])
{
    $ip = jrcLooksLikeIp(jrcCwmpFirst($host, [
        'IPAddress', 'IP4Address', 'IPv4Address', 'Address', 'IP', 'StaIP', 'STAIP', 'StaIpAddress',
        'AssociatedDeviceIPAddress', 'X_FH_IPAddress', 'X_FH_StaIP', 'X_HW_IPAddress',
        'X_CT-COM_IPAddress', 'X_CMCC_IPAddress',
    ]));
    if ($ip === '') {
        $ip = jrcLooksLikeIp(jrcCwmpFirst($host, ['IP6Address', 'IPv6Address']));
    }
    $mac = jrcNormalizeMac(jrcCwmpFirst($host, [
        'PhysAddress', 'MACAddress', 'MAC', 'Mac', 'StaMac', 'STAMac', 'ClientMAC', 'UserMAC',
        'AssociatedDeviceMACAddress', 'AssociatedDeviceMAC', 'Chaddr',
        'StaMac', 'StaMAC', 'StaMacAddress', 'X_FH_StaMac',
        'X_FH_MACAddress', 'X_HW_MACAddress', 'X_CT-COM_MACAddress', 'X_CMCC_MACAddress',
    ]));
    if ($mac === '') {
        return null;
    }
    $hostname = (string) (jrcCwmpFirst($host, [
        'HostName', 'Name', 'AssociatedDeviceName', 'StaHostName', 'X_FH_HostName', 'X_HW_HostName', 'Alias',
    ]) ?? '');
    $layer1 = (string) (jrcCwmpFirst($host, [
        'Layer1Interface', 'Layer3Interface', 'Interface', 'InterfaceType',
        'X_FH_Layer1Interface', 'X_HW_Layer1Interface',
    ]) ?? '');
    $interfaceRaw = (string) (jrcCwmpFirst($host, [
        'InterfaceType', 'X_FH_InterfaceType', 'X_HW_InterfaceType',
    ]) ?? '');
    $type = jrcClassifyInterface($interfaceRaw, $layer1);
    if ($type === '') {
        $type = $fallbackType;
    }
    $lanPort = ($type === 'WiFi') ? 0 : jrcLanPortFromLayer($layer1, $portMap);
    if ($lanPort > 0 && $type === '') {
        $type = 'Ethernet';
    }
    $wifiBand = $type === 'Ethernet' ? '' : jrcLookupWifiBand($layer1, $bandMap);
    if ($wifiBand === '') {
        $wifiBand = jrcWifiBandFromText(jrcCwmpFirst($host, [
            'OperatingFrequencyBand', 'X_HW_FrequencyBand', 'X_FH_WiFiBand',
        ]));
    }
    if ($wifiBand !== '' && $type === '') {
        $type = 'WiFi';
    }
    $activeNode = jrcCwmpFirst($host, ['Active', 'AssociatedDeviceActive', 'AssociationState']);
    $active = $activeNode === null ? true : jrcCwmpTruthy($activeNode);
    if ($activeNode !== null && jrcCwmpFalsy($activeNode)) {
        $active = false;
    }
    return jrcNewHost($mac, $ip, $hostname, $type, $active, $lanPort, $wifiBand);
}

function jrcCollectHostsFromTree($hosts, $bandMap, $fallbackType, array &$byMac, $ownMacs, array $portMap = [])
{
    foreach (jrcCwmpChildren($hosts) as $host) {
        $row = jrcReadHostNode($host, $bandMap, $fallbackType, $portMap);
        if ($row === null) {
            continue;
        }
        if (isset($ownMacs[jrcMacKey($row['mac_address'])])) {
            continue;
        }
        if ($row['active'] === false) {
            continue;
        }
        jrcMergeHost($byMac, $row, 'host');
    }
}

function jrcCollectAssociated($node, $band, $bandMap, $layerHint, array &$byMac, $ownMacs)
{
    foreach (jrcCwmpChildren($node) as $sta) {
        $mac = jrcNormalizeMac(jrcCwmpFirst($sta, [
            'MACAddress', 'AssociatedDeviceMACAddress', 'PhysAddress',
            'StaMac', 'StaMAC', 'StaMacAddress', 'X_FH_StaMac',
            'X_FH_MACAddress', 'X_HW_MACAddress',
        ]));
        if ($mac === '' || isset($ownMacs[jrcMacKey($mac)])) {
            continue;
        }
        $activeNode = jrcCwmpFirst($sta, ['Active', 'AssociatedDeviceActive', 'AssociationState']);
        if ($activeNode !== null && jrcCwmpFalsy($activeNode)) {
            continue;
        }
        $ip = jrcLooksLikeIp(jrcCwmpFirst($sta, [
            'IPAddress', 'AssociatedDeviceIPAddress', 'StaIP', 'StaIpAddress',
            'X_FH_IPAddress', 'X_FH_StaIP', 'X_HW_IPAddress',
        ]));
        $hostname = (string) (jrcCwmpFirst($sta, [
            'HostName', 'AssociatedDeviceName', 'StaHostName', 'X_FH_HostName',
        ]) ?? '');
        $wifiBand = $band !== '' ? $band : jrcLookupWifiBand($layerHint, $bandMap);
        jrcMergeHost($byMac, jrcNewHost($mac, $ip, $hostname, 'WiFi', true, 0, $wifiBand), 'wifi');
    }
}

function jrcCollectWifiStations($device, $bandMap, array &$byMac, $ownMacs)
{
    foreach (jrcCwmpChildren($device['Device']['WiFi']['AccessPoint'] ?? []) as $index => $ap) {
        $path = 'Device.WiFi.AccessPoint.' . $index;
        $ref = (string) (jrcCwmpValue($ap, 'SSIDReference') ?? $path);
        $band = $bandMap[$path] ?? $bandMap[$ref] ?? jrcLookupWifiBand($ref, $bandMap);
        jrcCollectAssociated($ap['AssociatedDevice'] ?? [], $band, $bandMap, $ref, $byMac, $ownMacs);
        jrcCollectAssociated($ap['X_FH_AssociatedDevice'] ?? [], $band, $bandMap, $ref, $byMac, $ownMacs);
        jrcCollectAssociated($ap['X_HW_AssociatedDevice'] ?? [], $band, $bandMap, $ref, $byMac, $ownMacs);
    }
    foreach (jrcCwmpChildren($device['InternetGatewayDevice']['LANDevice'] ?? []) as $lanId => $lan) {
        foreach (jrcCwmpChildren($lan['WLANConfiguration'] ?? []) as $wlanId => $wlan) {
            $path = 'InternetGatewayDevice.LANDevice.' . $lanId . '.WLANConfiguration.' . $wlanId;
            $band = $bandMap[$path] ?? '';
            foreach (['AssociatedDevice', 'X_HW_AssociatedDevice', 'X_FH_AssociatedDevice', 'X_CT-COM_AssociatedDevice', 'X_CMCC_AssociatedDevice', 'X_FH_WLANStaInfo', 'X_FH_StaInfo', 'StaInfo'] as $listName) {
                jrcCollectAssociated($wlan[$listName] ?? [], $band, $bandMap, $path, $byMac, $ownMacs);
            }
        }
    }
}

function jrcCollectDhcpClients($device, array &$byMac, $ownMacs, $bandMap, array $portMap)
{
    foreach (jrcCwmpChildren($device['Device']['DHCPv4']['Server']['Pool'] ?? []) as $pool) {
        foreach (jrcCwmpChildren($pool['Client'] ?? []) as $client) {
            $mac = jrcNormalizeMac(jrcCwmpFirst($client, ['Chaddr', 'MACAddress', 'PhysAddress']));
            if ($mac === '' || isset($ownMacs[jrcMacKey($mac)])) {
                continue;
            }
            $activeNode = jrcCwmpValue($client, 'Active');
            if ($activeNode !== null && jrcCwmpFalsy($activeNode)) {
                continue;
            }
            $ip = '';
            foreach (jrcCwmpChildren($client['IPv4Address'] ?? []) as $addr) {
                $ip = jrcLooksLikeIp(jrcCwmpValue($addr, 'IPAddress') ?? jrcCwmpValue($addr));
                if ($ip !== '') {
                    break;
                }
            }
            if ($ip === '') {
                $ip = jrcLooksLikeIp(jrcCwmpFirst($client, ['IPAddress', 'IP4Address']));
            }
            $hostname = (string) (jrcCwmpFirst($client, ['HostName', 'Alias', 'Name']) ?? '');
            jrcMergeHost($byMac, jrcNewHost($mac, $ip, $hostname, '', true, 0, ''), 'dhcp');
        }
    }
}

function jrcWalkClientBranch($node, $path, $depth, $bandMap, array $portMap, array &$byMac, $ownMacs)
{
    if (!is_array($node) || $depth > 9) {
        return;
    }
    $hint = strtolower((string) $path);
    $isClient = preg_match('/host|associated|station|client|lease|stainfo|wlanhost/', $hint) === 1;
    if ($isClient && $depth >= 3) {
        $fallback = '';
        if (preg_match('/wlan|wifi|associated|ssid|radio/', $hint) === 1) {
            $fallback = 'WiFi';
        } elseif (preg_match('/ethernet|lanethernet/', $hint) === 1) {
            $fallback = 'Ethernet';
        }
        $row = jrcReadHostNode($node, $bandMap, $fallback, $portMap);
        if ($row !== null && !isset($ownMacs[jrcMacKey($row['mac_address'])]) && $row['active'] !== false) {
            jrcMergeHost($byMac, $row, 'walk');
        }
    }
    foreach (jrcCwmpChildren($node) as $key => $child) {
        $name = (string) $key;
        if (preg_match('/^(WANDevice|WANConnectionDevice|Optical|VoiceService|Services|ManagementServer|Time|DeviceInfo|DownloadDiagnostics|UploadDiagnostics|TraceRoute|IPPing|UDPEcho|Layer3Forwarding|QueueManagement)$/i', $name) === 1) {
            continue;
        }
        jrcWalkClientBranch($child, $path . '.' . $name, $depth + 1, $bandMap, $portMap, $byMac, $ownMacs);
    }
}

function jrcExtractConnectedDevices($device, $lastInform = null)
{
    if (!is_array($device)) {
        return [];
    }
    $device = jrcHydrateDottedPaths($device);
    $bandMap = jrcWifiBandMap($device);
    $portMap = jrcEthernetPortMap($device);
    $ownMacs = jrcOwnMacs($device);
    $byMac = [];

    jrcCollectHostsFromTree($device['Device']['Hosts']['Host'] ?? [], $bandMap, '', $byMac, $ownMacs, $portMap);
    jrcCollectHostsFromTree($device['Device']['Hosts']['X_FH_Host'] ?? [], $bandMap, '', $byMac, $ownMacs, $portMap);
    foreach (jrcCwmpChildren($device['InternetGatewayDevice']['LANDevice'] ?? []) as $lan) {
        jrcCollectHostsFromTree($lan['Hosts']['Host'] ?? [], $bandMap, '', $byMac, $ownMacs, $portMap);
        jrcCollectHostsFromTree($lan['X_HW_LANHost']['Host'] ?? [], $bandMap, '', $byMac, $ownMacs, $portMap);
    }
    jrcCollectWifiStations($device, $bandMap, $byMac, $ownMacs);
    jrcCollectDhcpClients($device, $byMac, $ownMacs, $bandMap, $portMap);
    foreach (jrcCwmpChildren($device['InternetGatewayDevice'] ?? []) as $key => $child) {
        $name = (string) $key;
        if (preg_match('/LAN|WLAN|Host|WiFi|DHCP/i', $name) !== 1) {
            continue;
        }
        jrcWalkClientBranch($child, 'InternetGatewayDevice.' . $name, 1, $bandMap, $portMap, $byMac, $ownMacs);
    }
    jrcWalkClientBranch($device['Device']['Hosts'] ?? [], 'Device.Hosts', 1, $bandMap, $portMap, $byMac, $ownMacs);
    jrcWalkClientBranch($device['Device']['WiFi'] ?? [], 'Device.WiFi', 1, $bandMap, $portMap, $byMac, $ownMacs);
    jrcWalkClientBranch($device['Device']['DHCPv4'] ?? [], 'Device.DHCPv4', 1, $bandMap, $portMap, $byMac, $ownMacs);

    $out = [];
    foreach ($byMac as $row) {
        unset($row['_prefer']);
        if ($row['interface_type'] === 'WiFi' && ($row['wifi_band'] === '' || $row['wifi_band'] === null)) {
            $row['wifi_band'] = '';
        }
        if ($row['interface_type'] === '' || $row['interface_type'] === null) {
            if (!empty($row['wifi_band'])) {
                $row['interface_type'] = 'WiFi';
            } elseif (!empty($row['lan_port'])) {
                $row['interface_type'] = 'Ethernet';
            } else {
                $row['interface_type'] = 'Ethernet';
            }
        }
        $out[] = $row;
    }
    usort($out, static function ($a, $b) {
        $order = ['Ethernet' => 0, 'WiFi' => 1];
        $ta = $order[$a['interface_type']] ?? 2;
        $tb = $order[$b['interface_type']] ?? 2;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        $pa = (int) ($a['lan_port'] ?? 0);
        $pb = (int) ($b['lan_port'] ?? 0);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return strcasecmp((string) $a['hostname'], (string) $b['hostname']);
    });
    if (count($out) > 64) {
        $out = array_slice($out, 0, 64);
    }
    return $out;
}

function jrcPortStatus($value)
{
    $text = strtolower(trim((string) $value));
    if (in_array($text, ['up', 'connected', 'linkup', 'enabled', 'true', '1'], true)) {
        return 'up';
    }
    return 'down';
}

function jrcExtractLanPorts($device)
{
    if (!isset($device['Device']) && !isset($device['InternetGatewayDevice'])) {
        $device = jrcHydrateDottedPaths($device);
    }
    $ports = [];
    $legacy = $device['InternetGatewayDevice']['LANDevice']['1']['LANEthernetInterfaceConfig'] ?? [];
    foreach (jrcCwmpChildren($legacy) as $index => $iface) {
        $n = (int) $index;
        if ($n < 1 || $n > 8) {
            continue;
        }
        $status = jrcCwmpValue($iface, 'Status') ?? jrcCwmpValue($iface, 'Enable');
        $speed = jrcCwmpValue($iface, 'MaxBitRate') ?? jrcCwmpValue($iface, 'CurrentBitRate') ?? jrcCwmpValue($iface, 'Speed');
        $ports[] = [
            'port' => $n,
            'name' => 'LAN ' . $n,
            'status' => jrcPortStatus($status),
            'max_bit_rate' => $speed,
            'speed' => $speed,
        ];
    }
    if ($ports !== []) {
        usort($ports, static function ($a, $b) {
            return $a['port'] <=> $b['port'];
        });
        return $ports;
    }
    $portMap = jrcEthernetPortMap($device);
    foreach (jrcCwmpChildren($device['Device']['Ethernet']['Interface'] ?? []) as $index => $iface) {
        $path = 'Device.Ethernet.Interface.' . $index;
        $n = (int) ($portMap[$path] ?? 0);
        if ($n < 1) {
            continue;
        }
        $name = (string) (jrcCwmpValue($iface, 'Name') ?? jrcCwmpValue($iface, 'Alias') ?? ('LAN ' . $n));
        $status = jrcCwmpValue($iface, 'Status') ?? jrcCwmpValue($iface, 'Enable');
        $speed = jrcCwmpValue($iface, 'MaxBitRate') ?? jrcCwmpValue($iface, 'CurrentBitRate');
        $ports[] = [
            'port' => $n,
            'name' => preg_match('/LAN/i', $name) ? $name : ('LAN ' . $n),
            'status' => jrcPortStatus($status),
            'max_bit_rate' => $speed,
            'speed' => $speed,
        ];
    }
    usort($ports, static function ($a, $b) {
        return $a['port'] <=> $b['port'];
    });
    $uniq = [];
    foreach ($ports as $port) {
        $uniq[$port['port']] = $port;
    }
    return array_values($uniq);
}

function jrcCountMacsFromHosts($hosts, $ownMacs, array &$macs)
{
    foreach (jrcCwmpChildren($hosts) as $host) {
        $mac = jrcNormalizeMac(jrcCwmpFirst($host, [
            'PhysAddress', 'MACAddress', 'AssociatedDeviceMACAddress', 'AssociatedDeviceMAC',
            'X_FH_MACAddress', 'X_HW_MACAddress', 'Chaddr',
        ]));
        if ($mac === '' || isset($ownMacs[jrcMacKey($mac)])) {
            continue;
        }
        $activeNode = jrcCwmpFirst($host, ['Active', 'AssociatedDeviceActive', 'AssociationState']);
        if ($activeNode !== null && jrcCwmpFalsy($activeNode)) {
            continue;
        }
        $macs[jrcMacKey($mac)] = true;
    }
}

function jrcCountMacsFromAssociated($node, $ownMacs, array &$macs)
{
    foreach (jrcCwmpChildren($node) as $sta) {
        $mac = jrcNormalizeMac(jrcCwmpFirst($sta, [
            'MACAddress', 'AssociatedDeviceMACAddress', 'PhysAddress',
            'StaMac', 'StaMAC', 'StaMacAddress', 'X_FH_StaMac',
            'X_FH_MACAddress', 'X_HW_MACAddress',
        ]));
        if ($mac === '' || isset($ownMacs[jrcMacKey($mac)])) {
            continue;
        }
        $activeNode = jrcCwmpFirst($sta, ['Active', 'AssociatedDeviceActive', 'AssociationState']);
        if ($activeNode !== null && jrcCwmpFalsy($activeNode)) {
            continue;
        }
        $macs[jrcMacKey($mac)] = true;
    }
}

function jrcCountConnectedDevices($device)
{
    if (!is_array($device)) {
        return 0;
    }
    $ownMacs = jrcOwnMacs($device);
    $macs = [];
    jrcCountMacsFromHosts($device['Device']['Hosts']['Host'] ?? [], $ownMacs, $macs);
    jrcCountMacsFromHosts($device['Device']['Hosts']['X_FH_Host'] ?? [], $ownMacs, $macs);
    foreach (jrcCwmpChildren($device['InternetGatewayDevice']['LANDevice'] ?? []) as $lan) {
        jrcCountMacsFromHosts($lan['Hosts']['Host'] ?? [], $ownMacs, $macs);
        jrcCountMacsFromHosts($lan['X_HW_LANHost']['Host'] ?? [], $ownMacs, $macs);
        foreach (jrcCwmpChildren($lan['WLANConfiguration'] ?? []) as $wlan) {
            foreach (['AssociatedDevice', 'X_HW_AssociatedDevice', 'X_FH_AssociatedDevice', 'X_CT-COM_AssociatedDevice', 'X_CMCC_AssociatedDevice', 'X_FH_WLANStaInfo', 'X_FH_StaInfo', 'StaInfo'] as $listName) {
                jrcCountMacsFromAssociated($wlan[$listName] ?? [], $ownMacs, $macs);
            }
        }
    }
    foreach (jrcCwmpChildren($device['Device']['WiFi']['AccessPoint'] ?? []) as $ap) {
        jrcCountMacsFromAssociated($ap['AssociatedDevice'] ?? [], $ownMacs, $macs);
        jrcCountMacsFromAssociated($ap['X_FH_AssociatedDevice'] ?? [], $ownMacs, $macs);
        jrcCountMacsFromAssociated($ap['X_HW_AssociatedDevice'] ?? [], $ownMacs, $macs);
    }
    foreach (jrcCwmpChildren($device['Device']['DHCPv4']['Server']['Pool'] ?? []) as $pool) {
        foreach (jrcCwmpChildren($pool['Client'] ?? []) as $client) {
            $mac = jrcNormalizeMac(jrcCwmpFirst($client, ['Chaddr', 'MACAddress', 'PhysAddress']));
            if ($mac === '' || isset($ownMacs[jrcMacKey($mac)])) {
                continue;
            }
            $activeNode = jrcCwmpValue($client, 'Active');
            if ($activeNode !== null && jrcCwmpFalsy($activeNode)) {
                continue;
            }
            $macs[jrcMacKey($mac)] = true;
        }
    }
    return count($macs);
}

function jrcCollectLeafValues($node, $path, $depth, array &$found)
{
    if (!is_array($node) || $depth > 8 || count($found) > 400) {
        return;
    }
    if (array_key_exists('_value', $node) && !is_array($node['_value'])) {
        $found[$path] = $node['_value'];
    }
    foreach (jrcCwmpChildren($node) as $key => $child) {
        jrcCollectLeafValues($child, $path . '.' . $key, $depth + 1, $found);
    }
}

function jrcOpticalInRange($kind, $number)
{
    if ($kind === 'rx') {
        return $number <= -3 && $number >= -40;
    }
    if ($kind === 'tx') {
        return $number >= -1 && $number <= 8;
    }
    if ($kind === 'temperature') {
        return $number >= 20 && $number <= 80;
    }
    if ($kind === 'voltage') {
        return $number >= 2.8 && $number <= 3.8;
    }
    return false;
}

function jrcScaleOptical($kind, $value)
{
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float) $value;
    $log = null;
    if ($number > 0) {
        $log = 10 * log10($number / 10000);
    }
    if ($kind === 'rx') {
        $candidates = [
            $number,
            ($number > 0 && $number <= 40) ? -$number : null,
            $number / 100,
            -$number / 100,
            ($number / 100) - 40,
            $log,
        ];
    } elseif ($kind === 'tx') {
        $candidates = [
            $number,
            $number / 100,
            $number / 1000,
            $number >= 1000 ? $log : null,
        ];
    } elseif ($kind === 'temperature') {
        $candidates = [
            ($number > 0 && $number <= 85) ? $number : null,
            $number / 10,
            $number / 256,
            ($number * 100) / 256,
            ($number - 32) * 5 / 9,
        ];
    } elseif ($kind === 'voltage') {
        $candidates = [
            $number,
            $number / 10,
            $number / 100,
            $number / 1000,
            $number / 10000,
        ];
    } else {
        return null;
    }
    foreach ($candidates as $candidate) {
        if ($candidate !== null && is_finite($candidate) && jrcOpticalInRange($kind, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function jrcExtractOnuSignal($device)
{
    $empty = [
        'rx_power' => null,
        'tx_power' => null,
        'temperature' => null,
        'voltage' => null,
        'uptime' => null,
        'wan_status' => null,
    ];
    if (!is_array($device)) {
        return $empty;
    }
    $device = jrcHydrateDottedPaths($device);
    $leaves = [];
    foreach ([
        'InternetGatewayDevice.WANDevice' => $device['InternetGatewayDevice']['WANDevice'] ?? [],
        'InternetGatewayDevice.DeviceInfo' => $device['InternetGatewayDevice']['DeviceInfo'] ?? [],
        'Device.Optical' => $device['Device']['Optical'] ?? [],
        'Device.DeviceInfo' => $device['Device']['DeviceInfo'] ?? [],
        'VirtualParameters' => $device['VirtualParameters'] ?? [],
    ] as $path => $node) {
        jrcCollectLeafValues($node, $path, 1, $leaves);
    }
    $signal = $empty;
    $wanScore = -1;
    foreach ($leaves as $path => $value) {
        $name = strtolower((string) preg_replace('/^.*\./', '', (string) $path));
        if ($signal['rx_power'] === null && preg_match('/rxpower|receiveoptical|opticalsignal|rx_power/', $name)) {
            $signal['rx_power'] = jrcScaleOptical('rx', $value);
        }
        if ($signal['tx_power'] === null && preg_match('/txpower|transmitoptical|tx_power/', $name)) {
            $signal['tx_power'] = jrcScaleOptical('tx', $value);
        }
        if ($signal['temperature'] === null && preg_match('/transceivertemperature|temperature/', $name) && !preg_match('/cpu|board/', $name)) {
            $signal['temperature'] = jrcScaleOptical('temperature', $value);
        }
        if ($signal['voltage'] === null && preg_match('/supplyvoltage|voltage/', $name) && !preg_match('/bias|current/', $name)) {
            $signal['voltage'] = jrcScaleOptical('voltage', $value);
        }
        if ($signal['uptime'] === null && $name === 'uptime' && is_numeric($value) && (float) $value > 0) {
            $signal['uptime'] = (int) $value;
        }
        if (preg_match('/wanpppconnection|wanipconnection/', strtolower((string) $path)) && preg_match('/externalipaddress|connectionstatus|username/', $name)) {
            $score = 0;
            $status = null;
            if ($name === 'connectionstatus') {
                $text = strtolower(trim((string) $value));
                if (in_array($text, ['connected', 'up', 'online'], true)) {
                    $status = 'Connected';
                    $score = 3;
                }
            }
            if ($name === 'externalipaddress' && jrcLooksLikeIp($value) !== '') {
                $status = 'Connected';
                $score = 2;
            }
            if ($score > $wanScore && $status !== null) {
                $wanScore = $score;
                $signal['wan_status'] = $status;
            }
        }
    }
    foreach (['rx_power', 'tx_power', 'temperature', 'voltage'] as $key) {
        if ($signal[$key] !== null) {
            $signal[$key] = round($signal[$key], $key === 'temperature' || $key === 'voltage' ? 2 : 2);
        }
    }
    return $signal;
}

function jrcApplyConnectedHostsFast(array &$data, $device, $lastInform = null)
{
    try {
        $data['connected_devices_count'] = jrcCountConnectedDevices($device);
    } catch (Throwable $e) {
        if (!isset($data['connected_devices_count'])) {
            $data['connected_devices_count'] = 0;
        }
    }
}

function jrcApplyConnectedHosts(array &$data, $device, $lastInform = null)
{
    try {
        $hosts = jrcExtractConnectedDevices($device, $lastInform);
        $data['connected_devices'] = $hosts;
        $data['connected_devices_count'] = count($hosts);
        $wifi24 = [];
        $wifi5 = [];
        foreach ($hosts as $host) {
            if (($host['interface_type'] ?? '') !== 'WiFi' || empty($host['active'])) {
                continue;
            }
            if (($host['wifi_band'] ?? '') === '5') {
                $wifi5[] = $host;
            } elseif (($host['wifi_band'] ?? '') === '2.4') {
                $wifi24[] = $host;
            }
        }
        $data['wifi_clients_24ghz_devices'] = $wifi24;
        $data['wifi_clients_5ghz_devices'] = $wifi5;
        if (!isset($data['lan_ports']) || !is_array($data['lan_ports']) || $data['lan_ports'] === []) {
            $data['lan_ports'] = jrcExtractLanPorts($device);
        }
    } catch (Throwable $e) {
        if (!isset($data['connected_devices']) || !is_array($data['connected_devices'])) {
            $data['connected_devices'] = [];
        }
        if (!isset($data['connected_devices_count'])) {
            $data['connected_devices_count'] = count($data['connected_devices']);
        }
    }
}
