/* JRC_ALERTAS_V1 — lista de alertas da frota, no formato da referência. */
/* JRC_ALERTAS_CLICK_V1 — o card de avisos, críticos, info e reconhecidos filtra a lista. */
/* JRC_ALERTAS_PAGE_V1 — a lista pagina de 10 em 10. */
(function () {
    'use strict';
    if (window.__jrcAlertas) return;
    window.__jrcAlertas = true;
    if (!/\/alerts\.php$/.test(location.pathname)) return;

    var IDEAL = -25;
    var CRITICAL_RX = -27;
    var PAGE_SIZE = 10;
    var filter = 'abertos';
    var page = 1;
    var devices = [];
    var ixc = {};
    var acks = {};
    var phase = 'load';
    var error = '';
    var ackingAll = false;
    var signalReady = false;
    var DAY = 60 * 86400;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function request(url, body) {
        if (typeof window.fetchAPI === 'function') {
            return window.fetchAPI(url, body ? { method: 'POST', body: JSON.stringify(body) } : {});
        }
        return fetch(url, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json(); });
    }

    function normSerial(value) {
        return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function stampSeconds(raw) {
        if (raw == null || raw === '' || raw === 'N/A' || raw === 'N/D') return 0;
        if (typeof raw === 'number' && isFinite(raw)) {
            var numericStamp = raw > 1e12 ? raw / 1000 : raw;
            return numericStamp >= 946684800 ? numericStamp : 0;
        }
        var text = String(raw).trim();
        if (/^0{4}/.test(text) || /^00\/00\//.test(text)) return 0;
        if (/^\d+$/.test(text)) {
            var numeric = Number(text);
            numeric = numeric > 1e12 ? numeric / 1000 : numeric;
            return numeric >= 946684800 ? numeric : 0;
        }
        var br = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);
        if (br) {
            if (+br[3] < 2000) return 0;
            var brSeconds = new Date(+br[3], +br[2] - 1, +br[1], +(br[4] || 0), +(br[5] || 0), +(br[6] || 0)).getTime() / 1000;
            return brSeconds >= 946684800 ? brSeconds : 0;
        }
        var parsed = Date.parse(text.replace(' ', 'T'));
        var seconds = isNaN(parsed) ? 0 : parsed / 1000;
        return seconds >= 946684800 ? seconds : 0;
    }

    function ageFrom(diff) {
        if (!diff) return null;
        var days = Math.floor(diff / 86400);
        if (days >= 1) return { diff: diff, label: days + ' d' };
        var hours = Math.floor(diff / 3600);
        if (hours >= 1) return { diff: diff, label: hours + ' h' };
        return { diff: diff, label: Math.max(1, Math.floor(diff / 60)) + ' min' };
    }

    function seenInfo(device) {
        var ts = stampSeconds(device && device.last_inform_timestamp) || stampSeconds(device && device.last_inform);
        if (!ts) return null;
        return ageFrom(Math.max(0, Date.now() / 1000 - ts));
    }

    function ixcRecord(device) {
        var serial = String((device && device.serial_number) || '');
        var extra = ixc[serial] || ixc[serial.toUpperCase()] || ixc[serial.toLowerCase()] || null;
        if (!extra) {
            var key = normSerial(serial);
            if (key) extra = ixc[key] || null;
        }
        if (!extra && serial) {
            var names = Object.keys(ixc);
            var want = normSerial(serial);
            for (var i = 0; i < names.length; i++) {
                if (normSerial(names[i]) === want) {
                    extra = ixc[names[i]];
                    break;
                }
            }
        }
        return extra;
    }

    function loginState(extra) {
        return String(extra && extra.online != null ? extra.online : '').trim().toLowerCase();
    }

    function loginUp(extra) {
        var online = loginState(extra);
        return online === 's' || online === 'sim' || online === '1' || online === 'true' || online === 'online';
    }

    function loginKnownDown(extra) {
        var online = loginState(extra);
        return online === 'n' || online === 'nao' || online === 'não' || online === 'ss' || online === '0' || online === 'false' || online === 'offline';
    }

    function ageAt(seconds) {
        if (!seconds) return null;
        return ageFrom(Math.max(0, Date.now() / 1000 - seconds));
    }

    function disconnectAge(device) {
        var extra = ixcRecord(device);
        if (!extra || !extra.found || !loginKnownDown(extra)) return null;
        var end = stampSeconds(extra.disconnected_since) || stampSeconds(extra.last_connection_end);
        if (!end) return { diff: DAY, label: 'sem data', undated: true };
        var ended = ageAt(end);
        if (ended && ended.diff >= DAY) return ended;
        return null;
    }

    function equipName(device) {
        var parts = [device.manufacturer, device.product_class].filter(function (part) {
            var text = String(part || '').trim();
            return text && text !== 'N/A' && text !== 'N/D';
        });
        return parts.join(' ') || 'Equipamento';
    }

    function rxOf(device) {
        var extra = ixcRecord(device) || {};
        var raw = (extra.rx_power !== undefined && extra.rx_power !== null && extra.rx_power !== '')
            ? extra.rx_power
            : device.rx_power;
        var value = parseFloat(raw);
        if (!isFinite(value) || value === -999) return null;
        return value;
    }

    function deviceId(device) {
        var id = String(device.device_id || device.serial_number || '').trim();
        return id && id !== 'N/A' ? id : '';
    }

    function currentAlerts() {
        var list = [];
        var seen = {};
        devices.forEach(function (device) {
            var id = deviceId(device);
            if (!id || seen[id]) return;
            seen[id] = true;
            var online = String(device.status || '').toLowerCase() === 'online';
            var name = equipName(device);
            var when = seenInfo(device);
            var serial = String(device.serial_number || '').trim();
            if (!serial || serial === 'N/A') serial = '';
            var stoppedAge = disconnectAge(device);
            var stopped = !!stoppedAge;
            if (!online || stopped) {
                var age = stopped ? stoppedAge : when;
                var text = !age
                    ? ' não reporta.'
                    : (stopped ? (age.undated ? ' está com o login desconectado, sem data de queda no IXC.' : ' está com o login desconectado há ' + age.label + '.') : ' não reporta há ' + age.label + '.');
                list.push({
                    id: 'offline:' + id,
                    device_id: id,
                    serial: serial,
                    level: 'critico',
                    title: stopped ? 'ONU offline há mais de 60 dias' : 'Sem informe TR-069',
                    text: name + text,
                    age: age ? age.label : '',
                    diff: age ? age.diff : 0,
                    stopped: stopped
                });
                return;
            }
            var rx = rxOf(device);
            if (rx == null || rx > IDEAL) return;
            var critical = rx <= CRITICAL_RX;
            list.push({
                id: 'rx:' + id,
                device_id: id,
                serial: serial,
                level: critical ? 'critico' : 'aviso',
                title: critical ? 'Sinal óptico crítico' : 'Sinal óptico fraco',
                text: name + ' está com RX em ' + rx.toFixed(2) + ' dBm — abaixo do ideal (' + IDEAL.toFixed(0) + ' dBm).',
                age: when ? when.label : '',
                diff: when ? when.diff : 0
            });
        });
        list.sort(function (a, b) {
            if (a.level !== b.level) return a.level === 'critico' ? -1 : 1;
            return b.diff - a.diff;
        });
        return list;
    }

    function resolvedAlerts(current) {
        var open = {};
        current.forEach(function (item) { open[item.id] = true; });
        return Object.keys(acks).filter(function (id) { return !open[id]; }).map(function (id) {
            var saved = acks[id] || {};
            return {
                id: id,
                device_id: saved.device_id || '',
                serial: saved.serial || '',
                level: saved.level || 'info',
                title: saved.title || 'Alerta resolvido',
                text: saved.text || '',
                age: '',
                diff: 0,
                resolved: true,
                ack: saved
            };
        });
    }

    function decorate(item) {
        item.ack = acks[item.id] || null;
        return item;
    }

    function visible(current, resolved) {
        var open = current.map(decorate);
        if (filter === 'ativos') return open.filter(function (item) { return !item.ack; });
        if (filter === 'reconhecidos') return open.filter(function (item) { return item.ack; });
        if (filter === 'resolvidos') return resolved;
        if (filter === 'todos') return open.concat(resolved);
        if (filter === 'parado') return open.filter(function (item) { return item.stopped; });
        if (filter === 'critico' || filter === 'aviso' || filter === 'info') {
            return open.filter(function (item) { return item.level === filter; });
        }
        return open;
    }

    function pendingVisible() {
        return visible(currentAlerts(), resolvedAlerts(currentAlerts())).filter(function (item) {
            return !item.resolved && !item.ack && !acks[item.id];
        });
    }

    function countLevel(list, level) {
        return list.filter(function (item) { return item.level === level; }).length;
    }

    function pill(level) {
        if (level === 'critico') return '<em class="jrc-al-pill is-bad">Crítico</em>';
        if (level === 'aviso') return '<em class="jrc-al-pill is-warn">Aviso</em>';
        return '<em class="jrc-al-pill is-info">Info</em>';
    }

    function rowHtml(item) {
        var href = item.device_id ? '/device-detail.php?id=' + encodeURIComponent(item.device_id) : '';
        var title = href ? '<a href="' + href + '">' + esc(item.title) + '</a>' : esc(item.title);
        var ack = item.ack;
        var extra = '';
        if (item.resolved) extra = ' <em class="jrc-al-pill is-ack">Resolvido</em>';
        else if (ack) extra = ' <em class="jrc-al-pill is-ack">Reconhecido</em>';
        var meta = [item.serial, item.age].filter(Boolean).join(' · ');
        if (ack && ack.user) meta = (meta ? meta + ' · ' : '') + 'por ' + ack.user;
        var tone = item.level === 'critico' ? 'is-bad' : (item.level === 'aviso' ? 'is-warn' : 'is-info');
        var icon = item.level === 'critico' ? 'bi-x-circle' : (item.level === 'aviso' ? 'bi-exclamation-triangle' : 'bi-bell');
        var action = item.resolved || ack
            ? ''
            : '<button type="button" class="jrc-al-ack" data-ack="' + esc(item.id) + '">Reconhecer</button>';
        return '<article class="jrc-al-row ' + tone + '">' +
            '<span class="mark"><i class="bi ' + icon + '"></i></span>' +
            '<div><h2>' + title + ' ' + pill(item.level) + extra + '</h2>' +
            '<p>' + esc(item.text) + '</p>' +
            (meta ? '<small>' + esc(meta) + '</small>' : '') +
            '</div>' + action + '</article>';
    }

    function paintBadge(current) {
        var link = document.querySelector('#sidebar a[href="/alerts.php"], .fb-nav a[href="/alerts.php"]');
        if (!link) return;
        var count = current.filter(function (item) { return !acks[item.id]; }).length;
        var badge = link.querySelector('.jrc-al-count');
        if (!count) {
            if (badge) badge.remove();
            return;
        }
        if (!badge) {
            badge = document.createElement('em');
            badge.className = 'jrc-al-count';
            link.appendChild(badge);
        }
        badge.textContent = String(count);
    }

    function render() {
        var root = document.getElementById('jrc-alertas');
        if (!root) return;
        var current = currentAlerts();
        var resolved = resolvedAlerts(current);
        var rows = visible(current, resolved);
        var crit = countLevel(current, 'critico');
        var warn = countLevel(current, 'aviso');
        var info = countLevel(current, 'info');
        var acked = current.filter(function (item) { return acks[item.id]; }).length;
        var filters = [
            ['abertos', 'Abertos'],
            ['ativos', 'Ativos'],
            ['reconhecidos', 'Reconhecidos'],
            ['resolvidos', 'Resolvidos'],
            ['todos', 'Todos']
        ];
        var pending = pendingVisible();
        var bulk = '';
        if (pending.length || ackingAll) {
            bulk = '<button type="button" class="jrc-al-ackall" data-ack-all' + (ackingAll || !pending.length ? ' disabled' : '') + '>' + (ackingAll ? 'Reconhecendo…' : ('Reconhecer estes (' + pending.length + ')')) + '</button>';
        }
        var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE) || 1);
        if (page > pages) page = pages;
        if (page < 1) page = 1;
        var start = (page - 1) * PAGE_SIZE;
        var slice = rows.slice(start, start + PAGE_SIZE);
        var body;
        if (error && !devices.length) body = '<p class="jrc-al-empty is-err">' + esc(error) + '</p>';
        else if (phase === 'load' && !devices.length) body = '<p class="jrc-al-wait">Lendo os equipamentos...</p>';
        else if (filter === 'parado' && !signalReady && !rows.length) body = '<p class="jrc-al-wait">Conferindo os logins desconectados no IXC…</p>';
        else if (!rows.length) body = '<p class="jrc-al-empty">Nenhum alerta neste filtro.</p>';
        else body = '<div class="jrc-al-list">' + slice.map(rowHtml).join('') + '</div>' + pagerHtml(rows.length, pages);
        root.innerHTML =
            '<section class="jrc-al">' +
            '<h1>Alertas & eventos</h1>' +
            '<p class="jrc-al-sub">Sinal óptico, disponibilidade e saúde da frota — em tempo real.</p>' +
            '<div class="jrc-al-stats">' +
            stat('is-bad', 'bi-x-circle', crit, 'Críticos ativos', 'critico') +
            stat('is-warn', 'bi-exclamation-triangle', warn, 'Avisos ativos', 'aviso') +
            stat('is-info', 'bi-bell', info, 'Info ativos', 'info') +
            stat('is-ok', 'bi-check-circle', acked, 'Reconhecidos', 'reconhecidos') +
            '</div>' +
            '<div class="jrc-al-filters">' + filters.map(function (item) {
                return '<button type="button" data-filter="' + item[0] + '"' + (filter === item[0] ? ' class="is-on"' : '') + '>' + item[1] + '</button>';
            }).join('') + '<button type="button" data-filter="parado"' + (filter === 'parado' ? ' class="is-on"' : '') + '>Offline +60 dias <strong>' + (function () {
                var stoppedCount = current.filter(function (item) { return item.stopped; }).length;
                return (!signalReady && !stoppedCount) ? '…' : String(stoppedCount);
            })() + '</strong></button>' + bulk + '</div>' +
            body +
            '</section>';
        paintBadge(current);
    }

    function pagerHtml(total, pages) {
        if (total <= PAGE_SIZE) return '';
        var from = (page - 1) * PAGE_SIZE + 1;
        var to = Math.min(total, page * PAGE_SIZE);
        var windowStart = Math.max(1, page - 2);
        var windowEnd = Math.min(pages, windowStart + 4);
        windowStart = Math.max(1, windowEnd - 4);
        var html = '<nav class="jrc-al-pager">';
        html += '<button type="button" data-page="prev"' + (page === 1 ? ' disabled' : '') + '>Anterior</button>';
        if (windowStart > 1) {
            html += '<button type="button" data-page="1"' + (page === 1 ? ' class="is-on"' : '') + '>1</button>';
            if (windowStart > 2) html += '<span class="jrc-al-gap">…</span>';
        }
        for (var n = windowStart; n <= windowEnd; n++) {
            html += '<button type="button" data-page="' + n + '"' + (n === page ? ' class="is-on"' : '') + '>' + n + '</button>';
        }
        if (windowEnd < pages) {
            if (windowEnd < pages - 1) html += '<span class="jrc-al-gap">…</span>';
            html += '<button type="button" data-page="' + pages + '"' + (page === pages ? ' class="is-on"' : '') + '>' + pages + '</button>';
        }
        html += '<button type="button" data-page="next"' + (page === pages ? ' disabled' : '') + '>Próxima</button>';
        html += '<span class="jrc-al-range">' + from + '–' + to + ' de ' + total + '</span></nav>';
        return html;
    }

    function stat(tone, icon, value, label, key) {
        var shown = phase === 'load' && !devices.length ? '—' : String(value);
        var on = filter === key ? ' is-on' : '';
        return '<button type="button" class="jrc-al-stat ' + tone + on + '" data-filter="' + key + '"><span class="jrc-al-ico"><i class="bi ' + icon + '"></i></span><span class="jrc-al-stat-copy"><strong>' + esc(shown) + '</strong><span>' + esc(label) + '</span></span></button>';
    }

    function remember(item) {
        var button = document.querySelector('[data-ack="' + CSS.escape(item.id) + '"]');
        if (button) button.disabled = true;
        return request('/api/jrc-alertas.php', {
            action: 'ack',
            id: item.id,
            title: item.title,
            text: item.text,
            level: item.level,
            device_id: item.device_id,
            serial: item.serial
        }).then(function (res) {
            if (!res || res.success === false) throw new Error((res && res.message) || 'Não foi possível reconhecer o alerta.');
            acks[item.id] = res.ack || { user: 'você', at: Math.floor(Date.now() / 1000) };
            rememberCache();
            render();
        }).catch(function (err) {
            if (button) button.disabled = false;
            if (typeof window.showToast === 'function') window.showToast(err.message || 'Falha ao reconhecer.', 'danger');
        });
    }

    function rememberCache() {
        try {
            var all = JSON.parse(sessionStorage.getItem('jrc-get-v1') || '{}');
            if (!all || typeof all !== 'object') all = {};
            all['/api/jrc-alertas.php?action=list'] = { at: Date.now(), data: { success: true, acks: acks } };
            sessionStorage.setItem('jrc-get-v1', JSON.stringify(all));
        } catch (e) {}
    }

    function rememberMany(items) {
        if (ackingAll || !items.length) return;
        if (typeof window.confirm === 'function' && !window.confirm('Reconhecer ' + items.length + ' alertas de uma vez?')) return;
        ackingAll = true;
        render();
        request('/api/jrc-alertas.php', {
            action: 'ack-many',
            items: items.map(function (item) {
                return {
                    id: item.id,
                    title: item.title,
                    text: item.text,
                    level: item.level,
                    device_id: item.device_id,
                    serial: item.serial
                };
            })
        }).then(function (res) {
            ackingAll = false;
            if (!res || res.success === false) throw new Error((res && res.message) || 'Não foi possível reconhecer os alertas.');
            var extra = res.acks || {};
            Object.keys(extra).forEach(function (id) { acks[id] = extra[id]; });
            rememberCache();
            render();
        }).catch(function (err) {
            ackingAll = false;
            render();
            if (typeof window.showToast === 'function') window.showToast(err.message || 'Falha ao reconhecer.', 'danger');
        });
    }

    function bind() {
        var root = document.getElementById('jrc-alertas');
        if (!root || root.dataset.bound === '1') return;
        root.dataset.bound = '1';
        root.addEventListener('click', function (event) {
            var pageButton = event.target.closest('[data-page]');
            if (pageButton) {
                if (pageButton.disabled) return;
                var raw = pageButton.getAttribute('data-page');
                if (raw === 'prev') page -= 1;
                else if (raw === 'next') page += 1;
                else page = parseInt(raw, 10) || 1;
                render();
                return;
            }
            var filterButton = event.target.closest('[data-filter]');
            if (filterButton) {
                var next = filterButton.getAttribute('data-filter') || 'abertos';
                filter = filter === next ? 'abertos' : next;
                page = 1;
                render();
                return;
            }
            var bulkButton = event.target.closest('[data-ack-all]');
            if (bulkButton) {
                if (!bulkButton.disabled) rememberMany(pendingVisible());
                return;
            }
            var ackButton = event.target.closest('[data-ack]');
            if (!ackButton) return;
            var id = ackButton.getAttribute('data-ack');
            var item = currentAlerts().filter(function (row) { return row.id === id; })[0];
            if (item) remember(item);
        });
    }

    function mount() {
        document.documentElement.classList.add('jrc-alertas-page');
        var wrap = document.querySelector('.content-wrapper');
        if (!wrap) return false;
        if (!document.getElementById('jrc-alertas')) {
            wrap.innerHTML = '<div id="jrc-alertas"></div>';
        }
        bind();
        render();
        return true;
    }

    function loadAcks() {
        return request('/api/jrc-alertas.php?action=list').then(function (res) {
            acks = (res && res.acks) || {};
        }).catch(function () { acks = {}; });
    }

    function loadDevices() {
        var skip = 0;
        var guard = 0;
        function page() {
            return request('/api/get-devices.php?limit=100&skip=' + skip).then(function (res) {
                if (!res || res.success === false) throw new Error((res && (res.message || res.error)) || 'Não foi possível ler os equipamentos.');
                var chunk = Array.isArray(res.devices) ? res.devices : [];
                devices = devices.concat(chunk);
                phase = 'partial';
                render();
                var more = res.hasMore === true || (chunk.length === 100 && res.hasMore !== false && guard < 40);
                if (!more || !chunk.length) return;
                skip += chunk.length;
                guard += 1;
                return page();
            });
        }
        return page();
    }

    function absorbIxc(map) {
        Object.keys(map || {}).forEach(function (key) {
            var value = map[key];
            if (!value) return;
            ixc[key] = value;
            var normal = normSerial(key);
            if (normal) ixc[normal] = value;
        });
    }

    function recallIxc() {
        try {
            var all = JSON.parse(sessionStorage.getItem('jrc-ixc-v1') || '{}');
            var items = (all && all.items) || {};
            Object.keys(items).forEach(function (key) {
                var hit = items[key];
                if (!hit || !hit.value || !hit.at || Date.now() - hit.at >= 300000) return;
                absorbIxc((function () { var row = {}; row[key] = hit.value; return row; })());
            });
        } catch (e) {}
    }

    function rememberIxc(map) {
        try {
            var all = JSON.parse(sessionStorage.getItem('jrc-ixc-v1') || '{}');
            if (!all || typeof all !== 'object') all = {};
            if (!all.items) all.items = {};
            Object.keys(map || {}).forEach(function (key) {
                var normal = normSerial(key);
                if (!normal || !map[key]) return;
                all.items[normal] = { at: Date.now(), value: map[key] };
            });
            sessionStorage.setItem('jrc-ixc-v1', JSON.stringify(all));
        } catch (e) {}
    }

    function postJson(url, body) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, 60000) : 0;
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body),
            signal: controller ? controller.signal : undefined
        }).then(function (response) { return response.text(); }).then(function (text) {
            if (timer) clearTimeout(timer);
            try { return JSON.parse(text); }
            catch (e) { return { success: false }; }
        }, function () {
            if (timer) clearTimeout(timer);
            return { success: false };
        });
    }

    function ixcFresh(serial) {
        var extra = ixc[serial] || ixc[normSerial(serial)];
        return !!(extra && Object.prototype.hasOwnProperty.call(extra, 'last_connection_end'));
    }

    function loadSignal() {
    /* JRC_ALERTS_PROGRESSIVE_IXC_V1 */
    recallIxc();

    // Libera a tela imediatamente com os dados locais/TR-069.
    signalReady = true;
    render();

    var generation = (window.__jrcAlertsIxcGeneration || 0) + 1;
    window.__jrcAlertsIxcGeneration = generation;

    var serials = [];
    var seen = {};

    devices.forEach(function (device) {
        var serial = String(device.serial_number || '').trim();
        var key = normSerial(serial);

        if (!key || seen[key] || ixcFresh(serial)) return;

        seen[key] = 1;
        serials.push(serial);
    });

    if (!serials.length) {
        return Promise.resolve();
    }

    // Menos carga inicial: lotes menores e sequenciais.
    var batches = [];
    for (var offset = 0; offset < serials.length; offset += 50) {
        batches.push(serials.slice(offset, offset + 50));
    }

    var cursor = 0;
    var renderTimer = null;

    function scheduleRender() {
        if (renderTimer) clearTimeout(renderTimer);
        renderTimer = setTimeout(function () {
            if (generation !== window.__jrcAlertsIxcGeneration) return;
            render();
        }, 120);
    }

    function nextBatch() {
        if (generation !== window.__jrcAlertsIxcGeneration) return;
        if (cursor >= batches.length) {
            scheduleRender();
            return;
        }

        var batch = batches[cursor++];

        postJson('/api/get-devices-ixc-batch.php', {
            serial_numbers: batch
        }).then(function (res) {
            if (generation !== window.__jrcAlertsIxcGeneration) return;

            if (res && res.success !== false && res.devices) {
                absorbIxc(res.devices);
                rememberIxc(res.devices);
                scheduleRender();
            }
        }).catch(function (error) {
            console.warn('[ALERTAS] Complemento IXC em background falhou:', error);
        }).then(function () {
            if (generation !== window.__jrcAlertsIxcGeneration) return;

            // Cede CPU/rede entre lotes para manter a interface responsiva.
            setTimeout(nextBatch, 180);
        });
    }

    function startBackground() {
        if (generation !== window.__jrcAlertsIxcGeneration) return;

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(nextBatch, { timeout: 1200 });
        } else {
            setTimeout(nextBatch, 0);
        }
    }

    // Pequeno atraso para a página terminar de pintar antes do IXC.
    setTimeout(startBackground, 650);

    // Nao bloqueia o boot da tela aguardando todos os lotes IXC.
    return Promise.resolve();
}

    function boot() {
        if (!mount()) return;
        loadAcks().then(loadDevices).then(function () {
            phase = 'ready';
            render();
            return loadSignal();
        }).then(function () {
            phase = 'ready';
            render();
        }).catch(function (err) {
            error = err && err.message ? err.message : 'Não foi possível carregar os alertas.';
            phase = 'ready';
            signalReady = true;
            render();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
