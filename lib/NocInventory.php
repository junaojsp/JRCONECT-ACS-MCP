<?php
namespace App;

/** Explicit read allowlist. Never return raw parameter trees or credential fields. */
class NocInventory
{
    private const RX = ['VirtualParameters.RXPower',
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower', 'Device.Optical.Interface.1.RxPower'];
    private const UPTIME = ['InternetGatewayDevice.DeviceInfo.UpTime', 'Device.DeviceInfo.UpTime'];
    private const FIRMWARE = ['InternetGatewayDevice.DeviceInfo.SoftwareVersion', 'Device.DeviceInfo.SoftwareVersion'];
    private const TEMP = ['VirtualParameters.gettemp', 'VirtualParameters.Temperature',
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature',
        'InternetGatewayDevice.DeviceInfo.Temperature'];
    private const EXTRA = [
        'cpu' => ['InternetGatewayDevice.DeviceInfo.ProcessStatus.CPUUsage', 'Device.DeviceInfo.ProcessStatus.CPUUsage'],
        'memory_total' => ['InternetGatewayDevice.DeviceInfo.MemoryStatus.Total', 'Device.DeviceInfo.MemoryStatus.Total'],
        'memory_free' => ['InternetGatewayDevice.DeviceInfo.MemoryStatus.Free', 'Device.DeviceInfo.MemoryStatus.Free'],
        'hosts' => ['InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries', 'Device.Hosts.HostNumberOfEntries'],
        'ntp' => ['InternetGatewayDevice.Time.Status', 'Device.Time.Status'],
        'voice' => ['InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.Line.1.Status', 'Device.Services.VoiceService.1.VoiceProfile.1.Line.1.Status'],
    ];

    public static function projection(bool $detail): array
    {
        $fields = array_merge(['_id', '_deviceId', '_lastInform'], self::RX, self::UPTIME, self::FIRMWARE);
        foreach (['InternetGatewayDevice.DeviceInfo', 'Device.DeviceInfo'] as $base) {
            foreach (['Manufacturer', 'ModelName', 'SerialNumber'] as $leaf) $fields[] = "$base.$leaf";
        }
        foreach (self::wanBases() as $base) {
            foreach (['ExternalIPAddress', 'ConnectionStatus'] as $leaf) $fields[] = "$base.$leaf";
        }
        $fields[] = 'Device.IP.Interface.1.IPv4Address.1.IPAddress';
        if ($detail) {
            $fields = array_merge($fields, self::TEMP, ['VirtualParameters.TXPower']);
            foreach (self::EXTRA as $paths) $fields = array_merge($fields, $paths);
            foreach (self::wanBases() as $base) {
                foreach (['Uptime', 'Stats.EthernetBytesReceived', 'Stats.EthernetBytesSent',
                    'Stats.TotalBytesReceived', 'Stats.TotalBytesSent'] as $leaf) $fields[] = "$base.$leaf";
            }
            foreach (['InternetGatewayDevice.DeviceInfo', 'Device.DeviceInfo'] as $base) {
                foreach (['HardwareVersion', 'Manufacturer', 'ModelName', 'SerialNumber'] as $leaf) $fields[] = "$base.$leaf";
            }
            foreach (self::wifiBases() as $base) {
                foreach (['SSID', 'Enable', 'Status'] as $leaf) $fields[] = "$base.$leaf";
            }
        }
        return array_values(array_unique($fields));
    }

    private static function wanBases(): array
    {
        $paths = [];
        // Known instances from the existing parser; no entire WAN subtree/passwords.
        for ($i = 1; $i <= 8; $i++) {
            foreach (['WANPPPConnection', 'WANIPConnection'] as $type) {
                $paths[] = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.$type.$i";
            }
        }
        return $paths;
    }

    private static function wifiBases(): array
    {
        $paths = [];
        for ($i = 1; $i <= 8; $i++) {
            $paths[] = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$i";
            $paths[] = "Device.WiFi.SSID.$i";
        }
        return $paths;
    }

    private static function read(array $device, string $path): array
    {
        $node = $device;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) return ['value' => null];
            $node = $node[$part];
        }
        $value = is_array($node) ? ($node['_value'] ?? null) : $node;
        if (!is_scalar($value) || (is_float($value) && !is_finite($value))) return ['value' => null];
        return ['value' => $value, 'path' => $path,
            'updated_at' => is_array($node) && is_string($node['_timestamp'] ?? null) ? $node['_timestamp'] : null];
    }

    private static function first(array $device, array $paths): array
    {
        foreach ($paths as $path) {
            $result = self::read($device, $path);
            if ($result['value'] !== null && $result['value'] !== '') return $result;
        }
        return ['value' => null];
    }

    public static function parse(array $device, ?array $ixc, int $now, bool $detail): array
    {
        require_once __DIR__ . '/NocIxcOptical.php';
        $text = static fn($v) => is_string($v) || is_numeric($v) ? substr((string) $v, 0, 512) : null;
        $serial = $text($device['_deviceId']['_SerialNumber'] ?? null)
            ?: $text(self::first($device, ['InternetGatewayDevice.DeviceInfo.SerialNumber', 'Device.DeviceInfo.SerialNumber'])['value']);
        $rx = ['value' => null, 'source' => null, 'updated_at' => null];
        foreach (NocIxcOptical::serialAliases($serial ?? '') as $alias) {
            $optical = $ixc['devices'][$alias] ?? null;
            if (is_array($optical) && ($optical['rx'] ?? null) !== null) {
                $rx = ['value' => $optical['rx'], 'source' => 'IXC', 'updated_at' => $optical['updated_at'] ?? null];
                break;
            }
        }
        if ($rx['value'] === null) {
            foreach (self::RX as $path) {
                $candidate = self::read($device, $path);
                $value = $candidate['value'];
                if (!is_numeric($value)) continue;
                $value = (float) $value;
                if ($value > 100) $value = $value / 100 - 40;
                $value = NocIxcOptical::normalizeRx($value);
                if ($value !== null) { $rx = array_merge($candidate, ['value' => $value, 'source' => 'TR-069']); break; }
            }
        }
        $last = $text($device['_lastInform'] ?? null);
        $stamp = $last === null ? false : strtotime($last);
        $status = $stamp !== false && $stamp <= $now + 60 && $stamp >= $now - 300 ? 'online' : 'offline';
        $wans = [];
        foreach (self::wanBases() as $base) {
            $ip = self::read($device, "$base.ExternalIPAddress");
            $state = self::read($device, "$base.ConnectionStatus");
            if ($ip['value'] === null && $state['value'] === null) continue;
            $wans[] = ['path' => $base, 'ip' => $text($ip['value']), 'status' => $text($state['value']),
                'updated_at' => $ip['updated_at'] ?? $state['updated_at'] ?? null,
                'uptime' => self::read($device, "$base.Uptime")['value'],
                'bytes_received' => self::first($device, ["$base.Stats.EthernetBytesReceived", "$base.Stats.TotalBytesReceived"]),
                'bytes_sent' => self::first($device, ["$base.Stats.EthernetBytesSent", "$base.Stats.TotalBytesSent"])];
        }
        $ip = null;
        foreach ($wans as $wan) {
            if ($wan['ip'] && $wan['ip'] !== '0.0.0.0' && ($ip === null || $wan['status'] === 'Connected')) $ip = $wan['ip'];
        }
        $ip = $ip ?? self::read($device, 'Device.IP.Interface.1.IPv4Address.1.IPAddress')['value'];
        $uptime = self::first($device, self::UPTIME);
        $row = ['id' => $text($device['_id'] ?? null), 'serial' => $serial,
            'model' => $text($device['_deviceId']['_ProductClass'] ?? null)
                ?: $text(self::first($device, ['InternetGatewayDevice.DeviceInfo.ModelName', 'Device.DeviceInfo.ModelName'])['value']),
            'manufacturer' => $text($device['_deviceId']['_Manufacturer'] ?? null)
                ?: $text(self::first($device, ['InternetGatewayDevice.DeviceInfo.Manufacturer', 'Device.DeviceInfo.Manufacturer'])['value']),
            'last_inform' => $last, 'status' => $status, 'rx' => $rx,
            'rx_band' => NocIxcOptical::rxBand($rx['value']), 'ip' => $text($ip),
            'uptime' => is_numeric($uptime['value']) && $uptime['value'] >= 0 ? (int) $uptime['value'] : null,
            'firmware' => $text(self::first($device, self::FIRMWARE)['value'])];
        if (!$detail) return $row;
        $temp = self::first($device, self::TEMP);
        $value = is_numeric($temp['value']) ? (float) $temp['value'] : null;
        if ($value !== null && $value > 1000) $value /= 256;
        $temp['value'] = $value !== null && is_finite($value) && $value >= -40 && $value <= 125 ? round($value, 1) : null;
        $tx = self::read($device, 'VirtualParameters.TXPower');
        $value = is_numeric($tx['value']) ? (float) $tx['value'] : null;
        $tx['value'] = $value !== null && is_finite($value) && $value >= -10 && $value <= 10 ? round($value, 2) : null;
        $wifi = [];
        foreach (self::wifiBases() as $base) {
            $ssid = self::read($device, "$base.SSID");
            if (!is_string($ssid['value']) || $ssid['value'] === '') continue;
            $enabled = self::read($device, "$base.Enable")['value'];
            $wifi[] = ['ssid' => $text($ssid['value']), 'path' => $base,
                'enabled' => in_array($enabled, [true, 1, '1', 'true'], true) ? true
                    : (in_array($enabled, [false, 0, '0', 'false'], true) ? false : null),
                'status' => $text(self::read($device, "$base.Status")['value']),
                'updated_at' => $ssid['updated_at'] ?? null];
        }
        $extra = [];
        foreach (self::EXTRA as $key => $paths) $extra[$key] = self::first($device, $paths);
        $percent = static fn($v) => is_numeric($v) && is_finite((float) $v) && $v >= 0 && $v <= 100 ? (float) $v : null;
        $extra['cpu']['value'] = $percent($extra['cpu']['value']);
        $total = $extra['memory_total']['value']; $free = $extra['memory_free']['value'];
        $memorySourceMatches = isset($extra['memory_total']['path'], $extra['memory_free']['path'])
            && substr($extra['memory_total']['path'], 0, -6) === substr($extra['memory_free']['path'], 0, -5);
        $extra['memory'] = ['value' => is_numeric($total) && is_numeric($free) && is_finite((float) $total)
            && $memorySourceMatches && is_finite((float) $free) && $total > 0 && $free >= 0 && $free <= $total
            ? round(100 * (1 - $free / $total), 1) : null];
        $extra['hosts']['value'] = is_numeric($extra['hosts']['value']) && $extra['hosts']['value'] >= 0 && $extra['hosts']['value'] <= 1000000
            ? (int) $extra['hosts']['value'] : null;
        return array_merge($row, ['temperature' => $temp, 'tx' => $tx, 'wan' => $wans, 'wifi' => $wifi,
            'telemetry' => $extra,
            'hardware' => $text(self::first($device, ['InternetGatewayDevice.DeviceInfo.HardwareVersion', 'Device.DeviceInfo.HardwareVersion'])['value'])]);
    }
}
