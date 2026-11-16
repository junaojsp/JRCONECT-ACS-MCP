// Dependency-free DOM regression test for the customized ACS dashboard.
// It reproduces a DIV-based equipment grid and the styled signal sidebar.
const fs = require('fs');
const vm = require('vm');
const assert = require('node:assert/strict');
const path = require('node:path');

class El {
    constructor(tag, text = '', className = '') {
        this.tagName = tag.toLowerCase();
        this.ownText = text;
        this.className = className;
        this.children = [];
        this.parentElement = null;
        this.dataset = {};
        this.attributes = {};
        this.title = '';
        this.style = {};
        this.handlers = {};
    }
    append(...elements) {
        for (const item of elements) {
            item.parentElement = this;
            this.children.push(item);
        }
    }
    get textContent() {
        return [this.ownText, ...this.children.map(c => c.textContent)]
            .filter(Boolean).join(' ');
    }
    set textContent(text) {
        this.ownText = text;
        this.children = [];
    }
    all() {
        return this.children.flatMap(child => [child, ...child.all()]);
    }
    matches(selector) {
        if (selector === '*') return true;
        if (selector === '[id]') return Object.hasOwn(this.attributes, 'id');
        if (selector === '[data-jrc-router-summary="1"]')
            return this.dataset.jrcRouterSummary === '1';
        if (selector === '[data-jrc-router-count]')
            return this.dataset.jrcRouterCount === '1';
        if (selector === 'td:first-child')
            return this.tagName === 'td' &&
                this.parentElement?.children[0] === this;
        if (selector === '[class*="device-name"]') return this.className.includes('device-name');
        if (selector === '[class*="equipment-name"]') return this.className.includes('equipment-name');
        if (selector === '.badge') return this.className.includes('badge');
        return this.tagName === selector;
    }
    querySelectorAll(selector) {
        const selectors = selector.split(',').map(s => s.trim());
        if (selectors.includes('tbody tr') || selectors.includes('thead th'))
            return [];
        return this.all().filter(el => selectors.some(s => el.matches(s)));
    }
    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }
    removeAttribute(name) { delete this.attributes[name]; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    addEventListener(event, callback) {
        this.handlers[event] = this.handlers[event] || [];
        this.handlers[event].push(callback);
    }
    dispatch(event, key = null) {
        const e = {
            key, preventDefault() {}, stopPropagation() {},
            stopImmediatePropagation() {}
        };
        for (const cb of this.handlers[event] || []) cb(e);
    }
    cloneNode(deep = false) {
        const copy = new El(this.tagName, this.ownText, this.className);
        copy.title = this.title;
        copy.dataset = {...this.dataset};
        copy.attributes = {...this.attributes};
        if (deep) copy.append(...this.children.map(c => c.cloneNode(true)));
        return copy;
    }
    insertAdjacentElement(position, node) {
        assert.equal(position, 'afterend');
        const siblings = this.parentElement.children;
        siblings.splice(siblings.indexOf(this) + 1, 0, node);
        node.parentElement = this.parentElement;
    }
}
function element(tag, text, className) { return new El(tag, text, className); }
function row(model, signal) {
    const item = element('div', '', 'acs-equipment-row');
    const identity = element('div', '', 'acs-equipment-name');
    identity.append(element('strong', model), element('small', 'TEST_SERIAL'));
    const rx = element('div', '', 'acs-equipment-rx');
    rx.append(element('span', signal, 'badge'));
    item.append(identity, element('div', 'cliente'), rx);
    return {item, rx};
}
const body = element('body');
const side = element('div', '', 'signal-fleet-panel');
side.append(element('div', 'Sinal da frota'));
const signalRow = element('div', '', 'fleet-row original-styled-row');
signalRow.append(element('span', 'Sem leitura', 'fleet-label'),
                 element('strong', '1', 'fleet-value'));
side.append(signalRow);
body.append(side);
const grid = element('div', '', 'acs-grid-equipamentos');
const router = row('D-LINK Router', 'Sem dado');
const onuMissing = row('FiberHome HG6245D', 'Sem dado');
const onuWithRx = row('Huawei EG8145V5', '-26.12 dBm');
grid.append(router.item, onuMissing.item, onuWithRx.item);
body.append(grid);

let observer;
let lastNavigation = '';
const document = {
    readyState: 'complete',
    body,
    querySelectorAll: s => body.querySelectorAll(s),
    querySelector: s => body.querySelector(s)
};
const context = {
    document,
    window: {requestAnimationFrame: cb => cb(), location: {assign: url => { lastNavigation = url; }}},
    MutationObserver: class {
        constructor(callback) { observer = callback; }
        observe() {}
    },
    fetch: async () => ({
        ok: true,
        json: async () => ({
            success: true,
            scope: 'genieacs',
            cache_stale: false,
            routers: 5
        })
    }),
    console
};
const source = fs.readFileSync(
    path.join(__dirname, '../assets/js/jrc-router-signal.js'), 'utf8'
);
vm.runInNewContext(source, context);

(async () => {
    await new Promise(setImmediate); // async source summary completes
    assert.equal(router.rx.textContent, 'Roteador', 'D-Link grid badge replaced');
    assert.equal(onuMissing.rx.textContent, 'Sem dado', 'ONU lacking RX unchanged');
    assert.equal(onuWithRx.rx.textContent, '-26.12 dBm', 'real RX unchanged');

    const sidebarRow = body.querySelector('[data-jrc-router-summary="1"]');
    assert.ok(sidebarRow, 'counter appended in signal-fleet panel');
    assert.equal(sidebarRow.className, signalRow.className, 'same styled row structure');
    assert.equal(sidebarRow.children[0].textContent, 'Roteadores', 'label aligned');
    assert.equal(sidebarRow.children[1].textContent, '5', 'value aligned');
    assert.equal(sidebarRow.attributes.role, 'link', 'accessible clickable router row');
    assert.equal(sidebarRow.attributes.tabindex, '0', 'keyboard reachable');
    sidebarRow.dispatch('click');
    assert.equal(lastNavigation, '/router-report.php', 'router click opens report');
    lastNavigation = '';
    sidebarRow.dispatch('keydown', 'Enter');
    assert.equal(lastNavigation, '/router-report.php', 'keyboard Enter opens report');
    assert.equal(signalRow.children[0].textContent, 'Sem leitura', 'original label unchanged');
    assert.equal(signalRow.children[1].textContent, '1', 'original value unchanged');

    // Re-render: new D-Link row must get the badge, without duplicate summary.
    const nextRouter = row('D-LINK Router', 'Sem dado');
    grid.children[0] = nextRouter.item;
    nextRouter.item.parentElement = grid;
    observer();
    assert.equal(nextRouter.rx.textContent, 'Roteador', 're-rendered row updated');
    assert.equal(body.all().filter(el => el.dataset.jrcRouterSummary === '1').length,
                 1, 'sidebar counter not duplicated');
    process.stdout.write('JRC_ROUTER_DOM_TESTS_OK\n');
})().catch(e => { console.error(e); process.exitCode = 1; });
