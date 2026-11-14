/* JRC_REDE_HOSTS_V9 — lê os clientes no rádio da ONU, inclusive FiberHome. */
(function () {
    'use strict';

    if (window.__JRC_REDE_HOSTS_V1__) {
        return;
    }
    window.__JRC_REDE_HOSTS_V1__ = true;

    var painting = false;
    var busy = false;
    var liveAt = 0;
    var retried = false;
    var signal = null;
    var hostCount = 0;

    function quietBlueStrokes() {
        if (document.getElementById('jrc-rede-hosts-style')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'jrc-rede-hosts-style';
        style.textContent = '' +
            '#deviceTabs .nav-link,#deviceTabs .nav-link.active,#deviceTabs .nav-link:hover,' +
            '#deviceTabs .nav-link:focus,#deviceTabs .nav-link:focus-visible{' +
            'outline:none !important;box-shadow:none !important;border-color:transparent !important;}' +
            '#devices-content .alert,#devices-content .alert-info,#devices .alert{' +
            'background:transparent !important;color:#8ea0b0 !important;border:0 !important;box-shadow:none !important;}' +
            '#editWiFiModal .alert,#editWiFiModal .alert-info{' +
            'background:#12181f !important;color:#c5d2dc !important;border:1px solid rgba(255,255,255,.08) !important;box-shadow:none !important;}' +
            '#editWiFiModal .form-control,#editWiFiModal .form-select,#editWiFiModal .btn-outline-secondary{' +
            'border-color:rgba(255,255,255,.14) !important;box-shadow:none !important;outline:none !important;}' +
            '#editWiFiModal .form-control:focus,#editWiFiModal .form-select:focus{' +
            'border-color:rgba(255,255,255,.32) !important;box-shadow:none !important;outline:none !important;}' +
            '#editWiFiModal .btn-primary{' +
            'background:#e10600 !important;border-color:transparent !important;box-shadow:none !important;}';
        (document.head || document.documentElement).appendChild(style);
    }

    function deviceId() {
        if (window.DEVICE_ID) {
            return String(window.DEVICE_ID);
        }
        var params = new URLSearchParams(window.location.search);
        return params.get('id') || params.get('device_id') || '';
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function hostBox() {
        var byId = document.getElementById('devices-content');
        if (byId) {
            return byId;
        }
        var alerts = document.querySelectorAll('.alert, .tab-pane');
        for (var i = 0; i < alerts.length; i++) {
            var text = alerts[i].textContent || '';
            if (text.indexOf('No devices currently connected to this ONU') !== -1) {
                return alerts[i].closest('.tab-pane') || alerts[i].parentElement;
            }
        }
        return null;
    }

    function needsFill(box) {
        var state = box ? box.getAttribute('data-jrc-rede-hosts') : '';
        if (!box || state === '1' || state === 'wait') {
            return false;
        }
        return box.querySelectorAll('#connected-devices-table tbody tr').length === 0;
    }

    function via(device) {
        var type = String(device.interface_type || '').toLowerCase();
        if (type === 'wifi') {
            if (String(device.wifi_band || '') === '5') {
                return 'Wi-Fi · 5 GHz';
            }
            if (String(device.wifi_band || '') === '2.4') {
                return 'Wi-Fi · 2,4 GHz';
            }
            return 'Wi-Fi';
        }
        if (type === 'ethernet') {
            var port = Number(device.lan_port || 0);
            return port > 0 ? 'Cabo · LAN ' + port : 'Cabo';
        }
        return device.interface_type || 'Rede';
    }

    function siteDevices(list) {
        return list.map(function (device) {
            var type = String(device.interface_type || '').toLowerCase();
            return {
                hostname: device.hostname || 'Dispositivo',
                ip_address: device.ip_address || '-',
                mac_address: device.mac_address || '',
                interface_type: type === 'ethernet' ? 'ethernet' : 'wifi',
                wifi_band: device.wifi_band || '',
                lan_port: device.lan_port || 0,
                active: device.active !== false
            };
        });
    }

    function paintHosts(box, list) {
        var devices = siteDevices(list);
        painting = true;
        if (typeof renderConnectedDevicesTab === 'function') {
            box.innerHTML = renderConnectedDevicesTab(devices);
            if (typeof jrcEnhanceConnectedClientsTable === 'function') {
                jrcEnhanceConnectedClientsTable(devices);
            }
        } else {
            var rows = devices.map(function (device, index) {
                var isWifi = device.interface_type === 'wifi';
                return '<tr data-mac="' + esc(device.mac_address) + '">' +
                    '<td class="text-center">' + (index + 1) + '</td>' +
                    '<td><strong>' + esc(device.hostname) + '</strong></td>' +
                    '<td>' + esc(device.ip_address) + '</td>' +
                    '<td><code>' + esc(device.mac_address || '-') + '</code></td>' +
                    '<td class="text-center"><span class="badge ' + (isWifi ? 'bg-secondary' : 'bg-success') + '">' +
                    '<i class="bi ' + (isWifi ? 'bi-wifi' : 'bi-ethernet') + '"></i> ' + esc(via(device)) +
                    '</span></td></tr>';
            }).join('');
            box.innerHTML = '<div class="table-responsive"><table class="table table-sm table-bordered table-hover" id="connected-devices-table">' +
                '<thead class="table-light"><tr><th class="text-center">#</th><th>Nome</th><th>IP</th><th>MAC</th><th class="text-center">Conexão</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table></div>';
        }
        box.querySelectorAll('.alert, .alert-info, .alert-primary').forEach(function (node) {
            node.remove();
        });
        box.setAttribute('data-jrc-rede-hosts', '1');
        painting = false;
        hostCount = devices.length;
        if (window.currentDeviceData) {
            currentDeviceData.connected_devices = devices;
            currentDeviceData.connected_devices_count = devices.length;
            if (typeof jrcApplyWifiClientCounters === 'function') {
                jrcApplyWifiClientCounters(currentDeviceData);
            }
            if (typeof jrEnhanceWifiClientCounters === 'function') {
                jrEnhanceWifiClientCounters(currentDeviceData);
            }
        }
        var badge = document.getElementById('devices-count-badge');
        if (badge) {
            badge.textContent = String(devices.length);
        }
        paintLabeled('Hosts conectados', String(devices.length));
    }

    function isBlank(text) {
        var value = String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return value === '' || value === '—' || value === '-' || value === 'n/a' || value === 'na' ||
            value === 'unknown' || value === 'consultando...' || value === 'consultando' || value === '--' || value === 'rx';
    }

    function formatUptime(seconds) {
        var total = Number(seconds);
        if (!isFinite(total) || total <= 0) {
            return '';
        }
        var days = Math.floor(total / 86400);
        var hours = Math.floor((total % 86400) / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        return [days ? days + 'd' : '', hours ? hours + 'h' : '', minutes + 'min'].filter(Boolean).join(' ');
    }

    function shownNumber(node) {
        var match = String(node && node.textContent || '').replace(/\s/g, '').match(/-?\d+(?:[.,]\d+)?/);
        if (!match) {
            return null;
        }
        var number = Number(match[0].replace(',', '.'));
        return isFinite(number) ? number : null;
    }

    function inOpticalRange(kind, number) {
        if (kind === 'rx') {
            return number <= -3 && number >= -40;
        }
        if (kind === 'tx') {
            return number >= -1 && number <= 8;
        }
        if (kind === 'temperature') {
            return number >= 20 && number <= 80;
        }
        if (kind === 'voltage') {
            return number >= 2.8 && number <= 3.8;
        }
        return false;
    }

    function correctOptical(kind, value) {
        var number = Number(value);
        if (!isFinite(number)) {
            return null;
        }
        var log = number > 0 ? 10 * Math.log(number / 10000) / Math.LN10 : null;
        var candidates;
        if (kind === 'rx') {
            candidates = [number, (number > 0 && number <= 40) ? -number : null, number / 100, -number / 100, (number / 100) - 40, log];
        } else if (kind === 'tx') {
            candidates = [number, number / 100, number / 1000, number >= 1000 ? log : null];
        } else if (kind === 'temperature') {
            candidates = [(number > 0 && number <= 85) ? number : null, number / 10, number / 256, (number * 100) / 256, ((number - 32) * 5) / 9];
        } else if (kind === 'voltage') {
            candidates = [number, number / 10, number / 100, number / 1000, number / 10000];
        } else {
            return null;
        }
        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i] != null && isFinite(candidates[i]) && inOpticalRange(kind, candidates[i])) {
                return candidates[i];
            }
        }
        return null;
    }

    function formatReading(value) {
        return Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function paintNumber(node, value, kind, suffix) {
        if (!node) {
            return null;
        }
        var fromValue = correctOptical(kind, value);
        var shown = shownNumber(node);
        var corrected = fromValue != null ? fromValue : correctOptical(kind, shown);
        if (corrected == null) {
            return null;
        }
        if (shown != null && inOpticalRange(kind, shown) && (fromValue == null || Math.abs(shown - corrected) < 0.05)) {
            return shown;
        }
        node.textContent = formatReading(corrected) + suffix;
        return corrected;
    }

    function paintLabeled(label, text) {
        var items = document.querySelectorAll('.fb-diag-item, .fb-ind');
        for (var i = 0; i < items.length; i++) {
            var span = items[i].querySelector('span');
            var strong = items[i].querySelector('strong');
            if (!span || !strong || (span.textContent || '').indexOf(label) === -1) {
                continue;
            }
            if (label === 'Hosts conectados') {
                if (String(strong.textContent || '').trim() === '0' && hostCount > 0) {
                    strong.textContent = String(hostCount);
                }
                continue;
            }
            if (isBlank(strong.textContent)) {
                strong.textContent = text;
            }
        }
    }

    function paintSignal(info) {
        if (info && (info.rx_power != null || info.tx_power != null || info.temperature != null || info.voltage != null || info.uptime)) {
            signal = info;
        }
        info = info || signal || {};
        painting = true;
        var rx = null;
        document.querySelectorAll('[data-optical="rx_power"]').forEach(function (node) {
            rx = paintNumber(node, info.rx_power, 'rx', ' dBm') || rx;
        });
        document.querySelectorAll('[data-optical="tx_power"]').forEach(function (node) {
            paintNumber(node, info.tx_power, 'tx', ' dBm');
        });
        document.querySelectorAll('[data-optical="temperature"]').forEach(function (node) {
            paintNumber(node, info.temperature, 'temperature', ' °C');
        });
        document.querySelectorAll('[data-optical="voltage"]').forEach(function (node) {
            paintNumber(node, info.voltage, 'voltage', ' V');
        });
        rx = paintNumber(document.getElementById('optical-rx-power'), info.rx_power, 'rx', ' dBm') || rx;
        paintNumber(document.getElementById('optical-tx-power'), info.tx_power, 'tx', ' dBm');
        paintNumber(document.getElementById('optical-temperature'), info.temperature, 'temperature', ' °C');
        paintNumber(document.getElementById('optical-voltage'), info.voltage, 'voltage', ' V');
        if (rx != null) {
            var pct = Math.min(100, Math.max(0, ((rx + 30) / 22) * 100));
            document.querySelectorAll('[data-gauge-mark]').forEach(function (mark) {
                mark.style.left = pct + '%';
            });
            document.querySelectorAll('[data-gauge-label]').forEach(function (node) {
                node.textContent = formatReading(rx) + ' dBm';
            });
            var header = document.getElementById('device-status-header');
            if (header && String(header.textContent || '').toLowerCase() !== 'offline') {
                header.classList.remove('online', 'offline', 'warn');
                if (rx > -25) {
                    header.textContent = 'Bom';
                    header.classList.add('online');
                } else if (rx > -27) {
                    header.textContent = 'Fraco';
                    header.classList.add('warn');
                } else {
                    header.textContent = 'Crítico';
                    header.classList.add('offline');
                }
            }
        }
        if (info.uptime) {
            paintLabeled('Uptime', formatUptime(info.uptime));
            paintLabeled('Tempo ligado', formatUptime(info.uptime));
        }
        if (info.wan_status === 'Connected') {
            paintLabeled('Conexão WAN', 'Connected');
        }
        paintLabeled('Hosts conectados', String(hostCount));
        painting = false;
    }

    function signalPending() {
        if (signal) {
            return false;
        }
        var node = document.querySelector('[data-optical="rx_power"]');
        if (!node) {
            return true;
        }
        return isBlank(node.textContent);
    }

    function renderMessage(box, message) {
        painting = true;
        box.innerHTML = '<div class="acs-empty-state">' + esc(message) + '</div>';
        box.setAttribute('data-jrc-rede-hosts', '1');
        painting = false;
    }

    function refresh() {
        var id = deviceId();
        var box = hostBox();
        var fillHosts = !!(box && needsFill(box));
        var fillSignal = signalPending();
        if (!id || busy || painting || (!fillHosts && !fillSignal)) {
            if (signal) {
                paintSignal(signal);
            }
            return;
        }
        busy = true;
        var live = Date.now() - liveAt > 20000;
        if (live) {
            liveAt = Date.now();
        }
        fetch('/api/jrc-rede-hosts.php?' + (live ? 'live=1&' : '') + 'device_id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.text().then(function (raw) {
                var data;
                try {
                    data = JSON.parse(raw);
                } catch (error) {
                    throw new Error('resposta invalida');
                }
                if (!response.ok || !data || data.success !== true || !Array.isArray(data.devices)) {
                    throw new Error((data && data.message) || 'falha');
                }
                return data;
            });
        }).then(function (data) {
            hostCount = data.devices.length;
            if (data.signal) {
                paintSignal(data.signal);
            } else {
                signal = {};
            }
            var current = hostBox();
            if (!current || !needsFill(current)) {
                return;
            }
            if (!data.devices.length) {
                if (!retried) {
                    retried = true;
                    current.setAttribute('data-jrc-rede-hosts', 'wait');
                    window.setTimeout(function () {
                        var again = hostBox();
                        if (again) {
                            again.removeAttribute('data-jrc-rede-hosts');
                        }
                        busy = false;
                        refresh();
                    }, 6000);
                    return;
                }
                renderMessage(current, data.message || 'Nenhum dispositivo conectado.');
                return;
            }
            paintHosts(current, data.devices);
        }).catch(function () {
            var current = hostBox();
            if (current && needsFill(current) && !retried) {
                retried = true;
                current.setAttribute('data-jrc-rede-hosts', 'wait');
                window.setTimeout(function () {
                    var again = hostBox();
                    if (again) {
                        again.removeAttribute('data-jrc-rede-hosts');
                    }
                    busy = false;
                    refresh();
                }, 6000);
                return;
            }
            if (current && needsFill(current)) {
                renderMessage(current, 'Nenhum dispositivo conectado.');
            }
        }).then(function () {
            busy = false;
        });
    }

    function boot() {
        quietBlueStrokes();
        if (!deviceId()) {
            return;
        }
        var root = document.getElementById('deviceTabContent') || document.body;
        var observer = new MutationObserver(function () {
            if (painting || busy) {
                return;
            }
            window.setTimeout(refresh, 350);
        });
        observer.observe(root, { childList: true, subtree: true });
        window.setTimeout(refresh, 600);
        window.setInterval(function () {
            if (!painting) {
                paintSignal(signal);
            }
        }, 2000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
