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

    // A missing RX is only a symptom, not enough to infer equipment type.
    // Match the read-only backend classifier: optical evidence wins if the
    // product offers router functions and an ONT/ONU in the same device.
    function equipmentClass(model) {
        if (opticalModel.test(model)) return 'onu';
        if (routerModel.test(model)) return 'roteador';
        return 'desconhecido';
    }

    const missingLabels = {
        roteador: {
            text: 'Roteador',
            tip: 'Roteador comum: sinal óptico não se aplica ao próprio equipamento'
        },
        onu: {
            text: 'ONU sem leitura',
            tip: 'ONU óptica identificada, mas sem medição de potência RX disponível'
        },
        desconhecido: {
            text: 'Tipo a confirmar',
            tip: 'Sem potência RX e sem identificação segura como roteador ou ONU'
        }
    };

    function replaceNoReadingBadge(root, deviceClass) {
        const item = missingLabels[deviceClass];
        if (!item || !root) return false;
        const elements = [root, ...root.querySelectorAll('span, small, strong, td, div, .badge')];
        for (let i = elements.length - 1; i >= 0; --i) {
            const node = elements[i];
            const label = normalized(node.textContent);
            if (label.length > 32 || !badSignal.test(label) ||
                /dBm/i.test(label) || node.dataset?.jrcRouterSignal === '1') continue;
            if (Array.from(node.children).some(child =>
                badSignal.test(normalized(child.textContent)))) continue;
            node.textContent = item.text;
            node.title = item.tip;
            node.dataset.jrcRouterSignal = '1';
            node.dataset.jrcEquipmentType = deviceClass;
            return true;
        }
        return false;
    }

    function signalRootForRow(row) {
        // Prefer a node identifying the RX/signal field. Never replace
        // a missing TEMPERATURE measurement as though it were optical RX.
        const fields = Array.from(row.querySelectorAll(
            '[class*="rx"], [class*="signal"], [data-signal-rx]'
        ));
        for (const field of fields) {
            if (field === row) continue;
            if (badSignal.test(normalized(field.textContent))) return field;
        }

        // If any genuine RX in dBm exists on the row, the remaining missing
        // values are other telemetry fields: leave them untouched.
        if (/[-+]?\d+(?:[.,]\d+)?\s*dBm/i.test(row.textContent)) return null;

        // Fallback only when EXACTLY ONE missing field is visible, avoiding
        // accidental edits to unrelated missing temperature/SSID data.
        const leaves = Array.from(row.querySelectorAll('span, small, strong, td, div'))
            .filter(node => node.children.length === 0 &&
                badSignal.test(normalized(node.textContent)));
        return leaves.length === 1 ? leaves[0] : null;
    }

    function updateTableBadges() {
        // Semantic tables: use the actual "Sinal RX" column, never other
        // columns (temperature, Wi-Fi etc.) that may also say "Sem dado".
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
                const model = normalized(cells[deviceIndex].textContent);
                if (!model || /^(?:n\/a|n\/d|--)$/i.test(model)) continue;
                replaceNoReadingBadge(cells[rxIndex], equipmentClass(model));
            }
        }

        // Customized DIV grids: use a bounded single-equipment row.
        const models = document.querySelectorAll(
            'strong, b, a, td:first-child, [class*="device-name"], [class*="equipment-name"]'
        );
        for (const modelEl of models) {
            const model = normalized(modelEl.textContent);
            if (model.length < 3 || model.length > 90) continue;
            const deviceClass = equipmentClass(model);
            const parentClass = String(modelEl.parentElement?.className || '').toLowerCase();
            const ownClass = String(modelEl.className || '').toLowerCase();
            // For an unknown model, require an explicit device-name container
            // so a customer name or unrelated bold text is never misclassified.
            if (deviceClass === 'desconhecido' &&
                !/(?:device-name|equipment-name)/.test(parentClass + ' ' + ownClass)) {
                continue;
            }

            let row = modelEl.parentElement;
            for (let depth = 0; depth < 7 && row; depth++, row = row.parentElement) {
                const text = normalized(row.textContent);
                if (text.length > 800) break;
                if (Array.from(row.querySelectorAll('*')).some(node =>
                    node.dataset?.jrcRouterSignal === '1')) break;

                const deviceTitles = Array.from(row.querySelectorAll('strong, b'))
                    .map(node => normalized(node.textContent))
                    .filter(name => name.length < 90 &&
                        (routerModel.test(name) || opticalModel.test(name)));
                if (deviceTitles.length > 1) break;

                const css = String(row.className || '').toLowerCase()
                    .replace(/[-_]/g, ' ');
                const isRow = /(?:^|\s)(?:row|item|entry|record)(?:\s|$)/.test(css) &&
                    !/(?:container|grid|wrapper|list)/.test(css);
                const signalField = signalRootForRow(row);
                if (signalField && replaceNoReadingBadge(signalField, deviceClass)) break;
                if (isRow) break;
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

        // The row was cloned for styling, not behavior. The original
        // optical "Sem leitura" click handler is NOT appropriate here:
        // routers are classified using the GenieACS inventory snapshot.
        // Give it an explicit, independent report destination.
        copy.querySelectorAll('[onclick], a[href]').forEach(el => {
            el.removeAttribute('onclick');
            el.removeAttribute('href');
        });
        copy.removeAttribute('onclick');
        copy.style.cursor = 'pointer';
        copy.setAttribute('role', 'link');
        copy.setAttribute('tabindex', '0');
        copy.setAttribute('aria-label', 'Abrir relatório de roteadores');
        function openRouters(event) {
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
            window.location.assign('/router-report.php');
        }
        copy.addEventListener('click', openRouters, true);
        copy.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') openRouters(event);
        }, true);

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
