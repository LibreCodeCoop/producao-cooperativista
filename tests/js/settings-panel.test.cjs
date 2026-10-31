// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
    DEFAULT_STATUS, readState, tableStateUrl, exactFilter, initializeSettingsTable,
} = require('../../public/js/settings-panel.js');

function element(value = '') {
    const handlers = new Map();
    return {
        value,
        textContent: '',
        handlers,
        addEventListener(type, callback) { handlers.set(type, callback); },
        emit(type) { handlers.get(type)?.(); },
    };
}

function fixture(href = 'https://librecode.example/configuracao?competencia=2026-08', rows) {
    const elements = {
        'settings-members': element(),
        'settings-name-filter': element(),
        'settings-user-filter': element(),
        'settings-status-filter': element('Ativo'),
        'settings-hours-filter': element('all'),
        'settings-clear-filters': element(),
        'settings-default-filters': element(),
        'settings-visible-count': element(),
        'settings-table-error': element(),
    };
    const links = [{
        href: '/configuracao?competencia=2026-08&cooperado=2',
        handlers: new Map(),
        addEventListener(type, callback) { this.handlers.set(type, callback); },
        emit(type) { this.handlers.get(type)?.(); },
    }];
    const location = new URL(href);
    const history = { urls: [], replaceState(_state, _title, url) {
        this.urls.push(url);
        location.href = new URL(url, location.href).href;
    } };
    const data = rows ?? [
        ['Ana', 'ana', '10,00 h', '1,5', 'Com horas', 'Ativo', 'Conferir e editar'],
        ['Bruno', 'bruno', '0,00 h', '', 'Sem horas', 'Desativado', 'Conferir e editar'],
        ['Celia', 'celia', '14,00 h', '2,5', 'Com horas', 'Ativo', 'Conferir e editar'],
        ['Serviço', 'robot', '0,00 h', '', 'Sem horas', 'Conta de serviço', 'Não editável'],
    ];
    class DataTable {
        constructor(selector, settings) {
            this.selector = selector;
            this.settings = settings;
            const queries = new Map();
            let global = '';
            let pageIndex = 0;
            let pageSize = settings.pageLength;
            let order = settings.order;
            let draws = 0;
            const handlers = new Map();
            const filtered = () => data.filter(row =>
                (!global || row.some(item => item.toLowerCase().includes(global.toLowerCase()))) &&
                [...queries.entries()].every(([column, pattern]) => {
                    const term = pattern?.value || '';
                    const value = row[column];
                    if (!term) return true;
                    if (pattern.regex) return value === term.slice(1, -1);
                    return value.toLowerCase().includes(term.toLowerCase());
                }));
            const api = {
                column: index => ({
                    search: (value, regex = false) => {
                        if (value !== undefined) queries.set(index, {value, regex});
                        return api;
                    },
                }),
                search: value => {
                    if (value === undefined) return global;
                    global = value;
                    return api;
                },
                page: Object.assign(value => {
                    if (value === undefined) return pageIndex;
                    pageIndex = value;
                    return api;
                }, {
                    len: value => {
                        if (value === undefined) return pageSize;
                        pageSize = value;
                        return api;
                    },
                    info: () => ({pages: Math.ceil(filtered().length / pageSize)}),
                }),
                order: value => {
                    if (value === undefined) return order;
                    order = value;
                    return api;
                },
                draw: () => {
                    draws++;
                    handlers.get('draw.dt')?.();
                    handlers.get('search.dt page.dt length.dt order.dt')?.();
                    return api;
                },
                rows: () => ({count: () => filtered().length}),
                on: (events, callback) => {
                    handlers.set(events, callback);
                    return api;
                },
            };
            this.apiInstance = api;
            this.queries = queries;
            this.getDraws = () => draws;
            this.fire = events => handlers.get(events)?.();
            settings.initComplete.call({api: () => api});
        }
    }
    const doc = {
        getElementById: id => elements[id] || null,
        querySelectorAll: selector => selector === '[data-settings-member-link]' ? links : [],
    };
    return {doc, elements, links, location, history, data, DataTable};
}

test('default state shows active cooperatives, but keeps pagination and ordering options', () => {
    assert.equal(DEFAULT_STATUS, 'Ativo');
    assert.deepEqual(readState('?competencia=2026-08'), {
        name: '', user: '', status: 'Ativo', activity: 'all',
        search: '', page: 0, length: 10, order: [2, 'desc'],
    });
    const f = fixture();
    const dt = initializeSettingsTable(f.doc, {DataTable: f.DataTable, location: f.location, history: f.history});
    assert.ok(dt);
    assert.equal(dt.selector, '#settings-members');
    assert.equal(dt.settings.scrollX, true);
    assert.deepEqual(dt.settings.lengthMenu, [10, 25, 50, 100]);
    assert.deepEqual(dt.settings.order, [[2, 'desc']]);
    const buttons = dt.settings.layout.topStart.buttons;
    assert.deepEqual(buttons.map(button => typeof button === 'string' ? button : button.extend),
        ['pageLength', 'colvis', 'copy', 'csv', 'excel', 'pdf', 'print']);
    for (const button of buttons.slice(2)) {
        assert.deepEqual(button.exportOptions.columns, [0, 1, 2, 4, 5],
            'Do not export partially loaded live weights or hidden editing controls');
    }
    assert.deepEqual(dt.settings.columnDefs, [{targets: [3, 6], orderable: false, searchable: false}]);
    assert.equal(f.elements['settings-status-filter'].value, 'Ativo');
    assert.deepEqual(dt.queries.get(5), {value: '^Ativo$', regex: true});
    assert.equal(f.elements['settings-visible-count'].textContent, '2');
    assert.equal(f.history.urls.length, 0);
});

test('name, username and hours have editable filters and update URL', () => {
    const f = fixture();
    const dt = initializeSettingsTable(f.doc, {DataTable: f.DataTable, location: f.location, history: f.history});
    f.elements['settings-name-filter'].value = 'Ce';
    f.elements['settings-name-filter'].emit('input');
    assert.equal(dt.queries.get(0).value, 'Ce');
    assert.equal(f.elements['settings-visible-count'].textContent, '1');
    assert.equal(new URL(f.history.urls.at(-1), f.location.href).searchParams.get('dtname'), 'Ce');
    f.elements['settings-user-filter'].value = 'cel';
    f.elements['settings-user-filter'].emit('input');
    assert.equal(dt.queries.get(1).value, 'cel');
    f.elements['settings-hours-filter'].value = 'Com horas';
    f.elements['settings-hours-filter'].emit('change');
    assert.equal(dt.queries.get(4).value, '^Com horas$');
    assert.equal(new URL(f.history.urls.at(-1), f.location.href).searchParams.get('dtactivity'), 'Com horas');
});

test('clear all filters shows inactive and service accounts; restore hides them', () => {
    const f = fixture();
    const dt = initializeSettingsTable(f.doc, {DataTable: f.DataTable, location: f.location, history: f.history});
    f.elements['settings-clear-filters'].emit('click');
    assert.equal(f.elements['settings-status-filter'].value, 'all');
    assert.equal(dt.queries.get(5).value, '');
    assert.equal(f.elements['settings-visible-count'].textContent, '4');
    assert.equal(new URL(f.history.urls.at(-1), f.location.href).searchParams.get('dtstatus'), 'all');
    f.elements['settings-status-filter'].value = 'Desativado';
    f.elements['settings-status-filter'].emit('change');
    assert.equal(f.elements['settings-visible-count'].textContent, '1');
    f.elements['settings-default-filters'].emit('click');
    assert.equal(f.elements['settings-status-filter'].value, 'Ativo');
    assert.equal(f.elements['settings-visible-count'].textContent, '2');
    assert.equal(new URL(f.history.urls.at(-1), f.location.href).searchParams.get('dtstatus'), null);
});

test('restores shared URL filters, pagination, search and sort and keeps competence', () => {
    const f = fixture('https://librecode.example/configuracao?competencia=2026-08&cooperado=2&dtname=Cel&dtsearch=cel&dtstatus=all&dtactivity=Com%20horas&dtpage=1&dtlength=25&dtorder=2:desc');
    const dt = initializeSettingsTable(f.doc, {DataTable: f.DataTable, location: f.location, history: f.history});
    assert.equal(f.elements['settings-name-filter'].value, 'Cel');
    assert.equal(f.elements['settings-status-filter'].value, 'all');
    assert.equal(dt.apiInstance.page.len(), 25);
    assert.deepEqual(dt.apiInstance.order()[0], [2, 'desc']);
    assert.equal(dt.apiInstance.search(), 'cel');
    const url = new URL(tableStateUrl(f.location.href, {
        name: 'A & B', user: '', activity: 'Sem horas', status: 'Desativado',
        search: 'p', page: 2, length: 25, order: [2, 'desc'],
    }), f.location.href);
    assert.equal(url.searchParams.get('competencia'), '2026-08');
    assert.equal(url.searchParams.get('cooperado'), '2');
    assert.equal(url.searchParams.get('dtname'), 'A & B');
    assert.equal(url.searchParams.get('dtpage'), '2');
});

test('member link preserves changed filters on navigation', () => {
    const f = fixture();
    initializeSettingsTable(f.doc, {DataTable: f.DataTable, location: f.location, history: f.history});
    f.elements['settings-status-filter'].value = 'all';
    f.elements['settings-status-filter'].emit('change');
    f.elements['settings-name-filter'].value = 'Bruno';
    f.elements['settings-name-filter'].emit('input');
    f.links[0].emit('click');
    const dest = new URL(f.links[0].href, f.location.href);
    assert.equal(dest.searchParams.get('competencia'), '2026-08');
    assert.equal(dest.searchParams.get('cooperado'), '2');
    assert.equal(dest.searchParams.get('dtstatus'), 'all');
    assert.equal(dest.searchParams.get('dtname'), 'Bruno');
});

test('invalid URL filter values cannot inject custom regex or invalid sorting', () => {
    const state = readState('?dtstatus=.^%2B&dtactivity=evil&dtorder=8:desc&dtpage=oops&dtlength=999');
    assert.equal(state.status, 'Ativo');
    assert.equal(state.activity, 'all');
    assert.deepEqual(state.order, [2, 'desc']);
    assert.equal(state.page, 0);
    assert.equal(state.length, 10);
    assert.deepEqual(readState('?dtorder=3:desc').order, [2, 'desc'], 'Live field cannot be sorted from partial async data');
    assert.equal(exactFilter('all'), '');
    assert.equal(exactFilter('Ativo'), '^Ativo$');
});

test('empty roster still initializes pagination, search, filters and a zero count', () => {
    const f = fixture(undefined, []);
    const dt = initializeSettingsTable(f.doc, {DataTable: f.DataTable, location: f.location, history: f.history});
    assert.ok(dt);
    assert.equal(f.elements['settings-visible-count'].textContent, '0');
});

test('missing DataTables dependency is clearly reported, and pages without grid are ignored', () => {
    const f = fixture();
    assert.equal(initializeSettingsTable(f.doc, {location: f.location, history: f.history}), null);
    assert.match(f.elements['settings-table-error'].textContent, /não carregou/);
    assert.equal(initializeSettingsTable({getElementById: () => null}), null);
});
