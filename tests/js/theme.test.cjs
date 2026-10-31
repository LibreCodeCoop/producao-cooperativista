// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {STORAGE_KEY, readMode, preferredTheme, createThemeManager} = require('../../public/js/theme.js');

function makeFixture({systemDark = false, stored = null, storageBlocked = false} = {}) {
    const events = new Map();
    const documentEvents = new Map();
    const mediaEvents = new Map();
    const storageData = new Map(stored === null ? [] : [[STORAGE_KEY, stored]]);
    const storage = {
        getItem(key) {
            if (storageBlocked) throw new Error('storage blocked');
            return storageData.get(key) ?? null;
        },
        setItem(key, value) {
            if (storageBlocked) throw new Error('storage blocked');
            storageData.set(key, value);
        },
        removeItem(key) {
            if (storageBlocked) throw new Error('storage blocked');
            storageData.delete(key);
        },
    };

    function element() {
        const handlers = new Map();
        const attributes = new Map();
        return {
            hidden: false,
            textContent: '',
            title: '',
            attributes,
            addEventListener(event, handler) { handlers.set(event, handler); },
            click() { handlers.get('click')?.(); },
            setAttribute(name, value) { attributes.set(name, value); },
        };
    }

    const controls = {
        'theme-toggle': element(),
        'theme-toggle-icon': element(),
        'theme-toggle-label': element(),
        'theme-system': element(),
        'theme-status': element(),
    };
    const attrs = new Map();
    const classes = new Set();
    const html = {
        style: {},
        classList: {
            toggle(name, enabled) {
                if (enabled) classes.add(name);
                else classes.delete(name);
            },
            contains(name) { return classes.has(name); },
        },
        setAttribute(name, value) { attrs.set(name, value); },
        getAttribute(name) { return attrs.get(name) ?? null; },
    };
    const document = {
        documentElement: html,
        readyState: 'loading',
        getElementById(id) { return controls[id] ?? null; },
        addEventListener(name, callback) { documentEvents.set(name, callback); },
    };
    const media = {
        matches: systemDark,
        addEventListener: (name, callback) => mediaEvents.set(name, callback),
        change(matches) {
            this.matches = matches;
            mediaEvents.get('change')?.({matches});
        },
    };
    const win = {
        document,
        matchMedia(query) {
            assert.equal(query, '(prefers-color-scheme: dark)');
            return media;
        },
        addEventListener(name, callback) { events.set(name, callback); },
    };
    Object.defineProperty(win, 'localStorage', {
        get() {
            if (storageBlocked) throw new Error('localStorage getter blocked');
            return storage;
        },
    });
    function ready() {
        document.readyState = 'complete';
        documentEvents.get('DOMContentLoaded')?.();
    }
    function onStorage(newValue, key = STORAGE_KEY) {
        if (key === null) {
            storageData.clear();
        } else if (newValue === null) {
            storageData.delete(key);
        } else {
            storageData.set(key, newValue);
        }
        events.get('storage')?.({key, newValue});
    }
    return {win, html, controls, media, storageData, ready, onStorage};
}

test('no browser choice follows light operating system immediately before DOMContentLoaded', () => {
    const f = makeFixture();
    const manager = createThemeManager(f.win);
    assert.equal(manager.getMode(), 'system');
    assert.equal(manager.getTheme(), 'light');
    assert.equal(f.html.getAttribute('data-theme'), 'light');
    assert.equal(f.html.style.colorScheme, 'light');
    assert.equal(f.html.classList.contains('dark'), false);
    f.ready();
    assert.equal(f.controls['theme-toggle-label'].textContent, 'Tema escuro');
    assert.equal(f.controls['theme-status'].textContent, 'Navegador · Claro');
    assert.equal(f.controls['theme-system'].hidden, true);
    assert.equal(f.controls['theme-toggle'].attributes.get('aria-label'), 'Ativar tema escuro');
    assert.equal(f.storageData.size, 0);
});

test('system dark mode is used by default, without writing a saved preference', () => {
    const f = makeFixture({systemDark: true});
    const manager = createThemeManager(f.win);
    assert.equal(manager.getTheme(), 'dark');
    assert.equal(f.html.classList.contains('dark'), true); // Supports native DataTables dark mode.
    f.ready();
    assert.equal(f.controls['theme-toggle-icon'].textContent, '☀');
    assert.equal(f.controls['theme-toggle-label'].textContent, 'Tema claro');
    assert.equal(f.controls['theme-status'].textContent, 'Navegador · Escuro');
    assert.equal(f.storageData.size, 0);
});

test('toggle switches contrast, persists manual selection, and offers return to browser', () => {
    const f = makeFixture({systemDark: true});
    const manager = createThemeManager(f.win);
    f.ready();
    f.controls['theme-toggle'].click();
    assert.equal(manager.getMode(), 'light');
    assert.equal(manager.getTheme(), 'light');
    assert.equal(f.storageData.get(STORAGE_KEY), 'light');
    assert.equal(f.controls['theme-system'].hidden, false);
    assert.equal(f.controls['theme-status'].textContent, 'Claro');
    assert.equal(f.controls['theme-toggle'].attributes.get('aria-label'), 'Ativar tema escuro');
    f.controls['theme-toggle'].click();
    assert.equal(manager.getMode(), 'dark');
    assert.equal(f.storageData.get(STORAGE_KEY), 'dark');
    f.controls['theme-system'].click();
    assert.equal(manager.getMode(), 'system');
    assert.equal(manager.getTheme(), 'dark');
    assert.equal(f.storageData.has(STORAGE_KEY), false);
    assert.equal(f.controls['theme-system'].hidden, true);
});

test('manual selection survives navigation, ignores OS changes until reset', () => {
    const f = makeFixture({systemDark: false, stored: 'dark'});
    const manager = createThemeManager(f.win);
    assert.equal(manager.getMode(), 'dark');
    assert.equal(manager.getTheme(), 'dark');
    f.ready();
    f.media.change(true);
    f.media.change(false);
    assert.equal(manager.getTheme(), 'dark');
    f.controls['theme-system'].click();
    assert.equal(manager.getTheme(), 'light');
    f.media.change(true);
    assert.equal(manager.getTheme(), 'dark');
    assert.equal(f.controls['theme-status'].textContent, 'Navegador · Escuro');
});

test('updates from another tab synchronize immediately and invalid values revert to system', () => {
    const f = makeFixture({systemDark: true});
    const manager = createThemeManager(f.win);
    f.ready();
    f.onStorage('light');
    assert.equal(manager.getTheme(), 'light');
    assert.equal(manager.getMode(), 'light');
    f.onStorage('invalid');
    assert.equal(manager.getTheme(), 'dark');
    assert.equal(manager.getMode(), 'system');
    f.onStorage('light');
    f.onStorage(null, null); // Browser's clear() storage event.
    assert.equal(manager.getMode(), 'system');
    assert.equal(manager.getTheme(), 'dark');
});

test('blocked localStorage does not break theme switching', () => {
    const f = makeFixture({storageBlocked: true, systemDark: true});
    const manager = createThemeManager(f.win);
    f.ready();
    assert.equal(manager.getTheme(), 'dark');
    f.controls['theme-toggle'].click();
    assert.equal(manager.getTheme(), 'light');
    assert.equal(manager.getMode(), 'light');
    f.controls['theme-system'].click();
    assert.equal(manager.getTheme(), 'dark');
    assert.equal(manager.getMode(), 'system');
});



test('modes are allowlisted and absent DOM is harmless', () => {
    assert.equal(readMode({getItem: () => 'something unexpected'}), 'system');
    assert.equal(readMode(null), 'system');
    assert.equal(preferredTheme('system', true), 'dark');
    assert.equal(preferredTheme('system', false), 'light');
    assert.equal(preferredTheme('light', true), 'light');
    assert.equal(createThemeManager({document: null}), null);
});
