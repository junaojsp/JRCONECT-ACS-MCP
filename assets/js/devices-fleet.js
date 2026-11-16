// JRC fleet inventory search fix v1 — production layout preserved.
/**
 * devices.js
 * Main JavaScript for devices page
 * Global state variables are defined in devices-state.js
 */


let inventoryLoading = false;
let inventoryLoadError = false;
let inventoryRevision = 0;
let clientMatchTerm = '';
let clientMatchSerials = new Set();
itemsPerPage = 8;

function inventoryNotice(message) {
    const input = document.getElementById('search-input');
    if (!input) return;
    let notice = document.getElementById('device-search-status');
    if (!notice) {
        notice = document.createElement('div');
        notice.id = 'device-search-status';
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        input.parentElement.appendChild(notice);
    }
    notice.textContent = message;
}

async function loadDevices(isAutoRefresh = false) {
    if (inventoryLoading) return;
    inventoryLoading = true;
    inventoryLoadError = false;
    inventoryNotice('Atualizando inventário completo…');
    if (!isAutoRefresh) clearOnuReadCache();
    // Keep the last complete snapshot searchable until every new chunk succeeds.
    if (!allDevices.length) renderCurrentDeviceResults();
    const snapshot = [];
    const chunkSize = 100;
    try {
        let skip = 0;
        while (true) {
            const chunk = await fetchAPI(`/api/get-devices.php?limit=${chunkSize}&skip=${skip}`);
            if (!chunk?.success || !Array.isArray(chunk.devices)) throw new Error('Falha ao carregar o inventário completo.');
            if (chunk.hasMore && !chunk.devices.length) throw new Error('Paginação do inventário sem progresso.');
            snapshot.push(...chunk.devices);
            if (!chunk.hasMore) break;
            skip += chunkSize;
        }
        const previous = new Map(allDevices.map(d => [d.device_id, d]));
        for (const device of snapshot) {
            const old = previous.get(device.device_id);
            if (old) for (const key of Object.keys(old)) {
                if (key.startsWith('ixc_') && device[key] == null) device[key] = old[key];
            }
        }
        allDevices = snapshot;
        inventoryRevision++;
        inventoryLoading = false;
        generateTableHeader('onu');
        updateSearchPlaceholder('onu');
        updateDeviceTypeCounts(allDevices);
        // Same matcher for typing, refresh, sorting and page navigation.
        filterDevices(false);
        inventoryNotice('Inventário completo: ' + allDevices.length + ' equipamentos.');
    } catch (error) {
        inventoryLoading = false;
        inventoryLoadError = true;
        console.warn('Falha ao atualizar inventário:', error);
        renderCurrentDeviceResults();
        inventoryNotice(allDevices.length
            ? 'Falha na atualização. Mantida a última lista completa; tente Atualizar novamente.'
            : 'Não foi possível carregar o inventário completo. Tente Atualizar novamente.');
    }
}

let devicesRenderRequest = 0;

function fleetEscape(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[char]));
}

function fleetUptime(value) {
    const seconds = Number(value);
    if (!Number.isFinite(seconds) || seconds <= 0) return '—';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    if (days > 0) return days + 'd ' + hours + 'h';
    const minutes = Math.floor((seconds % 3600) / 60);
    if (hours > 0) return hours + 'h ' + minutes + 'min';
    return Math.max(1, minutes) + ' min';
}

function fleetSeen(device) {
    let timestamp = Number(device?.last_inform_timestamp);
    if (!timestamp && device?.last_inform && device.last_inform !== 'N/A') {
        const parsed = Date.parse(String(device.last_inform).replace(' ', 'T'));
        if (!Number.isNaN(parsed)) timestamp = parsed / 1000;
    }
    if (!timestamp) return '—';
    const diff = Math.max(0, Date.now() / 1000 - timestamp);
    const days = Math.floor(diff / 86400);
    if (days >= 1) return days + ' d';
    const hours = Math.floor(diff / 3600);
    if (hours >= 1) return hours + ' h';
    return Math.max(1, Math.floor(diff / 60)) + ' min';
}

function listWifiSsid(device) {
    return device.wifi_ssid_24ghz ?? device.wifi_ssid ?? 'N/A';
}

async function renderDevices(devices) {
    const renderRequest = ++devicesRenderRequest;
    const seen = new Set();
    devices = devices.filter(device => {
        const key = device.device_id || normalizeDeviceSerial(device.serial_number);
        if (!key) return true;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
    const tbody = document.getElementById('devices-tbody');
    tbody.innerHTML = '';

    // Determine appropriate colspan based on current filter type
    const colspan = 6;
    // Update totals even when there are no matches, before slicing the view.
    updatePaginationUI(devices.length);
    updateDeviceCount(devices.length, allDevices.length);
    updateDeviceStats(devices, true);

    if (devices.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center">${inventoryLoading && !allDevices.length ? 'Carregando inventário completo…' : inventoryLoadError && !allDevices.length ? 'Inventário indisponível. Tente Atualizar.' : 'Nenhum equipamento encontrado.'}</td></tr>`;
        updatePaginationUI(0);
        return;
    }

    // Store total for pagination
    totalDevices = devices.length;

    // Apply pagination (slice devices array)
    let devicesToRender = devices;
    if (itemsPerPage > 0) {
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        devicesToRender = devices.slice(startIndex, endIndex);

    }

    // Update pagination UI
    updatePaginationUI(totalDevices);

    const serialNumbers = devicesToRender.map(device => device.serial_number);
    let ixcDeviceMap = {};
    const renderedRows = new Map();

    function drawRows() {
        if (renderRequest !== devicesRenderRequest || currentFilterType !== 'onu') return;
    devicesToRender.forEach(device => {
        let row = renderedRows.get(device.device_id);
        const selected = row?.querySelector('.device-checkbox')?.checked || false;
        const isNewRow = !row;
        if (!row) {
            row = document.createElement('tr');
            renderedRows.set(device.device_id, row);
        }
        const serialKey = normalizeDeviceSerial(device.serial_number);
        const ixc = ixcDeviceMap[serialKey] || ixcDeviceMap[device.serial_number] || {};
        const ipAddress = extractIP(ixc.ip || device.ixc_ip || device.ip_tr069);
        const pppoeUsername = ixc.pppoe_username || device.ixc_pppoe_username || device.pppoe_username || 'N/A';
        const rxSourceValue = (ixc.rx_power !== null && ixc.rx_power !== undefined && ixc.rx_power !== '')
            ? ixc.rx_power
            : device.ixc_rx_power ?? device.rx_power;
        const networkSource = ixc.found ? (ixc.source || 'IXC') : 'TR-069 fallback';

        // Guarda o enriquecimento IXC no próprio objeto para busca/ordenação.
        // Não remove os valores originais do TR-069; apenas registra a fonte principal.
        if (ixc.found) {
            device.ixc_ip = ixc.ip ?? null;
            device.ixc_pppoe_username = ixc.pppoe_username ?? null;
            device.ixc_rx_power = ixc.rx_power ?? null;
            device.ixc_temperature = ixc.temperature ?? null;
            device.ixc_vlan = ixc.vlan ?? null;
            device.ixc_ipv6 = ixc.ipv6 ?? null;
            device.ixc_source = networkSource;
            device.ixc_queried_at = ixc._queried_at;
            device.ixc_last_signal_update = ixc.last_signal_update;
        }

        const titleParts = [device.manufacturer, device.product_class].filter(part => part && part !== 'N/A' && part !== 'N/D');
        const equipTitle = fleetEscape(titleParts.join(' ') || device.product_class || 'Equipamento');
        const serialText = fleetEscape(device.serial_number || '—');
        const clientRaw = String(pppoeUsername || '').trim();
        const clientText = (!clientRaw || clientRaw === 'N/A' || clientRaw === 'N/D') ? '—' : fleetEscape(clientRaw);
        const ipText = (!ipAddress || ipAddress === 'N/A') ? '' : fleetEscape(ipAddress);
        const ipCell = ipText ? `<a href="http://${ipText}" target="_blank" rel="noopener noreferrer">${ipText}</a>` : '—';
        const rxNumber = parseFloat(rxSourceValue);
        let signal = '<span class="fb-signal is-muted"><i class="bi bi-circle-fill"></i>Sem dado</span>';
        if (Number.isFinite(rxNumber) && rxNumber !== -999) {
            const band = rxNumber > -25 ? 'is-good' : (rxNumber > -27 ? 'is-warn' : 'is-bad');
            signal = `<span class="fb-signal ${band}"><i class="bi bi-circle-fill"></i>${fleetEscape(rxNumber.toFixed(2))} dBm</span>`;
        }
        const detailHref = '/device-detail.php?id=' + encodeURIComponent(device.device_id || '');

        row.innerHTML = `
            <td><a class="fb-equip" href="${detailHref}"><span class="fb-equip-icon"><i class="bi bi-router"></i></span><span><strong>${equipTitle}</strong><small>${serialText}</small></span></a></td>
            <td class="fb-muted">${clientText}</td>
            <td>${ipCell}</td>
            <td>${signal}</td>
            <td>${fleetEscape(fleetUptime(device.uptime))}</td>
            <td class="fb-muted">${fleetEscape(fleetSeen(device))}</td>
        `;
        if (isNewRow) tbody.appendChild(row);
        const checkbox = row.querySelector('.device-checkbox');
        if (checkbox) checkbox.checked = selected;
    });
    }

    // Display available device data before any network request completes.
    drawRows();

    void Promise.allSettled([
        (async () => {
            try {
                const result = await fetchOnuBatch('/api/get-devices-ixc-batch.php', serialNumbers);
                if (result?.success && result.devices) {
                    ixcDeviceMap = result.devices;
                    if (renderRequest !== devicesRenderRequest || currentFilterType !== 'onu') return;
                    drawRows();
                    updateDeviceStats(devices, true);
                }
            } catch (error) {
                console.warn('IXC enrichment failed:', error);
            }
        })()
    ]);
}

function updateDeviceCount(shown, total) {
    const countElement = document.getElementById('device-count');
    if (!countElement) return;
    const start = shown ? (itemsPerPage > 0 ? (currentPage - 1) * itemsPerPage + 1 : 1) : 0;
    const end = itemsPerPage > 0 ? Math.min(currentPage * itemsPerPage, shown) : shown;
    countElement.textContent = shown
        ? `Exibindo ${start}–${end} de ${shown} resultados (${total} no inventário)`
        : 'Nenhum resultado na lista carregada';
}

function updateDeviceStats(devices, showStats = true) {
    const statsContainer = document.getElementById('device-stats-badges');
    if (!statsContainer) return;

    if (!showStats) {
        statsContainer.innerHTML = '';
        return;
    }

    const list = Array.isArray(devices) ? devices : [];
    let weak = 0;
    let critical = 0;
    list.forEach(device => {
        const rx = parseFloat(device.ixc_rx_power ?? device.rx_power);
        if (!Number.isFinite(rx) || rx === -999) return;
        if (rx <= -27) critical += 1;
        else if (rx <= -25) weak += 1;
    });
    const fmt = (value) => Number(value || 0).toLocaleString('pt-BR');

    statsContainer.innerHTML = `
        <div class="fb-metric"><strong>${fmt(list.length)}</strong><span>total</span></div>
        <div class="fb-metric is-warn"><strong>${fmt(weak)}</strong><span>fraco</span></div>
        <div class="fb-metric is-bad"><strong>${fmt(critical)}</strong><span>crítico</span></div>
    `;
}

function updateDeviceTypeCounts(devices) {
    document.getElementById('count-onu').textContent = devices.length;
}

// Generate table header based on device type
function generateTableHeader(type) {
    const tableHeader = document.getElementById('table-header');

    if (type === 'onu') {
        // ONU devices table header (GenieACS devices)
        tableHeader.innerHTML = `
            <tr>
                <th class="sortable" onclick="sortTable('product_class')">Equipamento</th>
                <th class="sortable" onclick="sortTable('pppoe_username')">Cliente (PPPoE)</th>
                <th class="sortable" onclick="sortTable('ip')">IP WAN</th>
                <th class="sortable" onclick="sortTable('rx_power')">Sinal RX</th>
                <th class="sortable" onclick="sortTable('uptime')">Uptime</th>
                <th class="sortable" onclick="sortTable('last_inform')">Visto</th>
            </tr>
        `;
    }
}

// Select ONU equipment
function filterByType(type) {
    currentFilterType = 'onu';
    generateTableHeader('onu');
    updateSearchPlaceholder('onu');
    filterDevices(false);
}

function extractIP(ipString) {
    if (!ipString || ipString === 'N/A') return 'N/A';
    const match = ipString.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
    return match ? match[1] : 'N/A';
}

// Update search placeholder based on current tab
function updateSearchPlaceholder(type) {
    const searchInput = document.getElementById('search-input');
    if (type === 'onu') {
        searchInput.placeholder = 'Buscar por serial, MAC, login, CPF ou nome do cliente...';
    } else {
        searchInput.placeholder = 'Search by Name...';
    }
}

// Search functionality
let clientSearchTimer = null;
let clientSearchRequest = 0;

function normalizeDeviceSerial(value) {
    return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function normalizeSearchValue(value) {
    return String(value ?? '').toLowerCase().trim();
}

function compactSearchValue(value) {
    return normalizeSearchValue(value).replace(/[^a-z0-9]/g, '');
}

function deviceMatchesLocalSearch(device, searchTerm) {
    const term = normalizeSearchValue(searchTerm);
    const compactTerm = compactSearchValue(searchTerm);

    const directValues = [
        device.serial_number,
        device.mac_address,
        device.ixc_pppoe_username,
        device.pppoe_username,
        device.ixc_ip,
        device.device_id,
        device.product_class,
        device.manufacturer,
        listWifiSsid(device),
        device.ip_tr069,
        device.ip_address,
        device.status
    ];

    if (Array.isArray(device.tags)) {
        directValues.push(...device.tags);
    }

    // Primeiro pesquisa nos campos principais exibidos/úteis.
    const directMatch = directValues.some(value => {
        const text = normalizeSearchValue(value);
        const compact = compactSearchValue(value);
        return text.includes(term) || (compactTerm && compact.includes(compactTerm));
    });

    if (directMatch) return true;

    // Fallback: pesquisa em qualquer valor simples retornado pelo GenieACS.
    // Isso cobre modelos/fabricantes que usam nomes de campos diferentes.
    return Object.entries(device || {}).some(([key, value]) => {
        // Other Wi-Fi bands and credentials belong only in equipment details.
        if (/wifi|wlan|ssid|password|passphrase|pre.?shared/i.test(key)) return false;
        if (value == null) return false;

        if (Array.isArray(value)) {
            return value.some(item => {
                const text = normalizeSearchValue(item);
                const compact = compactSearchValue(item);
                return text.includes(term) || (compactTerm && compact.includes(compactTerm));
            });
        }

        if (typeof value === 'object') return false;

        const text = normalizeSearchValue(value);
        const compact = compactSearchValue(value);
        return text.includes(term) || (compactTerm && compact.includes(compactTerm));
    });
}

async function searchClientDevices(searchTerm, requestId, revision) {
    const isCurrent = () => requestId === clientSearchRequest && revision === inventoryRevision
        && readDeviceSearchTerm() === searchTerm && currentFilterType === 'onu';
    try {
        const response = await fetch('/api/search-client-devices.php?q=' + encodeURIComponent(searchTerm), {
            credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || data.success === false || !Array.isArray(data.serials)) throw new Error('Busca de cliente indisponível.');
        if (!isCurrent()) return;
        clientMatchTerm = searchTerm;
        clientMatchSerials = new Set(data.serials.map(normalizeDeviceSerial));
        renderCurrentDeviceResults();
    } catch (error) {
        // Never restore a stale list captured before another search/refresh.
        if (isCurrent()) console.warn('[SEARCH IXC] Mantida busca local:', error);
    }
}

function readDeviceSearchTerm() {
    return normalizeSearchValue(document.getElementById('search-input')?.value);
}

function currentDeviceResults() {
    const term = readDeviceSearchTerm();
    let results = allDevices.filter(device => !term || deviceMatchesLocalSearch(device, term)
        || (clientMatchTerm === term && clientMatchSerials.has(normalizeDeviceSerial(device.serial_number))));
    if (currentSortColumn) results = applySorting(results, currentSortColumn, currentSortDirection);
    return results;
}

function renderCurrentDeviceResults() {
    renderDevices(currentDeviceResults());
}

function filterDevices(resetPage = true) {
    const term = readDeviceSearchTerm();
    if (resetPage) currentPage = 1;
    clearTimeout(clientSearchTimer);
    const requestId = ++clientSearchRequest;
    if (clientMatchTerm !== term) {
        clientMatchTerm = term;
        clientMatchSerials = new Set();
    }
    renderCurrentDeviceResults();
    if (term.length >= 3 && allDevices.length) {
        const revision = inventoryRevision;
        clientSearchTimer = setTimeout(() => searchClientDevices(term, requestId, revision), 450);
    }
}

function clearSearch() {
    document.getElementById('search-input').value = '';
    filterDevices();
}

// Apply sorting to devices array (helper function for auto-refresh)
function applySorting(devices, column, direction) {
    const sortedDevices = [...devices];

    sortedDevices.sort((a, b) => {
        let valueA, valueB;

        switch (column) {
            case 'product_class':
                valueA = ([a.manufacturer, a.product_class].filter(Boolean).join(' ') || '').toLowerCase();
                valueB = ([b.manufacturer, b.product_class].filter(Boolean).join(' ') || '').toLowerCase();
                break;
            case 'uptime':
                valueA = Number(a.uptime) || 0;
                valueB = Number(b.uptime) || 0;
                break;
            case 'last_inform':
                valueA = Number(a.last_inform_timestamp) || Date.parse(String(a.last_inform || '').replace(' ', 'T')) || 0;
                valueB = Number(b.last_inform_timestamp) || Date.parse(String(b.last_inform || '').replace(' ', 'T')) || 0;
                break;
            case 'ip':
                valueA = extractIP(a.ixc_ip || a.ip_tr069);
                valueB = extractIP(b.ixc_ip || b.ip_tr069);
                // Convert IP to comparable format
                valueA = valueA === 'N/A' ? '' : valueA.split('.').map(n => n.padStart(3, '0')).join('.');
                valueB = valueB === 'N/A' ? '' : valueB.split('.').map(n => n.padStart(3, '0')).join('.');
                break;
            case 'ssid':
                valueA = (listWifiSsid(a) || '').toLowerCase();
                valueB = (listWifiSsid(b) || '').toLowerCase();
                break;
            case 'pppoe_username':
                valueA = (a.ixc_pppoe_username || a.pppoe_username || '').toLowerCase();
                valueB = (b.ixc_pppoe_username || b.pppoe_username || '').toLowerCase();
                break;
            case 'rx_power':
                valueA = parseFloat(a.ixc_rx_power ?? a.rx_power) || -999;
                valueB = parseFloat(b.ixc_rx_power ?? b.rx_power) || -999;
                break;
            case 'temperature':
                valueA = parseFloat(a.ixc_temperature ?? a.temperature) || -999;
                valueB = parseFloat(b.ixc_temperature ?? b.temperature) || -999;
                break;
            case 'connected_clients':
                valueA = parseInt(a.connected_devices_count) || 0;
                valueB = parseInt(b.connected_devices_count) || 0;
                break;
            case 'status':
                valueA = a.status || '';
                valueB = b.status || '';
                break;
            case 'tags':
                // Sort by tags: join array to string, empty array goes to bottom
                valueA = (a.tags && Array.isArray(a.tags) && a.tags.length > 0) ? a.tags.join(', ').toLowerCase() : 'zzz'; // 'zzz' puts empty tags at bottom
                valueB = (b.tags && Array.isArray(b.tags) && b.tags.length > 0) ? b.tags.join(', ').toLowerCase() : 'zzz';
                break;
            default:
                return 0;
        }

        let comparison = 0;
        if (valueA > valueB) comparison = 1;
        if (valueA < valueB) comparison = -1;

        return direction === 'asc' ? comparison : -comparison;
    });

    return sortedDevices;
}

// Sorting functionality
function sortTable(column) {
    if (currentSortColumn === column) currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
    else { currentSortColumn = column; currentSortDirection = 'asc'; }
    currentPage = 1;
    renderCurrentDeviceResults();
    updateSortIcons(column, currentSortDirection);
}

function updateSortIcons(column, direction) {
    // Reset all sort icons
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.className = 'bi bi-chevron-expand sort-icon';
    });

    // Update active sort icon (indices shifted +1 due to checkbox column)
    const columnMap = {
        'product_class': 4,
        'ip': 5,
        'ssid': 6,
        'pppoe_username': 7,
        'rx_power': 8,
        'temperature': 9,
        'connected_clients': 10,
        'status': 11,
        'tags': 12
    };

    const columnIndex = columnMap[column];
    if (columnIndex) {
        const header = document.querySelector(`thead tr th:nth-child(${columnIndex}) .sort-icon`);
        if (header) {
            header.className = direction === 'asc' ? 'bi bi-chevron-up sort-icon' : 'bi bi-chevron-down sort-icon';
        }
    }
}

function resetSortIcons() {
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.className = 'bi bi-chevron-expand sort-icon';
    });
}

// currentSummonDeviceId defined in devices-state.js

function summonDeviceQuick(deviceId) {
    currentSummonDeviceId = deviceId;
    document.getElementById('summon-device-id').textContent = deviceId;
    const modal = new bootstrap.Modal(document.getElementById('summonModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmSummon() {
    if (!currentSummonDeviceId) return;

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('summonModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: currentSummonDeviceId })
    });

    hideLoading();

    if (result && result.success) {
        showToast('Device summon berhasil!', 'success');
    } else {
        showToast(result.message || 'Gagal summon device', 'danger');
    }

    currentSummonDeviceId = null;
}

// Pagination functions
function updatePaginationUI(total) {
    totalDevices = total;
    const totalPages = itemsPerPage > 0 ? Math.max(1, Math.ceil(total / itemsPerPage)) : 1;
    currentPage = Math.min(Math.max(1, currentPage), totalPages);
    const container = document.getElementById('pagination-container');
    const info = document.getElementById('pagination-info');
    if (info) info.textContent = total ? `Página ${currentPage} de ${totalPages}` : 'Nenhum resultado';
    if (container) {
        container.hidden = totalPages <= 1;
        container.style.setProperty('display', totalPages > 1 ? 'block' : 'none', 'important');
    }
    for (const [id, disabled] of [
        ['pagination-first', currentPage <= 1], ['pagination-prev', currentPage <= 1],
        ['pagination-next', currentPage >= totalPages], ['pagination-last', currentPage >= totalPages]
    ]) {
        const element = document.getElementById(id);
        if (!element) continue;
        element.classList.toggle('disabled', disabled);
        element.setAttribute('aria-disabled', String(disabled));
        const button = element.querySelector('button');
        if (button) button.disabled = disabled;
    }
}

function goToPage(page) {
    const totalPages = itemsPerPage > 0 ? Math.ceil(totalDevices / itemsPerPage) : 1;
    if (!Number.isInteger(page) || page < 1 || page > totalPages || page === currentPage) return;
    currentPage = page;
    renderCurrentDeviceResults();
    document.getElementById('devices-table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function changeItemsPerPage() {
    const value = Number(document.getElementById('items-per-page')?.value);
    if (!Number.isInteger(value) || value < 0) return;
    itemsPerPage = value;
    currentPage = 1;
    renderCurrentDeviceResults();
}

// Toggle Tags column visibility
function toggleTagsColumn() {
    tagsColumnVisible = !tagsColumnVisible;

    const tagColumns = document.querySelectorAll('.tags-column');
    const toggleBtn = document.getElementById('toggle-tags-btn');

    if (tagsColumnVisible) {
        // Show tags column
        tagColumns.forEach(col => {
            col.style.display = '';
        });
        toggleBtn.innerHTML = '<i class="bi bi-tags-fill"></i> Hide Tags';
        toggleBtn.classList.remove('btn-secondary');
        toggleBtn.classList.add('btn-primary');
    } else {
        // Hide tags column
        tagColumns.forEach(col => {
            col.style.display = 'none';
        });
        toggleBtn.innerHTML = '<i class="bi bi-tags"></i> Show Tags';
        toggleBtn.classList.remove('btn-primary');
        toggleBtn.classList.add('btn-secondary');
    }
}

// Bulk operations - Checkbox functions
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const deviceCheckboxes = document.querySelectorAll('.device-checkbox');

    deviceCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });

    updateBulkActionButtons();
}

function updateBulkActionButtons() {
    const selectedCheckboxes = document.querySelectorAll('.device-checkbox:checked');
    const bulkActionButtons = document.getElementById('bulk-action-buttons');
    const selectedCount = document.getElementById('selected-count');

    if (selectedCheckboxes.length > 0) {
        bulkActionButtons.style.display = 'inline-block';
        selectedCount.textContent = `${selectedCheckboxes.length} selected`;
    } else {
        bulkActionButtons.style.display = 'none';
    }

    // Update select-all checkbox state
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const allCheckboxes = document.querySelectorAll('.device-checkbox');
    if (selectAllCheckbox && allCheckboxes.length > 0) {
        selectAllCheckbox.checked = selectedCheckboxes.length === allCheckboxes.length;
    }
}

function getSelectedDeviceIds() {
    const selectedCheckboxes = document.querySelectorAll('.device-checkbox:checked');
    return Array.from(selectedCheckboxes).map(cb => decodeURIComponent(cb.value));
}

// Bulk Add Tag
function showBulkAddTagModal() {
    const selectedIds = getSelectedDeviceIds();
    document.getElementById('add-tag-count').textContent = selectedIds.length;
    document.getElementById('new-tag-name').value = '';

    const modal = new bootstrap.Modal(document.getElementById('bulkAddTagModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmBulkAddTag() {
    const selectedIds = getSelectedDeviceIds();
    const tagName = document.getElementById('new-tag-name').value.trim();

    if (!tagName) {
        showToast('Please enter a tag name', 'warning');
        return;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkAddTagModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'add',
            device_ids: selectedIds,
            tag: tagName
        })
    });

    hideLoading();

    console.log('Bulk Add Tag Response:', result);

    // Show detailed debug info if available
    if (result && result.debug) {
        console.table(result.debug);
    }

    if (result && result.success) {
        showToast(`Tag "${tagName}" added to ${result.success_count || selectedIds.length} device(s)`, 'success');

        if (result.fail_count && result.fail_count > 0) {
            console.warn('Some devices failed:', result.errors);
            console.warn('Debug info for failures:', result.debug);
            showToast(`Warning: ${result.fail_count} device(s) failed`, 'warning');
        }

        loadDevices(); // Reload devices to show updated tags

        // Clear selections
        document.querySelectorAll('.device-checkbox:checked').forEach(cb => cb.checked = false);
        updateBulkActionButtons();
    } else {
        console.error('Add tag failed:', result);
        if (result && result.debug) {
            console.error('Debug details:', result.debug);
        }
        showToast(result?.message || 'Failed to add tags', 'error');
    }
}

// Bulk Untag
function showBulkUntagModal() {
    const selectedIds = getSelectedDeviceIds();
    document.getElementById('untag-count').textContent = selectedIds.length;

    // Clear input field
    const inputField = document.getElementById('remove-tag-name');
    inputField.value = '';

    const modal = new bootstrap.Modal(document.getElementById('bulkUntagModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmBulkUntag() {
    const selectedIds = getSelectedDeviceIds();
    const tagName = document.getElementById('remove-tag-name').value.trim();

    if (!tagName) {
        showToast('Please enter a tag name to remove', 'warning');
        return;
    }

    console.log('Bulk Untag Request:', {
        action: 'remove',
        device_ids: selectedIds,
        tag: tagName
    });

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkUntagModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'remove',
            device_ids: selectedIds,
            tag: tagName
        })
    });

    hideLoading();

    console.log('Bulk Untag Response:', result);

    // Show detailed debug info if available
    if (result && result.debug) {
        console.table(result.debug);
    }

    if (result && result.success) {
        showToast(`Tag "${tagName}" removed from ${result.success_count || selectedIds.length} device(s)`, 'success');

        if (result.fail_count && result.fail_count > 0) {
            console.warn('Some devices failed:', result.errors);
            console.warn('Debug info for failures:', result.debug);
            showToast(`Warning: ${result.fail_count} device(s) failed`, 'warning');
        }

                loadDevices(); // Reload devices to show updated tags

        // Clear selections
        document.querySelectorAll('.device-checkbox:checked').forEach(cb => cb.checked = false);
        updateBulkActionButtons();
    } else {
        console.error('Untag failed:', result);
        if (result && result.debug) {
            console.error('Debug details:', result.debug);
        }
        showToast(result?.message || 'Failed to remove tags', 'error');
    }
}

// Bulk Delete
function showBulkDeleteModal() {
    const selectedIds = getSelectedDeviceIds();
    document.getElementById('delete-count').textContent = selectedIds.length;

    const modal = new bootstrap.Modal(document.getElementById('bulkDeleteModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmBulkDelete() {
    const selectedIds = getSelectedDeviceIds();

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/bulk-delete-devices.php', {
        method: 'POST',
        body: JSON.stringify({
            device_ids: selectedIds
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(`${selectedIds.length} device(s) deleted successfully`, 'success');
                loadDevices(); // Reload devices

        // Clear selections
        document.querySelectorAll('.device-checkbox:checked').forEach(cb => cb.checked = false);
        updateBulkActionButtons();
    } else {
        showToast(result?.message || 'Failed to delete devices', 'error');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    
        if (window.GENIEACS_CONFIGURED) loadDevices(); // Initial load (manual)

        // Start auto-refresh timer
        if (window.GENIEACS_CONFIGURED) autoRefreshTimer = setInterval(() => loadDevices(true), 60000); // Auto-refresh every 60 seconds
    

    // Keyboard shortcuts for pagination (Left/Right arrow keys)
    document.addEventListener('keydown', function(e) {
        // Only work if not typing in input field
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }

        const totalPages = Math.ceil(totalDevices / itemsPerPage);

        if (e.key === 'ArrowLeft' && currentPage > 1) {
            goToPage(currentPage - 1);
        } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
            goToPage(currentPage + 1);
        }
    });
});

// Cleanup: Stop auto-refresh when user navigates away
window.addEventListener('beforeunload', function() {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
});

// Also cleanup on page visibility change (when tab becomes hidden)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        // Page is hidden, stop auto-refresh to save resources
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    } else {
        // Page is visible again, restart auto-refresh
        
        if (!autoRefreshTimer) {
            if (window.GENIEACS_CONFIGURED) autoRefreshTimer = setInterval(() => loadDevices(true), 60000);
        }

    }
});

