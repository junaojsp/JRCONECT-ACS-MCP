<?php
/* JRC_WIFI_GRAVACAO_V2 — o WLAN 2 do painel grava no rádio 5 GHz real da ONU. */

function jrcWifiNodeHas($node, $key)
{
    return is_array($node) && array_key_exists($key, $node);
}

function jrcWifiBand(array $wlan)
{
    foreach (['Channel', 'X_FH_Channel', 'X_HW_Channel'] as $key) {
        $channel = $wlan[$key]['_value'] ?? null;
        if (!is_numeric($channel)) {
            continue;
        }
        $number = (int) $channel;
        if ($number >= 36) {
            return '5';
        }
        if ($number >= 1 && $number <= 14) {
            return '2.4';
        }
    }
    $blob = '';
    foreach (['OperatingFrequencyBand', 'X_HW_FrequencyBand', 'X_FH_RFBand', 'X_CT-COM_RFBand', 'Standard'] as $key) {
        $blob .= ' ' . strtolower((string) ($wlan[$key]['_value'] ?? ''));
    }
    if (preg_match('/5\s*ghz|5g|5\.8/', $blob)) {
        return '5';
    }
    if (preg_match('/2\.4|2,4/', $blob)) {
        return '2.4';
    }
    return '';
}

function jrcWifiLegacyInstances($device)
{
    $root = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'] ?? [];
    if (!is_array($root)) {
        return [];
    }
    $instances = [];
    foreach ($root as $key => $wlan) {
        if (!is_array($wlan) || !ctype_digit((string) $key)) {
            continue;
        }
        $instances[(string) $key] = $wlan;
    }
    return $instances;
}

function jrcWifiResolveIndex($device, $wlanIndex)
{
    $requested = (string) max(1, (int) $wlanIndex);
    $instances = jrcWifiLegacyInstances($device);
    if ($instances === []) {
        return $requested;
    }
    $band = isset($instances[$requested]) ? jrcWifiBand($instances[$requested]) : '';
    if ($requested !== '2') {
        return isset($instances[$requested]) ? $requested : $requested;
    }
    if ($band === '5') {
        return $requested;
    }
    foreach ($instances as $key => $wlan) {
        if (jrcWifiBand($wlan) === '5') {
            return (string) $key;
        }
    }
    if (isset($instances['5'])) {
        return '5';
    }
    return isset($instances[$requested]) ? $requested : '5';
}

function jrcWifiWriteParameters($device, $ssid, $password, $wlanIndex, $securityMode)
{
    $device = is_array($device) ? $device : [];
    $index = jrcWifiResolveIndex($device, $wlanIndex);
    $password = (string) $password;
    $wantPassword = (string) $securityMode !== 'None' && $password !== '';

    $legacy = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'][$index] ?? null;
    if (is_array($legacy)) {
        $parameters = [[
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}.SSID",
            (string) $ssid,
            'xsd:string',
        ]];
        if ($wantPassword) {
            $parameters[] = jrcWifiLegacyPassword($legacy, $index, $password);
        }
        return $parameters;
    }

    $ssidNode = $device['Device']['WiFi']['SSID'][$index] ?? null;
    $security = $device['Device']['WiFi']['AccessPoint'][$index]['Security'] ?? null;
    if (is_array($ssidNode) || is_array($security)) {
        $parameters = [];
        if (is_array($ssidNode)) {
            $parameters[] = ["Device.WiFi.SSID.{$index}.SSID", (string) $ssid, 'xsd:string'];
        }
        if ($wantPassword && is_array($security)) {
            $parameters[] = ["Device.WiFi.AccessPoint.{$index}.Security.KeyPassphrase", $password, 'xsd:string'];
        }
        if ($parameters !== []) {
            return $parameters;
        }
    }

    $base = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}";
    $parameters = [["{$base}.SSID", (string) $ssid, 'xsd:string']];
    if ($wantPassword) {
        $parameters[] = ["{$base}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'];
    }
    return $parameters;
}

function jrcWifiLegacyPassword(array $legacy, $index, $password)
{
    $base = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$index}";
    $psk = $legacy['PreSharedKey']['1'] ?? null;
    if (is_array($psk) && jrcWifiNodeHas($psk, 'KeyPassphrase')) {
        return ["{$base}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'];
    }
    if (is_array($psk) && jrcWifiNodeHas($psk, 'PreSharedKey')) {
        return ["{$base}.PreSharedKey.1.PreSharedKey", $password, 'xsd:string'];
    }
    foreach (['X_FH_PreSharedKey', 'X_CT-COM_WPAKey', 'X_HW_WPAKey', 'KeyPassphrase'] as $key) {
        if (jrcWifiNodeHas($legacy, $key)) {
            return ["{$base}.{$key}", $password, 'xsd:string'];
        }
    }
    return ["{$base}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'];
}
