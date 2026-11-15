'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/admin/_sidebar.blade.php'), 'utf8');
const match = blade.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(match, 'canonical Sidebar inline script must exist');
const script = match[1]
    .replace(/@json\(\(bool\)\(\$sidebarDefaultCollapsed \?\? false\)\)/g, 'false')
    .replace(/@json\(__\('[A-Za-z0-9_.-]+'\)\)/g, '"Sidebar"')
    .replace(/\{\{ \$user->id \?\? 'guest' \}\}/g, 'storage-test-user');
assert.doesNotMatch(script, /@json\(|\{\{/, 'all server Blade values must be compiled for this runtime test');

class Element {
    constructor(name, document) {
        this.name = name;
        this.document = document;
        this.dataset = {};
        this.attrs = new Map();
        this.children = [];
        this.listeners = new Map();
        this.hidden = false;
        this.isConnected = true;
        const classes = new Set();
        this.classList = {
            toggle: (name, force) => {
                const enabled = force === undefined ? !classes.has(name) : Boolean(force);
                if (enabled) classes.add(name);
                else classes.delete(name);
                return enabled;
            },
            contains: name => classes.has(name),
        };
    }
    setAttribute(key, value) { this.attrs.set(key, String(value)); }
    getAttribute(key) { return this.attrs.get(key) ?? null; }
    hasAttribute(key) { return this.attrs.has(key); }
    removeAttribute(key) { this.attrs.delete(key); }
    append(...items) { this.children.push(...items); }
    addEventListener(name, cb) {
        const callbacks = this.listeners.get(name) ?? [];
        callbacks.push(cb);
        this.listeners.set(name, callbacks);
    }
    emit(name, data = {}) {
        for (const cb of this.listeners.get(name) ?? []) cb({ target: this, ...data });
    }
    contains(element) { return element === this || this.children.includes(element); }
    closest() { return null; }
    querySelector() { return null; }
    querySelectorAll() { return []; }
    focus() { this.document.activeElement = this; }
    getClientRects() { return [{}]; }
}

function run({ mobile = false, storage = 'denied-property', initial = {} } = {}) {
    const doc = new Element('document', null);
    doc.document = doc;
    doc.body = new Element('body', doc);
    const shell = new Element('aside', doc);
    const toggle = new Element('toggle', doc);
    const search = new Element('search', doc);
    const nav = new Element('nav', doc);
    const brand = new Element('brand', doc);
    const empty = new Element('empty', doc);
    shell.querySelector = selector => {
        if (selector === '[data-foodex-sidebar-toggle]') return toggle;
        if (selector === '[data-foodex-nav-search]') return search;
        if (selector === '[data-foodex-nav]') return nav;
        if (selector === '.foodex-sidebar-brand') return brand;
        if (selector === '[data-foodex-nav-search-empty]') return empty;
        return null;
    };
    doc.currentScript = { closest: () => shell };
    doc.querySelectorAll = () => [];
    const created = [];
    doc.createElement = tag => {
        const element = new Element(tag, doc);
        created.push(element);
        return element;
    };
    doc.activeElement = toggle;
    const memory = new Map(Object.entries(initial));
    const ctx = {
        document: doc,
        HTMLElement: Element,
        matchMedia: query => ({
            matches: mobile && (query.includes('767px') || query.includes('1023px')),
            addEventListener: () => {},
        }),
        requestAnimationFrame: callback => callback(),
        getComputedStyle: () => ({ display: 'block', visibility: 'visible' }),
        Set,
        Map,
    };
    if (storage === 'denied-property') {
        Object.defineProperty(ctx, 'localStorage', { get() { throw new Error('SecurityError'); } });
    } else {
        ctx.localStorage = {
            getItem: key => {
                if (storage === 'denied-methods') throw new Error('SecurityError');
                return memory.get(key) ?? null;
            },
            setItem: (key, value) => {
                if (storage === 'denied-methods') throw new Error('QuotaExceededError');
                memory.set(key, value);
            },
        };
    }
    vm.runInNewContext(script, ctx, { filename: '_sidebar.blade.inline.js', timeout: 2000 });
    return { doc, shell, toggle, nav, search, empty, created, memory };
}

test('denied localStorage property getter never prevents desktop toggle or authorized search', () => {
    const ui = run();
    assert.ok(ui.toggle.listeners.has('click'), 'desktop toggle must be registered');
    assert.ok(ui.search.listeners.has('input'), 'search must be registered');
    ui.toggle.emit('click');
    assert.ok(ui.shell.classList.contains('foodex-sidebar-expanded'));
    ui.search.value = 'no matching authorized items';
    ui.search.emit('input');
    assert.equal(ui.empty.hidden, false, 'search should report zero authorized matches');
});

test('denied storage methods never disable desktop control and search', () => {
    const ui = run({ storage: 'denied-methods' });
    ui.toggle.emit('click');
    assert.ok(ui.shell.classList.contains('foodex-sidebar-expanded'));
    ui.search.value = 'search';
    ui.search.emit('input');
    assert.equal(ui.empty.hidden, false);
});

test('mobile drawer stays keyboard-accessible when browser storage throws', () => {
    const ui = run({ mobile: true });
    const launcher = ui.created.find(item => item.className === 'foodex-mobile-nav-launcher');
    assert.ok(launcher, 'mobile launcher must be constructed');
    launcher.emit('click');
    assert.ok(ui.doc.body.classList.contains('foodex-mobile-nav-open'));
    assert.equal(ui.shell.getAttribute('aria-modal'), 'true');
    assert.equal(ui.doc.activeElement, ui.search, 'drawer opens with focus on search');
    let prevented = false;
    ui.doc.emit('keydown', { key: 'Escape', preventDefault: () => { prevented = true; } });
    assert.ok(prevented);
    assert.equal(ui.doc.body.classList.contains('foodex-mobile-nav-open'), false);
    assert.equal(ui.doc.activeElement, ui.toggle, 'Escape restores previously active control');
});

test('available storage still retains per-user desktop expansion preference', () => {
    const ui = run({ storage: 'available' });
    ui.toggle.emit('click');
    assert.equal(ui.memory.get('foodex.sidebar.expanded.storage-test-user'), '1');
    const reopened = run({ storage: 'available', initial: Object.fromEntries(ui.memory) });
    assert.ok(reopened.shell.classList.contains('foodex-sidebar-expanded'));
});
