/*
 * JR Conect ACS - optical signal display refinement (opt-in).
 * Does not replace IXC/TR-069 APIs or change existing optical counts.
 * Marks explicit non-optical router models in the current equipment table.
 * Adds an independent "Roteador (ACS)" line with inventory-snapshot scope.
 */
(() => {
    'use strict';

    const badSignal = /(?:sem\s+dad[oa]s?|sem\s+leitura|^(?:n\/d|n\/a|--?|—)$)/i;
    const routerModel = /(?:^|[^a-z0-9])(?:router|roteador|routerboard)(?:[^a-z0-9]|$)/i;
    const opticalModel = /(?:^|[^a-z0-9])(?:onu|ont|gpon|epon|xgpon|xgspon|ftth|optical|fiberhome|hg[68]\d{3}[a-z0-9]*|eg8\d{3}[a-z0-9]*|dpn-[a-z0-9-]+)(?:[^a-z0-9]|$)/i;

    function normalized(s) {
        return String(s || '').replace(/\s+/g, ' ').trim();
    }

    // Product/type evidence only; missing RX alone does not mean "router".
    function isExplicitRouter(model) {
        return routerModel.test(model) && !opticalModel.test(model);
    }

    function updateTableBadges() {
        for (const table of document.querySelectorAll('table')) {
            const heads = Array.from(table.querySelectorAll('thead th'));
            if (!heads.length) continue;
            const rxIndex = heads.findIndex(h => /sinal\s*rx|^rx(?:\s|$)/i.test(normalized(h.textContent)));
            const deviceIndex = heads.findIndex(h => /equipamento|modelo/i.test(normalized(h.textContent)));
            if (rxIndex < 0 || deviceIndex < 0) continue;

            for (const tr of table.querySelectorAll('tbody tr')) {
                const cells = tr.querySelectorAll('td');
                if (cells.length <= Math.max(rxIndex, deviceIndex)) continue;
                const model = normalized(cells[deviceIndex].textContent);
                if (!isExplicitRouter(model)) continue;
                const rxCell = cells[rxIndex];
                if (rxCell.dataset.jrcRouterSignal === '1') continue;
                const current = normalized(rxCell.textContent);
                if (!badSignal.test(current)) continue; // never override real RX.
                const badge = rxCell.querySelector('span, .badge') || rxCell;
                badge.textContent = 'Roteador';
                badge.title = 'Roteador sem interface óptica identificada: RX não se aplica neste dispositivo';
                rxCell.dataset.jrcRouterSignal = '1';
            }
        }
    }

    function findLeafLabel(label) {
        return Array.from(document.querySelectorAll('span, strong, small, div, p, li'))
            .find(el => el.children.length === 0 &&
                normalized(el.textContent).toLocaleLowerCase('pt-BR') === label.toLocaleLowerCase('pt-BR')) || null;
    }

    function getNoReadingRow() {
        const label = findLeafLabel('Sem leitura');
        if (!label) return null;

        // Only modify within the "Sinal da frota" card, never global alerts.
        let ancestor = label.parentElement;
        let section = null;
        for (let i = 0; i < 7 && ancestor; i++, ancestor = ancestor.parentElement) {
            const text = normalized(ancestor.textContent).toLowerCase();
            if (text.includes('sinal da frota') && text.includes('sem leitura')) {
                section = ancestor;
                break;
            }
        }
        if (!section) return null;

        let row = label.parentElement;
        for (let i = 0; i < 4 && row && row !== section; i++, row = row.parentElement) {
            const text = normalized(row.textContent).toLowerCase();
            if (text.includes('sem leitura') &&
                !text.includes('sinal crítico') &&
                !text.includes('sinal fraco') &&
                !text.includes('sinal da frota') &&
                /\d/.test(text)) {
                return row;
            }
        }
        return null;
    }

    let counts = null;
    function updateSidebar() {
        if (!counts || !Number.isFinite(counts.routers)) return;
        const existing = document.querySelector('[data-jrc-router-summary="1"]');
        if (existing) {
            const value = existing.querySelector('[data-jrc-router-count]');
            if (value && value.textContent !== counts.routers.toLocaleString('pt-BR')) {
                value.textContent = counts.routers.toLocaleString('pt-BR');
            }
            return;
        }
        const row = getNoReadingRow();
        if (!row || !row.parentElement) return;
        const copy = row.cloneNode(false);
        copy.removeAttribute('id');
        copy.dataset.jrcRouterSummary = '1';
        copy.title = 'Roteadores classificados no inventário do GenieACS. O contador óptico "Sem leitura" usa sua fonte atual e não foi alterado.';
        copy.style.display = 'flex';
        copy.style.alignItems = 'center';
        copy.style.justifyContent = 'space-between';

        const label = document.createElement('span');
        label.textContent = 'Roteador (ACS)';
        const value = document.createElement('strong');
        value.dataset.jrcRouterCount = '1';
        value.textContent = counts.routers.toLocaleString('pt-BR');
        copy.append(label, value);
        row.insertAdjacentElement('afterend', copy);
    }

    let queued = false;
    function refreshDecorations() {
        if (queued) return;
        queued = true;
        window.requestAnimationFrame(() => {
            queued = false;
            updateTableBadges();
            updateSidebar();
        });
    }

    async function loadRouterSummary() {
        try {
            const response = await fetch('/api/jrc-router-signal-summary.php', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data.success || data.scope !== 'genieacs' || data.cache_stale ||
                !Number.isSafeInteger(Number(data.routers)) ||
                Number(data.routers) < 0) return;
            counts = { routers: Number(data.routers) };
            refreshDecorations();
        } catch (_) {
            // The main dashboard and existing signal counters must keep working.
        }
    }

    function start() {
        refreshDecorations();
        const observer = new MutationObserver(refreshDecorations);
        // Device rows are asynchronously replaced, so decorate after rendering.
        observer.observe(document.body, { childList: true, subtree: true });
        loadRouterSummary();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
