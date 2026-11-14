/* JRC_DEVICE_CHARTS_FIX_V1 */
const deviceId = window.DEVICE_ID || '';
try { if (deviceId) localStorage.setItem('jrc_last_device', deviceId); } catch (e) {}
let savedScrollPosition = 0;
let savedHotspotData = {}; // Store last known hotspot data

// Cache local dos dados ópticos para sobreviver ao auto-refresh de 30 segundos
let cachedOpticalData = null;
let opticalLoading = false;
let opticalLoadedForDevice = null;
let opticalRequestCounter = 0;
let currentDeviceData = null;
let customerSummaryRequestCounter = 0;
let cachedCustomerSummary = null;
let aiRuntimeStatus = {
    configured: false,
    provider: '',
    model: '',
    connected: false
};
let lastDeviceRefreshAt = null;

const deviceAIStorageKey = 'jrconect-device-ai:' + String(deviceId || 'unknown');
let deviceAIState = {
    question: '',
    status: 'idle',
    answer: '',
    provider: '',
    model: '',
    error: '',
    messages: []
};

try {
    const savedAIState = sessionStorage.getItem(deviceAIStorageKey);
    if (savedAIState) {
        const parsedAIState = JSON.parse(savedAIState);
        if (parsedAIState && typeof parsedAIState === 'object') {
            deviceAIState = { ...deviceAIState, ...parsedAIState };
        }
    }
} catch (_) {
    // A IA continua funcionando mesmo se sessionStorage estiver indisponível.
}

function saveDeviceAIState(patch = {}) {
    deviceAIState = { ...deviceAIState, ...patch };
    try {
        sessionStorage.setItem(deviceAIStorageKey, JSON.stringify(deviceAIState));
    } catch (_) {}
}

function escapeAIMessage(value) {
    return escapeOpticalHtml(value || '').replace(/\n/g, '<br>');
}

function renderDeviceAIConversation() {
    const body = document.getElementById('jr-ai-drawer-messages');
    if (!body) return;

    const messages = Array.isArray(deviceAIState.messages) ? deviceAIState.messages : [];
    let html = '';

    if (!messages.length) {
        html = `
            <div class="jr-ai-drawer-empty">
                <div class="jr-ai-drawer-empty-icon"><i class="bi bi-stars"></i></div>
                <strong>Assistente técnico JR CONECT</strong>
                <span>Pergunte sobre sinal óptico, WAN, Wi-Fi, portas LAN, cliente ou diagnóstico deste equipamento.</span>
            </div>`;
    } else {
        html = messages.map(message => {
            const role = message.role === 'assistant' ? 'assistant' : 'user';
            const label = role === 'assistant' ? 'IA' : 'Você';
            const meta = role === 'assistant' && message.provider
                ? '<small>' + escapeOpticalHtml((message.provider === 'anthropic' ? 'Claude' : 'OpenAI') + (message.model ? ' · ' + message.model : '')) + '</small>'
                : '';
            return `
                <div class="jr-ai-message ${role}">
                    <div class="jr-ai-message-head"><strong>${label}</strong>${meta}</div>
                    <div class="jr-ai-message-bubble">${escapeAIMessage(message.content)}</div>
                </div>`;
        }).join('');
    }

    if (deviceAIState.status === 'loading') {
        html += `
            <div class="jr-ai-message assistant pending">
                <div class="jr-ai-message-head"><strong>IA</strong></div>
                <div class="jr-ai-message-bubble">
                    <span class="acs-ai-thinking"><i class="bi bi-stars"></i> Analisando dados atuais do equipamento...</span>
                </div>
            </div>`;
    }

    if (deviceAIState.status === 'error' && deviceAIState.error) {
        html += `
            <div class="jr-ai-message assistant error">
                <div class="jr-ai-message-head"><strong>IA</strong></div>
                <div class="jr-ai-message-bubble">
                    <span class="acs-ai-error"><i class="bi bi-exclamation-triangle"></i> ${escapeOpticalHtml(deviceAIState.error)}</span>
                </div>
            </div>`;
    }

    body.innerHTML = html;
    requestAnimationFrame(() => {
        body.scrollTop = body.scrollHeight;
    });
}

function renderStoredDeviceAIState() {
    renderDeviceAIConversation();

    const drawerInput = document.getElementById('jr-ai-drawer-input');
    if (drawerInput && deviceAIState.question && document.activeElement !== drawerInput) {
        drawerInput.value = '';
    }

    const providerLabel = document.getElementById('jr-ai-drawer-provider');
    const compactStatus = document.getElementById('acs-ai-compact-status');

    const provider = aiRuntimeStatus.provider || deviceAIState.provider;
    const model = aiRuntimeStatus.model || deviceAIState.model;

    if (provider) {
        const text = providerDisplayName(provider) +
            (model ? ' · ' + model : '') +
            (aiRuntimeStatus.configured ? (aiRuntimeStatus.connected ? ' · conectado' : ' · não testado') : '');
        if (providerLabel) providerLabel.textContent = text;
        if (compactStatus) compactStatus.textContent = text;
    }
}

function providerDisplayName(provider) {
    return provider === 'anthropic' ? 'Claude' : provider === 'openai' ? 'OpenAI' : 'IA';
}

function cleanOperationalValue(value) {
    const text = String(value ?? '').trim();
    if (!text || /^(n\/?a|none|null|undefined|-|sem erro|no error|error_none)$/i.test(text)) {
        return '';
    }
    return text;
}


function getDeviceHealth(device = currentDeviceData || {}) {
    const online = String(device.status || '').toLowerCase() === 'online';
    if (!online) {
        return {
            level: 'critical',
            label: 'CRÍTICO',
            detail: 'Equipamento offline'
        };
    }

    const wan = typeof getPrimaryWAN === 'function' ? (getPrimaryWAN(device) || {}) : {};
    const wanStatusRaw = String(wan.status || '').trim();
    const wanStatus = wanStatusRaw.toLowerCase();
    const connectedStates = ['connected', 'up', 'online', 'enabled'];

    // O estado atual da WAN tem prioridade sobre LastConnectionError,
    // porque muitos CPEs mantêm no TR-069 o último erro histórico mesmo após reconectar.
    if (connectedStates.includes(wanStatus)) {
        return {
            level: 'healthy',
            label: 'SAUDÁVEL',
            detail: 'Online · WAN conectada'
        };
    }

    if (wanStatus && !['unknown', 'n/a', 'na', '-'].includes(wanStatus)) {
        return {
            level: 'warning',
            label: 'ATENÇÃO',
            detail: 'WAN: ' + wanStatusRaw
        };
    }

    const lastError = cleanOperationalValue(wan.last_error);
    if (lastError) {
        return {
            level: 'warning',
            label: 'ATENÇÃO',
            detail: 'WAN sem estado atual · erro registrado'
        };
    }

    return {
        level: 'warning',
        label: 'ATENÇÃO',
        detail: 'WAN sem estado confirmado'
    };
}

function parseOperationalTimestamp(value) {
    if (!value) return null;

    const text = String(value).trim();

    let match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
    if (match) {
        const date = new Date(
            Number(match[1]),
            Number(match[2]) - 1,
            Number(match[3]),
            Number(match[4]),
            Number(match[5]),
            Number(match[6] || 0)
        );
        return Number.isNaN(date.getTime()) ? null : date;
    }

    match = text.match(/^(\d{2})\/(\d{2})\/(\d{4})[ ,T]+(\d{2}):(\d{2})(?::(\d{2}))?$/);
    if (match) {
        const date = new Date(
            Number(match[3]),
            Number(match[2]) - 1,
            Number(match[1]),
            Number(match[4]),
            Number(match[5]),
            Number(match[6] || 0)
        );
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const parsed = new Date(text);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function formatSourceAge(timestamp) {
    if (!timestamp) return null;

    const date = timestamp instanceof Date ? timestamp : new Date(timestamp);
    if (Number.isNaN(date.getTime())) return null;

    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 5) return 'agora';
    if (seconds < 60) return 'há ' + seconds + 's';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return 'há ' + minutes + ' min';

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    if (hours < 24) {
        return remainingMinutes
            ? 'há ' + hours + 'h ' + remainingMinutes + 'min'
            : 'há ' + hours + 'h';
    }

    const days = Math.floor(hours / 24);
    const remainingHours = hours % 24;
    return remainingHours
        ? 'há ' + days + 'd ' + remainingHours + 'h'
        : 'há ' + days + 'd';
}

function getOpticalSourceAge() {
    if (opticalLoading && (!cachedOpticalData || cachedOpticalData.device_id !== deviceId)) {
        return {
            text: 'consultando',
            title: 'Consultando leitura óptica no IXC'
        };
    }

    if (!cachedOpticalData || cachedOpticalData.device_id !== deviceId) {
        return {
            text: 'aguardando',
            title: 'Leitura óptica ainda não carregada'
        };
    }

    if (cachedOpticalData.error) {
        return {
            text: 'indisponível',
            title: cachedOpticalData.error
        };
    }

    const raw = cachedOpticalData.optical?.last_update || '';
    const parsed = parseOperationalTimestamp(raw);
    const age = formatSourceAge(parsed);

    if (age) {
        return {
            text: age,
            title: 'Última leitura óptica IXC: ' + formatOpticalDate(raw)
        };
    }

    return {
        text: 'sem horário',
        title: 'O IXC não informou data/hora da última leitura óptica'
    };
}

function updateOverviewOperationalMeta() {
    const acsAge = document.getElementById('acs-overview-acs-age');
    if (acsAge) {
        acsAge.textContent = lastDeviceRefreshAt ? formatSourceAge(lastDeviceRefreshAt) : 'aguardando';
        acsAge.title = lastDeviceRefreshAt
            ? 'Última atualização do ACS: ' + new Date(lastDeviceRefreshAt).toLocaleString('pt-BR')
            : 'Aguardando atualização do ACS';
    }

    const opticalAge = document.getElementById('acs-overview-optical-age');
    if (opticalAge) {
        const opticalMeta = getOpticalSourceAge();
        opticalAge.textContent = opticalMeta.text;
        opticalAge.title = opticalMeta.title;
    }

    const health = getDeviceHealth();
    const healthEl = document.getElementById('acs-device-health');
    if (healthEl) {
        healthEl.className = 'acs-health-pill ' + health.level;
        healthEl.innerHTML = '<i class="bi bi-heart-pulse"></i><span>' +
            escapeOpticalHtml(health.label) + '</span><small>' +
            escapeOpticalHtml(health.detail) + '</small>';
    }

    const aiStatus = document.getElementById('acs-ai-compact-status');
    const drawerProvider = document.getElementById('jr-ai-drawer-provider');
    if (aiRuntimeStatus.configured) {
        const text = providerDisplayName(aiRuntimeStatus.provider) +
            (aiRuntimeStatus.model ? ' · ' + aiRuntimeStatus.model : '') +
            (aiRuntimeStatus.connected ? ' · conectado' : ' · não testado');
        if (aiStatus) aiStatus.textContent = text;
        if (drawerProvider) drawerProvider.textContent = text;
    }
}

async function loadAIStatus() {
    try {
        const response = await fetch('/api/ai-status.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || !data.success) return;

        aiRuntimeStatus = {
            configured: !!data.configured,
            provider: data.provider || '',
            model: data.model || '',
            connected: !!data.connected
        };
        updateOverviewOperationalMeta();
    } catch (_) {
        // Mantém a interface funcional mesmo se o status da IA estiver indisponível.
    }
}

function runDeviceAISummary() {
    openDeviceAIDrawer();
    const prompt = [
        'Faça um resumo técnico deste equipamento para o suporte da JR CONECT.',
        'Use somente os dados atuais disponíveis.',
        'Organize em: Estado geral, Fibra/GPON, WAN/PPPoE, Wi-Fi/LAN, possíveis alertas e próximos testes recomendados.',
        'Não invente medições nem ações executadas.'
    ].join(' ');
    setTimeout(() => askDeviceAI(prompt), 180);
}

// Helper function to get active tab name
function getActiveTabName() {
    const activeTab = document.querySelector('.nav-link.active');
    if (!activeTab) return 'Unknown';

    const tabText = activeTab.textContent.trim();
    return tabText.replace(/\(\d+\)/g, '').trim(); // Remove badge counts like (2)
}

// Save current hotspot data before re-render
function saveCurrentHotspotData() {
    savedHotspotData = {};

    const userCells = document.querySelectorAll('.hotspot-user[data-mac]');
    const trafficCells = document.querySelectorAll('.hotspot-traffic[data-mac]');

    userCells.forEach(cell => {
        const mac = cell.getAttribute('data-mac');
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].userHtml = cell.innerHTML;
    });

    trafficCells.forEach(cell => {
        const mac = cell.getAttribute('data-mac');
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].trafficHtml = cell.innerHTML;
    });

    if (Object.keys(savedHotspotData).length > 0) {
        console.debug('[HOTSPOT] Saved data for', Object.keys(savedHotspotData).length, 'devices before re-render');
    }
}

// Restore saved hotspot data after re-render
function restoreSavedHotspotData() {
    let restoredCount = 0;

    Object.keys(savedHotspotData).forEach(mac => {
        const data = savedHotspotData[mac];
        const userCell = document.querySelector(`.hotspot-user[data-mac="${mac}"]`);
        const trafficCell = document.querySelector(`.hotspot-traffic[data-mac="${mac}"]`);

        if (userCell && data.userHtml) {
            userCell.innerHTML = data.userHtml;
            restoredCount++;
        }

        if (trafficCell && data.trafficHtml) {
            trafficCell.innerHTML = data.trafficHtml;
        }
    });

    if (restoredCount > 0) {
        console.debug('[HOTSPOT] Restored data for', restoredCount, 'devices after re-render');
    }
}

async function loadDeviceDetail(isAutoRefresh = false) {
    // A aba de monitoramento tem seu próprio ciclo de coleta (IXC/NE8000).
    // Recriar toda a aba a cada 30 segundos apaga o gráfico e causa o efeito
    // visual de a tela sumir e voltar enquanto o suporte acompanha o cliente.
    if (isAutoRefresh && document.getElementById('monitoring-tab')?.classList.contains('active')) {
        console.debug('[AUTO-REFRESH] Monitoramento ativo; preservando a tela e o gráfico.');
        return;
    }

    // SKIP auto-refresh if hotspot monitoring is active to prevent conflicts
    if (isAutoRefresh && hotspotMonitoringActive) {
        console.debug('[AUTO-REFRESH] Skipping device detail refresh while hotspot monitoring is active');
        return; // Skip refresh completely
    }

    // Save hotspot data BEFORE re-render (if auto-refresh)
    if (isAutoRefresh) {
        saveCurrentHotspotData();
    }

    // Save current scroll position if this is an auto-refresh
    if (isAutoRefresh) {
        savedScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
        const activeTab = getActiveTabName();
        console.debug(`[AUTO-REFRESH] Saving scroll position: ${savedScrollPosition}px (Active tab: ${activeTab})`);
    } else {
        // Manual refresh - reset scroll to top
        savedScrollPosition = 0;
    }

    // For auto-refresh: NO loading spinner at all (silent refresh)
    // For manual refresh: Show full loading spinner
    if (!isAutoRefresh) {
        // Full loading for manual refresh only
        document.getElementById('loading-spinner').innerHTML = '<div class="spinner"></div>';
        document.getElementById('loading-spinner').style.display = 'block';
        document.getElementById('deviceTabs').style.display = 'none';
        document.getElementById('deviceTabContent').style.display = 'none';
    }

    const result = await fetchAPI('/api/get-device-detail.php?device_id=' + encodeURIComponent(deviceId));

    if (result && result.success) {
        const device = result.device;
        currentDeviceData = device;
        lastDeviceRefreshAt = Date.now();

        // Update modern device header
        document.getElementById('device-id-badge').textContent = device.serial_number;

        const modelTitle = document.getElementById('device-model-title');
        if (modelTitle) {
            modelTitle.textContent = device.product_class || device.model || device.manufacturer || 'Equipamento';
        }

        const ipHeader = document.getElementById('device-ip-header');
        if (ipHeader) {
            ipHeader.textContent = extractIP(device.ip_tr069) || 'IP não disponível';
        }

        const statusHeader = document.getElementById('device-status-header');
        if (statusHeader) {
            const online = String(device.status || '').toLowerCase() === 'online';
            statusHeader.textContent = online ? 'Online' : 'Offline';
            statusHeader.classList.toggle('online', online);
            statusHeader.classList.toggle('offline', !online);
        }

        const drawerModel = document.getElementById('jr-ai-drawer-device-model');
        const drawerStatus = document.getElementById('jr-ai-drawer-device-status');
        if (drawerModel) {
            drawerModel.textContent = device.product_class || device.model || device.manufacturer || 'Equipamento';
        }
        if (drawerStatus) {
            drawerStatus.textContent = String(device.status || '').toLowerCase() === 'online' ? 'ONLINE' : 'OFFLINE';
            drawerStatus.classList.toggle('online', String(device.status || '').toLowerCase() === 'online');
        }

        // Update tags badge
        updateTagsBadge(device.tags || []);

        // Update badge counts
        document.getElementById('wan-count-badge').textContent = device.wan_details ? device.wan_details.length : 0;
        document.getElementById('devices-count-badge').textContent = device.connected_devices ? device.connected_devices.length : 0;

        // Populate Overview Tab - JR CONECT ACS V4 (modelo aprovado)
        const primaryWan = Array.isArray(device.wan_details)
            ? (device.wan_details.find(w => String(w.status || '').toLowerCase() === 'connected') || device.wan_details[0] || {})
            : {};

        const connectedDevices = Array.isArray(device.connected_devices) ? device.connected_devices : [];
        const wanCount = Array.isArray(device.wan_details) ? device.wan_details.length : 0;
        const wifiName = device.wifi_ssid || 'SSID não identificado';
        const lanPorts = Array.isArray(device.lan_ports) ? device.lan_ports : [];

        const renderLanVisual = (ports) => {
            const normalized = ports.length ? ports.slice(0, 4) : [1,2,3,4].map(i => ({ name: 'LAN ' + i, status: 'down' }));
            return normalized.map((port, idx) => {
                const name = port.name || ('LAN ' + (idx + 1));
                const status = String(port.status || '').toLowerCase();
                const isUp = status === 'up' || status === 'connected';
                const speedRaw = port.max_bit_rate || port.speed || '';
                let speed = '-';
                if (speedRaw !== '' && speedRaw !== null && speedRaw !== undefined && speedRaw !== 'N/A') {
                    const n = Number(speedRaw);
                    speed = Number.isFinite(n) ? (n >= 1000 ? (n / 1000) + ' Gbps' : n + ' Mbps') : String(speedRaw);
                }
                const portNumber = Number(port.port || (idx + 1));
                const ethernetDevices = connectedDevices.filter(d => String(d.interface_type || '').toLowerCase() === 'ethernet');
                const linkedCandidate = ethernetDevices.find(d => Number(d.lan_port) === portNumber) || null;
                const linked = isUp ? linkedCandidate : null;
                const linkedName = linked
                    ? (linked.hostname || linked.vendor || linked.ip_address || 'Dispositivo')
                    : (isUp ? 'Porta ativa' : '-');
                const linkedIp = linked?.ip_address || '';

                return `
                    <div class="acs-lan-visual ${isUp ? 'up' : 'down'}">
                        <div class="acs-lan-port-heading">
                            <strong>${name}</strong>
                            <span class="acs-lan-link-led ${isUp ? 'on' : 'off'}" title="${isUp ? 'Link ativo' : 'Sem link'}"></span>
                        </div>
                        <div class="acs-rj45-port-face">
                            <span class="acs-rj45-slot"></span>
                            <span class="acs-rj45-pins"></span>
                        </div>
                        <span class="acs-lan-state">${isUp ? 'Conectada' : 'Desconectada'}</span>
                        <small>${isUp ? speed : '-'}</small>
                        <small>${isUp ? 'Full' : '-'}</small>
                        <div class="acs-lan-device ${linked ? 'identified' : ''}">
                            <i class="bi bi-pc-display"></i>
                            <span>${linkedName}</span>
                            ${linkedIp ? `<small>${linkedIp}</small>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        };

        const renderConnectedCompact = (items) => {
            if (!items.length) {
                return '<div class="acs-empty-state">Nenhum dispositivo conectado.</div>';
            }
            return `
                <div class="acs-connected-compact-head">
                    <span>Nome do dispositivo</span><span>IP</span><span>MAC</span><span>Via</span>
                </div>
                ${items.slice(0, 5).map((d, idx) => `
                    <div class="acs-connected-compact-row">
                        <strong>${d.vendor || d.hostname || 'Dispositivo ' + (idx + 1)}</strong>
                        <span>${d.ip_address || '-'}</span>
                        <span>${d.mac_address || '-'}</span>
                        <span>${d.lan_port ? ('LAN' + d.lan_port) : (d.interface_type || d.interface || '-')}</span>
                    </div>
                `).join('')}
            `;
        };

        const onuOnline = device.status === 'online';
        const wanConnected = String(primaryWan.status || '').toLowerCase() === 'connected';
        const profileLabel = device.cpe_profile_source === 'auto-discovery'
            ? 'Auto Discovery'
            : device.cpe_profile_source === 'static+auto-discovery'
                ? 'Perfil + Auto Discovery'
                : 'Perfil fixo';
        const hostCount = Array.isArray(device.connected_devices)
            ? device.connected_devices.length
            : (device.connected_devices_count ?? '—');
        const escOverview = (value) => String(value ?? '—').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
        const ssid24 = device.wifi_ssid_24ghz || device.wifi_ssid_2g || device.wifi_ssid || '—';
        const ssid5 = device.wifi_ssid_5ghz || device.wifi_ssid_5g || device.wifi_ssid_5g_name || '—';
        const discoveryChip = document.getElementById('fb-discovery-chip');
        if (discoveryChip) {
            const auto = /auto/i.test(String(device.cpe_profile_source || ''));
            discoveryChip.classList.toggle('is-auto', auto);
            discoveryChip.title = auto
                ? 'Perfil identificado por descoberta automática neste CPE.'
                : 'Este CPE usa o perfil já cadastrado. A descoberta não acrescentou outro modo de leitura.';
        }
        const seenLabel = formatOpticalDate(device.last_inform);
        const uptimeLabel = formatUptimeValue(device.uptime);

        document.getElementById('overview-content').innerHTML = `
            <section class="jr-assistant-strip">
                <div class="jr-assistant-main">
                    <div class="acs-ai-topbar-icon"><i class="bi bi-stars"></i></div>
                    <div class="acs-ai-topbar-copy">
                        <span>ASSISTENTE IA</span>
                        <strong>Assistente técnico JR CONECT</strong>
                        <small id="acs-ai-compact-status">Consultando provedor...</small>
                    </div>
                </div>
                <div class="jr-assistant-ops">
                    <div id="acs-device-health" class="acs-health-pill neutral">
                        <i class="bi bi-heart-pulse"></i>
                        <span>VERIFICANDO</span>
                        <small>Saúde operacional</small>
                    </div>
                    <div class="acs-source-ages">
                        <div class="acs-source-age">
                            <i class="bi bi-router"></i>
                            <span>ACS</span>
                            <strong id="acs-overview-acs-age">aguardando</strong>
                        </div>
                        <div class="acs-source-age">
                            <i class="bi bi-reception-4"></i>
                            <span>Óptico/IXC</span>
                            <strong id="acs-overview-optical-age">aguardando</strong>
                        </div>
                    </div>
                </div>
                <div class="jr-assistant-actions">
                    <button type="button" class="acs-soft-btn acs-ai-summary-btn" onclick="runDeviceAISummary()">
                        <i class="bi bi-clipboard2-pulse"></i><span>Resumo técnico</span>
                    </button>
                    <button type="button" class="acs-soft-btn primary acs-ai-open-btn" onclick="openDeviceAIDrawer()">
                        <i class="bi bi-chat-dots"></i><span>Abrir IA</span>
                    </button>
                </div>
            </section>

            <div class="fb-onu">
                <section class="acs-overview-card fb-diag-card">
                    <h2 class="fb-section-title">Diagnóstico rápido</h2>
                    <div class="fb-diag-grid">
                        <div class="fb-diag-item ${onuOnline ? '' : 'is-bad'}"><i class="bi bi-circle-fill"></i><span>Conectividade</span><strong>${onuOnline ? 'Online' : 'Sem contato'} · ${escOverview(seenLabel)}</strong></div>
                        <div class="fb-diag-item ${wanConnected ? '' : 'is-bad'}"><i class="bi bi-circle-fill"></i><span>Conexão WAN</span><strong>${escOverview(primaryWan.status || '—')}</strong></div>
                        <div class="fb-diag-item"><i class="bi bi-circle-fill"></i><span>Sinal óptico (RX)</span><strong data-optical="rx_power">Consultando...</strong></div>
                        <div class="fb-diag-item"><i class="bi bi-circle-fill"></i><span>Transmissão óptica (TX)</span><strong data-optical="tx_power">Consultando...</strong></div>
                        <div class="fb-diag-item"><i class="bi bi-circle-fill"></i><span>Temperatura</span><strong data-optical="temperature">Consultando...</strong></div>
                        <div class="fb-diag-item"><i class="bi bi-circle-fill"></i><span>Voltagem</span><strong data-optical="voltage">Consultando...</strong></div>
                        <div class="fb-diag-item"><i class="bi bi-circle-fill"></i><span>Uptime</span><strong>${escOverview(uptimeLabel)}</strong></div>
                        <div class="fb-diag-item"><i class="bi bi-circle-fill"></i><span>PPPoE</span><strong data-mirror="wan-pppoe-user">${escOverview(primaryWan.username || '—')}</strong></div>
                        <div class="fb-diag-item"><i class="bi bi-circle-fill"></i><span>Hosts conectados</span><strong>${escOverview(hostCount)}</strong></div>
                    </div>
                </section>

                <div class="fb-onu-split">
                    ${renderOpticalHistoryCard()}
                    <section class="acs-overview-card">
                        <div class="acs-overview-card-header">
                            <span class="acs-kicker">Tráfego WAN</span>
                            <span class="acs-mini-badge ${wanConnected ? 'success' : ''}">${escOverview(primaryWan.status || 'STATUS')}</span>
                        </div>
                        <div class="fb-traffic">
                            <div><span>Download agora</span><strong id="live-rx-mbps">--</strong><small>Mbps</small></div>
                            <div><span>Upload agora</span><strong id="live-tx-mbps">--</strong><small>Mbps</small></div>
                        </div>
                        <div id="bandwidth-bars" class="acs-bandwidth-bars"></div>
                        <p class="fb-traffic-note" id="bandwidth-sample-status">Aguardando segunda leitura...</p>
                        <span id="live-rx-total" hidden></span>
                        <span id="live-tx-total" hidden></span>
                        <span id="live-traffic-source" hidden></span>
                    </section>
                </div>

                <section class="fb-indicators">
                    <h2 class="fb-section-title">Indicadores</h2>
                    <div class="fb-ind-grid">
                        <article class="fb-ind"><span>Sinal RX</span><strong id="optical-rx-power">—</strong><em id="optical-rx-power-pill" class="fb-pill">—</em></article>
                        <article class="fb-ind"><span>Sinal TX</span><strong id="optical-tx-power">—</strong><em id="optical-tx-power-pill" class="fb-pill">—</em></article>
                        <article class="fb-ind"><span>Temperatura</span><strong id="optical-temperature">—</strong><em id="optical-temperature-pill" class="fb-pill">—</em></article>
                        <article class="fb-ind"><span>Voltagem</span><strong id="optical-voltage">—</strong><em id="optical-voltage-pill" class="fb-pill">—</em></article>
                        <article class="fb-ind fb-ind-copy"><span>Tempo ligado</span><strong>${escOverview(uptimeLabel)}</strong></article>
                        <article class="fb-ind fb-ind-copy"><span>Última conexão</span><strong>${escOverview(seenLabel)}</strong></article>
                        <article class="fb-ind fb-ind-copy"><span>Hosts conectados</span><strong>${escOverview(hostCount)}</strong></article>
                        <article class="fb-ind"><span>OLT / PON</span><strong id="optical-pon-id">—</strong><small id="optical-last-update"></small><span id="optical-source" hidden></span></article>
                    </div>
                </section>

                <div class="fb-onu-split">
                    <section class="acs-overview-card acs-card-wan acs-approved-wan">
                        <div class="acs-overview-card-header">
                            <span class="acs-kicker">Conexão e Wi-Fi</span>
                            <button class="acs-toolbar-action" type="button" onclick="document.getElementById('wifi-tab')?.click()">Abrir Wi-Fi</button>
                        </div>
                        <div class="acs-reference-list">
                            <div><span>Cliente</span><strong id="fb-client-name">—</strong></div>
                            <div><span>IP WAN</span><strong id="wan-ip">${makeIPClickable(primaryWan.external_ip || extractIP(device.ip_tr069))}</strong></div>
                            <div><span>PPPoE</span><strong id="wan-pppoe-user">${escOverview(primaryWan.username || '—')}</strong></div>
                            <div><span>Método</span><strong>${escOverview(primaryWan.type || primaryWan.connection_type || 'PPPoE')}</strong></div>
                            <div><span>IPv6</span><strong id="wan-ipv6">${escOverview(primaryWan.ipv6 || '—')}</strong></div>
                            <div><span>VLAN</span><strong id="wan-vlan">${escOverview(primaryWan.vlan_id || primaryWan.vlan || '—')}</strong></div>
                            <div><span>Wi-Fi 2,4 GHz</span><strong>${escOverview(ssid24)}</strong></div>
                            <div><span>Wi-Fi 5 GHz</span><strong>${escOverview(ssid5)}</strong></div>
                            <div><span>Último erro</span><strong id="wan-last-error">${escOverview(primaryWan.last_error || '—')}</strong></div>
                        </div>
                    </section>
                    <section class="acs-overview-card acs-card-device acs-approved-device">
                        <div class="acs-overview-card-header">
                            <span class="acs-kicker">Equipamento</span>
                            <span class="acs-status-pill ${onuOnline ? 'online' : 'offline'}">${onuOnline ? 'Online' : 'Offline'}</span>
                        </div>
                        <div class="acs-reference-list">
                            <div><span>Modelo</span><strong>${escOverview(device.product_class || device.model || '—')}</strong></div>
                            <div><span>Fabricante</span><strong>${escOverview(device.manufacturer || '—')}</strong></div>
                            <div><span>Serial</span><strong>${escOverview(device.serial_number || '—')}</strong></div>
                            <div><span>Firmware</span><strong>${escOverview(device.software_version || '—')}</strong></div>
                            <div><span>Hardware</span><strong>${escOverview(device.hardware_version || '—')}</strong></div>
                            <div><span>MAC</span><strong>${escOverview(device.mac_address || '—')}</strong></div>
                            <div><span>Perfil TR-069</span><strong>${escOverview(profileLabel)}</strong></div>
                        </div>
                    </section>
                </div>

                <section class="acs-overview-card fb-alerts-card">
                    <h2 class="fb-section-title">Alertas ativos</h2>
                    <div id="fb-active-alerts"><p class="fb-alert-empty">Consultando alertas...</p></div>
                </section>
            </div>
        `;

        const historyMount = document.getElementById('history-content');
        if (historyMount) historyMount.innerHTML = renderDeviceActionHistoryCard();
        const opticalMount = document.getElementById('optical-chart-mount');
        if (opticalMount) opticalMount.innerHTML = renderOpticalHistoryCard('tab');
        if (typeof paintActiveAlerts === 'function') paintActiveAlerts();

        renderWifiHealthCard(device);
        jrcApplyWifiClientCounters(device);
        jrEnhanceWifiClientCounters(device);
        loadOpticalHistory(device.serial_number);
        loadDeviceActionHistory(device.device_id, deviceActionHistoryBefore);

        // Reaplica imediatamente o cache IXC após qualquer re-render,
        // inclusive no auto-refresh de 30s. Assim o card WAN não volta
        // temporariamente a exibir a camada TR-069 como principal.
        if (cachedOpticalData && cachedOpticalData.device_id === device.device_id && !cachedOpticalData.error) {
            updateOpticalDomFromCache();
        }

        renderStoredDeviceAIState();
        updateOverviewOperationalMeta();

        // Buscar dados ópticos FiberHome via TL1 sem bloquear o carregamento principal.
        // No auto-refresh, não inicia uma nova consulta se já existir uma em andamento
        // ou se já houver dados em cache para este equipamento.
        if (!isAutoRefresh) {
            loadFiberhomeOptical(device.device_id, true);
        } else if (opticalLoadedForDevice !== device.device_id && !opticalLoading) {
            loadFiberhomeOptical(device.device_id, false);
        }

        // Populate WAN Connections Tab
        document.getElementById('wan-content').innerHTML = renderWANDetailsTab(device.wan_details);

        // Populate DHCP Server Tab
        document.getElementById('dhcp-content').innerHTML = renderDHCPServerTab(device.dhcp_server);

        // Populate Firmware Tab
        document.getElementById('firmware-content').innerHTML = renderFirmwareTab(device);

        // Populate Connected Devices Tab
        document.getElementById('devices-content').innerHTML = renderConnectedDevicesTab(device.connected_devices);
        jrcEnhanceConnectedClientsTable(device.connected_devices);

        // Populate Monitoring tab
        document.getElementById('monitoring-content').innerHTML = renderMonitoringTab(device);
        renderStoredDeviceAIState();
        startBandwidthMonitoring();

        // Restore hotspot data after re-render (if available)
        if (isAutoRefresh && Object.keys(savedHotspotData).length > 0) {
            setTimeout(() => {
                restoreSavedHotspotData();
                console.debug('[AUTO-REFRESH] Restored hotspot data for', Object.keys(savedHotspotData).length, 'devices');
            }, 50);
        }

        // Hide loading, show tabs (only if they were hidden)
        document.getElementById('loading-spinner').style.display = 'none';
        if (!isAutoRefresh) {
            document.getElementById('deviceTabs').style.display = 'flex';
            document.getElementById('deviceTabContent').style.display = 'block';
            /* JRC_DIAG_ACOES_V1 — Diagnóstico do menu abre a aba Ações deste equipamento. */
            const firmwareLink = document.getElementById('nav-firmware');
            const diagLink = document.getElementById('nav-diagnostico');
            const devicesLink = document.querySelector('.fb-nav a[href="/devices.php"]');
            const actionsTab = (function () {
                var byId = document.getElementById('diagnostics-tab');
                if (byId) return byId;
                var pane = document.getElementById('acs-diagnostics-content');
                pane = pane && pane.closest ? pane.closest('.tab-pane') : null;
                if (!pane || !pane.id) return null;
                return document.querySelector('[data-bs-target="#' + pane.id + '"]');
            })();
            const onActionsTab = function (btn) {
                if (!btn) return false;
                if (actionsTab && btn === actionsTab) return true;
                var sel = btn.getAttribute && btn.getAttribute('data-bs-target');
                if (!sel) return false;
                var pane = document.querySelector(sel);
                return !!(pane && pane.querySelector && pane.querySelector('#acs-diagnostics-content'));
            };
            const paintShortcut = function (btn) {
                var onFirmware = btn && btn.id === 'firmware-tab';
                var onActions = onActionsTab(btn);
                if (firmwareLink) firmwareLink.classList.toggle('active', onFirmware);
                if (diagLink) diagLink.classList.toggle('active', onActions);
                if (devicesLink) devicesLink.classList.toggle('active', !onFirmware && !onActions);
            };
            document.getElementById('deviceTabs')?.addEventListener('shown.bs.tab', (event) => {
                paintShortcut(event.target);
            });
            const aba = new URLSearchParams(location.search).get('aba');
            if (aba === 'firmware') {
                const firmwareTab = document.getElementById('firmware-tab');
                if (firmwareTab && window.bootstrap?.Tab) {
                    bootstrap.Tab.getOrCreateInstance(firmwareTab).show();
                }
            }
            if (aba === 'acoes' && actionsTab && window.bootstrap?.Tab) {
                bootstrap.Tab.getOrCreateInstance(actionsTab).show();
            }
        }

        // Restore scroll position after refresh (if saved)
        if (isAutoRefresh && savedScrollPosition > 0) {
            setTimeout(() => {
                window.scrollTo(0, savedScrollPosition);
                const activeTab = getActiveTabName();
                console.debug(`[AUTO-REFRESH] ✓ Restored scroll position: ${savedScrollPosition}px (Active tab: ${activeTab})`);
            }, 50);
        }

        // Restore scroll position after Get Credentials (from sessionStorage)
        const credentialsScrollPos = sessionStorage.getItem('credentialsScrollPosition');
        if (credentialsScrollPos) {
            setTimeout(() => {
                window.scrollTo(0, parseInt(credentialsScrollPos));
                console.log('[GET-CREDENTIALS] ✓ Restored scroll position:', credentialsScrollPos);
                // Clear sessionStorage after use
                sessionStorage.removeItem('credentialsScrollPosition');
            }, 50);
        }

        // Restart hotspot monitoring if it was active before refresh
        if (hotspotMonitoringActive) {
            // Add delay to prevent immediate fetch after refresh (reduce load spikes)
            setTimeout(() => {
                const loadBtn = document.getElementById('load-hotspot-btn');
                if (loadBtn) {
                    console.log('[AUTO-REFRESH] Restarting hotspot monitoring (with 3s delay to reduce load)...');
                    // Don't call startHotspotTrafficMonitoring() directly - it fetches immediately
                    // Instead, just restart the interval without immediate fetch
                    const stopBtn = document.getElementById('stop-hotspot-btn');
                    const statusEl = document.getElementById('hotspot-status');

                    if (loadBtn) loadBtn.style.display = 'none';
                    if (stopBtn) stopBtn.style.display = 'inline-block';
                    if (statusEl) statusEl.innerHTML = '<span class="text-muted">⏱ Waiting for next update...</span>';

                    // 5 second interval - balanced between smooth updates and MikroTik load
                    hotspotTrafficInterval = setInterval(fetchHotspotTraffic, 5000);
                    console.log('[HOTSPOT] Monitoring restarted (5s interval)');
                }
            }, 3000); // 3 second delay before restarting
        }
    } else {
        // Show error in loading area
        document.getElementById('loading-spinner').innerHTML = '<div class="alert alert-danger">Failed to load device details</div>';
    }
}

function renderPhysicalPorts(ports) {
    if (!Array.isArray(ports) || ports.length === 0) {
        return `
            <div class="acs-physical-ports">
                <div class="acs-physical-ports-title">
                    <span><i class="bi bi-ethernet"></i> Portas físicas</span>
                    <small>Sem leitura</small>
                </div>
                <div class="acs-physical-ports-empty">Dados das portas LAN ainda não coletados.</div>
            </div>
        `;
    }

    const formatSpeed = (value) => {
        if (value === null || value === undefined || value === '' || value === 'N/A') return 'Auto';
        const text = String(value).trim();
        if (/^auto$/i.test(text)) return 'Auto';
        const speed = Number(text);
        if (!Number.isFinite(speed)) return text;
        if (speed >= 1000) return (speed / 1000) + 'G';
        return speed + 'M';
    };

    return `
        <div class="acs-physical-ports">
            <div class="acs-physical-ports-title">
                <span><i class="bi bi-ethernet"></i> Portas físicas</span>
                <small>${ports.length} LAN</small>
            </div>
            <div class="acs-physical-ports-grid">
                ${ports.map(port => {
                    const status = String(port.status || '').toLowerCase();
                    const isUp = status === 'up' || status === 'connected';
                    const isDisabled = port.enabled === false || String(port.enabled).toLowerCase() === 'false';

                    return `
                        <div class="acs-lan-port ${isUp ? 'link-up' : 'link-down'} ${isDisabled ? 'disabled' : ''}"
                             title="${port.name}: ${port.status || 'N/D'}">
                            <div class="acs-lan-port-icon"><i class="bi bi-ethernet"></i></div>
                            <strong>${port.name}</strong>
                            <span>${isUp ? formatSpeed(port.max_bit_rate) : 'Sem link'}</span>
                        </div>
                    `;
                }).join('')}
            </div>
        </div>
    `;
}

function extractIP(ipString) {
    if (!ipString || ipString === 'N/A') return 'N/A';
    const match = ipString.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
    return match ? match[1] : 'N/A';
}

function makeIPClickable(ip) {
    if (!ip || ip === 'N/A' || ip === '0.0.0.0') {
        return ip;
    }

    // Check if it's a valid IP address
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (!ipRegex.test(ip)) {
        return ip;
    }

    return `<a class="fb-ip-link" href="http://${ip}" target="_blank" rel="noopener noreferrer" title="Abrir http://${ip}">${ip}</a>`;
}

function renderWANDetailsTab(wanDetails) {
    const hasValue = (value) => (
        value !== null &&
        value !== undefined &&
        value !== '' &&
        value !== 'N/A' &&
        value !== '0.0.0.0'
    );

    if (!wanDetails || wanDetails.length === 0) {
        return `
            <div class="acs-wan-page">
                <div class="acs-wan-page-header">
                    <div>
                        <span class="acs-kicker"><i class="bi bi-globe2"></i> Conectividade</span>
                        <h4>Interfaces WAN</h4>
                        <p>Nenhuma conexão WAN foi identificada neste equipamento.</p>
                    </div>
                    <button class="acs-wan-primary-btn" type="button" onclick="openAddWANModal()">
                        <i class="bi bi-plus-lg"></i> Adicionar WAN
                    </button>
                </div>

                <div class="acs-wan-empty">
                    <i class="bi bi-router"></i>
                    <strong>Sem conexões WAN</strong>
                    <span>Quando o equipamento disponibilizar uma interface WAN pelo TR-069, ela aparecerá aqui.</span>
                    <button type="button" onclick="openAddWANModal()">
                        <i class="bi bi-plus-lg"></i> Adicionar primeira conexão
                    </button>
                </div>
            </div>
        `;
    }

    const connectedCount = wanDetails.filter(wan => String(wan.status || '').toLowerCase() === 'connected').length;

    let html = `
        <div class="acs-wan-page">
            <div class="acs-wan-page-header">
                <div>
                    <span class="acs-kicker"><i class="bi bi-globe2"></i> Conectividade</span>
                    <h4>Interfaces WAN</h4>
                    <p>Parâmetros de Internet coletados do equipamento via TR-069.</p>
                </div>

                <div class="acs-wan-header-actions">
                    <div class="acs-wan-summary-pill">
                        <strong>${connectedCount}</strong>
                        <span>conectada${connectedCount === 1 ? '' : 's'}</span>
                    </div>
                    <div class="acs-wan-summary-pill">
                        <strong>${wanDetails.length}</strong>
                        <span>total</span>
                    </div>
                    <button class="acs-wan-primary-btn" type="button" onclick="openAddWANModal()">
                        <i class="bi bi-plus-lg"></i> Adicionar WAN
                    </button>
                </div>
            </div>

            <div class="acs-wan-grid">
    `;

    wanDetails.forEach((wan, index) => {
        const isConnected = String(wan.status || '').toLowerCase() === 'connected';
        const connectionType = wan.connection_type || 'N/A';
        const isBridge = /bridge|bridged/i.test(connectionType);
        const vlanMatch = String(wan.name || '').match(/VID[_-]?(\d+)/i);
        const vlanId = vlanMatch ? vlanMatch[1] : null;
        const isTR069 = /TR069|CWMP/i.test(String(wan.service_list || '')) || /TR069|CWMP/i.test(String(wan.name || ''));
        const displayName = wan.name || `WAN ${index + 1}`;
        const typeLabel = wan.type || (isBridge ? 'Bridge' : 'WAN');

        html += `
            <section class="acs-wan-card">
                <div class="acs-wan-card-top">
                    <div class="acs-wan-card-identity">
                        <div class="acs-wan-card-icon">
                            <i class="bi ${isBridge ? 'bi-diagram-3' : (wan.type === 'PPPoE' ? 'bi-person-badge' : 'bi-globe2')}"></i>
                        </div>
                        <div>
                            <span class="acs-wan-index">WAN ${index + 1}</span>
                            <h5>${displayName}</h5>
                        </div>
                    </div>

                    <div class="acs-wan-card-status">
                        <span class="acs-wan-state ${isConnected ? 'online' : 'offline'}">
                            <span></span>
                            ${isConnected ? 'CONECTADA' : 'DESCONECTADA'}
                        </span>
                    </div>
                </div>

                <div class="acs-wan-tags">
                    <span>${typeLabel}</span>
                    <span>${connectionType}</span>
                    ${vlanId ? `<span>VLAN ${vlanId}</span>` : ''}
                    ${isTR069 ? '<span class="danger">TR-069</span>' : ''}
                </div>

                <div class="acs-wan-hero">
                    <span>Endereço IP</span>
                    <strong>${hasValue(wan.external_ip) ? makeIPClickable(wan.external_ip) : 'Não disponível'}</strong>
                </div>

                <div class="acs-wan-details">
                    ${hasValue(wan.gateway) ? `
                        <div>
                            <span><i class="bi bi-signpost-2"></i> Gateway</span>
                            <strong>${makeIPClickable(wan.gateway)}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.subnet_mask) ? `
                        <div>
                            <span><i class="bi bi-diagram-2"></i> Máscara</span>
                            <strong>${wan.subnet_mask}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.dns_servers) ? `
                        <div>
                            <span><i class="bi bi-hdd-network"></i> DNS</span>
                            <strong>${wan.dns_servers}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.mac_address) && wan.mac_address !== '00:00:00:00:00:00' ? `
                        <div>
                            <span><i class="bi bi-upc-scan"></i> MAC</span>
                            <strong>${wan.mac_address}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'PPPoE' && hasValue(wan.username) ? `
                        <div>
                            <span><i class="bi bi-person"></i> Usuário PPPoE</span>
                            <strong>${wan.username}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'IP' && hasValue(wan.addressing_type) ? `
                        <div>
                            <span><i class="bi bi-gear-wide-connected"></i> Endereçamento</span>
                            <strong>${wan.addressing_type}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.binding) ? `
                        <div>
                            <span><i class="bi bi-link-45deg"></i> Binding</span>
                            <strong>${wan.binding}</strong>
                        </div>
                    ` : ''}

                    ${hasValue(wan.uptime) && String(wan.uptime) !== '0' ? `
                        <div>
                            <span><i class="bi bi-clock-history"></i> Uptime</span>
                            <strong>${formatUptime(wan.uptime)}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'PPPoE' && hasValue(wan.mru_size) && String(wan.mru_size) !== '0' ? `
                        <div>
                            <span><i class="bi bi-arrows-expand"></i> MRU</span>
                            <strong>${wan.mru_size}</strong>
                        </div>
                    ` : ''}

                    ${wan.type === 'PPPoE' && hasValue(wan.last_error) ? `
                        <div class="acs-wan-error-row">
                            <span><i class="bi bi-exclamation-triangle"></i> Último erro</span>
                            <strong>${wan.last_error}</strong>
                        </div>
                    ` : ''}
                </div>

                <div class="acs-wan-card-actions">
                    <button type="button" onclick='openEditWANModal(${JSON.stringify(wan)})'>
                        <i class="bi bi-pencil"></i> Editar
                    </button>
                    <button type="button" class="danger" onclick='openDeleteWANModal(${JSON.stringify(wan)})'>
                        <i class="bi bi-trash"></i> Excluir
                    </button>
                </div>
            </section>
        `;
    });

    html += `
            </div>

            ${renderWanUsageCards(wanDetails)}

            <div class="acs-wan-footnote">
                <i class="bi bi-info-circle"></i>
                <span>IPv6 será exibido aqui quando adicionarmos a coleta desses parâmetros ao backend do ACS.</span>
            </div>
        </div>
    `;

    return html;
}

/* JRC_WAN_TAB_USAGE_V1 */
function renderWanUsageCards(wanDetails) {
    const list = Array.isArray(wanDetails) ? wanDetails : [];
    const primary = list.find(w => String(w.status || '').toLowerCase() === 'connected') || list[0] || {};
    const connected = String(primary.status || '').toLowerCase() === 'connected';
    const statusText = String(primary.status || 'STATUS').replace(/[&<>"']/g, ch => (
        {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]
    ));

    return `
        <div class="jr-wan-usage">
            <section class="acs-wan-card jr-wan-usage-card">
                <div class="acs-wan-card-top">
                    <span class="acs-kicker"><i class="bi bi-activity"></i> Tráfego WAN</span>
                    <span class="acs-mini-badge ${connected ? 'success' : ''}">${statusText}</span>
                </div>
                <div class="jr-wan-rates">
                    <div><span>Download agora</span><strong id="live-rx-mbps">--</strong><small>Mbps</small></div>
                    <div><span>Upload agora</span><strong id="live-tx-mbps">--</strong><small>Mbps</small></div>
                </div>
                <div id="bandwidth-bars" class="acs-bandwidth-bars"></div>
                <p class="jr-wan-usage-note" id="bandwidth-sample-status">Aguardando segunda leitura...</p>
            </section>

            <section class="acs-wan-card jr-wan-usage-card">
                <div class="acs-wan-card-top">
                    <span class="acs-kicker"><i class="bi bi-bar-chart-line"></i> Histórico de uso</span>
                    <span class="jr-wan-usage-source" id="jr-ixc-consumption-source">Carregando fonte...</span>
                </div>
                <div class="jr-wan-usage-title">Consumo dos últimos 30 dias</div>
                <div id="jr-ixc-consumption"><div class="jr-ixc-empty">Carregando consumo...</div></div>
            </section>
        </div>
    `;
}

function renderWANDetails(wanDetails) {
    if (!wanDetails || wanDetails.length === 0) {
        return '';
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += '<div class="d-flex justify-content-between align-items-center mb-2">';
    html += '<h6><i class="bi bi-globe"></i> WAN Connection Details</h6>';
    html += '<button class="btn btn-sm btn-success" onclick="openAddWANModal()"><i class="bi bi-plus-lg"></i> Add WAN Connection</button>';
    html += '</div>';

    wanDetails.forEach((wan, index) => {
        const statusBadge = wan.status === 'Connected' ?
            '<span class="badge online">Connected</span>' :
            '<span class="badge offline">Disconnected</span>';

        // Check if this is a bridge connection
        const isBridge = wan.connection_type && (
            wan.connection_type.includes('Bridge') ||
            wan.connection_type.includes('Bridged')
        );

        // Extract VLAN ID from connection name (e.g., "2_INTERNET_B_VID_20" -> "20")
        const vlanMatch = wan.name.match(/VID[_-]?(\d+)/i);
        const vlanId = vlanMatch ? vlanMatch[1] : null;

        // Check if this is TR069 connection
        const isTR069 = (wan.service_list && (wan.service_list.toUpperCase().includes('TR069') || wan.service_list.toUpperCase().includes('CWMP'))) ||
                        (wan.name && (wan.name.toUpperCase().includes('TR069') || wan.name.toUpperCase().includes('CWMP')));

        html += `
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${wan.name}</strong>
                            <span class="badge bg-info ms-2">${wan.type}</span>
                            ${statusBadge}
                            ${isBridge ? '<span class="badge bg-secondary ms-2">Bridge Mode</span>' : ''}
                            ${isTR069 ? '<span class="badge bg-danger ms-2"><i class="bi bi-exclamation-triangle"></i> TR069</span>' : ''}
                        </div>
                        <div>
                            <button class="btn btn-sm btn-warning" onclick='openEditWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-danger" onclick='openDeleteWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">`;

        // For bridge connections, show minimal info
        if (isBridge) {
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            html += `
                    </table>`;
        } else {
            // For routed connections, show all available info
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            // Show External IP if available
            if (wan.external_ip && wan.external_ip !== 'N/A' && wan.external_ip !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>External IP</th>
                            <td>${makeIPClickable(wan.external_ip)}</td>
                        </tr>`;
            }

            // Show Gateway if available
            if (wan.gateway && wan.gateway !== 'N/A' && wan.gateway !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>Gateway</th>
                            <td>${makeIPClickable(wan.gateway)}</td>
                        </tr>`;
            }

            // Show Subnet Mask if available
            if (wan.subnet_mask && wan.subnet_mask !== 'N/A') {
                html += `
                        <tr>
                            <th>Subnet Mask</th>
                            <td>${wan.subnet_mask}</td>
                        </tr>`;
            }

            // Show DNS Servers if available
            if (wan.dns_servers && wan.dns_servers !== 'N/A' && wan.dns_servers !== '') {
                html += `
                        <tr>
                            <th>DNS Servers</th>
                            <td>${wan.dns_servers}</td>
                        </tr>`;
            }

            // Show MAC Address if available
            if (wan.mac_address && wan.mac_address !== 'N/A' && wan.mac_address !== '00:00:00:00:00:00') {
                html += `
                        <tr>
                            <th>MAC Address</th>
                            <td>${wan.mac_address}</td>
                        </tr>`;
            }

            // PPPoE specific fields
            if (wan.type === 'PPPoE') {
                if (wan.username && wan.username !== 'N/A' && wan.username !== '') {
                    html += `
                        <tr>
                            <th>Username</th>
                            <td>${wan.username}</td>
                        </tr>`;
                }

                if (wan.last_error && wan.last_error !== 'N/A') {
                    html += `
                        <tr>
                            <th>Last Error</th>
                            <td>${wan.last_error}</td>
                        </tr>`;
                }

                if (wan.mru_size && wan.mru_size !== 'N/A' && wan.mru_size !== '0' && wan.mru_size !== 0) {
                    html += `
                        <tr>
                            <th>MRU Size</th>
                            <td>${wan.mru_size}</td>
                        </tr>`;
                }
            }

            // IP Connection specific fields
            if (wan.type === 'IP') {
                if (wan.addressing_type && wan.addressing_type !== 'N/A') {
                    html += `
                        <tr>
                            <th>Addressing Type</th>
                            <td>${wan.addressing_type}</td>
                        </tr>`;
                }
            }

            // Show uptime if available and not 0
            if (wan.uptime && wan.uptime !== 'N/A' && wan.uptime !== '0' && wan.uptime !== 0) {
                html += `
                        <tr>
                            <th>Uptime</th>
                            <td>${formatUptime(wan.uptime)}</td>
                        </tr>`;
            }

            html += `
                    </table>`;
        }

        html += `
                </div>
            </div>
        `;
    });

    html += '</div></div>';
    return html;
}

function renderDHCPServerTab(dhcpServer) {
    if (!dhcpServer) {
        return '<div class="alert alert-info"><i class="bi bi-info-circle"></i> DHCP server not supported on this device</div>';
    }

    let html = '<div class="d-flex justify-content-between align-items-center mb-3">';
    html += '<h6><i class="bi bi-router"></i> DHCP Server Configuration</h6>';
    html += `<button class="btn btn-sm btn-primary" onclick='openEditDHCPModal(${JSON.stringify(dhcpServer)})'><i class="bi bi-pencil"></i> Edit DHCP</button>`;
    html += '</div>';

    const dhcpEnabled = dhcpServer.enabled === true || dhcpServer.enabled === 'true';
    const statusBadge = dhcpEnabled ?
        '<span class="badge online">Enabled</span>' :
        '<span class="badge offline">Disabled</span>';

    html += `
        <div class="card">
            <div class="card-header bg-light">
                <strong>DHCP Server Status</strong>
                ${statusBadge}
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <th width="30%">DHCP Server</th>
                        <td>${dhcpEnabled ? 'Enabled' : 'Disabled'}</td>
                    </tr>`;

    if (dhcpEnabled || dhcpServer.min_address !== 'N/A') {
        html += `
                    <tr>
                        <th>IP Address Pool Start</th>
                        <td>${dhcpServer.min_address}</td>
                    </tr>
                    <tr>
                        <th>IP Address Pool End</th>
                        <td>${dhcpServer.max_address || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Subnet Mask</th>
                        <td>${dhcpServer.subnet_mask || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Default Gateway</th>
                        <td>${makeIPClickable(dhcpServer.gateway || 'N/A')}</td>
                    </tr>
                    <tr>
                        <th>DNS Servers</th>
                        <td>${dhcpServer.dns_servers || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Lease Time</th>
                        <td>${dhcpServer.lease_time ? formatUptime(dhcpServer.lease_time) : 'N/A'}</td>
                    </tr>`;
    }

    html += `
                </table>
            </div>
        </div>
    `;

    return html;
}

function renderDHCPServer(dhcpServer) {
    if (!dhcpServer) {
        return '';
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += '<div class="d-flex justify-content-between align-items-center mb-2">';
    html += '<h6><i class="bi bi-router"></i> DHCP Server Configuration</h6>';
    html += `<button class="btn btn-sm btn-primary" onclick='openEditDHCPModal(${JSON.stringify(dhcpServer)})'><i class="bi bi-pencil"></i> Edit DHCP</button>`;
    html += '</div>';

    const dhcpEnabled = dhcpServer.enabled === true || dhcpServer.enabled === 'true';
    const statusBadge = dhcpEnabled ?
        '<span class="badge online">Enabled</span>' :
        '<span class="badge offline">Disabled</span>';

    html += `
        <div class="card">
            <div class="card-header bg-light">
                <strong>DHCP Server Status</strong>
                ${statusBadge}
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <th width="30%">DHCP Server</th>
                        <td>${dhcpEnabled ? 'Enabled' : 'Disabled'}</td>
                    </tr>`;

    if (dhcpEnabled && dhcpServer.min_address) {
        html += `
                    <tr>
                        <th>IP Address Pool Start</th>
                        <td>${dhcpServer.min_address}</td>
                    </tr>
                    <tr>
                        <th>IP Address Pool End</th>
                        <td>${dhcpServer.max_address || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Subnet Mask</th>
                        <td>${dhcpServer.subnet_mask || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Default Gateway</th>
                        <td>${makeIPClickable(dhcpServer.gateway || 'N/A')}</td>
                    </tr>
                    <tr>
                        <th>DNS Servers</th>
                        <td>${dhcpServer.dns_servers || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Lease Time</th>
                        <td>${dhcpServer.lease_time ? formatUptime(dhcpServer.lease_time) : 'N/A'}</td>
                    </tr>`;
    }

    html += `
                </table>
            </div>
        </div>
    `;

    html += '</div></div>';
    return html;
}

// JRC_CONNECTED_CLIENTS_UI_V2
function jrcClientIsActive(client) {
    if (!client) return false;
    const value = client.active;
    return ![false, 0, '0', 'false', 'False', 'FALSE'].includes(value);
}

function jrcClientBandList(device, band) {
    if (!device || !Array.isArray(device.connected_devices)) return [];
    return device.connected_devices.filter(function (client) {
        return jrcClientIsActive(client) &&
            String(client.interface_type || '').toLowerCase() === 'wifi' &&
            String(client.wifi_band || '') === band;
    });
}

function jrcEscapeClientHtml(value) {
    return String(value == null ? '' : value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function jrcConnectionText(client) {
    const type = String((client && client.interface_type) || '').toLowerCase();

    if (type === 'wifi') {
        if (String(client.wifi_band || '') === '5') return 'Wi-Fi · 5 GHz';
        if (String(client.wifi_band || '') === '2.4') return 'Wi-Fi · 2,4 GHz';
        return 'Wi-Fi';
    }

    if (type === 'ethernet') {
        const port = Number(client.lan_port || 0);
        return port > 0 ? 'Cabo · LAN ' + port : 'Cabo';
    }

    return (client && client.interface_type) || 'Rede';
}

function jrcEnhanceConnectedClientsTable(connectedDevices) {
    const table = document.getElementById('connected-devices-table');
    if (!table || !Array.isArray(connectedDevices)) return;

    const byMac = new Map();
    connectedDevices.forEach(function (client) {
        const mac = String((client && client.mac_address) || '').trim().toLowerCase();
        if (mac) byMac.set(mac, client);
    });

    table.querySelectorAll('tbody tr[data-mac]').forEach(function (row) {
        const mac = String(row.getAttribute('data-mac') || '').trim().toLowerCase();
        const client = byMac.get(mac);
        if (!client) return;

        const cell = row.querySelector('td:nth-child(5)');
        if (!cell) return;

        const isWifi = String(client.interface_type || '').toLowerCase() === 'wifi';
        cell.innerHTML =
            '<span class="badge ' + (isWifi ? 'bg-secondary' : 'bg-success') + '">' +
            '<i class="bi ' + (isWifi ? 'bi-wifi' : 'bi-ethernet') + '"></i> ' +
            jrcEscapeClientHtml(jrcConnectionText(client)) +
            '</span>';
    });
}

function jrcApplyWifiClientCounters(device) {
    const root = document.querySelector('.acs-wifi-reference-grid');
    if (!root) return;

    const rows = Array.from(
        root.querySelectorAll('.acs-wifi-health-row:not(.acs-wifi-unified-row)')
    );

    [
        { row: rows[0], band: '2.4', label: '2,4 GHz' },
        { row: rows[1], band: '5', label: '5 GHz' }
    ].forEach(function (spec) {
        if (!spec.row) return;

        const clients = jrcClientBandList(device, spec.band);
        const detail = Array.from(
            spec.row.querySelectorAll('.acs-wifi-health-detail')
        ).find(function (item) {
            const small = item.querySelector('small');
            return small && small.textContent.trim().toLowerCase() === 'clientes';
        });

        if (!detail) return;
        const strong = detail.querySelector('strong');
        if (!strong) return;

        const label = clients.length === 1
            ? '1 conectado'
            : String(clients.length) + ' conectados';

        if (!clients.length) {
            strong.textContent = label;
            return;
        }

        strong.innerHTML =
            '<button type="button" class="btn btn-link p-0 text-decoration-none" ' +
            'style="color:inherit;font-size:inherit;font-weight:700" ' +
            'onclick="jrcOpenWifiClients(\'' + spec.band + '\', \'' + spec.label + '\')" ' +
            'title="Ver clientes desta banda">' +
            jrcEscapeClientHtml(label) +
            '</button>';
    });
}

function jrcOpenWifiClients(band, bandLabel) {
    const device = (typeof currentDeviceData === 'object' && currentDeviceData)
        ? currentDeviceData
        : {};
    const clients = jrcClientBandList(device, band);

    let modal = document.getElementById('jrcWifiClientsModalV2');
    if (!modal) {
        modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'jrcWifiClientsModalV2';
        modal.tabIndex = -1;
        modal.innerHTML =
            '<div class="modal-dialog modal-lg modal-dialog-centered">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<div>' +
                            '<small class="text-danger fw-bold">JR CONECT · WI-FI</small>' +
                            '<h5 class="modal-title mt-1" id="jrcWifiClientsTitleV2">Clientes conectados</h5>' +
                        '</div>' +
                        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>' +
                    '</div>' +
                    '<div class="modal-body" id="jrcWifiClientsBodyV2"></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
    }

    const title = modal.querySelector('#jrcWifiClientsTitleV2');
    const body = modal.querySelector('#jrcWifiClientsBodyV2');

    if (title) title.textContent = 'Clientes conectados · ' + bandLabel;

    if (body) {
        if (!clients.length) {
            body.innerHTML = '<div class="alert alert-info mb-0">Nenhum cliente conectado nesta banda.</div>';
        } else {
            let rows = '';
            clients.forEach(function (client) {
                const name = client.hostname || client.vendor || 'Dispositivo';
                const vendor = client.vendor && client.vendor !== client.hostname
                    ? '<br><small class="text-muted">' + jrcEscapeClientHtml(client.vendor) + '</small>'
                    : '';

                rows +=
                    '<tr>' +
                        '<td><strong>' + jrcEscapeClientHtml(name) + '</strong>' + vendor + '</td>' +
                        '<td>' + jrcEscapeClientHtml(client.ip_address || '-') + '</td>' +
                        '<td><code>' + jrcEscapeClientHtml(client.mac_address || '-') + '</code></td>' +
                    '</tr>';
            });

            body.innerHTML =
                '<div class="table-responsive">' +
                    '<table class="table table-sm table-hover align-middle mb-0">' +
                        '<thead><tr><th>Dispositivo</th><th>IP</th><th>MAC</th></tr></thead>' +
                        '<tbody>' + rows + '</tbody>' +
                    '</table>' +
                '</div>';
        }
    }

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }
}

window.jrcOpenWifiClients = jrcOpenWifiClients;

function renderConnectedDevicesTab(connectedDevices) {
    if (!connectedDevices || connectedDevices.length === 0) {
        return `
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No devices currently connected to this ONU
            </div>
        `;
    }

    let html = '<h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-primary">' + connectedDevices.length + '</span></h6>';

    html += `
        <div class="mb-3">
            <small class="text-muted" id="hotspot-status"><i class="bi bi-router"></i> Hotspot monitoring will start automatically...</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover" id="connected-devices-table">
                <thead class="table-light">
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="18%"><i class="bi bi-pc-display"></i> Device Name</th>
                        <th width="12%"><i class="bi bi-hdd-network"></i> IP Address</th>
                        <th width="15%"><i class="bi bi-ethernet"></i> MAC Address</th>
                        <th width="10%" class="text-center"><i class="bi bi-wifi"></i> Connection</th>
                        <th width="10%" class="text-center"><i class="bi bi-check-circle"></i> Status</th>
                        <th width="13%"><i class="bi bi-person-badge"></i> Hotspot User</th>
                        <th width="18%"><i class="bi bi-speedometer2"></i> Traffic (RX / TX)</th>
                    </tr>
                </thead>
                <tbody>
    `;

    connectedDevices.forEach((device, index) => {
        // Determine connection type icon
        let connectionIcon = 'bi-ethernet';
        let connectionBadge = 'bg-secondary';
        if (device.interface_type === 'WiFi') {
            connectionIcon = 'bi-wifi';
            connectionBadge = 'bg-info';
        } else if (device.interface_type === 'Ethernet') {
            connectionIcon = 'bi-ethernet';
            connectionBadge = 'bg-success';
        }

        // Determine status badge
        const statusBadge = device.active ?
            '<span class="badge online">Active</span>' :
            '<span class="badge offline">Inactive</span>';

        // Display vendor name if available, otherwise use hostname
        const displayName = device.vendor || device.hostname || 'Unknown Device';

        html += `
            <tr data-mac="${device.mac_address}">
                <td class="text-center">${index + 1}</td>
                <td>
                    <i class="bi bi-pc-display text-primary"></i> <strong>${displayName}</strong>
                    ${device.hostname && device.vendor && device.hostname !== device.vendor ? `<br><small class="text-muted">Hostname: ${device.hostname}</small>` : ''}
                </td>
                <td>${makeIPClickable(device.ip_address)}</td>
                <td><code>${device.mac_address}</code></td>
                <td class="text-center">
                    <span class="badge ${connectionBadge}">
                        <i class="${connectionIcon}"></i> ${device.interface_type}
                    </span>
                </td>
                <td class="text-center">${statusBadge}</td>
                <td class="hotspot-user" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
                <td class="hotspot-traffic" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    return html;
}

function renderConnectedDevices(connectedDevices) {
    if (!connectedDevices || connectedDevices.length === 0) {
        return `
            <div class="row mt-3">
                <div class="col-md-12">
                    <h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-secondary">0</span></h6>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No devices currently connected to this ONU
                    </div>
                </div>
            </div>
        `;
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += `<h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-primary">${connectedDevices.length}</span></h6>`;

    html += `
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover" id="connected-devices-table">
                <thead class="table-light">
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="18%"><i class="bi bi-pc-display"></i> Device Name</th>
                        <th width="12%"><i class="bi bi-hdd-network"></i> IP Address</th>
                        <th width="15%"><i class="bi bi-ethernet"></i> MAC Address</th>
                        <th width="10%" class="text-center"><i class="bi bi-wifi"></i> Connection</th>
                        <th width="10%" class="text-center"><i class="bi bi-check-circle"></i> Status</th>
                        <th width="13%"><i class="bi bi-person-badge"></i> Hotspot User</th>
                        <th width="18%"><i class="bi bi-speedometer2"></i> Traffic (RX / TX)</th>
                    </tr>
                </thead>
                <tbody>
    `;

    connectedDevices.forEach((device, index) => {
        // Determine connection type icon
        let connectionIcon = 'bi-ethernet';
        let connectionBadge = 'bg-secondary';
        if (device.interface_type === 'WiFi') {
            connectionIcon = 'bi-wifi';
            connectionBadge = 'bg-info';
        } else if (device.interface_type === 'Ethernet') {
            connectionIcon = 'bi-ethernet';
            connectionBadge = 'bg-success';
        }

        // Determine status badge
        const statusBadge = device.active ?
            '<span class="badge online">Active</span>' :
            '<span class="badge offline">Inactive</span>';

        // Display vendor name if available, otherwise use hostname
        const displayName = device.vendor || device.hostname || 'Unknown Device';

        html += `
            <tr data-mac="${device.mac_address}">
                <td class="text-center">${index + 1}</td>
                <td>
                    <i class="bi bi-pc-display text-primary"></i> <strong>${displayName}</strong>
                    ${device.hostname && device.vendor && device.hostname !== device.vendor ? `<br><small class="text-muted">Hostname: ${device.hostname}</small>` : ''}
                </td>
                <td>${makeIPClickable(device.ip_address)}</td>
                <td><code>${device.mac_address}</code></td>
                <td class="text-center">
                    <span class="badge ${connectionBadge}">
                        <i class="${connectionIcon}"></i> ${device.interface_type}
                    </span>
                </td>
                <td class="text-center">${statusBadge}</td>
                <td class="hotspot-user" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
                <td class="hotspot-traffic" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    html += '</div></div>';
    return html;
}

function togglePassword() {
    const hidden = document.getElementById('wifi-pass-hidden');
    const shown = document.getElementById('wifi-pass-shown');
    const icon = document.getElementById('toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function toggleAdminPassword() {
    const hidden = document.getElementById('admin-pass-hidden');
    const shown = document.getElementById('admin-pass-shown');
    const icon = document.getElementById('admin-toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function toggleTelecomPassword() {
    const hidden = document.getElementById('telecom-pass-hidden');
    const shown = document.getElementById('telecom-pass-shown');
    const icon = document.getElementById('telecom-toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function summonDevice() {
    document.getElementById('summon-device-id').textContent = deviceId;
    const modal = new bootstrap.Modal(document.getElementById('summonModal'), {
        backdrop: false
    });
    modal.show();
}

// Summon device specifically to get admin credentials
// Uses GenieACS task to fetch VirtualParameters (superAdmin, superPassword)
async function summonForAdminCredentials() {
    const btn = document.getElementById('get-credentials-btn');
    const statusDiv = document.getElementById('credentials-status');
    const statusText = document.getElementById('credentials-status-text');

    // Disable button and show status
    if (btn) btn.disabled = true;
    if (statusDiv) statusDiv.style.display = 'block';
    if (statusText) statusText.textContent = 'Summoning device...';

    // Summon device and request VirtualParameters for admin credentials
    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: deviceId })
    });

    if (result && result.success) {
        // Single toast notification with longer duration (5 seconds)
        showToast('Device summon berhasil, mengambil credentials...', 'success', 5000);

        // Show countdown in status div only (not in toast)
        let countdown = 10;
        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                if (statusText) statusText.textContent = `Menunggu credentials dari device (${countdown}s)...`;
            }
        }, 1000);

        setTimeout(() => {
            clearInterval(countdownInterval);
            if (statusText) statusText.textContent = 'Refreshing device data...';

            // Save scroll position before refresh
            const currentScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
            console.log('[GET-CREDENTIALS] Saving scroll position:', currentScrollPosition);

            // Store in sessionStorage to persist across reload
            sessionStorage.setItem('credentialsScrollPosition', currentScrollPosition);

            // Reload device detail
            loadDeviceDetail();
        }, 10000);
    } else {
        // Hide status and show error (longer duration for error messages)
        if (statusDiv) statusDiv.style.display = 'none';
        if (btn) btn.disabled = false;
        showToast(result.message || 'Gagal summon device', 'danger', 5000);
    }
}

async function confirmSummon() {
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('summonModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: deviceId })
    });

    hideLoading();

    if (result && result.success) {
        showToast('🚀 Device summon berhasil! Menunggu device response...', 'success');

        // Wait longer for device to respond and GenieACS to fetch all parameters
        // This is especially important for admin credentials (VirtualParameters)
        let countdown = 15;
        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                showToast(`⏳ Auto-refresh dalam ${countdown} detik...`, 'info');
            }
        }, 1000);

        setTimeout(() => {
            clearInterval(countdownInterval);
            showToast('🔄 Refreshing device data...', 'info');
            loadDeviceDetail();
        }, 15000);
    } else {
        showToast(result.message || 'Gagal summon device', 'danger');
    }
}

function fbOpenLegacyWifi() {
    const device = (typeof currentDeviceData === 'object' && currentDeviceData) ? currentDeviceData : {};
    const ssid = device.wifi_ssid_24ghz || device.wifi_ssid_2g || device.wifi_ssid || '';
    const tab = document.getElementById('wifi-tab');
    if (tab && !tab.classList.contains('active')) tab.click();
    openEditWiFiModal(device.device_id || window.DEVICE_ID || '', ssid, device.wifi_password || '');
}

function openEditWiFiModal(deviceId, currentSsid, currentPassword) {
    // Set form values
    document.getElementById('edit-device-id').value = deviceId;
    document.getElementById('edit-wifi-ssid').value = currentSsid;
    document.getElementById('edit-wifi-password').value = currentPassword;
    document.getElementById('edit-wlan-index').value = '1'; // Default to WLAN 1

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editWiFiModal'), {
        backdrop: false
    });
    modal.show();
}

function toggleEditPassword() {
    const passwordField = document.getElementById('edit-wifi-password');
    const icon = document.getElementById('edit-toggle-icon');

    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        passwordField.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function togglePasswordField() {
    const securityMode = document.getElementById('edit-security-mode').value;
    const passwordFieldGroup = document.getElementById('password-field-group');
    const passwordField = document.getElementById('edit-wifi-password');

    if (securityMode === 'None') {
        // Hide password field for Open network
        passwordFieldGroup.style.display = 'none';
        passwordField.removeAttribute('required');
        passwordField.value = ''; // Clear password value
    } else {
        // Show password field for secured network
        passwordFieldGroup.style.display = 'block';
        passwordField.setAttribute('required', 'required');
    }
}

async function confirmUpdateWiFi() {
    const form = document.getElementById('editWiFiForm');

    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Get form values
    const deviceId = document.getElementById('edit-device-id').value;
    const wifiSsid = document.getElementById('edit-wifi-ssid').value.trim();
    const securityMode = document.getElementById('edit-security-mode').value;
    const wifiPassword = document.getElementById('edit-wifi-password').value;
    const wlanIndex = document.getElementById('edit-wlan-index').value;

    // Validate SSID length
    if (wifiSsid.length < 1 || wifiSsid.length > 32) {
        showToast('WiFi SSID harus antara 1-32 karakter', 'danger');
        return;
    }

    // Validate password length only if security mode is not Open
    if (securityMode !== 'None') {
        if (wifiPassword.length < 8 || wifiPassword.length > 63) {
            showToast('WiFi Password harus antara 8-63 karakter', 'danger');
            return;
        }
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editWiFiModal'));
    modal.hide();

    // Show loading
    showLoading('Updating WiFi configuration...');

    try {
        const requestData = {
            device_id: deviceId,
            wifi_ssid: wifiSsid,
            security_mode: securityMode,
            wlan_index: parseInt(wlanIndex)
        };

        // Only include password if security mode is not Open
        if (securityMode !== 'None') {
            requestData.wifi_password = wifiPassword;
        }

        const result = await fetchAPI('/api/update-wifi-config.php', {
            method: 'POST',
            body: JSON.stringify(requestData)
        });

        hideLoading();

        if (result && result.success) {
            showToast(result.message || 'WiFi configuration updated successfully!', 'success');

            // Reload device detail after a short delay
            setTimeout(() => {
                loadDeviceDetail();
            }, 2000);
        } else {
            const errorMessage = result && result.message ? result.message : 'Failed to update WiFi configuration';
            showToast(errorMessage, 'danger');
            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                console.error('WiFi update failed:', result);
            }
        }
    } catch (error) {
        hideLoading();
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('WiFi update error:', error);
        }
        showToast('Connection error: ' + error.message, 'danger');
    }
}

// Global variable to store WAN data for delete confirmation
let currentWANDelete = null;

// WAN Modal Functions
function openEditWANModal(wanData) {
    // Populate form
    document.getElementById('edit-wan-device-id').value = deviceId;
    document.getElementById('edit-wan-connection-index').value = wanData.connection_index || '';
    document.getElementById('edit-wan-connection-type').value = wanData.type === 'PPPoE' ? 'ppp' : 'ip';
    document.getElementById('edit-wan-name').value = wanData.name || '';
    document.getElementById('edit-wan-enable').value = wanData.status === 'Connected' ? 'true' : 'false';

    // Show/hide PPPoE fields
    const isPPPoE = wanData.type === 'PPPoE';
    document.getElementById('edit-wan-username-group').style.display = isPPPoE ? 'block' : 'none';
    document.getElementById('edit-wan-password-group').style.display = isPPPoE ? 'block' : 'none';

    if (isPPPoE) {
        document.getElementById('edit-wan-username').value = wanData.username || '';
        document.getElementById('edit-wan-password').value = '';  // Don't prefill password
    }

    // NATEnabled may not be available in all WAN data
    if (wanData.nat_enabled !== undefined) {
        document.getElementById('edit-wan-nat').value = wanData.nat_enabled ? 'true' : 'false';
    }

    // Extract VLAN from name
    const vlanMatch = wanData.name.match(/VID[_-]?(\d+)/i);
    if (vlanMatch) {
        document.getElementById('edit-wan-vlan').value = vlanMatch[1];
    }

    // Check if TR069
    const isTR069 = (wanData.service_list && (wanData.service_list.toUpperCase().includes('TR069') || wanData.service_list.toUpperCase().includes('CWMP'))) ||
                    (wanData.name && (wanData.name.toUpperCase().includes('TR069') || wanData.name.toUpperCase().includes('CWMP')));

    document.getElementById('tr069-warning').style.display = isTR069 ? 'block' : 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editWANModal'), { backdrop: false });
    modal.show();
}

async function confirmUpdateWAN() {
    const connectionIndex = document.getElementById('edit-wan-connection-index').value;
    const connectionType = document.getElementById('edit-wan-connection-type').value;
    const enable = document.getElementById('edit-wan-enable').value === 'true';
    const username = document.getElementById('edit-wan-username').value.trim();
    const password = document.getElementById('edit-wan-password').value;
    const natEnabled = document.getElementById('edit-wan-nat').value === 'true';
    const vlanId = document.getElementById('edit-wan-vlan').value;

    const parameters = {
        Enable: enable,
        NATEnabled: natEnabled
    };

    if (connectionType === 'ppp' && username) {
        parameters.Username = username;
        if (password) {
            parameters.Password = password;
        }
    }

    if (vlanId) {
        parameters['X_CT-COM_VLANID'] = parseInt(vlanId);
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editWANModal'));
    modal.hide();

    showLoading('Updating WAN configuration...');

    const result = await fetchAPI('/api/update-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: parseInt(connectionIndex),
            connection_type: connectionType,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN configuration updated successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to update WAN configuration', 'danger');
    }
}

function openAddWANModal() {
    // Reset form
    document.getElementById('addWANForm').reset();
    document.getElementById('add-wan-username-group').style.display = 'none';
    document.getElementById('add-wan-password-group').style.display = 'none';
    document.getElementById('add-wan-service-custom').style.display = 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('addWANModal'), { backdrop: false });
    modal.show();
}

function toggleAddWANFields() {
    const connectionType = document.getElementById('add-wan-type').value;
    const usernameGroup = document.getElementById('add-wan-username-group');
    const passwordGroup = document.getElementById('add-wan-password-group');

    if (connectionType === 'ppp') {
        usernameGroup.style.display = 'block';
        passwordGroup.style.display = 'block';
    } else {
        usernameGroup.style.display = 'none';
        passwordGroup.style.display = 'none';
    }
}

async function confirmAddWAN() {
    const form = document.getElementById('addWANForm');

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const connectionIndex = parseInt(document.getElementById('add-wan-index').value);
    const connectionType = document.getElementById('add-wan-type').value;
    const connectionName = document.getElementById('add-wan-name').value.trim();
    const username = document.getElementById('add-wan-username').value.trim();
    const password = document.getElementById('add-wan-password').value;
    const serviceList = document.getElementById('add-wan-service').value;
    const vlanId = parseInt(document.getElementById('add-wan-vlan').value);
    const natEnabled = document.getElementById('add-wan-nat').value === 'true';

    // Build parameters
    const parameters = {
        Enable: true,
        ConnectionType: connectionType === 'ppp' ? 'IP_Routed' : 'IP_Routed',
        NATEnabled: natEnabled,
        'X_CT-COM_VLANID': vlanId,
        'X_CT-COM_ServiceList': serviceList === 'CUSTOM' ? document.getElementById('add-wan-service-custom').value : serviceList
    };

    if (connectionType === 'ppp') {
        if (!username || !password) {
            showToast('Username and password are required for PPPoE connections', 'danger');
            return;
        }
        parameters.Username = username;
        parameters.Password = password;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('addWANModal'));
    modal.hide();

    showLoading('Creating WAN connection...');

    const result = await fetchAPI('/api/add-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: connectionIndex,
            connection_type: connectionType,
            name: connectionName,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN connection created successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to create WAN connection', 'danger');
    }
}

function openDeleteWANModal(wanData) {
    currentWANDelete = wanData;

    // Check if TR069
    const isTR069 = (wanData.service_list && (wanData.service_list.toUpperCase().includes('TR069') || wanData.service_list.toUpperCase().includes('CWMP'))) ||
                    (wanData.name && (wanData.name.toUpperCase().includes('TR069') || wanData.name.toUpperCase().includes('CWMP')));

    if (isTR069) {
        document.getElementById('delete-wan-normal-warning').style.display = 'none';
        document.getElementById('delete-wan-tr069-warning').style.display = 'block';
        document.getElementById('delete-wan-tr069-name').textContent = wanData.name;
        document.getElementById('delete-wan-tr069-service').textContent = wanData.service_list || 'N/A';
        document.getElementById('delete-wan-tr069-confirm').checked = false;
    } else {
        document.getElementById('delete-wan-normal-warning').style.display = 'block';
        document.getElementById('delete-wan-tr069-warning').style.display = 'none';
        document.getElementById('delete-wan-name').textContent = wanData.name;
        document.getElementById('delete-wan-service').textContent = wanData.service_list || 'N/A';
    }

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteWANModal'), { backdrop: false });
    modal.show();
}

async function confirmDeleteWAN() {
    if (!currentWANDelete) return;

    // Check if TR069 and needs confirmation
    const isTR069 = (currentWANDelete.service_list && (currentWANDelete.service_list.toUpperCase().includes('TR069') || currentWANDelete.service_list.toUpperCase().includes('CWMP'))) ||
                    (currentWANDelete.name && (currentWANDelete.name.toUpperCase().includes('TR069') || currentWANDelete.name.toUpperCase().includes('CWMP')));

    if (isTR069 && !document.getElementById('delete-wan-tr069-confirm').checked) {
        showToast('Please confirm that you understand the risks before deleting TR069 connection', 'warning');
        return;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteWANModal'));
    modal.hide();

    showLoading('Deleting WAN connection...');

    const result = await fetchAPI('/api/delete-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: currentWANDelete.connection_index,
            connection_type: currentWANDelete.type === 'PPPoE' ? 'ppp' : 'ip',
            connection_name: currentWANDelete.name,
            service_list: currentWANDelete.service_list || '',
            confirm_tr069_delete: isTR069
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN connection deleted successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else if (result && result.requires_confirmation) {
        showToast('TR069 connection deletion blocked - please confirm deletion', 'warning');
    } else {
        showToast(result.message || 'Failed to delete WAN connection', 'danger');
    }

    currentWANDelete = null;
}

// DHCP Modal Functions
function openEditDHCPModal(dhcpData) {
    // Populate form
    const dhcpEnabled = dhcpData.enabled === true || dhcpData.enabled === 'true';
    document.getElementById('edit-dhcp-enable').value = dhcpEnabled ? 'true' : 'false';
    document.getElementById('edit-dhcp-min-address').value = dhcpData.min_address || '';
    document.getElementById('edit-dhcp-max-address').value = dhcpData.max_address || '';
    document.getElementById('edit-dhcp-subnet-mask').value = dhcpData.subnet_mask || '';
    document.getElementById('edit-dhcp-gateway').value = dhcpData.gateway || '';
    document.getElementById('edit-dhcp-dns').value = dhcpData.dns_servers || '';
    document.getElementById('edit-dhcp-lease-time').value = dhcpData.lease_time || '';

    // Show/hide fields
    toggleDHCPFields();

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editDHCPModal'), { backdrop: false });
    modal.show();
}

function toggleDHCPFields() {
    const dhcpEnabled = document.getElementById('edit-dhcp-enable').value === 'true';
    document.getElementById('dhcp-config-fields').style.display = dhcpEnabled ? 'block' : 'none';
}

async function confirmUpdateDHCP() {
    const dhcpEnabled = document.getElementById('edit-dhcp-enable').value === 'true';

    const parameters = {
        DHCPServerEnable: dhcpEnabled
    };

    if (dhcpEnabled) {
        parameters.MinAddress = document.getElementById('edit-dhcp-min-address').value.trim();
        parameters.MaxAddress = document.getElementById('edit-dhcp-max-address').value.trim();
        parameters.SubnetMask = document.getElementById('edit-dhcp-subnet-mask').value.trim();
        parameters.IPRouters = document.getElementById('edit-dhcp-gateway').value.trim();
        parameters.DNSServers = document.getElementById('edit-dhcp-dns').value.trim();

        const leaseTime = document.getElementById('edit-dhcp-lease-time').value.trim();
        if (leaseTime) {
            parameters.DHCPLeaseTime = parseInt(leaseTime);
        }
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editDHCPModal'));
    modal.hide();

    showLoading('Updating DHCP configuration...');

    const result = await fetchAPI('/api/update-dhcp-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'DHCP configuration updated successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to update DHCP configuration', 'danger');
    }
}

// Tags Management Functions
function updateTagsBadge(tags) {
    const badgeElement = document.getElementById('device-tags-badge');
    if (tags && tags.length > 0) {
        const tagBadges = tags.map(tag => `<span class="badge bg-info ms-1">${tag}</span>`).join('');
        badgeElement.innerHTML = tagBadges;
    } else {
        badgeElement.innerHTML = '';
    }
}

function showAddTagModal() {
    const modal = new bootstrap.Modal(document.getElementById('addTagModal'));
    document.getElementById('addTagName').value = '';
    modal.show();
}

async function addTagToDevice() {
    const tagName = document.getElementById('addTagName').value.trim();

    if (!tagName) {
        showToast('Please enter a tag name', 'warning');
        return;
    }

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'add',
            device_ids: [deviceId],
            tag: tagName
        })
    });

    if (result && result.success) {
        showToast(`Tag "${tagName}" added successfully`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('addTagModal')).hide();
        loadDeviceDetail(); // Reload to show new tag
    } else {
        showToast(result.message || 'Failed to add tag', 'danger');
    }
}

function showRemoveTagModal() {
    fetchAPI('/api/get-device-detail.php?device_id=' + encodeURIComponent(deviceId))
        .then(result => {
            if (result && result.success && result.device.tags && result.device.tags.length > 0) {
                const tagsList = document.getElementById('deviceTagsList');
                tagsList.innerHTML = result.device.tags.map(tag =>
                    `<button class="btn btn-sm btn-outline-warning me-2 mb-2" onclick="removeTagFromDevice('${tag}')">
                        <i class="bi bi-x-circle"></i> ${tag}
                    </button>`
                ).join('');

                document.getElementById('removeTagContent').style.display = 'block';
                document.getElementById('noTagsMessage').style.display = 'none';
            } else {
                document.getElementById('removeTagContent').style.display = 'none';
                document.getElementById('noTagsMessage').style.display = 'block';
            }

            const modal = new bootstrap.Modal(document.getElementById('removeTagModal'));
            modal.show();
        });
}

async function removeTagFromDevice(tagName) {
    if (!confirm(`Remove tag "${tagName}"?`)) {
        return;
    }

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'remove',
            device_ids: [deviceId],
            tag: tagName
        })
    });

    if (result && result.success) {
        showToast(`Tag "${tagName}" removed successfully`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('removeTagModal')).hide();
        loadDeviceDetail(); // Reload to update tags
    } else {
        showToast(result.message || 'Failed to remove tag', 'danger');
    }
}

// Hotspot Traffic Management
let hotspotTrafficInterval = null;
let previousBytesData = {}; // Store previous bytes for rate calculation
let hotspotMonitoringActive = false; // Track if monitoring is active
let hotspotFetchInProgress = false; // Prevent concurrent requests
let hotspotAbortController = null; // AbortController to cancel pending requests

async function fetchHotspotTraffic() {
    // Prevent concurrent requests - check FIRST before any cancellation
    if (hotspotFetchInProgress) {
        console.debug('[HOTSPOT] Fetch already in progress, skipping...');
        return;
    }

    // Get all MAC addresses from connected devices table
    const macElements = document.querySelectorAll('.hotspot-user[data-mac]');
    if (macElements.length === 0) {
        console.debug('[HOTSPOT] No MAC addresses found in table');
        return;
    }

    const macAddresses = Array.from(macElements).map(el => el.dataset.mac);
    console.log(`[HOTSPOT] Starting fetch for ${macAddresses.length} devices`);

    // Mark as in progress BEFORE creating AbortController
    hotspotFetchInProgress = true;

    // Create new AbortController for this request only
    hotspotAbortController = new AbortController();

    // Update status to show fetching (but keep it subtle)
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) {
        statusEl.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> <small class="text-muted">Updating...</small>';
    }

    try {
        const result = await fetchAPI('/api/get-hotspot-traffic.php', {
            method: 'POST',
            body: JSON.stringify({ mac_addresses: macAddresses }),
            timeout: 15000, // 15 second timeout (MikroTik may be slow)
            signal: hotspotAbortController.signal
        });

        console.log('[HOTSPOT] Fetch completed successfully', {
            from_cache: result.from_cache,
            cache_age: result.cache_age,
            total_matched: result.total_matched
        });

        if (result && result.success && result.data) {
            updateHotspotTrafficDisplay(result.data, result.timestamp);

            // Update status with last update time and cache info
            if (statusEl) {
                const now = new Date();
                const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const cacheInfo = result.from_cache ? ` <small class="text-info">[cached ${result.cache_age}s]</small>` : '';
                statusEl.innerHTML = `<span class="text-success">✓ ${timeStr}</span>${cacheInfo}`;
            }
        } else if (result && result.error === 'timeout') {
            // Request timeout (15s)
            console.warn('[HOTSPOT] Request timeout after 15s');
            const errorMsg = 'Request timeout. Retrying in 5s...';
            updateHotspotTrafficError(errorMsg);

            if (statusEl) {
                statusEl.innerHTML = '<i class="bi bi-clock text-warning"></i> <small>Timeout - retrying...</small>';
            }
        } else {
            // MikroTik offline or other error
            console.warn('[HOTSPOT] Error response:', result);
            const errorMsg = (result && result.message) ? result.message : 'MikroTik Offline';
            updateHotspotTrafficError(errorMsg);

            if (statusEl) {
                statusEl.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i> ' + errorMsg;
            }
        }
    } catch (error) {
        // Check if it was aborted
        if (error.name === 'AbortError') {
            console.warn('[HOTSPOT] Request was aborted (likely due to page refresh)');
        } else {
            console.error('[HOTSPOT] Fetch failed:', error.message);
        }

        // Don't show error to user on every fetch, just keep last known data
        // Only update status, not the actual traffic data
        if (statusEl) {
            statusEl.innerHTML = '<i class="bi bi-x-circle text-danger"></i> <small>Connection error - showing last known data</small>';
        }
    } finally {
        // Always reset the flag when done (CRITICAL for preventing stuck state)
        console.debug('[HOTSPOT] Resetting fetch flag');
        hotspotFetchInProgress = false;
        hotspotAbortController = null;
    }
}

function updateHotspotTrafficDisplay(data, timestamp) {
    Object.keys(data).forEach(mac => {
        const userInfo = data[mac];
        const userCell = document.querySelector(`.hotspot-user[data-mac="${mac}"]`);
        const trafficCell = document.querySelector(`.hotspot-traffic[data-mac="${mac}"]`);

        if (!userCell || !trafficCell) return;

        // Update username
        if (userInfo.found) {
            userCell.innerHTML = `<strong>${userInfo.username}</strong>`;

            // Calculate RX/TX rate from bytes difference
            const currentBytesIn = userInfo.bytes_in || 0;
            const currentBytesOut = userInfo.bytes_out || 0;

            let rxRate = 0;
            let txRate = 0;

            if (previousBytesData[mac]) {
                const timeDiff = timestamp - previousBytesData[mac].timestamp;
                if (timeDiff > 0) {
                    const bytesDiffIn = Math.max(0, currentBytesIn - previousBytesData[mac].bytes_in);
                    const bytesDiffOut = Math.max(0, currentBytesOut - previousBytesData[mac].bytes_out);

                    // Calculate rate in bytes per second, then convert to bits per second
                    const rawRxRate = (bytesDiffIn / timeDiff) * 8; // bits per second
                    const rawTxRate = (bytesDiffOut / timeDiff) * 8; // bits per second

                    // Apply simple smoothing: 30% new value, 70% old value (reduces jitter)
                    const smoothingFactor = 0.3;
                    if (previousBytesData[mac].rxRate !== undefined) {
                        rxRate = (smoothingFactor * rawRxRate) + ((1 - smoothingFactor) * previousBytesData[mac].rxRate);
                        txRate = (smoothingFactor * rawTxRate) + ((1 - smoothingFactor) * previousBytesData[mac].txRate);
                    } else {
                        rxRate = rawRxRate;
                        txRate = rawTxRate;
                    }
                } else {
                    // Time diff is 0, use previous rate if available
                    rxRate = previousBytesData[mac].rxRate || 0;
                    txRate = previousBytesData[mac].txRate || 0;
                }
            }

            // Store current bytes and calculated rate for next iteration
            previousBytesData[mac] = {
                bytes_in: currentBytesIn,
                bytes_out: currentBytesOut,
                timestamp: timestamp,
                rxRate: rxRate,
                txRate: txRate
            };

            // Format and display traffic
            const rxFormatted = formatBitrate(rxRate);
            const txFormatted = formatBitrate(txRate);

            // Debug log for first device only (to avoid console spam)
            if (mac === Object.keys(data)[0]) {
                console.debug('[HOTSPOT] Traffic update:', {
                    mac: mac,
                    username: userInfo.username,
                    rx: rxFormatted,
                    tx: txFormatted,
                    bytes_in: currentBytesIn,
                    bytes_out: currentBytesOut
                });
            }

            trafficCell.innerHTML = `<span class="text-success">↓ ${rxFormatted}</span> / <span class="text-danger">↑ ${txFormatted}</span>`;
        } else {
            userCell.innerHTML = '<span class="text-muted">N/A</span>';
            trafficCell.innerHTML = '<span class="text-muted">N/A</span>';
        }

        // Also save to savedHotspotData for persistence across refreshes
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].userHtml = userCell.innerHTML;
        savedHotspotData[mac].trafficHtml = trafficCell.innerHTML;
    });
}

function updateHotspotTrafficError(message) {
    // Only show error in status indicator, keep existing data in cells (don't overwrite)
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) {
        statusEl.innerHTML = `<span class="text-danger">⚠ ${message}</span>`;
    }

    // DON'T clear existing data - let users see last known good data
    // This prevents the annoying "all N/A" experience when timeout occurs
    console.warn('[HOTSPOT] Error:', message, '- Keeping last known data visible');
}

function formatBitrate(bps) {
    if (bps === 0 || isNaN(bps)) return '0 bps';

    const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    let value = Math.abs(bps);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return value.toFixed(2) + ' ' + units[unitIndex];
}

function startHotspotTrafficMonitoring() {
    // Check if table exists (tab must be active)
    const table = document.getElementById('connected-devices-table');
    if (!table) {
        console.debug('[HOTSPOT] Table not found, skipping monitoring start');
        return;
    }

    // Update status
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) statusEl.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Starting...';

    // Clear existing interval if any
    if (hotspotTrafficInterval) {
        clearInterval(hotspotTrafficInterval);
    }

    // Set monitoring as active
    hotspotMonitoringActive = true;

    console.log('[HOTSPOT] Monitoring started (auto-mode)');

    // Fetch immediately
    fetchHotspotTraffic();

    // Then fetch every 5 seconds - balanced between smooth updates and stability
    // 5s interval with 15s timeout allows enough time for slow MikroTik response
    hotspotTrafficInterval = setInterval(fetchHotspotTraffic, 5000);
}

function stopHotspotTrafficMonitoring() {
    console.log('[HOTSPOT] Stopping monitoring...');

    // Clear status
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) statusEl.innerHTML = '<i class="bi bi-router text-muted"></i> <span class="text-muted">Monitoring stopped</span>';

    // Clear interval first
    if (hotspotTrafficInterval) {
        clearInterval(hotspotTrafficInterval);
        hotspotTrafficInterval = null;
    }

    // Abort any pending request
    if (hotspotAbortController) {
        console.log('[HOTSPOT] Aborting pending request...');
        hotspotAbortController.abort();
        hotspotAbortController = null;
    }

    // Reset flags
    hotspotFetchInProgress = false;
    previousBytesData = {};

    // Set monitoring as inactive
    hotspotMonitoringActive = false;

    console.log('[HOTSPOT] Monitoring stopped successfully');
}


/* =========================================================
   FIBERHOME TL1 - OPTICAL INFORMATION
   ========================================================= */

function opticalLoadingHtml() {
    return '<span class="text-muted">Consultando OLT...</span>';
}

function opticalUnavailableHtml(message = 'Não disponível') {
    return '<span class="text-muted">' + escapeOpticalHtml(message) + '</span>';
}

function formatOpticalValue(value, unit, status) {
    if (value === null || value === undefined || String(value).trim() === '') {
        return opticalUnavailableHtml();
    }

    const numericValue = Number(value);

    if (!Number.isFinite(numericValue) || (unit === 'V' && numericValue <= 0)) {
        return opticalUnavailableHtml();
    }

    const formatted = numericValue.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    let statusHtml = '';

    if (status) {
        const statusText = String(status).trim();
        const normalizedStatus = statusText
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();

        if (normalizedStatus === 'normal') {
            statusHtml = ' <span class="badge bg-success ms-2">Normal</span>';
        } else if (normalizedStatus === 'critico' || normalizedStatus === 'critical') {
            statusHtml =
                ' <span class="badge bg-danger ms-2">' +
                escapeOpticalHtml(statusText) +
                '</span>';
        } else {
            statusHtml =
                ' <span class="badge bg-warning text-dark ms-2">' +
                escapeOpticalHtml(statusText) +
                '</span>';
        }
    }

    return (
        '<strong>' +
        formatted +
        ' ' +
        escapeOpticalHtml(unit) +
        '</strong>' +
        statusHtml
    );
}

function renderOpticalCachedValue(valueKey, unit, statusKey) {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        const optical = cachedOpticalData.optical || {};
        return formatOpticalValue(optical[valueKey], unit, optical[statusKey]);
    }

    return opticalLoadingHtml();
}

function renderOpticalCachedPon() {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        if (cachedOpticalData.pon_id) {
            return '<strong>' + escapeOpticalHtml(cachedOpticalData.pon_id) + '</strong>';
        }

        const parts = [];
        if (cachedOpticalData.slot !== null && cachedOpticalData.slot !== undefined && cachedOpticalData.slot !== '') {
            parts.push('Slot ' + cachedOpticalData.slot);
        }
        if (cachedOpticalData.pon !== null && cachedOpticalData.pon !== undefined && cachedOpticalData.pon !== '') {
            parts.push('PON ' + cachedOpticalData.pon);
        }
        if (cachedOpticalData.onu_number !== null && cachedOpticalData.onu_number !== undefined && cachedOpticalData.onu_number !== '') {
            parts.push('ONU ' + cachedOpticalData.onu_number);
        }

        if (parts.length) {
            return '<strong>' + escapeOpticalHtml(parts.join(' / ')) + '</strong>';
        }

        if (cachedOpticalData.id_transmissor) {
            return '<strong>Transmissor #' + escapeOpticalHtml(cachedOpticalData.id_transmissor) + '</strong>';
        }

        return opticalUnavailableHtml('Não identificado');
    }

    return opticalLoadingHtml();
}

function formatOpticalDate(value) {
    if (!value) {
        return 'Não disponível';
    }

    const text = String(value).trim();
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);

    if (match) {
        return `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}:${match[6]}`;
    }

    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) {
        return parsed.toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    return text;
}

function renderOpticalLastUpdate() {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        const optical = cachedOpticalData.optical || {};

        if (optical.last_update) {
            return '<strong>' + escapeOpticalHtml(formatOpticalDate(optical.last_update)) + '</strong>';
        }

        return opticalUnavailableHtml();
    }

    return opticalLoadingHtml();
}

function renderOpticalSource() {
    if (cachedOpticalData && cachedOpticalData.device_id === deviceId) {
        if (cachedOpticalData.error) {
            return opticalUnavailableHtml();
        }

        const source = cachedOpticalData.source || 'IXC';
        return '<span class="badge bg-info">' + escapeOpticalHtml(source) + '</span>';
    }

    return opticalLoadingHtml();
}

function isOperationallyMissing(value) {
    const text = String(value ?? '').trim();
    return !text || /^(n\/?a|n\/d|não disponível|não informado|-|none|null|undefined|error_none|sem erro|no error)$/i.test(text);
}

function applyIxcNetworkPrimary() {
    if (!cachedOpticalData || cachedOpticalData.error) return;

    const network = cachedOpticalData.network || {};
    const sourceTitle = network.source || 'API IXC';

    const setPrimary = (id, value, formatter = null) => {
        if (value === null || value === undefined || String(value).trim() === '') return;
        const el = document.getElementById(id);
        if (!el) return;

        if (formatter) {
            el.innerHTML = formatter(value);
        } else {
            el.textContent = String(value);
        }
        el.title = 'Fonte oficial: ' + sourceTitle;
        el.dataset.source = 'ixc';
    };

    // Dados de rede/cliente: IXC é a fonte principal quando possui valor.
    setPrimary('wan-ip', network.ip, value => makeIPClickable(String(value)));
    setPrimary('wan-pppoe-user', network.login);
    setPrimary('wan-ipv6', network.ipv6 || network.ipv6_pd);
    setPrimary('wan-vlan', network.vlan);

    if (network.last_error) {
        const el = document.getElementById('wan-last-error');
        if (el) {
            el.textContent = 'Última queda: ' + String(network.last_error);
            el.title = 'Fonte oficial: IXC Cliente Fibra';
            el.dataset.source = 'ixc';
        }
    }

    // Enriquecemos o objeto atual para IA/resumo técnico.
    // Se o IXC não informou um campo, o valor TR-069 já presente é preservado.
    if (currentDeviceData && Array.isArray(currentDeviceData.wan_details)) {
        const wan = currentDeviceData.wan_details.find(w =>
            String(w?.status || '').toLowerCase() === 'connected'
        ) || currentDeviceData.wan_details[0];

        if (wan) {
            if (network.ip) wan.external_ip = network.ip;
            if (network.login) wan.username = network.login;
            if (network.ipv6 || network.ipv6_pd) wan.ipv6 = network.ipv6 || network.ipv6_pd;
            if (network.vlan) wan.vlan_id = network.vlan;
            if (network.last_error) wan.last_error = 'Última queda: ' + network.last_error;
            wan.data_source = sourceTitle;
        }
    }
}


function opticalPlainText(valueKey, unit) {
    if (!cachedOpticalData || cachedOpticalData.device_id !== deviceId) return 'Consultando...';
    if (cachedOpticalData.error) return 'Sem leitura';
    const raw = (cachedOpticalData.optical || {})[valueKey];
    if (raw === null || raw === undefined || String(raw).trim() === '') return 'Sem leitura';
    const numericValue = Number(String(raw).replace(',', '.'));
    if (!Number.isFinite(numericValue) || (unit === 'V' && numericValue <= 0)) return 'Sem leitura';
    return numericValue.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' ' + unit;
}

function opticalTone(valueKey, unit, statusKey) {
    const reading = opticalPlainText(valueKey, unit);
    if (reading === 'Consultando...' || reading === 'Sem leitura') {
        return { label: 'Sem dado', tone: 'is-muted' };
    }
    if (!cachedOpticalData || cachedOpticalData.device_id !== deviceId || cachedOpticalData.error) {
        return { label: 'Sem dado', tone: 'is-muted' };
    }
    const optical = cachedOpticalData.optical || {};
    const numericValue = Number(optical[valueKey]);
    if (!Number.isFinite(numericValue)) return { label: 'Sem dado', tone: 'is-muted' };
    if (valueKey === 'rx_power') {
        if (numericValue > -25) return { label: 'Bom', tone: 'is-good' };
        if (numericValue > -27) return { label: 'Fraco', tone: 'is-warn' };
        return { label: 'Crítico', tone: 'is-bad' };
    }
    const statusText = String(optical[statusKey] || '').trim();
    const normalized = statusText.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    if (normalized === 'critico' || normalized === 'critical') return { label: 'Crítico', tone: 'is-bad' };
    if (normalized && normalized !== 'normal') return { label: statusText, tone: 'is-warn' };
    return { label: 'Bom', tone: 'is-good' };
}

function paintOpticalReadout(id, valueKey, unit, statusKey) {
    const text = opticalPlainText(valueKey, unit);
    const tone = opticalTone(valueKey, unit, statusKey);
    const el = document.getElementById(id);
    if (el) el.textContent = text;
    const pill = document.getElementById(id + '-pill');
    if (pill) {
        pill.textContent = tone.label;
        pill.className = 'fb-pill ' + tone.tone;
    }
    document.querySelectorAll('[data-optical="' + valueKey + '"]').forEach(node => {
        node.textContent = text;
        if (valueKey === 'rx_power') {
            const item = node.closest('.fb-diag-item');
            if (item) {
                item.classList.toggle('is-bad', tone.tone === 'is-bad');
                item.classList.toggle('is-warn', tone.tone === 'is-warn');
            }
        }
    });
    document.querySelectorAll('[data-optical-pill="' + valueKey + '"]').forEach(node => {
        node.textContent = tone.label;
        node.className = 'fb-pill ' + tone.tone;
    });
}

function opticalHtmlText(html) {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    return holder.textContent || '—';
}

function paintHeaderSignal() {
    const statusHeader = document.getElementById('device-status-header');
    if (!statusHeader || !currentDeviceData) return;
    const online = String(currentDeviceData.status || '').toLowerCase() === 'online';
    statusHeader.classList.remove('online', 'offline', 'warn');
    if (!online) {
        statusHeader.textContent = 'Offline';
        statusHeader.classList.add('offline');
        return;
    }
    const rx = Number(cachedOpticalData && cachedOpticalData.optical ? cachedOpticalData.optical.rx_power : NaN);
    if (!Number.isFinite(rx) || (cachedOpticalData && cachedOpticalData.error)) {
        statusHeader.textContent = 'Online';
        statusHeader.classList.add('online');
        return;
    }
    if (rx > -25) {
        statusHeader.textContent = 'Bom';
        statusHeader.classList.add('online');
    } else if (rx > -27) {
        statusHeader.textContent = 'Fraco';
        statusHeader.classList.add('warn');
    } else {
        statusHeader.textContent = 'Crítico';
        statusHeader.classList.add('offline');
    }
}

function paintOpticalGauge() {
    const rx = Number(cachedOpticalData && cachedOpticalData.optical ? cachedOpticalData.optical.rx_power : NaN);
    const finite = Number.isFinite(rx);
    const pct = finite ? Math.min(100, Math.max(0, (rx + 30) / 22 * 100)) : 0;
    const label = finite
        ? rx.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' dBm'
        : 'RX';
    document.querySelectorAll('[data-gauge-mark]').forEach(mark => { mark.style.left = pct + '%'; });
    document.querySelectorAll('[data-gauge-label]').forEach(node => { node.textContent = label; });
}

function paintActiveAlerts() {
    const box = document.getElementById('fb-active-alerts');
    if (!box || !currentDeviceData) return;
    const device = currentDeviceData;
    const online = String(device.status || '').toLowerCase() === 'online';
    const wan = (typeof getPrimaryWAN === 'function' ? getPrimaryWAN(device) : null) || {};
    const wanStatus = String(wan.status || '').toLowerCase();
    const wanConnected = ['connected', 'up', 'online', 'enabled'].includes(wanStatus);
    const rx = Number(cachedOpticalData && cachedOpticalData.optical ? cachedOpticalData.optical.rx_power : NaN);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    const items = [];
    if (!online) items.push({ tone: 'is-bad', title: 'Equipamento sem contato', detail: device.last_inform || 'Sem último inform' });
    if (online && wanStatus && !wanConnected) items.push({ tone: 'is-bad', title: 'WAN desconectada', detail: wan.status });
    if (Number.isFinite(rx) && rx <= -27) {
        items.push({ tone: 'is-bad', title: 'Sinal óptico crítico', detail: rx.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' dBm' });
    } else if (Number.isFinite(rx) && rx <= -25) {
        items.push({ tone: 'is-warn', title: 'Sinal óptico fraco', detail: rx.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' dBm' });
    }
    if (!items.length) {
        box.innerHTML = '<p class="fb-alert-empty">Nenhum alerta ativo neste equipamento.</p>';
        return;
    }
    box.innerHTML = items.map(item =>
        `<article class="fb-alert ${item.tone}"><i class="bi bi-exclamation-circle"></i><div><strong>${esc(item.title)}</strong><span>${esc(item.detail)}</span></div></article>`
    ).join('');
}

function updateOpticalDomFromCache() {
    // IMPORTANTE: busca os elementos novamente depois da resposta.
    // O auto-refresh pode ter recriado todo o HTML enquanto a API estava consultando a OLT.
    paintOpticalReadout('optical-rx-power', 'rx_power', 'dBm', 'rx_status');
    paintOpticalReadout('optical-tx-power', 'tx_power', 'dBm', 'tx_status');
    paintOpticalReadout('optical-temperature', 'temperature', '°C', 'temperature_status');
    paintOpticalReadout('optical-voltage', 'voltage', 'V', 'voltage_status');
    const ponEl = document.getElementById('optical-pon-id');
    const lastUpdateEl = document.getElementById('optical-last-update');
    const sourceEl = document.getElementById('optical-source');
    const ponText = opticalHtmlText(renderOpticalCachedPon());
    const seenText = opticalHtmlText(renderOpticalLastUpdate());
    if (ponEl) ponEl.textContent = ponText;
    if (lastUpdateEl) lastUpdateEl.textContent = seenText;
    const opticalPon = document.getElementById('fb-optical-pon');
    const opticalSeen = document.getElementById('fb-optical-seen');
    if (opticalPon) opticalPon.textContent = ponText;
    if (opticalSeen) opticalSeen.textContent = seenText;
    if (sourceEl) sourceEl.innerHTML = renderOpticalSource();
    const clientName = document.getElementById('fb-client-name');
    if (clientName && cachedOpticalData && cachedOpticalData.device_id === deviceId && !cachedOpticalData.error) {
        clientName.textContent = cachedOpticalData.nome || '—';
    }
    paintHeaderSignal();
    paintOpticalGauge();
    paintActiveAlerts();
    document.querySelectorAll('[data-mirror]').forEach(node => {
        const source = document.getElementById(node.dataset.mirror);
        if (source) node.innerHTML = source.innerHTML;
    });
    updateIxcOnuSummary();
    applyIxcNetworkPrimary();
    updateOverviewOperationalMeta();
}

function updateIxcOnuSummary() {
    const container = document.getElementById('ixc-onu-summary');
    if (!container) return;
    if (!cachedOpticalData || cachedOpticalData.error) {
        container.innerHTML = '<div class="acs-info-row"><span><i class="bi bi-database"></i> Cadastro oficial</span><strong>IXC indisponível</strong></div>';
        return;
    }
    const value = (v) => escapeOpticalHtml(v == null || v === '' ? 'Não informado' : v);
    const pon = [cachedOpticalData.slot, cachedOpticalData.pon, cachedOpticalData.onu_number]
        .filter(v => v !== null && v !== undefined && v !== '')
        .join(' / ') || cachedOpticalData.pon_id || 'Não informado';
    container.innerHTML = `
        <div class="acs-info-row"><span><i class="bi bi-database-check"></i> Cliente (IXC)</span><strong>${value(cachedOpticalData.nome)}</strong></div>
        <div class="acs-info-row"><span><i class="bi bi-person-vcard"></i> Login / contrato</span><strong>${value(cachedOpticalData.id_login)} / ${value(cachedOpticalData.id_contrato)}</strong></div>
        <div class="acs-info-row"><span><i class="bi bi-diagram-2"></i> Slot / PON / ONU</span><strong>${value(pon)}</strong></div>`;
}

async function loadFiberhomeOptical(deviceIdToLoad, forceRefresh = false) {
    if (!deviceIdToLoad) {
        return;
    }

    // Se já existe uma consulta para a mesma ONU, não abre outra.
    if (opticalLoading && opticalLoadedForDevice === deviceIdToLoad) {
        return;
    }

    // Se já temos os dados da mesma ONU e não foi solicitado refresh manual,
    // apenas redesenha usando o cache local.
    if (
        !forceRefresh &&
        cachedOpticalData &&
        cachedOpticalData.device_id === deviceIdToLoad &&
        !cachedOpticalData.error
    ) {
        updateOpticalDomFromCache();
        loadCustomerSummary(cachedOpticalData);
        return;
    }

    opticalLoading = true;
    opticalLoadedForDevice = deviceIdToLoad;

    const currentRequest = ++opticalRequestCounter;

    // Mostra "Consultando OLT..." apenas quando realmente iniciamos nova consulta.
    const loadingIds = [
        'optical-rx-power',
        'optical-tx-power',
        'optical-temperature',
        'optical-voltage',
        'optical-pon-id',
        'optical-last-update',
        'optical-source'
    ];

    loadingIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = opticalLoadingHtml();
    });

    try {
        const response = await fetch(
            '/api/get-onu-optical.php?device_id=' + encodeURIComponent(deviceIdToLoad),
            {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );

        const raw = await response.text();
        let data;

        try {
            data = JSON.parse(raw);
        } catch (jsonError) {
            throw new Error('Resposta inválida da API óptica.');
        }

        if (currentRequest !== opticalRequestCounter) {
            return;
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Falha ao consultar dados ópticos.');
        }

        cachedOpticalData = {
            device_id: deviceIdToLoad,
            source: data.source || 'IXC',
            nome: data.nome || null,
            id_login: data.id_login ?? null,
            id_contrato: data.id_contrato ?? null,
            id_transmissor: data.id_transmissor ?? null,
            network: data.network || {},
            source_policy: data.source_policy || {},
            pon_id: data.pon_id || null,
            onu_number: data.onu_number ?? null,
            olt_id: data.olt_id || null,
            slot: data.slot ?? null,
            pon: data.pon ?? null,
            optical: data.optical || {},
            error: null,
            loaded_at: Date.now()
        };

        opticalLoadedForDevice = deviceIdToLoad;

        // Busca os elementos atuais do DOM e atualiza a tela atual,
        // mesmo que o auto-refresh tenha acontecido durante a consulta.
        updateOpticalDomFromCache();
        loadCustomerSummary(cachedOpticalData);

        console.log('[IXC] Dados ópticos carregados:', data);

    } catch (error) {
        console.error('[IXC] Erro:', error);

        if (currentRequest !== opticalRequestCounter) {
            return;
        }

        cachedOpticalData = {
            device_id: deviceIdToLoad,
            source: 'IXC',
            pon_id: null,
            onu_number: null,
            olt_id: null,
            id_transmissor: null,
            network: {},
            optical: {},
            error: error && error.message ? error.message : 'Erro TL1',
            loaded_at: Date.now()
        };

        opticalLoadedForDevice = deviceIdToLoad;
        updateOpticalDomFromCache();

    } finally {
        if (currentRequest === opticalRequestCounter) {
            opticalLoading = false;
        }
    }
}

function customerValue(value, fallback = 'Não informado') {
    return value === null || value === undefined || String(value).trim() === '' ? fallback : String(value);
}

function setCustomerText(id, value, fallback = 'Não informado') {
    const el = document.getElementById(id);
    if (el) el.textContent = customerValue(value, fallback);
}

function customerPillClass(value, kind) {
    const text = String(value || '').toLowerCase();
    if (kind === 'financial') {
        if (text.includes('em dia')) return 'ok';
        if (text.includes('vencid') || text.includes('atras')) return 'danger';
        return 'neutral';
    }
    if (text.includes('ativo')) return 'ok';
    if (text.includes('cancel') || text.includes('inativ') || text.includes('desativ')) return 'danger';
    if (text.includes('pend')) return 'warning';
    return 'neutral';
}

function renderCustomerSummary(data) {
    const strip = document.getElementById('acs-customer-strip');
    if (!strip) return;

    strip.hidden = false;
    setCustomerText('acs-customer-name', data?.customer?.name || cachedOpticalData?.nome, 'Cliente não identificado');
    setCustomerText('acs-customer-address', data?.customer?.address, 'Endereço não informado');
    setCustomerText('acs-customer-plan', data?.contract?.plan);
    setCustomerText('acs-customer-contract', data?.contract?.id || cachedOpticalData?.id_contrato);
    setCustomerText('acs-customer-login', data?.login?.username || data?.login?.id || cachedOpticalData?.id_login);
    setCustomerText('acs-customer-phone', data?.customer?.phone);

    const contractStatus = document.getElementById('acs-customer-contract-status');
    if (contractStatus) {
        contractStatus.textContent = customerValue(data?.contract?.status_label, 'Não informado');
        contractStatus.className = 'acs-customer-pill ' + customerPillClass(data?.contract?.status_label, 'contract');
    }

    const financial = document.getElementById('acs-customer-financial');
    if (financial) {
        financial.textContent = customerValue(data?.financial?.label, 'Não informado');
        financial.className = 'acs-customer-pill ' + customerPillClass(data?.financial?.label, 'financial');
    }

    const source = document.getElementById('acs-customer-source');
    if (source) {
        const clientId = data?.customer?.id;
        source.textContent = clientId ? 'IXC · Cliente #' + clientId : 'IXC';
    }

    const drawerClient = document.getElementById('jr-ai-drawer-client');
    if (drawerClient) {
        drawerClient.textContent = customerValue(data?.customer?.name || cachedOpticalData?.nome, 'Cliente não identificado');
    }
}

function renderCustomerSummaryLoading(optical) {
    const strip = document.getElementById('acs-customer-strip');
    if (!strip) return;
    strip.hidden = false;
    setCustomerText('acs-customer-name', optical?.nome, 'Consultando cliente...');
    setCustomerText('acs-customer-address', null, 'Consultando cadastro no IXC...');
    setCustomerText('acs-customer-plan', null, 'Consultando...');
    setCustomerText('acs-customer-contract', optical?.id_contrato, '—');
    setCustomerText('acs-customer-login', optical?.id_login, '—');
    setCustomerText('acs-customer-phone', null, 'Consultando...');
}

async function loadCustomerSummary(optical) {
    if (!optical || optical.error || (!optical.id_contrato && !optical.id_login)) return;

    if (
        cachedCustomerSummary &&
        String(cachedCustomerSummary.contract?.id || '') === String(optical.id_contrato || '') &&
        String(cachedCustomerSummary.login?.id || '') === String(optical.id_login || '')
    ) {
        renderCustomerSummary(cachedCustomerSummary);
        return;
    }

    renderCustomerSummaryLoading(optical);
    const requestId = ++customerSummaryRequestCounter;
    const params = new URLSearchParams();
    if (optical.id_contrato) params.set('id_contrato', optical.id_contrato);
    if (optical.id_login) params.set('id_login', optical.id_login);

    try {
        const response = await fetch('/api/get-client-summary.php?' + params.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        });
        const data = await response.json();
        if (requestId !== customerSummaryRequestCounter) return;
        if (!response.ok || !data.success) throw new Error(data.message || 'Falha ao consultar cliente no IXC.');
        cachedCustomerSummary = data;
        renderCustomerSummary(data);
    } catch (error) {
        if (requestId !== customerSummaryRequestCounter) return;
        const strip = document.getElementById('acs-customer-strip');
        if (strip) strip.hidden = false;
        setCustomerText('acs-customer-address', null, 'Cadastro complementar indisponível');
        const contractStatus = document.getElementById('acs-customer-contract-status');
        if (contractStatus) {
            contractStatus.textContent = 'IXC indisponível';
            contractStatus.className = 'acs-customer-pill neutral';
        }
        const financial = document.getElementById('acs-customer-financial');
        if (financial) {
            financial.textContent = 'Não consultado';
            financial.className = 'acs-customer-pill neutral';
        }
        console.warn('[IXC] Resumo do cliente indisponível:', error);
    }
}

function escapeOpticalHtml(value) {
    return String(value == null ? '' : value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

document.addEventListener('DOMContentLoaded', function() {
    loadDeviceDetail(); // Initial load (manual, scroll to top)
    loadAIStatus();
    // Auto refresh every 30 seconds (preserve scroll position)
    setInterval(() => loadDeviceDetail(true), 30000);
    setInterval(updateRadiusBandwidthSample, 5000);
    setInterval(updateOverviewOperationalMeta, 1000);

    // Auto-start/stop hotspot monitoring based on Connected Devices tab visibility
    const allTabs = document.querySelectorAll('[data-bs-toggle="tab"]');
    allTabs.forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(event) {
            if (tab.id === 'devices-tab') {
                // Start monitoring when Connected Devices tab is opened
                console.log('[TAB] Connected Devices tab opened - starting hotspot monitoring...');
                // Small delay to ensure table is rendered
                setTimeout(() => {
                    startHotspotTrafficMonitoring();
                }, 200);
            } else {
                // Stop monitoring when switching to other tabs
                if (hotspotTrafficInterval) {
                    console.log('[TAB] Switched away from Connected Devices - stopping hotspot monitoring...');
                    stopHotspotTrafficMonitoring();
                }
            }
        });
    });
});


function renderFirmwareTab(device) {
    const manufacturer = device.manufacturer || 'Não disponível';
    const model = device.product_class || 'Não disponível';
    const currentVersion = device.software_version || 'Não disponível';
    const hardwareVersion = device.hardware_version || 'Não disponível';

    return `
        <div class="acs-firmware-page">
            <div class="acs-firmware-header">
                <div>
                    <span class="acs-kicker"><i class="bi bi-cloud-arrow-up"></i> MANUTENÇÃO</span>
                    <h4>Atualização de Firmware</h4>
                    <p>Gerencie versões de software do equipamento através do ACS.</p>
                </div>
                <span class="acs-firmware-safe"><i class="bi bi-shield-check"></i> Atualização controlada</span>
            </div>

            <div class="acs-firmware-grid">
                <section class="acs-firmware-card">
                    <div class="acs-firmware-card-title">
                        <div class="acs-firmware-icon"><i class="bi bi-router"></i></div>
                        <div>
                            <span>Equipamento</span>
                            <h5>${model}</h5>
                        </div>
                    </div>
                    <div class="acs-firmware-info">
                        <div><span>Fabricante</span><strong>${manufacturer}</strong></div>
                        <div><span>Hardware</span><strong>${hardwareVersion}</strong></div>
                        <div><span>Firmware atual</span><strong class="version">${currentVersion}</strong></div>
                    </div>
                </section>

                <section class="acs-firmware-card acs-firmware-update">
                    <div class="acs-firmware-card-title">
                        <div class="acs-firmware-icon"><i class="bi bi-file-earmark-arrow-up"></i></div>
                        <div>
                            <span>Nova versão</span>
                            <h5>Enviar firmware</h5>
                        </div>
                    </div>

                    <div class="acs-firmware-dropzone">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <strong>Selecione o arquivo de firmware</strong>
                        <span>O envio e a instalação serão habilitados na próxima etapa.</span>
                        <button type="button" disabled><i class="bi bi-folder2-open"></i> Selecionar arquivo</button>
                    </div>
                </section>

                <section class="acs-firmware-card acs-firmware-wide">
                    <div class="acs-firmware-card-title">
                        <div class="acs-firmware-icon"><i class="bi bi-activity"></i></div>
                        <div>
                            <span>Status</span>
                            <h5>Processo de atualização</h5>
                        </div>
                    </div>
                    <div class="acs-firmware-status">
                        <div class="acs-firmware-step active"><b>1</b><span><strong>Equipamento identificado</strong><small>Modelo e versão atual coletados pelo ACS.</small></span></div>
                        <div class="acs-firmware-line"></div>
                        <div class="acs-firmware-step"><b>2</b><span><strong>Arquivo validado</strong><small>Aguardando seleção de firmware compatível.</small></span></div>
                        <div class="acs-firmware-line"></div>
                        <div class="acs-firmware-step"><b>3</b><span><strong>Instalação</strong><small>A atualização será enviada ao equipamento via TR-069.</small></span></div>
                    </div>
                    <div class="acs-firmware-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span><strong>Proteção ativa.</strong> O botão de instalação permanecerá bloqueado até implementarmos a validação do arquivo, modelo e versão. Nenhum firmware será enviado nesta etapa.</span>
                    </div>
                </section>
            </div>
        </div>
    `;
}


function openWebManagement() {
    if (!currentDeviceData) {
        alert('Os dados do equipamento ainda não foram carregados.');
        return;
    }
    const raw = currentDeviceData.ip_tr069 || currentDeviceData.ip_address || '';
    let host = '';
    try {
        host = /^https?:\/\//i.test(raw) ? new URL(raw).hostname : extractIP(raw);
    } catch (e) {
        host = extractIP(raw);
    }
    if (!host) {
        alert('Não foi possível identificar o IP de gerenciamento deste equipamento.');
        return;
    }
    window.open('http://' + host, '_blank', 'noopener,noreferrer');
}


let bandwidthSamples = [];
let bandwidthMonitorInterval = null;
let bandwidthSampleRequestInFlight = false;

function getPrimaryWAN(device) {
    const list = Array.isArray(device?.wan_details) ? device.wan_details : [];
    return list.find(w => String(w.status || '').toLowerCase() === 'connected') || list[0] || null;
}

function getTrafficCounters(device, wan) {
    const wanRx = toCounter(wan?.bytes_received);
    const wanTx = toCounter(wan?.bytes_sent);
    if (wanRx !== null && wanTx !== null && (wanRx > 0 || wanTx > 0)) {
        return { rx: wanRx, tx: wanTx, source: 'contador WAN' };
    }
    const lan = device?.lan_traffic;
    const lanRx = toCounter(lan?.bytes_received);
    const lanTx = toCounter(lan?.bytes_sent);
    if (lanRx !== null && lanTx !== null) {
        return { rx: lanRx, tx: lanTx, source: lan?.source || 'Portas LAN' };
    }
    return { rx: wanRx, tx: wanTx, source: 'contador WAN' };
}

function toCounter(value) {
    if (value === null || value === undefined || value === '' || value === 'N/A') return null;
    const n = Number(value);
    return Number.isFinite(n) && n >= 0 ? n : null;
}

function formatTrafficBytes(bytes) {
    let value = toCounter(bytes);
    if (value === null) return 'Sem leitura';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return (i === 0 ? value.toFixed(0) : value.toFixed(value >= 100 ? 0 : value >= 10 ? 1 : 2)) + ' ' + units[i];
}

function formatCounter(value) {
    const counter = toCounter(value);
    return counter === null ? 'Sem leitura' : counter.toLocaleString('pt-BR');
}

function formatUptimeValue(value) {
    const seconds = Number(value);
    if (!Number.isFinite(seconds)) return value || 'N/A';
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return [d ? d + 'd' : '', h ? h + 'h' : '', m + 'min'].filter(Boolean).join(' ');
}

function renderModernBandwidthChart(samples) {
    const valid=(Array.isArray(samples)?samples:[]).filter(s=>Number.isFinite(s.time)&&Number.isFinite(s.rxMbps)&&Number.isFinite(s.txMbps)&&s.rxMbps>=0&&s.txMbps>=0).slice(-60).sort((a,b)=>a.time-b.time);
    if (!valid.length) return '<div class="acs-chart-wait" role="status">Ainda sem amostras validas de trafego. O motivo da consulta aparece abaixo.</div>';
    const left=66,right=704,top=18,bottom=174;
    const peak=Math.max(...valid.flatMap(s=>[s.rxMbps,s.txMbps]));
    const upper=Math.max(0.1,Math.ceil(peak*1.15*10)/10);
    const first=valid[0].time,last=valid[valid.length-1].time;
    const x=t=>last===first ? (left+right)/2 : left+(t-first)/(last-first)*(right-left);
    const y=v=>bottom-v/upper*(bottom-top);
    const stamp=t=>new Date(t).toLocaleTimeString('pt-BR');
    const line=(field,color)=>{
        let previous=null,path='';
        for (const s of valid){path+=(previous===null||s.time-previous>30000?'M':'L')+x(s.time).toFixed(2)+','+y(s[field]).toFixed(2)+' ';previous=s.time;}
        const s=valid[valid.length-1];
        return `<path d="${path}" fill="none" stroke="${color}" stroke-width="2"/><circle cx="${x(s.time)}" cy="${y(s[field])}" r="3.5" fill="${color}"><title>${stamp(s.time)} | ${s[field].toFixed(3)} Mbps</title></circle>`;
    };
    const grid=[0,upper/2,upper].map(v=>`<line x1="${left}" x2="${right}" y1="${y(v)}" y2="${y(v)}" stroke="#364152" stroke-dasharray="4 5"/><text x="58" y="${y(v)+4}" text-anchor="end" fill="#aeb6c4" font-size="11">${v.toFixed(2)}</text>`).join('');
    const elapsed=Math.max(0,Math.round((last-first)/1000));
    return `<svg data-jrc-chart-fix="wan-v1" viewBox="0 0 740 220" style="display:block;width:100%;height:220px" role="img" aria-label="Download e upload medidos"><text x="${left}" y="12" fill="#aeb6c4" font-size="11">Mbps</text>${grid}${line('rxMbps','#ff5b63')}${line('txMbps','#60a5fa')}<text x="${left}" y="202" fill="#aeb6c4" font-size="11">${stamp(first)}</text>${last!==first?`<text x="${right}" y="202" text-anchor="end" fill="#aeb6c4" font-size="11">${stamp(last)}</text>`:''}</svg><div class="small text-muted"><span style="color:#ff5b63">Download</span> / <span style="color:#60a5fa">Upload</span> | ${valid.length} amostra(s) recebida(s) em ${elapsed}s</div>`;
}

function renderMonitoringTab(device) {
    const wan = getPrimaryWAN(device);
    if (!wan) {
        return '<div class="acs-monitor-empty"><i class="bi bi-graph-up"></i><h5>Monitoramento indisponível</h5><p>Nenhuma conexão WAN foi identificada neste equipamento.</p></div>';
    }
    const traffic = getTrafficCounters(device, wan);
    const rx = traffic.rx;
    const tx = traffic.tx;
    const connected = String(wan.status || '').toLowerCase() === 'connected';
    return `
        <div class="acs-monitor-shell">
            <div class="acs-monitor-head">
                <div><span class="acs-kicker"><i class="bi bi-activity"></i> TR-069</span><h4>Monitoramento da conexão</h4><p>O uso de banda em tempo real fica na Visão geral. Aqui continua o relatório da sessão.</p></div>
                <span class="acs-mini-badge ${connected ? 'success' : ''}">${wan.status || 'Unknown'}</span>
            </div>
            <div class="acs-connection-report">
                <div class="acs-chart-title"><strong>Relatório da conexão</strong><span>Sessão atual</span></div>
                <div class="acs-report-grid">
                    <div><span>Interface</span><strong>${wan.name || 'N/A'}</strong></div>
                    <div><span>Tipo</span><strong>${wan.type || 'N/A'}</strong></div>
                    <div><span>IP WAN</span><strong>${wan.external_ip || 'N/A'}</strong></div>
                    <div><span>Uptime</span><strong>${formatUptimeValue(wan.uptime)}</strong></div>
                    <div><span>Pacotes RX</span><strong>${formatCounter(wan.packets_received)}</strong></div>
                    <div><span>Pacotes TX</span><strong>${formatCounter(wan.packets_sent)}</strong></div>
                    <div><span>Erros RX/TX</span><strong>${formatCounter(wan.errors_received)} / ${formatCounter(wan.errors_sent)}</strong></div>
                    <div><span>Último erro</span><strong>${wan.last_error || 'N/A'}</strong></div>
                </div>
            </div>
        </div>`;
}

function updateBandwidthSample(device) {
    const wan = getPrimaryWAN(device);
    if (!wan) return;
    const now = Date.now();
    const traffic = getTrafficCounters(device, wan);
    const rx = traffic.rx;
    const tx = traffic.tx;
    const previous = bandwidthSamples.length ? bandwidthSamples[bandwidthSamples.length - 1] : null;
    let rxMbps = null, txMbps = null;
    if (previous && rx !== null && tx !== null && previous.rx !== null && previous.tx !== null && now > previous.time && rx >= previous.rx && tx >= previous.tx) {
        const seconds = (now - previous.time) / 1000;
        rxMbps = ((rx - previous.rx) * 8) / seconds / 1000000;
        txMbps = ((tx - previous.tx) * 8) / seconds / 1000000;
    }
    bandwidthSamples.push({time: now, rx, tx, rxMbps, txMbps});
    if (bandwidthSamples.length > 24) bandwidthSamples.shift();

    const rxEl = document.getElementById('live-rx-mbps');
    const txEl = document.getElementById('live-tx-mbps');
    if (rxEl) rxEl.textContent = rxMbps === null ? '--' : rxMbps.toFixed(2);
    if (txEl) txEl.textContent = txMbps === null ? '--' : txMbps.toFixed(2);

    const status = document.getElementById('bandwidth-sample-status');
    if (status) status.textContent = (rx === null || tx === null) ? 'Contadores ainda não foram coletados. Clique em Comunicar.' : (rxMbps === null ? 'Aguardando segunda leitura...' : 'Última amostra (' + traffic.source + '): ' + new Date(now).toLocaleTimeString('pt-BR'));

    const chart = document.getElementById('bandwidth-bars');
    if (chart) {
        const valid = bandwidthSamples.filter(s => s.rxMbps !== null);
        chart.innerHTML = renderModernBandwidthChart(valid);
    }
}

function startBandwidthMonitoring() {
    if (bandwidthMonitorInterval) {
        clearInterval(bandwidthMonitorInterval);
    }
    updateRadiusBandwidthSample();
    bandwidthMonitorInterval = setInterval(updateRadiusBandwidthSample, 6000);
}

function renderBandwidthLiveSample(live, sourceLabel) {
    const number=v=>(typeof v==='number'||(typeof v==='string'&&v.trim()!==''))&&Number.isFinite(Number(v))&&Number(v)>=0 ? Number(v):null;
    const rx=number(live?.download_mbps),tx=number(live?.upload_mbps);
    if(rx===null||tx===null) return false;
    const now=Date.now();
    bandwidthSamples.push({time:now,rx:null,tx:null,rxMbps:rx,txMbps:tx,source:String(sourceLabel||'Fonte nao informada')});
    if(bandwidthSamples.length>60)bandwidthSamples.splice(0,bandwidthSamples.length-60);
    const format=v=>v.toFixed(v>0&&v<0.01?4:2);
    const set=(id,text)=>{const node=document.getElementById(id);if(node)node.textContent=text;};
    set('live-rx-mbps',format(rx));set('live-tx-mbps',format(tx));
    set('bandwidth-sample-status',String(sourceLabel||'Fonte nao informada')+' | Amostra recebida: '+new Date(now).toLocaleTimeString('pt-BR'));
    const chart=document.getElementById('bandwidth-bars');
    if(chart)chart.innerHTML=renderModernBandwidthChart(bandwidthSamples);
    return true;
}

async function fetchBandwidthJson(url) {
    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),15000);
    try {
        const parsed=new URL(url,window.location.href);
        const allowed=['/api/get-radius-session.php','/api/get-ixc-live-traffic.php','/api/get-ne-live-traffic.php','/api/get-tr069-live-traffic.php'];
        if(parsed.origin!==window.location.origin || !allowed.includes(parsed.pathname)) return {success:false,available:false,message:'Consulta de trafego recusada: endpoint inesperado.'};
        const response=await fetch(parsed.href,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
        if(!response.ok)return {success:false,available:false,message:'Consulta de trafego retornou HTTP '+response.status+'.'};
        const type=response.headers.get('content-type')||'';
        if(!type.includes('json'))return {success:false,available:false,message:'A consulta nao retornou JSON. Verifique a sessao de acesso.'};
        const data=await response.json();
        if(!data||typeof data!=='object'||Array.isArray(data))return {success:false,available:false,message:'Formato de resposta de trafego invalido.'};
        if(!data.success)return {success:false,available:false,message:typeof data.message==='string'?data.message:'Fonte de trafego indisponivel.'};
        return data;
    } catch(error) {
        return {success:false,available:false,message:error?.name==='AbortError'?'Tempo limite de 15s na consulta de trafego.':'Falha de rede ou resposta invalida na consulta de trafego.'};
    } finally {clearTimeout(timeout);}
}

async function updateRadiusBandwidthSample() {
    const setStatus=text=>{const el=document.getElementById('bandwidth-sample-status');if(el)el.textContent=text;};
    const emptyRates=()=>{for(const id of ['live-rx-mbps','live-tx-mbps']){const el=document.getElementById(id);if(el)el.textContent='--';}};
    const paint=()=>{const chart=document.getElementById('bandwidth-bars');if(chart)chart.innerHTML=renderModernBandwidthChart(bandwidthSamples);};
    if(!deviceId || !document.getElementById('bandwidth-bars'))return;
    paint();
    if(bandwidthSampleRequestInFlight){setStatus('Consulta em andamento; aguardando resposta da fonte.');return;}
    bandwidthSampleRequestInFlight=true;
    const reasons=[];
    try {
        setStatus('Consultando sessao IXC/RADIUS...');
        const radius=await fetchBandwidthJson('/api/get-radius-session.php?device_id='+encodeURIComponent(deviceId));
        if(!radius?.success){emptyRates();setStatus('IXC/RADIUS: '+(radius?.message||'Consulta indisponivel.'));return;}
        const session=radius.session;
        if(!radius.online || !session){emptyRates();setStatus('A consulta nao localizou uma sessao PPPoE online no IXC/RADIUS. O estado WAN, sozinho, nao fornece uma taxa de trafego.');return;}
        const downloadTotal=toCounter(session.download_bytes),uploadTotal=toCounter(session.upload_bytes);
        for(const [id,value] of [['live-rx-total',downloadTotal],['live-tx-total',uploadTotal]]){const el=document.getElementById(id);if(el)el.textContent=formatTrafficBytes(value);}
        const source=document.getElementById('live-traffic-source');
        if(source)source.textContent='IXC/RADIUS | '+(session.interface||'sessao PPPoE');
        const accept=(result,label)=>!!(result?.success&&result?.available&&renderBandwidthLiveSample(result.live,result.source||label));
        const loginId=Number(radius.ixc_login_id);
        if(Number.isInteger(loginId)&&loginId>0){
            setStatus('Consultando trafego no IXC...');
            const live=await fetchBandwidthJson('/api/get-ixc-live-traffic.php?login_id='+encodeURIComponent(loginId));
            if(accept(live,'IXC'))return;
            reasons.push('IXC: '+(live?.message||'sem amostra valida'));
        }
        setStatus('Consultando trafego no concentrador...');
        const params=new URLSearchParams({ip:session.ip||'',username:session.username||'',nas_ip:session.bras||'',session_id:session.session_id||''});
        const ne=await fetchBandwidthJson('/api/get-ne-live-traffic.php?'+params);
        if(accept(ne,'Concentrador'))return;
        reasons.push('Concentrador: '+(ne?.message||'sem amostra valida'));
        setStatus('Consultando contadores TR-069 da sessao...');
        const tr=await fetchBandwidthJson('/api/get-tr069-live-traffic.php?device_id='+encodeURIComponent(deviceId));
        const management=/WAN common/i.test(String(tr?.profile||''));
        if(!management && accept(tr,'TR-069'))return;
        reasons.push(management?'TR-069: contador WANCommon excluido, conforme politica existente.':'TR-069: '+(tr?.message||'sem segunda amostra valida'));
        emptyRates();
        setStatus(reasons.join(' | '));
    } catch(error) {
        emptyRates();setStatus('Falha ao atualizar o grafico WAN. As amostras anteriores foram preservadas.');
        console.warn('[JRC device charts]',error);
    } finally {
        bandwidthSampleRequestInFlight=false;
        paint();
    }
}
function buildDeviceAIContext() {
    const device = currentDeviceData || {};
    const wan = typeof getPrimaryWAN === 'function' ? (getPrimaryWAN(device) || {}) : {};
    const optical = cachedOpticalData || {};
    const customer = cachedCustomerSummary || {};
    const opticalValues = optical.optical || {};

    return {
        equipment: {
            model: device.product_class || device.model || null,
            manufacturer: device.manufacturer || null,
            serial: device.serial_number || null,
            status: device.status || null,
            firmware: device.software_version || null,
            hardware: device.hardware_version || null,
            uptime: device.uptime || wan.uptime || null,
            last_inform: device.last_inform || null
        },
        wan: {
            name: wan.name || null,
            status: wan.status || null,
            type: wan.type || wan.connection_type || null,
            external_ip: wan.external_ip || null,
            pppoe_username: wan.username || device.pppoe_username || null,
            vlan: wan.vlan_id || wan.vlan || null,
            dns: wan.dns_servers || null,
            last_error: wan.last_error || null
        },
        optical: {
            rx_power_dbm: opticalValues.rx_power ?? null,
            tx_power_dbm: opticalValues.tx_power ?? null,
            temperature_c: opticalValues.temperature ?? null,
            voltage_v: opticalValues.voltage ?? null,
            olt_pon: optical.pon_id || null,
            slot: optical.slot ?? null,
            pon: optical.pon ?? null,
            onu_number: optical.onu_number ?? null,
            last_update: opticalValues.last_update || null
        },
        wifi: {
            ssid: device.wifi_ssid || null
        },
        lan_ports: Array.isArray(device.lan_ports)
            ? device.lan_ports.slice(0, 8).map(port => ({
                name: port.name || ('LAN' + (port.port || '')),
                status: port.status || null,
                speed: port.max_bit_rate || null,
                duplex: port.duplex_mode || null
            }))
            : [],
        connected_devices_count: Number(device.connected_devices_count ?? (Array.isArray(device.connected_devices) ? device.connected_devices.length : 0)),
        customer: {
            plan: customer.contract?.plan || null,
            contract_status: customer.contract?.status_label || null,
            financial_status: customer.financial?.label || null
        }
    };
}

function formatDeviceAIAnswer(text, provider, model) {
    const safe = escapeOpticalHtml(text || 'Sem resposta da IA.').replace(/\n/g, '<br>');
    const providerLabel = provider === 'anthropic' ? 'Claude' : 'OpenAI';
    const meta = [providerLabel, model].filter(Boolean).join(' · ');
    return '<div class="acs-ai-response-text">' + safe + '</div>' +
        (meta ? '<div class="acs-ai-response-meta"><i class="bi bi-stars"></i> ' + escapeOpticalHtml(meta) + '</div>' : '');
}

function openDeviceAIDrawer() {
    const drawerEl = document.getElementById('jrDeviceAIDrawer');
    if (!drawerEl || typeof bootstrap === 'undefined') return;

    renderStoredDeviceAIState();
    const drawer = bootstrap.Offcanvas.getOrCreateInstance(drawerEl);
    drawer.show();

    setTimeout(() => {
        const input = document.getElementById('jr-ai-drawer-input');
        if (input) input.focus();
    }, 250);
}

function clearDeviceAIConversation() {
    saveDeviceAIState({
        question: '',
        status: 'idle',
        answer: '',
        provider: '',
        model: '',
        error: '',
        messages: []
    });
    renderStoredDeviceAIState();

    const input = document.getElementById('jr-ai-drawer-input');
    if (input) input.value = '';
}

async function askDeviceAI(question) {
    const cleanQuestion = String(question || '').trim();
    if (!cleanQuestion || deviceAIState.status === 'loading') return;

    const messages = Array.isArray(deviceAIState.messages) ? [...deviceAIState.messages] : [];
    messages.push({
        role: 'user',
        content: cleanQuestion,
        created_at: Date.now()
    });

    saveDeviceAIState({
        question: cleanQuestion,
        status: 'loading',
        answer: '',
        error: '',
        messages
    });
    renderStoredDeviceAIState();

    const input = document.getElementById('jr-ai-drawer-input');
    const sendButton = document.getElementById('jr-ai-drawer-send');
    if (input) {
        input.value = '';
        input.disabled = true;
    }
    if (sendButton) sendButton.disabled = true;

    try {
        const recentConversation = messages.slice(0, -1).slice(-8).map(message => ({
            role: message.role,
            content: message.content
        }));

        const response = await fetch('/api/device-ai.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                device_id: deviceId,
                question: cleanQuestion,
                context: buildDeviceAIContext(),
                conversation: recentConversation
            })
        });

        const raw = await response.text();
        let data = null;
        try {
            data = JSON.parse(raw);
        } catch (_) {
            throw new Error('Resposta inválida do servidor de IA.');
        }

        if (!response.ok || !data?.success) {
            throw new Error(data?.message || 'Falha ao consultar a IA.');
        }

        const updatedMessages = Array.isArray(deviceAIState.messages)
            ? [...deviceAIState.messages]
            : messages;

        updatedMessages.push({
            role: 'assistant',
            content: data.answer || 'Sem resposta da IA.',
            provider: data.provider || '',
            model: data.model || '',
            created_at: Date.now()
        });

        saveDeviceAIState({
            question: '',
            status: 'done',
            answer: data.answer || '',
            provider: data.provider || '',
            model: data.model || '',
            error: '',
            messages: updatedMessages.slice(-20)
        });

    } catch (error) {
        const message = error && error.message ? error.message : 'Falha ao consultar a IA.';
        saveDeviceAIState({
            question: '',
            status: 'error',
            answer: '',
            error: message
        });
    } finally {
        renderStoredDeviceAIState();
        const latestInput = document.getElementById('jr-ai-drawer-input');
        const latestButton = document.getElementById('jr-ai-drawer-send');
        if (latestInput) {
            latestInput.disabled = false;
            latestInput.focus();
        }
        if (latestButton) latestButton.disabled = false;
    }
}

async function sendDeviceAIDrawerMessage() {
    const input = document.getElementById('jr-ai-drawer-input');
    if (!input) return;
    await askDeviceAI(input.value);
}

async function runDeviceAIAnalysis() {
    openDeviceAIDrawer();
}

async function runOverviewAIQuestion() {
    openDeviceAIDrawer();
}


function ensureNetworkDiagnosticModal() {
    let modalEl = document.getElementById('networkDiagnosticModal');
    if (modalEl) return modalEl;

    modalEl = document.createElement('div');
    modalEl.className = 'modal fade jr-network-diagnostic-modal';
    modalEl.id = 'networkDiagnosticModal';
    modalEl.tabIndex = -1;
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <small id="network-diagnostic-kicker">Diagnóstico de rede</small>
                        <h5 class="modal-title" id="network-diagnostic-title">Diagnóstico</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" id="network-diagnostic-body"></div>
                <div class="modal-footer">
                    <button type="button" class="acs-soft-btn" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(modalEl);
    return modalEl;
}

function diagnosticTargetChips() {
    return `
        <div class="jr-diagnostic-targets">
            <span><i class="bi bi-google"></i> Google</span>
            <span><i class="bi bi-instagram"></i> Instagram</span>
            <span><i class="bi bi-facebook"></i> Facebook</span>
            <span><i class="bi bi-youtube"></i> YouTube</span>
            <span><i class="bi bi-whatsapp"></i> WhatsApp</span>
        </div>`;
}

function openPendingNetworkDiagnostic(tool) {
    const modalEl = ensureNetworkDiagnosticModal();
    const title = modalEl.querySelector('#network-diagnostic-title');
    const body = modalEl.querySelector('#network-diagnostic-body');

    const data = {
        ping: {
            title: 'Teste de ping',
            icon: 'bi-geo-alt',
            text: 'A interface está pronta. O próximo passo é executar o ping pelo próprio equipamento do cliente via diagnóstico TR-069/TR-181, para que a latência represente a conexão real do assinante.'
        },
        traceroute: {
            title: 'Traceroute',
            icon: 'bi-bezier2',
            text: 'A interface está pronta. A rota deverá ser coletada pelo CPE compatível, evitando que o traceroute represente apenas o caminho do servidor ACS.'
        },
        nearby: {
            title: 'Redes próximas',
            icon: 'bi-broadcast-pin',
            text: 'A varredura precisa ser feita pelo rádio Wi-Fi do equipamento do cliente. Quando o modelo disponibilizar o scan, serão exibidos SSID, canal, nível de sinal e interferência.'
        }
    }[tool];

    if (!data) return;

    title.textContent = data.title;
    body.innerHTML = `
        <div class="jr-diagnostic-pending">
            <div class="jr-diagnostic-pending-icon"><i class="bi ${data.icon}"></i></div>
            <strong>Integração CPE pendente</strong>
            <p>${data.text}</p>
            ${tool === 'nearby' ? '' : diagnosticTargetChips()}
            <div class="jr-diagnostic-source-note">
                <i class="bi bi-shield-check"></i>
                Nenhum resultado será simulado ou executado a partir do servidor ACS como se fosse o equipamento do cliente.
            </div>
        </div>`;

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function runNetworkDiagnostic(tool) {
    if (tool === 'speedtest') {
        if (typeof window.startONUSpeedtest === 'function') {
            window.startONUSpeedtest();
            return;
        }
        if (typeof startONUSpeedtest === 'function') {
            startONUSpeedtest();
            return;
        }
        showToast('O teste de velocidade da ONU não está disponível nesta tela.', 'warning', 4000);
        return;
    }
    openPendingNetworkDiagnostic(tool);
}

// JRC_WIFI_CLIENTS_BY_BAND_UI_V1
function jrWifiClientIsActive(client) {
    if (!client) return false;
    return ![false, 0, '0', 'false', 'False', 'FALSE'].includes(client.active);
}

function jrWifiBandClients(device, band) {
    if (!device) return null;

    const direct = band === '2.4'
        ? device.wifi_clients_24ghz_devices
        : band === '5'
            ? device.wifi_clients_5ghz_devices
            : null;

    if (Array.isArray(direct)) {
        return direct.filter(jrWifiClientIsActive);
    }

    if (!Array.isArray(device.connected_devices)) {
        return null;
    }

    return device.connected_devices.filter(client => {
        if (!jrWifiClientIsActive(client)) return false;
        if (String(client.interface_type || '').toLowerCase() !== 'wifi') return false;
        return String(client.wifi_band || '') === band;
    });
}

function jrWifiClientCountLabel(count) {
    if (count === 1) return '1 conectado';
    return String(count) + ' conectados';
}

function jrWifiEscape(value) {
    return String(value == null ? '' : value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function jrEnhanceWifiClientCounters(device) {
    const container = document.querySelector('.acs-wifi-reference-grid');
    if (!container) return;

    const rows = Array.from(
        container.querySelectorAll('.acs-wifi-health-row:not(.acs-wifi-unified-row)')
    );

    const specs = [
        { band: '2.4', label: '2,4 GHz', row: rows[0] || null },
        { band: '5', label: '5 GHz', row: rows[1] || null }
    ];

    specs.forEach(spec => {
        if (!spec.row) return;

        const details = Array.from(spec.row.querySelectorAll('.acs-wifi-health-detail'));
        const target = details.find(detail => {
            const small = detail.querySelector('small');
            return small && small.textContent.trim().toLowerCase() === 'clientes';
        });
        if (!target) return;

        const strong = target.querySelector('strong');
        if (!strong) return;

        const clients = jrWifiBandClients(device, spec.band);
        if (clients === null) {
            strong.textContent = 'Não informado';
            return;
        }

        const label = jrWifiClientCountLabel(clients.length);

        if (clients.length === 0) {
            strong.textContent = label;
            return;
        }

        strong.innerHTML =
            '<button type="button" ' +
            'style="background:none;border:0;padding:0;color:inherit;font:inherit;font-weight:700;cursor:pointer;text-decoration:underline;text-decoration-color:#ef1b22;text-underline-offset:3px" ' +
            'onclick="jrOpenWifiClientsModal(\'' + spec.band + '\', \'' + spec.label + '\')" ' +
            'title="Ver clientes conectados">' +
            jrWifiEscape(label) +
            '</button>';
    });
}

function jrOpenWifiClientsModal(band, bandLabel) {
    const device = typeof currentDeviceData === 'object' && currentDeviceData
        ? currentDeviceData
        : null;
    const clients = jrWifiBandClients(device, band) || [];

    let modalEl = document.getElementById('jrWifiClientsModal');
    if (!modalEl) {
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'jrWifiClientsModal';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML =
            '<div class="modal-dialog modal-lg modal-dialog-centered">' +
              '<div class="modal-content" style="background:#111419;color:#f4f6f8;border:1px solid #2b3038">' +
                '<div class="modal-header" style="border-color:#2b3038">' +
                  '<div>' +
                    '<small style="color:#ef1b22;font-weight:700">JR CONECT · WI-FI</small>' +
                    '<h5 class="modal-title" id="jrWifiClientsTitle" style="margin-top:3px">Clientes conectados</h5>' +
                  '</div>' +
                  '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>' +
                '</div>' +
                '<div class="modal-body" id="jrWifiClientsBody"></div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modalEl);
    }

    const title = modalEl.querySelector('#jrWifiClientsTitle');
    const body = modalEl.querySelector('#jrWifiClientsBody');

    if (title) {
        title.textContent = 'Clientes conectados · ' + bandLabel;
    }

    if (body) {
        if (!clients.length) {
            body.innerHTML =
                '<div style="padding:18px;border:1px solid #2b3038;border-radius:10px;color:#aeb6c2">' +
                'Nenhum cliente conectado nesta banda.' +
                '</div>';
        } else {
            const header =
                '<div style="display:grid;grid-template-columns:minmax(180px,1.3fr) minmax(120px,.8fr) minmax(160px,1fr);gap:12px;padding:0 12px 9px;color:#8f9aaa;font-size:11px;font-weight:700;text-transform:uppercase">' +
                  '<span>Dispositivo</span><span>IP</span><span>MAC</span>' +
                '</div>';

            const rows = clients.map(client => {
                const name = client.hostname || client.vendor || 'Dispositivo';
                const vendor = client.vendor && client.vendor !== name ? client.vendor : '';
                const ip = client.ip_address || '-';
                const mac = client.mac_address || '-';
                const path = client.interface_path || '';

                return (
                    '<div style="display:grid;grid-template-columns:minmax(180px,1.3fr) minmax(120px,.8fr) minmax(160px,1fr);gap:12px;align-items:center;padding:12px;border:1px solid #2b3038;border-radius:10px;margin-bottom:8px;background:#15191f">' +
                      '<div><strong>' + jrWifiEscape(name) + '</strong>' +
                        (vendor ? '<small style="display:block;color:#8f9aaa;margin-top:3px">' + jrWifiEscape(vendor) + '</small>' : '') +
                        (path ? '<small style="display:block;color:#6f7885;margin-top:3px;word-break:break-all">' + jrWifiEscape(path) + '</small>' : '') +
                      '</div>' +
                      '<div>' + jrWifiEscape(ip) + '</div>' +
                      '<div><code style="color:#f4f6f8">' + jrWifiEscape(mac) + '</code></div>' +
                    '</div>'
                );
            }).join('');

            body.innerHTML = header + rows;
        }
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

window.jrOpenWifiClientsModal = jrOpenWifiClientsModal;

function renderWifiHealthCard(device) {
    const container = document.querySelector('.acs-wifi-reference-grid');
    if (!container) return;

    const escapeHtml = (value) => String(value ?? 'Não disponível')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;').replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const valueOf = (...values) => {
        const value = values.find(item => item !== null && item !== undefined && String(item).trim() !== '' && String(item).trim() !== 'N/A');
        return value === undefined ? 'Não disponível' : String(value);
    };

    const ssid24 = valueOf(device.wifi_ssid_24ghz, device.wifi_ssid_2g, device.wifi_ssid);
    const ssid5 = valueOf(device.wifi_ssid_5ghz, device.wifi_ssid_5g, device.wifi_ssid_5g_name);
    const connectedDevices = Array.isArray(device.connected_devices) ? device.connected_devices.length : null;
    const clientText = connectedDevices === null ? 'Não informado' : String(connectedDevices);
    const lastInform = valueOf(device.last_inform, device.last_update, device.last_seen);
    const safeForAttribute = value => String(value ?? '').replaceAll("'", "\\'");

    const boolText = value => value === false ? 'Desabilitada' : value === true ? 'Habilitada' : 'Não informado';
    const securityText = value => {
        const text = valueOf(value);
        if (text === 'Não disponível') return 'Não informado';
        if (/wpaand11i/i.test(text)) return 'WPA/WPA2';
        if (/11i|wpa2/i.test(text)) return 'WPA2';
        if (/wpa/i.test(text)) return 'WPA';
        if (/aes/i.test(text)) return 'AES';
        return text;
    };

    const renderBand = (band, icon, ssid, enabled, channel, security, clients) => `
        <article class="acs-wifi-health-row">
            <div class="acs-wifi-health-state">
                <i class="bi ${icon}"></i>
                <div><strong>${band}</strong><span><i class="bi bi-circle-fill"></i> ${boolText(enabled)}</span></div>
            </div>
            <div class="acs-wifi-health-detail"><small>SSID</small><strong title="${escapeHtml(ssid)}">${escapeHtml(ssid)}</strong></div>
            <div class="acs-wifi-health-detail"><small>Canal</small><strong>${escapeHtml(valueOf(channel))}</strong></div>
            <div class="acs-wifi-health-detail"><small>Segurança</small><strong>${escapeHtml(securityText(security))}</strong></div>
            <div class="acs-wifi-health-detail"><small>Clientes</small><strong>${clients ?? 'Não informado'}</strong></div>
            <button class="acs-wifi-edit-btn" type="button"
                onclick="openEditWiFiModal('${safeForAttribute(device.device_id)}', '${safeForAttribute(ssid)}', '${safeForAttribute(device.wifi_password)}')"
                title="Editar rede ${band}"><i class="bi bi-sliders"></i> Editar</button>
        </article>`;

    container.innerHTML = `
        <div class="acs-wifi-health-list">
            ${renderBand('2,4 GHz', 'bi-wifi', ssid24, device.wifi_enabled_24ghz, device.wifi_channel_24ghz, device.wifi_security_24ghz, device.wifi_clients_24ghz)}
            ${renderBand('5 GHz (5,8)', 'bi-wifi', ssid5, device.wifi_enabled_5ghz, device.wifi_channel_5ghz, device.wifi_security_5ghz, device.wifi_clients_5ghz)}
            <article class="acs-wifi-health-row acs-wifi-unified-row">
                <div class="acs-wifi-health-state">
                    <i class="bi bi-diagram-3"></i>
                    <div><strong>Rede Unificada</strong><span>Smart Connect</span></div>
                </div>
                <div class="acs-wifi-health-detail"><small>Tipo</small><strong>Band Steering</strong></div>
                <div class="acs-wifi-health-detail"><small>Bandas</small><strong>Conforme modem</strong></div>
                <div class="acs-wifi-health-detail acs-wifi-health-wide"><small>Status</small><strong>Consulta disponível</strong></div>
                <button class="acs-wifi-edit-btn" type="button"
                    onclick="openEditWiFiModal('${safeForAttribute(device.device_id)}', '${safeForAttribute(ssid24)}', '${safeForAttribute(device.wifi_password)}')"
                    title="Gerenciar Rede Unificada"><i class="bi bi-sliders"></i> Gerenciar</button>
            </article>
        </div>
        <footer class="acs-wifi-health-footer">
            <span><i class="bi bi-arrow-repeat"></i> Última leitura Wi-Fi: <strong>${escapeHtml(lastInform)}</strong></span>
            <span><i class="bi bi-shield-check"></i> TR-069 conectado</span>
            <span><i class="bi bi-people"></i> ${clientText} dispositivo(s) identificado(s)</span>
            <button type="button" onclick="loadDeviceDetail()" title="Atualizar dados do Wi-Fi"><i class="bi bi-arrow-clockwise"></i> Atualizar dados Wi-Fi</button>
        </footer>`;
}

const FB_ACTION_CATALOG = {
    ping: {
        title: 'Ping',
        icon: 'bi-activity',
        safe: true,
        host: true,
        desc: 'Testa conectividade e latência até um host. O teste precisa sair do próprio CPE.',
        run(host) { fbWritePendingResult('ping', host); }
    },
    traceroute: {
        title: 'TraceRoute',
        icon: 'bi-signpost-split',
        safe: true,
        host: true,
        desc: 'Mostra o caminho até o host. A rota precisa ser coletada pelo CPE, não pelo servidor ACS.',
        run(host) { fbWritePendingResult('traceroute', host); }
    },
    nearby: {
        title: 'Redes próximas',
        icon: 'bi-broadcast',
        safe: true,
        host: false,
        desc: 'Lista Wi-Fi, canal, sinal e interferência vistos pelo rádio do equipamento.',
        run() { fbWritePendingResult('nearby'); }
    },
    speedtest: {
        title: 'Velocidade',
        icon: 'bi-speedometer2',
        safe: true,
        host: false,
        desc: 'Mede download, upload e ping no fluxo de velocidade já usado neste equipamento.',
        run() {
            if (typeof startONUSpeedtest === 'function') startONUSpeedtest();
            fbWriteActionNote('bi-speedometer2', 'Velocidade', 'O teste abre na janela de velocidade deste equipamento.');
        }
    },
    'wifi-ssid': {
        title: 'Trocar SSID',
        icon: 'bi-wifi',
        safe: false,
        host: false,
        desc: 'Abre a edição de SSID e senha. A gravação usa a mesma API de Wi-Fi deste ACS.',
        run() {
            if (typeof fbOpenLegacyWifi === 'function') fbOpenLegacyWifi();
            fbWriteActionNote('bi-wifi', 'Trocar SSID', 'A edição de SSID e senha foi aberta.');
        }
    },
    'wifi-pass': {
        title: 'Trocar senha',
        icon: 'bi-key',
        safe: false,
        host: false,
        desc: 'Abre a edição de SSID e senha. A gravação usa a mesma API de Wi-Fi deste ACS.',
        run() {
            if (typeof fbOpenLegacyWifi === 'function') fbOpenLegacyWifi();
            fbWriteActionNote('bi-key', 'Trocar senha', 'A edição de SSID e senha foi aberta.');
        }
    },
    summon: {
        title: 'Comunicar',
        icon: 'bi-arrow-repeat',
        safe: true,
        host: false,
        desc: 'Pede ao equipamento uma nova comunicação com o ACS.',
        run() {
            if (typeof summonDevice === 'function') summonDevice();
            fbWriteActionNote('bi-arrow-repeat', 'Comunicar', 'Confirme o pedido na janela de comunicação.');
        }
    },
    web: {
        title: 'Acesso web',
        icon: 'bi-box-arrow-up-right',
        safe: true,
        host: false,
        desc: 'Abre o gerenciamento web do equipamento no endereço que ele informou.',
        run() {
            if (typeof openWebManagement === 'function') openWebManagement();
            fbWriteActionNote('bi-box-arrow-up-right', 'Acesso web', 'O gerenciamento web foi solicitado.');
        }
    },
    tag: {
        title: 'Etiqueta',
        icon: 'bi-tag',
        safe: false,
        host: false,
        desc: 'Adiciona uma etiqueta a este equipamento.',
        run() {
            if (typeof showAddTagModal === 'function') showAddTagModal();
            fbWriteActionNote('bi-tag', 'Etiqueta', 'A janela de etiqueta foi aberta.');
        }
    },
    untag: {
        title: 'Remover etiqueta',
        icon: 'bi-tag-fill',
        safe: false,
        host: false,
        desc: 'Remove uma etiqueta deste equipamento.',
        run() {
            if (typeof showRemoveTagModal === 'function') showRemoveTagModal();
            fbWriteActionNote('bi-tag-fill', 'Remover etiqueta', 'A janela para remover etiqueta foi aberta.');
        }
    },
    refresh: {
        title: 'Atualizar coleta',
        icon: 'bi-arrow-clockwise',
        safe: true,
        host: false,
        desc: 'Solicita uma nova coleta TR-069 ao equipamento.',
        async run() {
            try {
                const result = await fetchAPI('/api/device-actions.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        device_id: deviceId,
                        action: 'refresh'
                    })
                });

                if (!result || !result.success) {
                    throw new Error(
                        result?.message || 'Falha ao solicitar a coleta.'
                    );
                }

                if (typeof showToast === 'function') {
                    showToast(
                        'Nova coleta TR-069 solicitada.',
                        'success'
                    );
                }

                fbWriteActionNote(
                    'bi-arrow-clockwise',
                    'Atualizar coleta',
                    'Nova coleta TR-069 solicitada ao equipamento.'
                );

                setTimeout(() => {
                    if (typeof loadDeviceDetail === 'function') {
                        loadDeviceDetail();
                    }
                }, 4000);

            } catch (error) {

                if (typeof showToast === 'function') {
                    showToast(
                        error.message || 'Falha ao atualizar coleta.',
                        'danger'
                    );
                }
            }
        }
    },

    reboot: {
        title: 'Reiniciar equipamento',
        icon: 'bi-power',
        safe: false,
        host: false,
        desc: 'Reinicia o equipamento remotamente via TR-069.',
        async run() {

            const confirmation = window.prompt(
                'O cliente ficará temporariamente offline.\n\nDigite REINICIAR para confirmar:'
            );

            if (
                String(confirmation || '')
                    .trim()
                    .toUpperCase() !== 'REINICIAR'
            ) {
                return;
            }

            try {

                const result = await fetchAPI(
                    '/api/device-actions.php',
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            device_id: deviceId,
                            action: 'reboot',
                            confirm: 'REINICIAR'
                        })
                    }
                );

                if (!result || !result.success) {
                    throw new Error(
                        result?.message ||
                        'Falha ao enviar reinício.'
                    );
                }

                if (typeof showToast === 'function') {
                    showToast(
                        'Comando de reinício enviado ao equipamento.',
                        'success'
                    );
                }

                fbWriteActionNote(
                    'bi-power',
                    'Reiniciar equipamento',
                    'O comando de reinício foi enviado ao GenieACS.'
                );

            } catch (error) {

                if (typeof showToast === 'function') {
                    showToast(
                        error.message ||
                        'Falha ao reiniciar equipamento.',
                        'danger'
                    );
                }
            }
        }
    },

    summary: {
        title: 'Resumo',
        icon: 'bi-clipboard2-pulse',
        safe: true,
        host: false,
        desc: 'Gera o resumo técnico com os dados já coletados.',
        run() {
            if (typeof runDeviceAISummary === 'function') runDeviceAISummary();
            fbWriteActionNote('bi-clipboard2-pulse', 'Resumo', 'O resumo técnico foi solicitado.');
        }
    },
    assistant: {
        title: 'Assistente',
        icon: 'bi-stars',
        safe: true,
        host: false,
        desc: 'Abre o assistente técnico deste equipamento.',
        run() {
            if (typeof openDeviceAIDrawer === 'function') openDeviceAIDrawer();
            fbWriteActionNote('bi-stars', 'Assistente', 'O assistente foi aberto.');
        }
    }
};

function fbEscapeAction(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function fbActionHost() {
    const input = document.getElementById('fb-action-host');
    const value = input ? input.value.trim() : '';
    return value || '8.8.8.8';
}

function fbWriteResultCard(icon, title, text, note) {
    const slot = document.getElementById('fb-action-result');
    if (!slot) return;
    slot.innerHTML = `
        <article class="fb-result-card">
            <div>
                <i class="bi ${fbEscapeAction(icon)}"></i>
                <strong>${fbEscapeAction(title)}</strong>
                <span>${fbEscapeAction(text)}</span>
            </div>
            <em class="fb-result-wait">Pendente</em>
        </article>
        ${note ? `<p class="fb-result-note">${fbEscapeAction(note)}</p>` : ''}`;
}

function fbWriteActionNote(icon, title, text) {
    fbWriteResultCard(icon, title, text, '');
}

function fbWritePendingResult(tool, host) {
    const target = host || '8.8.8.8';
    const copy = {
        ping: {
            icon: 'bi-activity',
            title: `Ping · ${target}`,
            text: 'Aguardando o diagnóstico sair do CPE.',
            note: 'Nenhum resultado é simulado. Quando o equipamento responder o diagnóstico TR-069, a latência até ' + target + ' aparece aqui.'
        },
        traceroute: {
            icon: 'bi-signpost-split',
            title: `TraceRoute · ${target}`,
            text: 'Aguardando a rota ser coletada pelo CPE.',
            note: 'Nenhum resultado é simulado. O caminho exibido precisa ser o do equipamento do cliente, não o do servidor ACS.'
        },
        nearby: {
            icon: 'bi-broadcast',
            title: 'Redes próximas',
            text: 'Aguardando a varredura do rádio Wi-Fi do CPE.',
            note: 'Quando o modelo informar o scan, SSID, canal, sinal e interferência aparecem aqui.'
        }
    }[tool];
    if (!copy) return;
    fbWriteResultCard(copy.icon, copy.title, copy.text, copy.note);
}

function fbSelectAction(id) {
    const action = FB_ACTION_CATALOG[id];
    const root = document.getElementById('acs-diagnostics-content');
    if (!action || !root) return;
    root.dataset.fbCurrent = id;
    root.querySelectorAll('[data-fb-action]').forEach((button) => {
        button.classList.toggle('is-on', button.dataset.fbAction === id);
    });
    const icon = document.getElementById('fb-action-icon');
    const title = document.getElementById('fb-action-title');
    const desc = document.getElementById('fb-action-desc');
    const safe = document.getElementById('fb-action-safe');
    const hostWrap = document.getElementById('fb-action-host-wrap');
    if (icon) icon.className = `bi ${action.icon}`;
    if (title) title.textContent = action.title;
    if (desc) desc.textContent = action.desc;
    if (safe) safe.classList.toggle('is-off', !action.safe);
    if (hostWrap) hostWrap.classList.toggle('is-off', !action.host);
}

function fbFilterActions() {
    const query = (document.getElementById('fb-action-search')?.value || '').trim().toLowerCase();
    document.querySelectorAll('#acs-diagnostics-content .fb-action-group').forEach((group) => {
        let visible = 0;
        group.querySelectorAll('[data-fb-action]').forEach((button) => {
            const show = !query || button.textContent.trim().toLowerCase().includes(query);
            button.classList.toggle('is-hidden', !show);
            if (show) visible += 1;
        });
        group.classList.toggle('is-hidden', visible === 0);
    });
}

function fbInitActionPalette() {
    const root = document.getElementById('acs-diagnostics-content');
    if (!root || root.dataset.fbReady === '1') return;
    root.dataset.fbReady = '1';
    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-fb-action]');
        if (!button || button.id === 'fb-action-run') return;
        fbSelectAction(button.dataset.fbAction);
    });
    document.getElementById('fb-action-run')?.addEventListener('click', () => {
        const current = FB_ACTION_CATALOG[root.dataset.fbCurrent || 'ping'];
        if (current) current.run(fbActionHost());
    });
    document.getElementById('fb-action-host')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        document.getElementById('fb-action-run')?.click();
    });
    document.getElementById('fb-action-search')?.addEventListener('input', fbFilterActions);
    fbSelectAction('ping');
}

fbInitActionPalette();


