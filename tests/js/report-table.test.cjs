// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
    escapeHtml, columnTitle, nestedTableHtml, safeLink, renderCell, buildColumns,
    stateUrl, setupFilters, apiErrorDetails, initializeReportTable,
} = require('../../public/js/report-table.js');

function element(tagName = 'div') {
    const listeners = new Map();
    return {
        tagName,
        textContent: '',
        value: '',
        children: [],
        name: '',
        addEventListener(type, handler) { listeners.set(type, handler); },
        emit(type) { listeners.get(type)?.(); },
        appendChild(child) { this.children.push(child); return child; },
        replaceChildren(...children) { this.children = children; },
        listeners,
    };
}

function fixture(payload = { data: [{ id: 1, name: 'Teste', metadata: '{}' }] }, opts = {}) {
    const footer = element('tr');
    const table = {
        dataset: { url: '/api/v1/producao?ano-mes=2026-05', filterColumns: '["metadata"]' },
        tFoot: { rows: [footer] },
    };
    const errors = element('div');
    const location = new URL(opts.href || 'https://librecode.test/relatorios/producao?ano-mes=2026-05');
    const history = { calls: [], replaceState(_state, _title, url) {
        this.calls.push(url);
        const updated = new URL(url, location.href);
        location.href = updated.href;
    } };
    const doc = {
        createElement: element,
        getElementById(name) { return { example: table, erros: errors }[name] || null; },
        querySelector() { return null; },
    };
    const calls = { fetch: 0, DataTable: 0 };

    class DataTable {
        constructor(selector, settings) {
            calls.DataTable++;
            this.selector = selector;
            this.settings = settings;
            this.searchTerm = '';
            this.pageIndex = 0;
            this.draws = 0;
            this.handlers = {};
            this.cols = settings.columns.map((_, index) => ({
                value: '',
                search(value) {
                    if (value === undefined) return this.value;
                    this.value = value;
                    return this;
                },
                footer() { return footer.children[index]; },
                draw() { return this; },
            }));
            const api = {
                columns: () => ({ every: callback => {
                    this.cols.forEach((column, index) => callback.call(column, index));
                } }),
                search: value => {
                    if (value === undefined) return this.searchTerm;
                    this.searchTerm = value;
                    return api;
                },
                page: value => {
                    if (value === undefined) return this.pageIndex;
                    this.pageIndex = value;
                    return api;
                },
                draw: () => { this.draws++; return api; },
                on: (events, callback) => { this.handlers[events] = callback; },
            };
            this.apiInstance = api;
            settings.initComplete.call({ api: () => api });
        }
    }

    const options = {
        DataTable,
        location,
        history,
        async fetch(url, settings) {
            calls.fetch++;
            calls.request = { url, settings };
            if (opts.reject) throw new Error(opts.reject);
            return {
                ok: opts.status === undefined ? true : opts.status >= 200 && opts.status < 300,
                status: opts.status ?? 200,
                async json() {
                    if (opts.badJson) throw new SyntaxError('Invalid JSON');
                    return payload;
                },
            };
        },
    };
    return { table, footer, errors, doc, calls, options, history, location };
}

test('HTML escaping protects financial values, quotes and empty cells', () => {
    assert.equal(escapeHtml('<b id="a">&\'X</b>'), '&lt;b id=&quot;a&quot;&gt;&amp;&#39;X&lt;/b&gt;');
    assert.equal(renderCell(0, 'display', 'amount'), '0');
    assert.equal(renderCell(null, 'display', 'amount'), '');
    assert.equal(renderCell('<img src=x onerror=alert(1)>', 'display', 'name'),
        '&lt;img src=x onerror=alert(1)&gt;');
    assert.equal(renderCell('<tag>', 'sort', 'name'), '<tag>');
    assert.equal(columnTitle('custo_por_cliente'), 'Custo por cliente');
});

test('nested tables render valid balanced markup with escaped headers and cells', () => {
    const rows = JSON.stringify([
        { nome_cliente: '<script>alert(1)</script>', total: 120 },
        { nome_cliente: 'Segundo & terceiro', total: 80 },
    ]);
    const output = nestedTableHtml(rows);
    assert.match(output, /^<table border="1"><thead><tr><th>Nome cliente<\/th>/);
    assert.equal((output.match(/<tr>/g) || []).length, 3);
    assert.equal((output.match(/<\/tr>/g) || []).length, 3);
    assert.equal((output.match(/<tbody>/g) || []).length, 1);
    assert.equal((output.match(/<\/tbody>/g) || []).length, 1);
    assert.ok(!output.includes('<script>'));
    assert.match(output, /&lt;script&gt;alert/);
    assert.match(output, /Segundo &amp; terceiro/);
});

test('nested arrays support disjoint keys and invalid data does not generate HTML', () => {
    const output = nestedTableHtml([{ primeira: 'A' }, { segunda: 'B' }]);
    assert.match(output, /<th>Primeira<\/th><th>Segunda<\/th>/);
    assert.match(output, /<td>A<\/td><td><\/td>/);
    for (const invalid of ['[]', 'not JSON', '{"id":1}', '["not an object"]', '[null]', []]) {
        assert.equal(nestedTableHtml(invalid), null);
    }
});

test('Akaunting URLs render as escaped links, never server-supplied HTML', () => {
    const source = 'https://accounting.example.test/1/sales/invoices/8?a=1&b=2';
    const link = safeLink(source, 'invoice');
    assert.match(link, /rel="noopener noreferrer"/);
    assert.match(link, /a=1&amp;b=2/);
    assert.equal(renderCell(source, 'display', 'URL', {type:'invoice'}), link);
    assert.equal(safeLink('javascript:alert(1)', 'bad'), null);
    assert.equal(safeLink('data:text/html,malicious', 'bad'), null);
    assert.equal(safeLink('<a href="https://example.com">bad</a>', 'bad'), null);
    assert.equal(renderCell('<img src=x>', 'display', 'URL'), '&lt;img src=x&gt;');
});

test('capital-social names and targets are separate, untrusted data fields', () => {
    const row = {nome:'Cooperado A',nome_url:'/relatorios/capital-social?contact_id=4'};
    assert.equal(renderCell(row.nome,'display','nome',row),
        '<a href="/relatorios/capital-social?contact_id=4">Cooperado A</a>');
    assert.equal(safeLink('//evil.example/', 'Malicioso', true), null);
    assert.equal(safeLink('https://evil.example/', 'Externo', true), null);
    assert.equal(renderCell('<img src=x onerror=alert(1)>','display','nome',{
        nome_url:'/relatorios/capital-social?contact_id=4',
    }), '<a href="/relatorios/capital-social?contact_id=4">&lt;img src=x onerror=alert(1)&gt;</a>');
    assert.equal(renderCell('Cooperado A','display','nome',{nome_url:'javascript:alert(1)'}),'Cooperado A');
    assert.deepEqual(buildColumns([row]).map(c=>c.data),['nome']);
});

test('column creation includes keys from later rows while excluding metadata', () => {
    const columns = buildColumns([{ metadata: '{}', nome: 'Pessoa' }, { id: 11, nome: 'Outra' }]);
    assert.deepEqual(columns.map(column => column.data), ['nome', 'id']);
    assert.equal(columns[0].title, 'Nome');
    assert.equal(columns[0].render('<script>', 'display'), '&lt;script&gt;');
    assert.equal(columns[0].render('123', 'sort'), '123');
    assert.equal(columns[0].defaultContent, '');
});

test('URL state replaces filters without losing the selected competence or hash', () => {
    const current = 'https://librecode.test/relatorios/producao?ano-mes=2026-05&nome=old&dtpage=4#resumo';
    const updated = stateUrl(current, { nome: 'Outro & <x>', custo: '' }, 'completo', 2);
    const url = new URL(updated, current);
    assert.equal(url.searchParams.get('ano-mes'), '2026-05');
    assert.equal(url.searchParams.get('nome'), 'Outro & <x>');
    assert.equal(url.searchParams.get('custo'), null);
    assert.equal(url.searchParams.get('dtsearch'), 'completo');
    assert.equal(url.searchParams.get('dtpage'), '2');
    assert.equal(url.hash, '#resumo');
});

test('clearing search and returning to first page removes their URL parameters', () => {
    const href = 'https://librecode.test/p?dtsearch=A&dtpage=1&nome=X&ano-mes=2026-05';
    const url = new URL(stateUrl(href, { nome: '' }, '', 0), href);
    assert.equal(url.search, '?ano-mes=2026-05');
});

test('initialized report loads actual API data and preserves column export actions', async () => {
    const f = fixture();
    const dt = await initializeReportTable(f.doc, f.options);
    assert.ok(dt);
    assert.equal(f.calls.fetch, 1);
    assert.equal(f.calls.DataTable, 1);
    assert.deepEqual(f.calls.request, {
        url: '/api/v1/producao?ano-mes=2026-05',
        settings: {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        },
    });
    assert.deepEqual(dt.settings.columns.map(col => col.data), ['id', 'name']);
    assert.ok(dt.settings.layout.topStart.buttons.includes('excel'));
    assert.equal(f.footer.children.length, 2);
    assert.equal(f.footer.children[1].children[0].name, 'name');
    assert.equal(f.errors.textContent, '');
});

test('report restores column filters, global search and page without rewriting navigation', async () => {
    const f = fixture({ data: [{ nome: 'A', metadata: '{}' }] }, {
        href: 'https://librecode.test/relatorios/producao?ano-mes=2026-05&nome=Abc&dtsearch=Total&dtpage=3',
    });
    const dt = await initializeReportTable(f.doc, f.options);
    assert.equal(dt.cols[0].value, 'Abc');
    assert.equal(dt.searchTerm, 'Total');
    assert.equal(dt.pageIndex, 3);
    assert.equal(dt.draws, 2);
    assert.equal(f.history.calls.length, 0);
    assert.equal(f.footer.children[0].children[0].value, 'Abc');
});

test('typing a filter updates the DataTable and URL using replaceState', async () => {
    const f = fixture({ data: [{ nome: 'A' }] });
    const dt = await initializeReportTable(f.doc, f.options);
    const input = f.footer.children[0].children[0];
    input.value = 'A & B';
    input.emit('keyup');
    assert.equal(dt.cols[0].value, 'A & B');
    assert.equal(new URL(f.history.calls[0], f.location.href).searchParams.get('nome'), 'A & B');
    assert.equal(f.history.calls.length, 1);
    input.value = '';
    input.emit('keyup');
    assert.equal(new URL(f.history.calls.at(-1), f.location.href).searchParams.get('nome'), null);
});

test('global search and pagination events save their state', async () => {
    const f = fixture({ data: [{ id: 11 }] });
    const dt = await initializeReportTable(f.doc, f.options);
    dt.apiInstance.search('renda');
    dt.apiInstance.page(2);
    dt.handlers['search.dt page.dt length.dt order.dt']();
    const latest = new URL(f.history.calls.at(-1), f.location.href);
    assert.equal(latest.searchParams.get('dtsearch'), 'renda');
    assert.equal(latest.searchParams.get('dtpage'), '2');
});

test('empty API errors never suppress otherwise valid reports', async () => {
    const f = fixture({ error: [], data: [{ id: 42, nome: 'Receita' }] });
    const dt = await initializeReportTable(f.doc, f.options);
    assert.ok(dt);
    assert.equal(f.errors.textContent, '');
});

test('server errors include resolution and the reference ID without inserting HTML', async () => {
    const message = '<script>alert(1)</script>';
    const response = {
        error: message,
        resolution: 'Verifique as permissões no serviço de origem.',
        request_id: 'cafef00d',
    };
    assert.match(apiErrorDetails(response), /Identificador da falha: cafef00d/);
    assert.equal(apiErrorDetails({ error: [] }), '');
    assert.equal(apiErrorDetails({}), '');

    const f = fixture(response, { status: 503 });
    assert.equal(await initializeReportTable(f.doc, f.options), null);
    assert.match(f.errors.textContent, /HTTP 503/);
    assert.match(f.errors.textContent, /Verifique as permissões/);
    assert.match(f.errors.textContent, /Identificador da falha: cafef00d/);
    assert.match(f.errors.textContent, /<script>/);
    assert.equal(f.errors.innerHTML, undefined);
});

test('empty report shows a clear message instead of accessing data[0]', async () => {
    const f = fixture({ data: [] });
    assert.equal(await initializeReportTable(f.doc, f.options), null);
    assert.match(f.errors.textContent, /Nenhum registro/);
    assert.equal(f.calls.DataTable, 0);
});

for (const [name, payload, expected] of [
    ['API error list', { error: ['Não foi possível calcular'] }, 'Não foi possível calcular'],
    ['API error object', { error: { message: 'Falha interna' } }, 'Falha interna'],
    ['malformed response', { records: [] }, 'formato de relatório inválido'],
    ['no visible columns', { data: [{ metadata: '{}' }] }, 'colunas do relatório'],
]) {
    test(name + ' is surfaced safely and does not create a table', async () => {
        const f = fixture(payload);
        assert.equal(await initializeReportTable(f.doc, f.options), null);
        assert.match(f.errors.textContent, new RegExp(expected));
        assert.equal(f.calls.DataTable, 0);
    });
}

for (const [failure, options, expected] of [
    ['HTTP 403', { status: 403 }, 'HTTP 403'],
    ['HTTP 500', { status: 500 }, 'HTTP 500'],
    ['network error', { reject: 'Rede indisponível' }, 'Rede indisponível'],
    ['invalid JSON', { badJson: true }, 'Invalid JSON'],
]) {
    test(failure + ' shows an actionable message instead of an empty report', async () => {
        const f = fixture({ data: [{ id: 1 }] }, options);
        assert.equal(await initializeReportTable(f.doc, f.options), null);
        assert.match(f.errors.textContent, new RegExp(expected));
    });
}

test('unavailable DataTables library is reported', async () => {
    const f = fixture({ data: [{ id: 1 }] });
    f.options.DataTable = null;
    // Option fallback must not resolve in Node's module environment.
    assert.equal(await initializeReportTable(f.doc, f.options), null);
    assert.match(f.errors.textContent, /não foi carregado/);
});

test('malformed or missing table configuration cannot execute an API request', async () => {
    const f = fixture();
    f.table.dataset.filterColumns = '{not an array}';
    assert.equal(await initializeReportTable(f.doc, f.options), null);
    assert.equal(f.calls.fetch, 0);

    const m = fixture();
    m.table.dataset.url = '';
    assert.equal(await initializeReportTable(m.doc, m.options), null);
    assert.equal(m.calls.fetch, 0);
});

test('no DataTable root means the initializer does not perform any requests', async () => {
    const f = fixture();
    assert.equal(await initializeReportTable({ getElementById: () => null }, f.options), null);
    assert.equal(f.calls.fetch, 0);
});

test('production report formats money as BRL while retaining raw values for sorting', () => {
    const opts = {productionMode: true, competence: '2026-08'};
    const html = renderCell(100.320000001, 'display', 'base_producao', {}, opts);
    assert.match(html, /R\$\s?100,32/);
    assert.ok(!html.includes('000001'));
    assert.equal(renderCell(100.320000001, 'sort', 'base_producao', {}, opts), 100.320000001);
    assert.equal(renderCell(0, 'display', 'liquido', {}, opts).includes('0,00'), true);
});

test('production report renders imported weight without editing controls or source requests', () => {
    const opts = {productionMode: true, competence: '2026-08'};
    const row = {kimai_user_id: 14, peso: '1.25'};
    const html = renderCell(row.peso, 'display', 'peso', row, opts);
    assert.match(html, /Peso da última importação/);
    assert.match(html, /1,25/);
    assert.ok(!html.includes('data-weight-url'));
    assert.ok(!html.includes('data-weight-edit'));
    assert.ok(!html.includes('<form'));
    assert.equal(renderCell(row.peso, 'sort', 'peso', row, opts), '1.25');
    assert.match(renderCell('<img src=x>', 'display', 'peso', row, opts), /&lt;img src=x&gt;/);
    assert.match(renderCell(null, 'display', 'peso', row, opts), /—/);
});
