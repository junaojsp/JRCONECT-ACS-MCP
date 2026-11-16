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

    // Keep the existing RX value if the ONU/IXC provided one.
    // Some customized dashboards render the Equipment rows as DIVs rather
    // than a semantic TABLE; both layouts must be supported.
    function replaceNoReadingBadge(root) {
        const elements = [root, ...root.querySelectorAll('span, small, strong, td, div, .badge')];
        // Prefer the innermost matching node, preserving the surrounding row.
        for (let i = elements.length - 1; i >= 0; --i) {
            const node = elements[i];
            const label = normalized(node.textContent);
            if (label.length > 32 || !badSignal.test(label) ||
                /dBm/i.test(label) || node.dataset?.jrcRouterSignal === '1') continue;
            // Do not replace an ancestor when its child already has the label.
            if (Array.from(node.children).some(child =>
                badSignal.test(normalized(child.textContent)))) continue;
            node.textContent = 'Roteador';
            node.title = 'Roteador comum; não possui leitura óptica própria neste ACS';
            node.dataset.jrcRouterSignal = '1';
            return true;
        }
        return false;
    }

    function updateTableBadges() {
        // Semantic tables: map RX column by its heading.
        for (const table of document.querySelectorAll('table')) {
            const heads = Array.from(table.querySelectorAll('thead th'));
            if (!heads.length) continue;
            const rxIndex = heads.findIndex(h =>
                /sinal\s*rx|^rx(?:\s|$)/i.test(normalized(h.textContent)));
            const deviceIndex = heads.findIndex(h =>
                /equipamento|modelo/i.test(normalized(h.textContent)));
            if (rxIndex < 0 || deviceIndex < 0) continue;
            for (const tr of table.querySelectorAll('tbody tr')) {
                const cells = tr.querySelectorAll('td');
                if (cells.length <= Math.max(rxIndex, deviceIndex)) continue;
                if (!isExplicitRouter(normalized(cells[deviceIndex].textContent))) continue;
                replaceNoReadingBadge(cells[rxIndex]);
            }
        }

        // Customized card/grid rows: the deployed dashboard can render its
        // "Equipamentos" list without <table>/<thead>. Find a short explicit
        // model label and its *nearest bounded row* containing "Sem dado".
        // Never search the whole dashboard container for a badge.
        const models = document.querySelectorAll(
            'strong, b, a, td:first-child, [class*="device-name"], [class*="equipment-name"]'
        );
        for (const modelEl of models) {
            const model = normalized(modelEl.textContent);
            if (model.length < 3 || model.length > 90 || !isExplicitRouter(model)) continue;
            let row = modelEl.parentElement;
            for (let depth = 0; depth < 7 && row; depth++, row = row.parentElement) {
                const text = normalized(row.textContent);
                if (text.length > 800) break; // prevent crossing into another equipment row
                if (!badSignal.test(text)) continue;
                if (replaceNoReadingBadge(row)) break;
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
        if (!counts || !Number.isSafeInteger(counts.routers)) return;
        const existing = document.querySelector('[data-jrc-router-summary="1"]');
        if (existing) {
            const value = existing.querySelector('[data-jrc-router-count]');
            const formatted = counts.routers.toLocaleString('pt-BR');
            if (value && value.textContent !== formatted) value.textContent = formatted;
            return;
        }
        const row = getNoReadingRow();
        if (!row || !row.parentElement) return;

        // Clone the COMPLETE styled indicator row. Shallow cloneNode(false)
        // dropped the actual typography/columns and produced overlapping text.
        const copy = row.cloneNode(true);
        copy.dataset.jrcRouterSummary = '1';
        copy.title = 'Quantidade de roteadores identificados no inventário ACS; não subtrai o indicador óptico Sem leitura';
        copy.removeAttribute('id');
        copy.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));

        const leafNodes = Array.from(copy.querySelectorAll('*'))
            .filter(el => el.children.length === 0);
        const label = leafNodes.find(el =>
            normalized(el.textContent).toLowerCase() === 'sem leitura');
        const number = leafNodes.find(el =>
            /^(?:\d[\d.,]*|[-–])$/.test(normalized(el.textContent)));

        // Do not insert a malformed row if the original's structure differs.
        if (!label || !number) return;
        label.textContent = 'Roteadores';
        number.textContent = counts.routers.toLocaleString('pt-BR');
        number.dataset.jrcRouterCount = '1';
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
