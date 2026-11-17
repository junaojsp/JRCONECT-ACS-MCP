<?php
/** Normalize cached TR-098/TR-181 resource parameters without issuing device commands. */
function jrcDeviceResources(array $raw): array {
    $read = static function (string $path) use ($raw) {
        $node = $raw;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) return null;
            $node = $node[$key];
        }
        return is_array($node) ? ($node['_value'] ?? null) : $node;
    };
    $number = static function ($value) {
        if (is_string($value)) $value = trim(str_replace('%', '', $value));
        return is_numeric($value) ? (float)$value : null;
    };
    $result = ['cpu_usage_percent' => null, 'memory_usage_percent' => null,
               'cpu_resource_source' => null, 'memory_resource_source' => null];
    foreach (['Device.DeviceInfo.ProcessStatus.CPUUsage',
              'InternetGatewayDevice.DeviceInfo.ProcessStatus.CPUUsage',
              'Device.DeviceInfo.CPUUsage', 'InternetGatewayDevice.DeviceInfo.CPUUsage'] as $path) {
        $value = $number($read($path));
        if ($value !== null && $value >= 0 && $value <= 100) {
            $result['cpu_usage_percent'] = $value;
            $result['cpu_resource_source'] = $path;
            break;
        }
    }
    foreach (['Device.DeviceInfo.MemoryStatus', 'InternetGatewayDevice.DeviceInfo.MemoryStatus'] as $path) {
        $total = $number($read($path . '.Total'));
        $free = $number($read($path . '.Free'));
        if ($total !== null && $total > 0 && $free !== null && $free >= 0 && $free <= $total) {
            $result['memory_usage_percent'] = round(100 * ($total - $free) / $total, 1);
            $result['memory_total_kib'] = $total;
            $result['memory_free_kib'] = $free;
            $result['memory_resource_source'] = $path;
            break;
        }
    }
    // Keep already-populated parser values if this model exposes different vendor paths.
    return array_filter($result, static function ($value) { return $value !== null; });
}
