<?php
declare(strict_types=1);

namespace JRC\Collector;

/** Strict GET-only GenieACS NBI reader; never posts TR-069 tasks. */
final class NbiReadOnly
{
    private string $url;
    private string $user;
    private string $password;

    public function __construct(string $url, string $user = '', string $password = '')
    {
        $this->url = Config::requireSafeUrl($url, true);
        $this->user = $user;
        $this->password = $password;
    }

    /** Avoid downloading WAN passwords and other sensitive CPE parameters. */
    public static function projection(): string
    {
        $paths = [
            '_id', '_deviceId', '_lastInform', '_tags',
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'InternetGatewayDevice.DeviceInfo.Temperature',
            'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
            'Device.ManagementServer.ConnectionRequestURL',
            'Device.DeviceInfo.UpTime',
            'Device.DeviceInfo.SoftwareVersion',
            'Device.DeviceInfo.HardwareVersion',
            'Device.WiFi.SSID.1.SSID',
            'Device.WiFi.Radio.1.OperatingFrequencyBand',
            'InternetGatewayDevice.LANDevice.1.WLANEthernetInterfaceConfig.1.MACAddress',
            'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BSSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.OperatingFrequencyBand',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature',
            'VirtualParameters.RXPower', 'VirtualParameters.gettemp',
            'VirtualParameters.Temperature', 'Device.Optical.Interface.1.RxPower',
        ];
        for ($i = 1; $i <= 8; ++$i) {
            $stem = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.' . $i;
            $paths[] = $stem . '.WANPPPConnection.1.Username';
            $paths[] = $stem . '.WANPPPConnection.1.ExternalIPAddress';
            $paths[] = $stem . '.WANIPConnection.1.ExternalIPAddress';
            $paths[] = $stem . '.WANIPConnection.1.MACAddress';
        }
        return implode(',', $paths);
    }

    /** Bounded, deterministic pagination. Reject duplicates instead of publishing partial data. */
    public function all(int $pageSize = 100, int $maxPages = 1000): array
    {
        if ($pageSize < 1 || $pageSize > 500 || $maxPages < 1 || $maxPages > 1000) {
            throw new \InvalidArgumentException('Paginacao invalida');
        }
        $out = [];
        $seen = [];
        for ($page = 0; $page < $maxPages; ++$page) {
            $params = http_build_query([
                'limit' => $pageSize,
                'skip' => $pageSize * $page,
                'sort' => json_encode(['_id' => 1]),
                'projection' => self::projection()
            ], '', '&', PHP_QUERY_RFC3986);
            $ch = curl_init($this->url . '/devices/?' . $params);
            if ($ch === false) throw new \RuntimeException('Falha ao iniciar NBI');
            $opts = [
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ];
            if ($this->user !== '' && $this->password !== '') {
                $opts[CURLOPT_USERPWD] = $this->user . ':' . $this->password;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($raw) || $http !== 200) {
                throw new \RuntimeException('NBI leitura falhou: HTTP ' . $http);
            }
            $batch = json_decode($raw, true);
            if (!is_array($batch) || !array_is_list($batch)) {
                throw new \RuntimeException('NBI retornou lote invalido');
            }
            foreach ($batch as $device) {
                $id = is_array($device) ? (string)($device['_id'] ?? '') : '';
                if ($id === '' || isset($seen[$id])) {
                    throw new \RuntimeException('NBI duplicou/omitiu ID durante paginacao');
                }
                $seen[$id] = true;
                $out[] = $device;
            }
            if (count($batch) < $pageSize) return $out;
        }
        throw new \RuntimeException('NBI excedeu limite de paginas');
    }
}
