(() => {
    'use strict';

    if (window.__JRC_REAL_PAGINATION_V1__) return;
    window.__JRC_REAL_PAGINATION_V1__ = true;

    const original = {
        renderDevices: window.renderDevices,
        renderMapItems: window.renderMapItems,
        generateTableHeader: window.generateTableHeader,
        updateSearchPlaceholder: window.updateSearchPlaceholder,
        resetSortIcons: window.resetSortIcons,
        updateDeviceStats: window.updateDeviceStats,
        filterDevices: window.filterDevices,
        clearSearch: window.clearSearch,
        sortTable: window.sortTable,
    };

    const required = [
        ['renderDevices', original.renderDevices],
        ['renderMapItems', original.renderMapItems],
        ['generateTableHeader', original.generateTableHeader],
        ['updateSearchPlaceholder', original.updateSearchPlaceholder],
    ];

    const missing = required.filter(([, fn]) => typeof fn !== 'function').map(([name]) => name);
    if (missing.length) {
        console.error('[JRC PAGINATION] Funções ausentes:', missing);
        return;
    }

    let mapCounts = null;
    let mapItemsLoaded = false;
    let mapItemsPromise = null;
    let searchDebounce = null;
    let searchGeneration = 0;
    let ixcSerials = [];
    let pageRequest = 0;

    function currentSearchTerm() {
        return (document.getElementById('search-input')?.value || '').trim();
    }

    function setTableLoading(message = 'Carregando equipamentos...') {
        const tbody = document.getElementById('devices-tbody');
        if (!tbody) return;
        tbody.innerHTML =
            '<tr><td colspan="13" class="text-center">' +
            '<div class="spinner"></div>' +
            '<div style="margin-top:10px;">' + message + '</div>' +
            '</td></tr>';
    }

    async function ensureMapCounts() {
        if (mapCounts) return mapCounts;
        try {
            const result = await fetchAPI('/api/get-map-counts.php');
            if (result?.success) mapCounts = result.counts || {};
        } catch (error) {
            console.warn('[JRC PAGINATION] Falha ao carregar contadores do mapa:', error);
        }
        return mapCounts || {};
    }

    async function ensureMapItems() {
        if (mapItemsLoaded) return allMapItems;
        if (mapItemsPromise) return mapItemsPromise;

        mapItemsPromise = (async () => {
            const tbody = document.getElementById('devices-tbody');
            if (tbody) {
                tbody.innerHTML =
                    '<tr><td colspan="6" class="text-center">' +
                    '<div class="spinner"></div>' +
                    '<div style="margin-top:10px;">Carregando infraestrutura...</div>' +
                    '</td></tr>';
            }

            const result = await fetchAPI('/api/map-get-items.php');
            if (result?.success) {
                allMapItems = result.items || [];
                mapItemsLoaded = true;
                return allMapItems;
            }

            throw new Error(result?.message || 'Falha ao carregar infraestrutura');
        })().catch(error => {
            mapItemsPromise = null;
            throw error;
        });

        return mapItemsPromise;
    }

    function updateTabCounts(stats) {
        const total = Number(stats?.total);
        if (Number.isFinite(total)) {
            const onu = document.getElementById('count-onu');
            if (onu) onu.textContent = total;
        }

        const counts = mapCounts || {};
        for (const type of ['odp', 'odc', 'olt', 'server']) {
            const el = document.getElementById('count-' + type);
            if (el && Number.isFinite(Number(counts[type]))) {
                el.textContent = Number(counts[type]);
            }
        }
    }

    function updateStats(stats) {
        const holder = document.getElementById('device-stats-badges');
        if (!holder) return;

        const total = Number(stats?.total);
        const online = Number(stats?.online);
        const offline = Number(stats?.offline);

        if (!Number.isFinite(total)) return;

        holder.innerHTML =
            '<span class="badge bg-secondary">Total [' + total + ']</span> ' +
            (Number.isFinite(online)
                ? '<span class="badge bg-success">Online [' + online + ']</span> '
                : '') +
            (Number.isFinite(offline)
                ? '<span class="badge bg-danger">Offline [' + offline + ']</span>'
                : '');
    }

    async function renderCurrentPage(devices, serverTotal) {
        const realPage = currentPage;
        const realItemsPerPage = itemsPerPage;

        allDevices = Array.isArray(devices) ? devices : [];

        // O render legado aplica paginação client-side. Desabilitamos
        // temporariamente somente durante o desenho da página atual.
        currentPage = 1;
        itemsPerPage = 0;

        try {
            await original.renderDevices(allDevices);
        } finally {
            currentPage = realPage;
            itemsPerPage = realItemsPerPage;
        }

        totalDevices = Number(serverTotal) || 0;
        updatePaginationUI(totalDevices);
        updateDeviceCount(allDevices.length, totalDevices);

        // Mantém a ordenação visual da página atual, sem perder o total do servidor.
        if (currentSortColumn) {
            updateSortIcons(currentSortColumn, currentSortDirection);
        }
    }

    async function loadOnuPage(isAutoRefresh = false) {
        const requestId = ++pageRequest;
        const term = currentSearchTerm();
        const limit = [20, 50, 100].includes(Number(itemsPerPage)) ? Number(itemsPerPage) : 20;

        if (!isAutoRefresh) {
            setTableLoading(term ? 'Pesquisando equipamentos...' : 'Carregando página...');
        }

        const params = new URLSearchParams({
            page: String(Math.max(1, Number(currentPage) || 1)),
            limit: String(limit),
        });

        if (term) params.set('q', term);
        if (ixcSerials.length) params.set('serials', ixcSerials.slice(0, 50).join(','));

        const countsPromise = ensureMapCounts();

        const response = await fetch('/api/get-devices-page.php?' + params.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });

        const data = await response.json();

        if (requestId !== pageRequest) return;
        if (!response.ok || !data?.success) {
            throw new Error(data?.message || 'Falha ao carregar equipamentos.');
        }

        currentPage = Number(data.page) || currentPage || 1;

        await renderCurrentPage(data.devices || [], data.total || 0);

        if (requestId !== pageRequest) return;

        updateStats(data.stats || {});
        await countsPromise;
        updateTabCounts(data.stats || { total: data.total });

        if (!isAutoRefresh && currentSortColumn) {
            // A V1 mantém a ordenação apenas na página atual.
            const sorted = applySorting([...allDevices], currentSortColumn, currentSortDirection);
            await renderCurrentPage(sorted, data.total || 0);
        }

        if (isAutoRefresh && savedScrollPosition) {
            window.scrollTo(0, savedScrollPosition);
        }
    }

    window.loadDevices = async function(isAutoRefresh = false) {
        if (isAutoRefresh) {
            savedScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
        }

        try {
            if (currentFilterType === 'onu') {
                await loadOnuPage(isAutoRefresh);
                return;
            }

            await ensureMapItems();
            original.renderMapItems(currentFilterType);
            if (typeof original.updateDeviceStats === 'function') {
                original.updateDeviceStats([], false);
            }
        } catch (error) {
            console.error('[JRC PAGINATION]', error);
            const tbody = document.getElementById('devices-tbody');
            if (tbody && currentFilterType === 'onu') {
                tbody.innerHTML =
                    '<tr><td colspan="13" class="text-center text-danger">' +
                    'Não foi possível carregar esta página de equipamentos.' +
                    '</td></tr>';
            }
            if (!isAutoRefresh && typeof showToast === 'function') {
                showToast(error.message || 'Falha ao carregar equipamentos.', 'danger');
            }
        }
    };

    window.goToPage = function(page) {
        if (itemsPerPage <= 0) return;

        const totalPages = Math.max(1, Math.ceil(totalDevices / itemsPerPage));
        page = Number(page);

        if (!Number.isFinite(page) || page < 1 || page > totalPages) return;
        if (page === currentPage) return;

        currentPage = page;

        if (currentFilterType === 'onu') {
            window.loadDevices(false);
        } else {
            original.renderMapItems(currentFilterType);
        }

        document.getElementById('devices-table')?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    };

    window.changeItemsPerPage = function() {
        const selector = document.getElementById('items-per-page');
        const value = Number(selector?.value);

        itemsPerPage = [20, 50, 100].includes(value) ? value : 20;
        currentPage = 1;

        if (selector && String(itemsPerPage) !== selector.value) {
            selector.value = String(itemsPerPage);
        }

        if (currentFilterType === 'onu') {
            window.loadDevices(false);
        } else {
            original.renderMapItems(currentFilterType);
        }
    };

    window.filterByType = async function(type) {
        currentFilterType = type;
        currentPage = 1;
        currentSortColumn = null;
        currentSortDirection = 'asc';
        ixcSerials = [];
        ++searchGeneration;

        original.generateTableHeader(type);
        original.updateSearchPlaceholder(type);
        if (typeof original.resetSortIcons === 'function') original.resetSortIcons();

        const search = document.getElementById('search-input');
        if (search) search.value = '';

        if (type === 'onu') {
            await window.loadDevices(false);
            return;
        }

        try {
            await ensureMapItems();
            original.renderMapItems(type);
            if (typeof original.updateDeviceStats === 'function') {
                original.updateDeviceStats([], false);
            }
        } catch (error) {
            console.error('[JRC PAGINATION] Infraestrutura:', error);
            if (typeof showToast === 'function') {
                showToast('Falha ao carregar infraestrutura.', 'danger');
            }
        }
    };

    window.filterDevices = function() {
        if (currentFilterType !== 'onu') {
            if (typeof original.filterDevices === 'function') {
                original.filterDevices();
            }
            return;
        }

        currentPage = 1;
        ixcSerials = [];

        clearTimeout(searchDebounce);
        const generation = ++searchGeneration;
        const term = currentSearchTerm();

        searchDebounce = setTimeout(async () => {
            if (generation !== searchGeneration) return;

            await window.loadDevices(false);

            if (term.length < 3 || generation !== searchGeneration) return;

            try {
                const response = await fetch('/api/search-client-devices.php?q=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                });

                const data = await response.json();
                if (generation !== searchGeneration) return;
                if (currentSearchTerm() !== term || currentFilterType !== 'onu') return;

                const serials = Array.isArray(data?.serials) ? data.serials : [];
                if (!serials.length) return;

                ixcSerials = [...new Set(serials.map(value =>
                    String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '')
                ).filter(Boolean))].slice(0, 50);

                currentPage = 1;
                await window.loadDevices(false);
            } catch (error) {
                console.warn('[JRC PAGINATION] Busca IXC indisponível:', error);
            }
        }, term ? 300 : 0);
    };

    window.clearSearch = function() {
        const input = document.getElementById('search-input');
        if (input) input.value = '';

        ixcSerials = [];
        currentPage = 1;
        ++searchGeneration;
        clearTimeout(searchDebounce);

        if (currentFilterType === 'onu') {
            window.loadDevices(false);
        } else if (typeof original.clearSearch === 'function') {
            original.clearSearch();
        }
    };

    window.sortTable = async function(column) {
        if (currentFilterType !== 'onu') {
            if (typeof original.sortTable === 'function') original.sortTable(column);
            return;
        }

        if (currentSortColumn === column) {
            currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            currentSortColumn = column;
            currentSortDirection = 'asc';
        }

        const sorted = applySorting([...allDevices], currentSortColumn, currentSortDirection);
        await renderCurrentPage(sorted, totalDevices);
        updateSortIcons(currentSortColumn, currentSortDirection);
    };

    // Remove a opção "Todos" caso uma versão antiga de devices.php ainda a contenha.
    document.addEventListener('DOMContentLoaded', () => {
        const selector = document.getElementById('items-per-page');
        if (selector) {
            selector.querySelector('option[value="0"]')?.remove();
            if (![20, 50, 100].includes(Number(selector.value))) selector.value = '20';
        }
    });

    console.info('[JRC PAGINATION] Paginação real de equipamentos habilitada.');
})();