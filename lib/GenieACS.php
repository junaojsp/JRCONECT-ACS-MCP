<?php
namespace App;

/**
 * GenieACS API Client
 */
class GenieACS {
    private $host;
    private $port;
    private $username;
    private $password;
    private $baseUrl;

    public function __construct($host = null, $port = 7557, $username = null, $password = null) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = "http://{$this->host}:{$this->port}";
    }

    /**
     * Make HTTP request to GenieACS API
     */
    private function request($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Increased to 300 seconds (5 minutes) for large datasets (400+ devices)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Connection timeout 30 seconds

        // Add authentication if provided
        if ($this->username && $this->password) {
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        }

        // Set method and data
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => json_decode($response, true),
            'http_code' => $httpCode
        ];
    }

    /**
     * Test connection to GenieACS
     */
    public function testConnection() {
        $result = $this->request('/devices?limit=1');
        return $result['success'];
    }

    /**
     * Get all devices
     * @param array $query - MongoDB query
     * @param int $limit - Maximum number of devices to return (0 = no limit)
     * @param int $skip - Number of devices to skip (for pagination)
     */
    public function getDevices($query = [], $limit = 0, $skip = 0, $projection = []) {
    $params = [];

    if (!empty($query)) {
        $params[] = 'query=' . urlencode(json_encode($query));
    }

    if ($limit > 0) {
        $params[] = 'limit=' . $limit;
    }

    if ($skip > 0) {
        $params[] = 'skip=' . $skip;
    }

    if (!empty($projection)) {
        $params[] = 'projection=' . urlencode(implode(',', $projection));
    }

    $queryString = !empty($params) ? '?' . implode('&', $params) : '';

    return $this->request('/devices/' . $queryString);
}

    /**
     * Get total device count
     */
    public function getDeviceCount($query = []) {
        $queryString = empty($query) ? '' : '?query=' . urlencode(json_encode($query));
        $result = $this->request('/devices/' . $queryString);

        if ($result['success'] && isset($result['data'])) {
            return ['success' => true, 'count' => count($result['data'])];
        }

        return ['success' => false, 'count' => 0];
    }

    /**
     * Get device by ID
     */
    public function getDevice($deviceId) {
        $query = ['_id' => $deviceId];
        $result = $this->request('/devices/?query=' . urlencode(json_encode($query)));

        if ($result['success'] && !empty($result['data'])) {
            return ['success' => true, 'data' => $result['data'][0]];
        }

        return ['success' => false, 'error' => 'Device not found'];
    }

    /**
     * Get device parameters
     */
    public function getDeviceParameters($deviceId) {
        return $this->getDevice($deviceId);
    }

    /**
     * Delete a device from GenieACS.
     */
    public function deleteDevice($deviceId) {
        $encodedId = rawurlencode($deviceId);
        return $this->request("/devices/{$encodedId}", 'DELETE');
    }

    /**
     * Execute task on device
     */
    public function executeTask($deviceId, $taskName, $params = []) {
        $endpoint = "/devices/{$deviceId}/tasks";
        $data = [
            'name' => $taskName
        ];

        if (!empty($params)) {
            $data['parameterValues'] = $params;
        }

        return $this->request($endpoint, 'POST', $data);
    }

    /**
     * Summon device (connection request)
     */
    public function summonDevice($deviceId) {
        // URL encode device ID to handle special characters
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?connection_request";
        return $this->request($endpoint, 'POST');
    }

    /**
     * Refresh device inform (force device to connect to ACS)
     */
    public function refreshInform($deviceId) {
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?connection_request";
        return $this->request($endpoint, 'POST');
    }

    /**
     * Add refresh task for specific parameter
     * This forces GenieACS to fetch the parameter value from device
     */
    public function addRefreshTask($deviceId, $parameterPath) {
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?connection_request";

        $data = [
            'name' => 'refreshObject',
            'objectName' => $parameterPath
        ];

        return $this->request($endpoint, 'POST', $data);
    }

    /**
     * Get parameter values from device (force fetch from device)
     * This creates a task to fetch specific parameters from the device
     *
     * @param string $deviceId Device ID
     * @param array $parameterNames Array of parameter names to fetch
     * @param int $timeout Timeout in milliseconds (default: 3000)
     * @return array Response with task ID
     */
    public function getParameterValues($deviceId, $parameterNames, $timeout = 3000) {
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?timeout={$timeout}&connection_request";

        $data = [
            'name' => 'getParameterValues',
            'parameterNames' => $parameterNames
        ];

        return $this->request($endpoint, 'POST', $data);
    }

    /**
     * Summon device and fetch admin credentials (VirtualParameters)
     * This is a convenience method that:
     * 1. Summons the device (connection request)
     * 2. Refreshes all VirtualParameters so GenieACS evaluates superAdmin/superPassword
     *
     * VirtualParameters are computed by GenieACS from actual device parameters.
     * Refreshing the VirtualParameters object triggers evaluation of ALL VirtualParameters,
     * including superAdmin and superPassword which read admin credentials from various device parameters.
     *
     * @param string $deviceId Device ID
     * @return array Response with success status
     */
    public function summonAndFetchAdminCredentials($deviceId) {
        $encodedId = rawurlencode($deviceId);

        // Connection request + Refresh VirtualParameters object
        $endpoint = "/devices/{$encodedId}/tasks?connection_request";

        // Refresh all VirtualParameters - this triggers evaluation of superAdmin/superPassword
        $data = [
            'name' => 'refreshObject',
            'objectName' => 'VirtualParameters'
        ];

        return $this->request($endpoint, 'POST', $data);
    }

    /** Queue refreshes for the TR-098 and TR-181 objects used in device details. */
    public function refreshDeviceDiagnostics($deviceId) {
        $paths = [
            'InternetGatewayDevice.WANDevice',
            'InternetGatewayDevice.LANDevice',
            // Explicit WAN subtree required by FiberHome HG6143D3 to refresh
            // PPP connection statistics used by the bandwidth monitor.
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice',
            'Device.PPP',
            'Device.IP',
            'Device.Ethernet',
        ];
        $results = [];
        foreach ($paths as $path) {
            $results[] = $this->addRefreshTask($deviceId, $path);
        }
        return [
            'success' => (bool)array_filter($results, fn($result) => !empty($result['success'])),
            'results' => $results,
        ];
    }

    /**
     * Reboot device
     */
    public function rebootDevice($deviceId) {
        return $this->executeTask($deviceId, 'reboot');
    }

    /**
     * Set parameter values on device
     *
     * @param string $deviceId Device ID
     * @param array $parameters Array of parameters to set [['path', 'value', 'type'], ...]
     * @param int $timeout Timeout in milliseconds (default: 3000)
     * @return array Response with success status
     *
     * Example:
     * $parameters = [
     *     ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'NewSSID', 'xsd:string'],
     *     ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase', 'NewPassword', 'xsd:string']
     * ];
     */
    public function setParameterValues($deviceId, $parameters, $timeout = 3000) {
        // URL encode device ID to handle special characters
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?timeout={$timeout}&connection_request";

        $data = [
            'name' => 'setParameterValues',
            'parameterValues' => $parameters
        ];

        return $this->request($endpoint, 'POST', $data);
    }

    /**
     * Set WiFi configuration (SSID, Password, and Security Mode)
     *
     * @param string $deviceId Device ID
     * @param string $ssid New WiFi SSID
     * @param string $password New WiFi Password (optional for Open network)
     * @param int $wlanIndex WLAN Configuration index (default: 1)
     * @param string $securityMode Security mode (WPA2PSK, WPAPSK, WPA2PSKWPAPSK, None)
     * @return array Response with success status
     */
    public function setWiFiConfig($deviceId, $ssid, $password = '', $wlanIndex = 1, $securityMode = 'WPA2PSK') {
        /* JRC_WIFI_GRAVACAO_V1 */
        if (!function_exists('jrcWifiWriteParameters')) {
            require_once __DIR__ . '/jrc-wifi-write.php';
        }
        $device = [];
        $loaded = $this->getDevice($deviceId);
        if (!empty($loaded['success']) && is_array($loaded['data'] ?? null)) {
            $device = $loaded['data'];
        }
        $parameters = \jrcWifiWriteParameters($device, (string) $ssid, (string) $password, (int) $wlanIndex, (string) $securityMode);
        if ($parameters === []) {
            return ['success' => false, 'error' => 'Não encontrei o Wi-Fi desta ONU para gravar.', 'http_code' => 0];
        }
        return $this->setParameterValues($deviceId, $parameters, 20000);
    }


    /**
     * Get device statistics
     */
    public function getDeviceStats() {
       $devices = $this->getDevices([], 0, 0, ['_id', '_lastInform']);

        if (!$devices['success']) {
            return ['success' => false, 'error' => 'Failed to fetch devices'];
        }

        $total = count($devices['data']);
        $online = 0;
        $offline = 0;

        foreach ($devices['data'] as $device) {
            // Check last inform time (within last 5 minutes = online)
            $lastInform = isset($device['_lastInform']) ? $device['_lastInform'] : null;

            $isOnline = false;

            if ($lastInform) {
                // Convert ISO 8601 to Unix timestamp
                $lastInformTimestamp = strtotime($lastInform);
                if ($lastInformTimestamp !== false) {
                    // Online if last inform within 5 minutes
                    $isOnline = (time() - $lastInformTimestamp) < 300;
                }
            }

            if ($isOnline) {
                $online++;
            } else {
                $offline++;
            }
        }

        return [
            'success' => true,
            'data' => [
                'total' => $total,
                'online' => $online,
                'offline' => $offline
            ]
        ];
    }

    /**
     * Parse device data for display
     */
    public function parseDeviceData($device) {
        $data = [];

        // Helper function to get nested parameter value
        $getParam = function($path) use ($device) {
            $keys = explode('.', $path);
            $value = $device;

            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return null;
                }
            }

            // GenieACS uses object format with _value field
            if (is_array($value) && isset($value['_value'])) {
                return $value['_value'];
            }

            // Fallback for direct values
            return is_array($value) ? null : $value;
        };

        // Basic info
        $data['device_id'] = $device['_id'] ?? 'N/A';
        $data['serial_number'] = $getParam('_deviceId._SerialNumber') ?? $getParam('InternetGatewayDevice.DeviceInfo.SerialNumber') ?? 'N/A';

        // MAC Address - try multiple paths
        $macAddress = $getParam('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress') ??
                     $getParam('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress') ??
                     $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BSSID') ??
                     $getParam('Device.Ethernet.Interface.1.MACAddress') ??
                     $getParam('_deviceId._MACAddress');

        // If MAC still not found, try to construct from OUI and serial number
        if (empty($macAddress) || $macAddress === 'N/A') {
            $oui = $getParam('_deviceId._OUI');
            $serial = $getParam('_deviceId._SerialNumber');

            // Some devices have MAC embedded in serial number (last 6 chars)
            if ($oui && $serial && strlen($serial) >= 6) {
                $lastSixChars = substr($serial, -6);
                // Check if last 6 chars are hex
                if (ctype_xdigit($lastSixChars)) {
                    // Format OUI properly (F86CE1 -> F8:6C:E1)
                    $ouiFormatted = strtoupper(substr($oui, 0, 2) . ':' .
                                               substr($oui, 2, 2) . ':' .
                                               substr($oui, 4, 2));

                    $macAddress = $ouiFormatted . ':' .
                                 strtoupper(substr($lastSixChars, 0, 2)) . ':' .
                                 strtoupper(substr($lastSixChars, 2, 2)) . ':' .
                                 strtoupper(substr($lastSixChars, 4, 2));
                }
            }
        }

        $data['mac_address'] = $macAddress ?? 'N/A';
        $data['manufacturer'] = $getParam('_deviceId._Manufacturer') ?? $getParam('InternetGatewayDevice.DeviceInfo.Manufacturer') ?? 'N/A';
        $data['oui'] = $getParam('_deviceId._OUI') ?? $getParam('InternetGatewayDevice.DeviceInfo.ManufacturerOUI') ?? 'N/A';
        $data['product_class'] = $getParam('_deviceId._ProductClass') ?? $getParam('InternetGatewayDevice.DeviceInfo.ProductClass') ?? 'N/A';
        $data['hardware_version'] = $getParam('InternetGatewayDevice.DeviceInfo.HardwareVersion') ?? 'N/A';
        $data['software_version'] = $getParam('InternetGatewayDevice.DeviceInfo.SoftwareVersion') ?? 'N/A';

        // Status
        $lastInform = isset($device['_lastInform']) ? $device['_lastInform'] : null;

        if ($lastInform) {
            $lastInformTimestamp = strtotime($lastInform);
            if ($lastInformTimestamp !== false) {
                $data['last_inform'] = date('Y-m-d H:i:s', $lastInformTimestamp);
                $data['status'] = (time() - $lastInformTimestamp) < 300 ? 'online' : 'offline';
            } else {
                $data['last_inform'] = 'N/A';
                $data['status'] = 'offline';
            }
        } else {
            $data['last_inform'] = 'N/A';
            $data['status'] = 'offline';
        }

        // Ping/Latency - try to get actual ping from VirtualParameters
        // GenieACS stores ping result in VirtualParameters.Ping
        $ping = $getParam('VirtualParameters.Ping') ??
                $getParam('VirtualParameters.ping') ??
                $getParam('VirtualParameters.PingResult');

        if ($data['status'] === 'online') {
            // If ping value exists and is numeric, use it
            if ($ping !== null && is_numeric($ping)) {
                $data['ping'] = intval($ping);
            } else {
                // Fallback: estimate based on inform freshness if actual ping not available
                if ($lastInformTimestamp) {
                    $timeSinceInform = time() - $lastInformTimestamp;

                    if ($timeSinceInform < 30) {
                        $data['ping'] = rand(1, 5);
                    } elseif ($timeSinceInform < 60) {
                        $data['ping'] = rand(5, 15);
                    } elseif ($timeSinceInform < 120) {
                        $data['ping'] = rand(15, 50);
                    } else {
                        $data['ping'] = rand(50, 200);
                    }
                } else {
                    $data['ping'] = null;
                }
            }
        } else {
            $data['ping'] = null;
        }

        // Network info
        $connectionUrl = $getParam('InternetGatewayDevice.ManagementServer.ConnectionRequestURL') ??
                        $getParam('Device.ManagementServer.ConnectionRequestURL') ?? 'N/A';

        $data['ip_tr069'] = $connectionUrl;

        // Extract IP address from ConnectionRequestURL
        $ipAddress = 'N/A';
        if ($connectionUrl && $connectionUrl !== 'N/A') {
            // Extract IP from URL format: http://IP:PORT/path or https://IP:PORT/path
            if (preg_match('/https?:\/\/([^:\/]+)/', $connectionUrl, $matches)) {
                $ipAddress = $matches[1];
            }
        }

        // Also try WAN IP if available
        if ($ipAddress === 'N/A') {
            $ipAddress = $getParam('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress') ??
                        $getParam('Device.IP.Interface.1.IPv4Address.1.IPAddress') ?? 'N/A';
        }

        $data['ip_address'] = $ipAddress;
        $data['uptime'] = $getParam('InternetGatewayDevice.DeviceInfo.UpTime') ??
                         $getParam('Device.DeviceInfo.UpTime') ?? 'N/A';

        // WiFi info - Huawei EG8145V5 commonly exposes 2.4 GHz and 5 GHz
        // on different WLANConfiguration indexes (often 1 and 5).
        $wifiSsids = [];
        for ($i = 1; $i <= 8; $i++) {
            $ssid = $getParam("InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}.SSID");
            if ($ssid !== null && trim((string)$ssid) !== '') {
                $wifiSsids[$i] = (string)$ssid;
            }
        }

        for ($i = 1; $i <= 8; $i++) {
            $ssid = $getParam("Device.WiFi.SSID.{$i}.SSID");
            if ($ssid !== null && trim((string)$ssid) !== '' && !isset($wifiSsids[$i])) {
                $wifiSsids[$i] = (string)$ssid;
            }
        }

        $wifiSsid = $wifiSsids[1] ?? (reset($wifiSsids) ?: null);
        $data['wifi_ssid'] = $wifiSsid ?? 'N/A';
        $data['wifi_ssid_24ghz'] = $wifiSsids[1] ?? $wifiSsid ?? 'N/A';
        $data['wifi_ssid_5ghz'] = $wifiSsids[5] ?? $wifiSsids[2] ?? $wifiSsid ?? 'N/A';

        $wifiPassword = null;
        for ($i = 1; $i <= 8 && !$wifiPassword; $i++) {
            $wifiPassword =
                $getParam("InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}.KeyPassphrase") ??
                $getParam("InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}.PreSharedKey.1.KeyPassphrase") ??
                $getParam("InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}.PreSharedKey.1.PreSharedKey");
        }
        if (!$wifiPassword) {
            for ($i = 1; $i <= 8 && !$wifiPassword; $i++) {
                $wifiPassword = $getParam("Device.WiFi.AccessPoint.{$i}.Security.KeyPassphrase");
            }
        }

        $data['wifi_password'] = $wifiPassword ?? 'N/A';

        // Optical info
        $rxPower = $getParam('VirtualParameters.RXPower') ??
                   $getParam('InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower') ??
                   $getParam('Device.Optical.Interface.1.RxPower');

        // Convert raw value to dBm if needed
        if ($rxPower !== null && is_numeric($rxPower)) {
            $rxPower = floatval($rxPower);
            if ($rxPower > 100) {
                $rxPower = ($rxPower / 100) - 40;
            }
            $data['rx_power'] = number_format($rxPower, 2);
        } else {
            $data['rx_power'] = $rxPower ?? 'N/A';
        }

        // Temperature
        $temperature = $getParam('VirtualParameters.gettemp') ??
                      $getParam('InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature') ??
                      $getParam('VirtualParameters.Temperature') ??
                      $getParam('InternetGatewayDevice.DeviceInfo.Temperature');

        // Convert raw value if needed (> 1000 indicates raw format)
        if ($temperature !== null && is_numeric($temperature)) {
            $temperature = floatval($temperature);
            if ($temperature > 1000) {
                $temperature = $temperature / 256; // Convert from raw to Celsius
            }
            $data['temperature'] = number_format($temperature, 1);
        } else {
            $data['temperature'] = $temperature ?? 'N/A';
        }

        // WAN Details - try multiple connection types and device numbers
        $wanDetails = [];

        // Helper function to check if WAN connection exists
        $checkWANExists = function($path) use ($device) {
            $keys = explode('.', $path);
            $value = $device;

            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return false;
                }
            }

            // Check if this is an actual connection object (has _object or parameters)
            if (is_array($value)) {
                // If it has _object field and it's true, or has connection parameters
                if (isset($value['_object']) || isset($value['ConnectionStatus']) ||
                    isset($value['Enable']) || isset($value['Name'])) {
                    return true;
                }
            }

            return false;
        };

        // Helper function to detect active WLAN/LAN interfaces
        $detectActiveInterfaces = function() use ($getParam) {
            $activeInterfaces = [];

            // Check WLAN configurations (1-4)
            for ($i = 1; $i <= 4; $i++) {
                $wlanBase = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}";
                $wlanEnable = $getParam("{$wlanBase}.Enable");
                $wlanStatus = $getParam("{$wlanBase}.Status");
                $wlanSSID = $getParam("{$wlanBase}.SSID");
                $wlanVLAN = $getParam("{$wlanBase}.X_CT-COM_VLAN");

                // WLAN is active if enabled and status is "Up" or has SSID
                if (($wlanEnable === true || $wlanStatus === 'Up') && $wlanSSID) {
                    $activeInterfaces[] = [
                        'type' => 'WLAN',
                        'number' => $i,
                        'interface' => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}",
                        'ssid' => $wlanSSID,
                        'vlan' => $wlanVLAN ?? 'N/A'
                    ];
                }
            }

            // Check LAN Ethernet configurations (1-4)
            for ($i = 1; $i <= 4; $i++) {
                $lanBase = "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{$i}";
                $lanEnable = $getParam("{$lanBase}.Enable");
                $lanStatus = $getParam("{$lanBase}.Status");
                $lanVLAN = $getParam("{$lanBase}.X_CT-COM_VLAN");

                // LAN is active if enabled or has status other than "NoLink"
                if ($lanEnable === true || ($lanStatus && $lanStatus !== 'NoLink')) {
                    $activeInterfaces[] = [
                        'type' => 'LAN Ethernet',
                        'number' => $i,
                        'interface' => "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{$i}",
                        'vlan' => $lanVLAN ?? 'N/A'
                    ];
                }
            }

            return $activeInterfaces;
        };

        // Resolve VLAN from standard/vendor fields, with a safe fallback to
        // interface names commonly reported by Huawei (e.g. *_VID_100).
        $resolveWanVlan = function(string $basePath, ?string $name = null) use ($getParam) {
            $paths = [
                "{$basePath}.VLANID",
                "{$basePath}.VlanID",
                "{$basePath}.X_HW_VLANID",
                "{$basePath}.X_HW_VLANId",
                "{$basePath}.X_HW_VLAN",
                "{$basePath}.X_CT-COM_VLANID",
                "{$basePath}.X_CT-COM_VLAN",
                "{$basePath}.X_ZTE-COM_VLANID",
            ];

            foreach ($paths as $path) {
                $value = $getParam($path);
                if ($value === null || $value === '' || $value === 'N/A') continue;
                if (is_numeric($value)) {
                    $id = (int)$value;
                    if ($id >= 1 && $id <= 4094) return $id;
                }
                if (preg_match('/\b(?:VID|VLAN)[_\- ]?(\d{1,4})\b/i', (string)$value, $m)) {
                    $id = (int)$m[1];
                    if ($id >= 1 && $id <= 4094) return $id;
                }
            }

            if ($name && preg_match('/(?:^|[_\-])(?:VID|VLAN)[_\-]?(\d{1,4})(?:$|[_\-])/i', $name, $m)) {
                $id = (int)$m[1];
                if ($id >= 1 && $id <= 4094) return $id;
            }

            return null;
        };

        // Try WANPPPConnection (most common for PPPoE)
        for ($i = 1; $i <= 8; $i++) {
            $basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$i}.WANPPPConnection.1";

            // Check if this connection exists
            if (!$checkWANExists($basePath)) {
                continue;
            }

            $name = $getParam("{$basePath}.Name");
            $externalIP = $getParam("{$basePath}.ExternalIPAddress");
            $serviceList = $getParam("{$basePath}.X_CT-COM_ServiceList");
            $connectionStatus = $getParam("{$basePath}.ConnectionStatus");
            $lanInterface = $getParam("{$basePath}.X_CT-COM_LanInterface");

            // If ConnectionStatus is not available, try to determine from Enable flag
            if (!$connectionStatus || $connectionStatus === 'Unknown') {
                $enabled = $getParam("{$basePath}.Enable");
                if ($enabled !== null) {
                    $connectionStatus = $enabled ? 'Connected' : 'Disconnected';
                } else {
                    $connectionStatus = 'Unknown';
                }
            }

            // Parse LAN interface binding
            $bindingInfo = 'N/A';
            if ($lanInterface !== null && $lanInterface !== '') {
                // Extract interface type and number
                // e.g., "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1" -> "WLAN 1"
                // e.g., "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1" -> "LAN Ethernet 1"
                if (preg_match('/WLANConfiguration\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "WLAN " . $matches[1];
                } elseif (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "LAN Ethernet " . $matches[1];
                } elseif (preg_match('/LANHostConfigManagement/', $lanInterface)) {
                    $bindingInfo = "All LAN Ports";
                } else {
                    $bindingInfo = $lanInterface;
                }
            }

            // If binding info is still N/A, try to infer from active interfaces
            if ($bindingInfo === 'N/A') {
                $activeInterfaces = $detectActiveInterfaces();

                if (!empty($activeInterfaces)) {
                    $bindingList = [];
                    foreach ($activeInterfaces as $iface) {
                        if ($iface['type'] === 'WLAN') {
                            $bindingList[] = "WLAN {$iface['number']}";
                        }
                    }

                    if (!empty($bindingList)) {
                        $bindingInfo = implode(', ', $bindingList);
                    }
                }
            }

            // Only add if we have at least a name, IP, or service identifier
            if ($name || $externalIP || $serviceList) {
                // Generate name if not available
                if (!$name) {
                    $name = $serviceList ? "WAN_{$serviceList}_{$i}" : "WAN_PPP_Connection_{$i}";
                }

                // HG6143D3 reports live byte/packet totals in the WAN common
                // interface, not under WANPPPConnection.Stats.
                $commonBase = 'InternetGatewayDevice.WANDevice.1.WANCommonInterfaceConfig';
                $bytesReceived = $getParam("{$basePath}.Stats.BytesReceived");
                $bytesSent = $getParam("{$basePath}.Stats.BytesSent");
                $packetsReceived = $getParam("{$basePath}.Stats.PacketsReceived");
                $packetsSent = $getParam("{$basePath}.Stats.PacketsSent");
                if (!is_numeric($bytesReceived) || (float)$bytesReceived <= 0) $bytesReceived = $getParam("{$commonBase}.TotalBytesReceived");
                if (!is_numeric($bytesSent) || (float)$bytesSent <= 0) $bytesSent = $getParam("{$commonBase}.TotalBytesSent");
                if (!is_numeric($packetsReceived) || (float)$packetsReceived <= 0) $packetsReceived = $getParam("{$commonBase}.TotalPacketsReceived");
                if (!is_numeric($packetsSent) || (float)$packetsSent <= 0) $packetsSent = $getParam("{$commonBase}.TotalPacketsSent");

                $vlanId = $resolveWanVlan($basePath, $name);

                $wanDetails[] = [
                    'type' => 'PPPoE',
                    'name' => $name,
                    'status' => $connectionStatus,
                    'connection_type' => $getParam("{$basePath}.ConnectionType") ?? 'N/A',
                    'external_ip' => $externalIP ?? 'N/A',
                    'gateway' => $getParam("{$basePath}.RemoteIPAddress") ?? $getParam("{$basePath}.DefaultGateway") ?? 'N/A',
                    'subnet_mask' => $getParam("{$basePath}.SubnetMask") ?? 'N/A',
                    'dns_servers' => $getParam("{$basePath}.DNSServers") ?? 'N/A',
                    'mac_address' => $getParam("{$basePath}.MACAddress") ?? 'N/A',
                    'username' => $getParam("{$basePath}.Username") ?? 'N/A',
                    'uptime' => $getParam("{$basePath}.Uptime") ?? 'N/A',
                    'last_error' => $getParam("{$basePath}.LastConnectionError") ?? 'N/A',
                    'mru_size' => $getParam("{$basePath}.MaxMRUSize") ?? 'N/A',
                    'bytes_received' => $bytesReceived,
                    'bytes_sent' => $bytesSent,
                    'packets_received' => $packetsReceived,
                    'packets_sent' => $packetsSent,
                    'errors_received' => $getParam("{$basePath}.Stats.ErrorsReceived") ?? 0,
                    'errors_sent' => $getParam("{$basePath}.Stats.ErrorsSent") ?? 0,
                    'binding' => $bindingInfo,
                    'vlan_id' => $vlanId ?? 'N/A',
                ];
            }
        }

        // TR-181 WAN: used by Nokia and newer FiberHome firmware.
        for ($i = 1; $i <= 16; $i++) {
            $basePath = "Device.PPP.Interface.{$i}";
            $status = $getParam("{$basePath}.Status");
            $enable = $getParam("{$basePath}.Enable");
            $name = $getParam("{$basePath}.Name") ?? $getParam("{$basePath}.Alias");
            $username = $getParam("{$basePath}.Username");
            $bytesReceived = $getParam("{$basePath}.Stats.BytesReceived");
            $bytesSent = $getParam("{$basePath}.Stats.BytesSent");
            if ($status === null && $enable === null && $name === null && $username === null && $bytesReceived === null && $bytesSent === null) continue;

            $wanDetails[] = [
                'type' => 'PPPoE',
                'name' => $name ?: "PPP{$i}",
                'status' => $status ?? ($enable ? 'Up' : 'Unknown'),
                'connection_type' => 'PPPoE',
                'external_ip' => $getParam("{$basePath}.IPCP.LocalIPAddress") ?? 'N/A',
                'gateway' => $getParam("{$basePath}.IPCP.RemoteIPAddress") ?? 'N/A',
                'subnet_mask' => 'N/A', 'dns_servers' => 'N/A', 'mac_address' => 'N/A',
                'username' => $username ?? 'N/A',
                'uptime' => $getParam("{$basePath}.Uptime") ?? 'N/A',
                'last_error' => $getParam("{$basePath}.LastConnectionError") ?? 'N/A',
                'mru_size' => $getParam("{$basePath}.MaxMRUSize") ?? 'N/A',
                'bytes_received' => $bytesReceived,
                'bytes_sent' => $bytesSent,
                'packets_received' => $getParam("{$basePath}.Stats.PacketsReceived"),
                'packets_sent' => $getParam("{$basePath}.Stats.PacketsSent"),
                'errors_received' => $getParam("{$basePath}.Stats.ErrorsReceived"),
                'errors_sent' => $getParam("{$basePath}.Stats.ErrorsSent"),
                'binding' => $getParam("{$basePath}.LowerLayers") ?? 'N/A',
            ];
        }

        // Try WANIPConnection (for DHCP/Static IP)
        for ($i = 1; $i <= 8; $i++) {
            $basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$i}.WANIPConnection.1";

            // Check if this connection exists
            if (!$checkWANExists($basePath)) {
                continue;
            }

            $name = $getParam("{$basePath}.Name");
            $externalIP = $getParam("{$basePath}.ExternalIPAddress");
            $serviceList = $getParam("{$basePath}.X_CT-COM_ServiceList");
            $connectionStatus = $getParam("{$basePath}.ConnectionStatus");
            $lanInterface = $getParam("{$basePath}.X_CT-COM_LanInterface");

            // If ConnectionStatus is not available, try to determine from Enable flag
            if (!$connectionStatus || $connectionStatus === 'Unknown') {
                $enabled = $getParam("{$basePath}.Enable");
                if ($enabled !== null) {
                    $connectionStatus = $enabled ? 'Connected' : 'Disconnected';
                } else {
                    $connectionStatus = 'Unknown';
                }
            }

            // Parse LAN interface binding
            $bindingInfo = 'N/A';
            if ($lanInterface !== null && $lanInterface !== '') {
                if (preg_match('/WLANConfiguration\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "WLAN " . $matches[1];
                } elseif (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "LAN Ethernet " . $matches[1];
                } elseif (preg_match('/LANHostConfigManagement/', $lanInterface)) {
                    $bindingInfo = "All LAN Ports";
                } else {
                    $bindingInfo = $lanInterface;
                }
            }

            // If binding info is still N/A, try to infer from active interfaces
            if ($bindingInfo === 'N/A') {
                $activeInterfaces = $detectActiveInterfaces();

                if (!empty($activeInterfaces)) {
                    $bindingList = [];
                    foreach ($activeInterfaces as $iface) {
                        if ($iface['type'] === 'WLAN') {
                            $bindingList[] = "WLAN {$iface['number']}";
                        }
                    }

                    if (!empty($bindingList)) {
                        $bindingInfo = implode(', ', $bindingList);
                    }
                }
            }

            // Only add if we have at least a name, IP, or service identifier
            if ($name || $externalIP || $serviceList) {
                // Generate name if not available
                if (!$name) {
                    $name = $serviceList ? "WAN_{$serviceList}_{$i}" : "WAN_IP_Connection_{$i}";
                }

                $vlanId = $resolveWanVlan($basePath, $name);

                $wanDetails[] = [
                    'type' => 'IP',
                    'name' => $name,
                    'status' => $connectionStatus,
                    'connection_type' => $getParam("{$basePath}.ConnectionType") ?? 'N/A',
                    'external_ip' => $externalIP ?? 'N/A',
                    'gateway' => $getParam("{$basePath}.DefaultGateway") ?? 'N/A',
                    'subnet_mask' => $getParam("{$basePath}.SubnetMask") ?? 'N/A',
                    'dns_servers' => $getParam("{$basePath}.DNSServers") ?? 'N/A',
                    'mac_address' => $getParam("{$basePath}.MACAddress") ?? 'N/A',
                    'addressing_type' => $getParam("{$basePath}.AddressingType") ?? 'N/A',
                    'uptime' => $getParam("{$basePath}.Uptime") ?? 'N/A',
                    'binding' => $bindingInfo,
                    'vlan_id' => $vlanId ?? 'N/A',
                    'username' => 'N/A', // IP connections don't have username
                    'last_error' => 'N/A', // IP connections don't have last error
                    'mru_size' => 'N/A', // IP connections don't have MRU size
                    'bytes_received' => $getParam("{$basePath}.Stats.BytesReceived") ?? 0,
                    'bytes_sent' => $getParam("{$basePath}.Stats.BytesSent") ?? 0,
                    'packets_received' => $getParam("{$basePath}.Stats.PacketsReceived") ?? 0,
                    'packets_sent' => $getParam("{$basePath}.Stats.PacketsSent") ?? 0,
                    'errors_received' => $getParam("{$basePath}.Stats.ErrorsReceived") ?? 0,
                    'errors_sent' => $getParam("{$basePath}.Stats.ErrorsSent") ?? 0,
                ];
            }
        }

        // If no WAN connections found, try to create virtual WAN details from active interfaces
        if (empty($wanDetails)) {
            $activeInterfaces = $detectActiveInterfaces();

            if (!empty($activeInterfaces)) {
                // Group interfaces by VLAN to create logical WAN connections
                $vlanGroups = [];

                foreach ($activeInterfaces as $iface) {
                    $vlan = $iface['vlan'] !== 'N/A' && $iface['vlan'] !== '' ? $iface['vlan'] : 'default';

                    if (!isset($vlanGroups[$vlan])) {
                        $vlanGroups[$vlan] = [];
                    }
                    $vlanGroups[$vlan][] = $iface;
                }

                // Create WAN detail for each VLAN group
                $connIndex = 1;
                foreach ($vlanGroups as $vlan => $interfaces) {
                    $bindingList = [];

                    foreach ($interfaces as $iface) {
                        if ($iface['type'] === 'WLAN') {
                            $bindingList[] = "WLAN {$iface['number']} ({$iface['ssid']})";
                        } else {
                            $bindingList[] = "{$iface['type']} {$iface['number']}";
                        }
                    }

                    $bindingInfo = implode(', ', $bindingList);

                    // Use device IP as external IP if available
                    $externalIP = $data['ip_address'] ?? 'N/A';

                    $wanDetails[] = [
                        'type' => 'Bridge',
                        'name' => $vlan !== 'default' ? "Bridge_VLAN_{$vlan}" : "Bridge_Connection",
                        'status' => 'Connected',
                        'connection_type' => 'Bridged',
                        'external_ip' => $externalIP,
                        'gateway' => 'N/A',
                        'subnet_mask' => 'N/A',
                        'dns_servers' => 'N/A',
                        'mac_address' => $data['mac_address'] ?? 'N/A',
                        'addressing_type' => 'Bridged',
                        'uptime' => $data['uptime'] ?? 'N/A',
                        'binding' => $bindingInfo,
                        'username' => 'N/A',
                        'last_error' => 'N/A',
                        'mru_size' => 'N/A',
                    ];

                    $connIndex++;
                }
            }
        }

        $data['wan_details'] = $wanDetails;

        // Extract PPPoE username from first PPPoE connection (for devices.php display)
        $pppoeUsername = 'N/A';
        foreach ($wanDetails as $wan) {
            if ($wan['type'] === 'PPPoE' && isset($wan['username']) && $wan['username'] !== 'N/A' && $wan['username'] !== '') {
                $pppoeUsername = $wan['username'];
                break; // Use first found PPPoE username (non-empty)
            }
        }
        $data['pppoe_username'] = $pppoeUsername;

        // Connected Devices (LAN Hosts)
        $connectedDevices = [];

        // Get hosts from LANDevice.1.Hosts.Host
        $hostsBase = 'InternetGatewayDevice.LANDevice.1.Hosts.Host';

        // Get device's last inform time for comparison
        $deviceLastInformTime = null;
        if ($lastInform) {
            $deviceLastInformTime = strtotime($lastInform);
        }

        // Try to get hosts object
        if (isset($device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'])) {
            $hosts = $device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'];

            // Iterate through all host entries
            foreach ($hosts as $hostId => $hostData) {
                // Skip metadata fields
                if (strpos($hostId, '_') === 0) {
                    continue;
                }

                // Get host details
                $ipAddress = isset($hostData['IPAddress']['_value']) ? $hostData['IPAddress']['_value'] : null;
                $macAddress = isset($hostData['MACAddress']['_value']) ? $hostData['MACAddress']['_value'] : null;
                $hostName = isset($hostData['HostName']['_value']) ? $hostData['HostName']['_value'] : '';
                $interfaceType = isset($hostData['InterfaceType']['_value']) ? $hostData['InterfaceType']['_value'] : 'Unknown';
                $active = isset($hostData['Active']['_value']) ? $hostData['Active']['_value'] : null;
                $timestamp = isset($hostData['_timestamp']) ? $hostData['_timestamp'] : null;

                // Only add devices with valid IP and MAC
                if ($ipAddress && $macAddress) {
                    // Filter strategy: Only count hosts that were updated recently relative to device last inform
                    // This filters out old/disconnected devices from GenieACS historical data
                    $isRecentlyActive = true; // Default to true if no timestamp

                    if ($timestamp && $deviceLastInformTime) {
                        $hostTimestamp = strtotime($timestamp);
                        if ($hostTimestamp !== false) {
                            // Strategy: Count host as active if:
                            // 1. Host timestamp is within 3 hours before OR after device last inform
                            // 2. This catches hosts that were active around the time of last inform
                            //    (accounts for clock drift and DHCP lease refresh timing)
                            $threeHoursBefore = $deviceLastInformTime - (3 * 3600);
                            $threeHoursAfter = $deviceLastInformTime + (3 * 3600);
                            $isRecentlyActive = ($hostTimestamp >= $threeHoursBefore && $hostTimestamp <= $threeHoursAfter);
                        }
                    }

                    // Skip hosts that are not recently active
                    if (!$isRecentlyActive) {
                        continue;
                    }

                    // Determine interface type (WiFi/LAN)
                    $connectionType = 'LAN';
                    if ($interfaceType === '802.11') {
                        $connectionType = 'WiFi';
                    } elseif ($interfaceType === 'Ethernet') {
                        $connectionType = 'Ethernet';
                    }

                    // Get MAC vendor name
                    $vendorName = getMACVendor($macAddress, $hostName);

                    // If hostname is empty and vendor found, use vendor name
                    // Otherwise use "Unknown Device"
                    if (empty($hostName) || trim($hostName) === '') {
                        $hostName = $vendorName;
                    }

                    $connectedDevices[] = [
                        'hostname' => $hostName,
                        'vendor' => $vendorName,
                        'ip_address' => $ipAddress,
                        'mac_address' => $macAddress,
                        'interface_type' => $connectionType,
                        'active' => $active ?? true, // Default to active if not specified
                    ];
                }
            }
        }

        $data['connected_devices'] = $connectedDevices;
        $data['connected_devices_count'] = count($connectedDevices);

        // Clientes realmente ativos separados por banda Wi-Fi.
        $activeConnectedDevices = array_values(array_filter(
            $connectedDevices,
            static function (array $item): bool {
                $active = $item['active'] ?? true;
                return !in_array($active, [false, 0, '0', 'false', 'False', 'FALSE'], true);
            }
        ));

        $wifiClients24 = array_values(array_filter(
            $activeConnectedDevices,
            static fn(array $item): bool =>
                ($item['interface_type'] ?? '') === 'WiFi' &&
                ($item['wifi_band'] ?? null) === '2.4'
        ));

        $wifiClients5 = array_values(array_filter(
            $activeConnectedDevices,
            static fn(array $item): bool =>
                ($item['interface_type'] ?? '') === 'WiFi' &&
                ($item['wifi_band'] ?? null) === '5'
        ));

        $data['wifi_clients_24ghz'] = count($wifiClients24);
        $data['wifi_clients_5ghz'] = count($wifiClients5);
        $data['wifi_clients_24ghz_devices'] = $wifiClients24;
        $data['wifi_clients_5ghz_devices'] = $wifiClients5;

        // Physical LAN Ethernet ports
        // TR-098: InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{i}
        $lanPorts = [];
        $lanPortCount = (int)($getParam('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceNumberOfEntries') ?? 0);
        $maxLanPorts = $lanPortCount > 0 ? min($lanPortCount, 8) : 8;

        for ($i = 1; $i <= $maxLanPorts; $i++) {
            $lanBase = "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{$i}";
            $status = $getParam("{$lanBase}.Status");
            $enable = $getParam("{$lanBase}.Enable");
            $maxBitRate = $getParam("{$lanBase}.MaxBitRate");
            $duplexMode = $getParam("{$lanBase}.DuplexMode");

            // Ignore non-existent instances. A port is considered present when
            // at least one commonly exposed parameter was collected by GenieACS.
            if ($status === null && $enable === null && $maxBitRate === null && $duplexMode === null) {
                continue;
            }

            $statsBase = "{$lanBase}.Stats";

            $lanPorts[] = [
                'port' => $i,
                'name' => "LAN{$i}",
                'enabled' => $enable,
                'status' => $status ?? 'Unknown',
                'max_bit_rate' => $maxBitRate ?? 'N/A',
                'duplex_mode' => $duplexMode ?? 'N/A',
                'bytes_received' => $getParam("{$statsBase}.BytesReceived") ?? 0,
                'bytes_sent' => $getParam("{$statsBase}.BytesSent") ?? 0,
                'packets_received' => $getParam("{$statsBase}.PacketsReceived") ?? 0,
                'packets_sent' => $getParam("{$statsBase}.PacketsSent") ?? 0,
                'errors_received' => $getParam("{$statsBase}.ErrorsReceived") ?? 0,
                'errors_sent' => $getParam("{$statsBase}.ErrorsSent") ?? 0,
            ];
        }

        // TR-181 physical LAN ports, used by Nokia and some FiberHome RP firmware.
        if (empty($lanPorts)) {
            for ($i = 1; $i <= 16; $i++) {
                $lanBase = "Device.Ethernet.Interface.{$i}";
                $status = $getParam("{$lanBase}.Status");
                $enable = $getParam("{$lanBase}.Enable");
                $name = $getParam("{$lanBase}.Name") ?? $getParam("{$lanBase}.Alias");
                $maxBitRate = $getParam("{$lanBase}.MaxBitRate");
                $duplexMode = $getParam("{$lanBase}.DuplexMode");
                if ($status === null && $enable === null && $name === null && $maxBitRate === null && $duplexMode === null) continue;
                if ($name && preg_match('/^(br|bridge|lo|cpu|veip)/i', $name)) continue;

                $statsBase = "{$lanBase}.Stats";
                $lanPorts[] = [
                    'port' => $i, 'name' => $name ?: "LAN{$i}", 'enabled' => $enable,
                    'status' => $status ?? 'Unknown', 'max_bit_rate' => $maxBitRate ?? 'N/A',
                    'duplex_mode' => $duplexMode ?? 'N/A',
                    'bytes_received' => $getParam("{$statsBase}.BytesReceived"),
                    'bytes_sent' => $getParam("{$statsBase}.BytesSent"),
                    'packets_received' => $getParam("{$statsBase}.PacketsReceived"),
                    'packets_sent' => $getParam("{$statsBase}.PacketsSent"),
                    'errors_received' => $getParam("{$statsBase}.ErrorsReceived"),
                    'errors_sent' => $getParam("{$statsBase}.ErrorsSent"),
                ];
            }
        }

        $data['lan_ports'] = $lanPorts;

        // DHCP Server Configuration
        $dhcpServer = [];
        $dhcpBase = 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement';

        // Check if DHCP capability exists by checking for any DHCP parameter
        // (not just DHCPServerEnable, as it may not have _value if not configured)
        $hasdhcpCapability = false;

        // Check multiple DHCP parameters to determine if device supports DHCP
        $dhcpEnabled = $getParam("{$dhcpBase}.DHCPServerEnable");
        $dhcpLeaseTime = $getParam("{$dhcpBase}.DHCPLeaseTime");

        // Device has DHCP capability if any DHCP parameter is present
        if ($dhcpEnabled !== null || $dhcpLeaseTime !== null) {
            $hasdhcpCapability = true;
        }

        if ($hasdhcpCapability) {
            // Extract DHCP parameters (use false/N/A as defaults if not configured)
            $dhcpServer['enabled'] = $dhcpEnabled ?? false;
            $dhcpServer['configurable'] = $getParam("{$dhcpBase}.DHCPServerConfigurable") ?? true;
            $dhcpServer['min_address'] = $getParam("{$dhcpBase}.MinAddress") ?? 'N/A';
            $dhcpServer['max_address'] = $getParam("{$dhcpBase}.MaxAddress") ?? 'N/A';
            $dhcpServer['subnet_mask'] = $getParam("{$dhcpBase}.SubnetMask") ?? 'N/A';
            $dhcpServer['gateway'] = $getParam("{$dhcpBase}.IPRouters") ?? 'N/A';
            $dhcpServer['dns_servers'] = $getParam("{$dhcpBase}.DNSServers") ?? 'N/A';
            $dhcpServer['lease_time'] = $dhcpLeaseTime ?? 86400; // Default to 24 hours

            $data['dhcp_server'] = $dhcpServer;
        } else {
            // Device does not support DHCP - set to null
            $data['dhcp_server'] = null;
        }

        // Admin Web Access Credentials
        $data['admin_user'] = $getParam('VirtualParameters.superAdmin') ?? 'N/A';
        $data['admin_password'] = $getParam('VirtualParameters.superPassword') ?? 'N/A';
        $data['telecom_password'] = $getParam('InternetGatewayDevice.DeviceInfo.X_CT-COM_TeleComAccount.Password') ?? 'N/A';

        // Tags
        $data['tags'] = $device['_tags'] ?? [];

        return CPEProfiles::enrich($device, $data);
    }
}
