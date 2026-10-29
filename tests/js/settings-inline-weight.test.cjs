// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {initialize, validWeight, displayWeight} = require('../../public/js/settings-inline-weight.js');

function fixture({rows = 1, getResult, saveResult} = {}) {
    const tableHandlers = new Map();
    const drawHandlers = new Map();
    const cells = [];
    const httpCalls = [];

    function createCell(id) {
        const value = {textContent: 'Consultando…'};
        const message = {textContent: ''};
        const edit = {
            disabled: true, hidden: false, textContent: 'Editar',
            matches(selector) { return selector === '[data-weight-edit]'; },
        };
        const input = {value: '', focused: false, focus() { this.focused = true; }};
        const expected = {value: ''};
        const submit = {disabled: false};
        const form = {
            hidden: true,
            action: 'https://example.test/configuracao/cooperado/' + id + '/nextcloud',
            querySelector(selector) {
                return {'[name="value"]': input, '[name="expected"]': expected, '[type="submit"]': submit}[selector] ?? null;
            },
            closest(selector) { return selector === '[data-weight-url]' ? cell : null; },
        };
        const cancel = {
            matches(selector) { return selector === '[data-weight-cancel]'; },
        };
        const parts = {
            '[data-weight-current]': value, '[data-weight-edit]': edit,
            '[data-weight-form]': form, '[data-weight-message]': message,
        };
        const cell = {
            dataset: {weightUrl: 'https://example.test/configuracao/cooperado/' + id + '/peso'},
            querySelector(selector) { return parts[selector] ?? null; },
        };
        edit.closest = selector => selector === '[data-weight-edit], [data-weight-cancel]'
            ? edit : (selector === '[data-weight-url]' ? cell : null);
        cancel.closest = selector => selector === '[data-weight-edit], [data-weight-cancel]'
            ? cancel : (selector === '[data-weight-url]' ? cell : null);
        const row = {querySelector(selector) { return selector === '[data-weight-url]' ? cell : null; }};
        return {cell, value, message, edit, form, input, expected, submit, cancel, row};
    }

    for (let n = 0; n < rows; n++) cells.push(createCell(n + 1));
    const api = {
        on(name, callback) { drawHandlers.set(name, callback); },
        rows() {
            return {nodes: () => ({toArray: () => visibleRows.map(cell => cell.row)})};
        },
    };
    let visibleRows = cells.slice(0, Math.min(1, cells.length));
    const table = {
        dataset: {},
        addEventListener(name, callback) { tableHandlers.set(name, callback); },
        emitClick(target) {
            tableHandlers.get('click')?.({target, preventDefault() {}});
        },
        emitSubmit(form) {
            let prevented = false;
            tableHandlers.get('submit')?.({
                target: {...form, closest: selector => selector === '[data-weight-form]' ? form : null},
                preventDefault() { prevented = true; },
            });
            return prevented;
        },
    };

    const fetch = async (url, options) => {
        httpCalls.push({url, options});
        const isPost = options.method === 'POST';
        const outcome = isPost ? saveResult : getResult;
        const data = typeof outcome === 'function' ? outcome({url, options}) : outcome;
        return {
            ok: data?.ok ?? true,
            status: data?.status ?? 200,
            async json() {
                return data?.body ?? (isPost
                    ? {value: '2.75', expected: 'hash2', message: 'Salvo no Nextcloud. Reimporte.'}
                    : {value: '1.5', expected: 'hash1'});
            },
        };
    };
    const formData = form => new URLSearchParams([
        ['_token', 'valid-csrf'],
        ['field', 'weight'],
        ['expected', form.querySelector('[name="expected"]').value],
        ['competencia', '2026-08'],
        ['value', form.querySelector('[name="value"]').value],
    ]);
    const options = {fetch, formData};
    const instance = initialize(table, api, options);
    const settle = async () => {
        for (let n = 0; n < 4; n++) await new Promise(resolve => setImmediate(resolve));
    };
    return {table, api, cells, httpCalls, instance, settle, nextVisible(rows) {
        visibleRows = rows.map(i => cells[i]);
        drawHandlers.get('draw.dt')?.();
    }};
}

test('weight input accepts Brazilian decimals but rejects invalid precision and range', () => {
    for (const value of ['1', '2,75', '999.9999', '0,0001']) {
        assert.equal(validWeight(value), true, value);
    }
    for (const value of ['', '0', '0,0', '-1', '1e3', '1.00000', '1000', 'x', '1..2']) {
        assert.equal(validWeight(value), false, value);
    }
    assert.equal(displayWeight('1.75'), '1,75');
    assert.equal(displayWeight(''), 'Não definido');
});

test('weight is read only for visible rows; pagination loads additional rows on demand', async () => {
    const f = fixture({rows: 3});
    await f.settle();
    assert.equal(f.httpCalls.length, 1);
    assert.ok(f.httpCalls[0].url.endsWith('/cooperado/1/peso'));
    assert.equal(f.cells[0].value.textContent, '1,5');
    assert.equal(f.cells[1].value.textContent, 'Consultando…');
    f.nextVisible([1, 2]);
    await f.settle();
    assert.equal(f.httpCalls.length, 3);
    assert.equal(f.cells[1].edit.disabled, false);
    f.nextVisible([0, 1]);
    await f.settle();
    assert.equal(f.httpCalls.length, 3, 'Avoid repeating lookups when returning to a page');
});

test('inline edit uses the live value, explicit submit, csrf and optimistic revision', async () => {
    const f = fixture();
    await f.settle();
    const row = f.cells[0];
    f.table.emitClick(row.edit);
    await f.settle();
    assert.equal(row.form.hidden, false);
    assert.equal(row.edit.hidden, true);
    assert.equal(row.input.value, '1.5');
    assert.equal(row.expected.value, 'hash1');
    assert.equal(f.httpCalls.length, 1, 'Opening editor should not perform a write');
    row.input.value = '2,75';
    assert.equal(f.table.emitSubmit(row.form), true);
    await f.settle();

    assert.equal(f.httpCalls.length, 2);
    const post = f.httpCalls[1];
    assert.equal(post.options.method, 'POST');
    assert.equal(post.options.headers.Accept, 'application/json');
    assert.equal(post.options.credentials, 'same-origin');
    const params = new URLSearchParams(post.options.body);
    assert.deepEqual(
        [params.get('field'), params.get('expected'), params.get('_token'), params.get('value')],
        ['weight', 'hash1', 'valid-csrf', '2,75'],
    );
    assert.equal(row.form.hidden, true);
    assert.equal(row.edit.hidden, false);
    assert.equal(row.value.textContent, '2,75');
    assert.match(row.message.textContent, /Reimporte/);
    assert.equal(row.cell.dataset.weightExpected, 'hash2');
});

test('cancel does not save, and malformed input never sends a request', async () => {
    const f = fixture();
    await f.settle();
    const row = f.cells[0];
    f.table.emitClick(row.edit);
    await f.settle();
    row.input.value = '2.000000';
    f.table.emitSubmit(row.form);
    await f.settle();
    assert.equal(f.httpCalls.length, 1);
    assert.match(row.message.textContent, /até 999/);
    f.table.emitClick(row.cancel);
    assert.equal(row.form.hidden, true);
    assert.equal(row.edit.hidden, false);
    assert.equal(f.httpCalls.length, 1);
});

test('stale concurrent update returns 409 and requires refreshing before retry', async () => {
    const f = fixture({saveResult: {ok: false, status: 409, body: {error: 'Peso alterado. Reabra a edição.'}}});
    await f.settle();
    const row = f.cells[0];
    f.table.emitClick(row.edit);
    await f.settle();
    row.input.value = '3';
    f.table.emitSubmit(row.form);
    await f.settle();
    assert.equal(row.form.hidden, true);
    assert.equal(row.cell.dataset.weightLoaded, 'failed');
    assert.equal(row.edit.textContent, 'Atualizar valor');
    assert.match(row.message.textContent, /Reabra a edição/);
    f.table.emitClick(row.edit);
    await f.settle();
    assert.equal(f.httpCalls.length, 3, 'Refreshes live value, never submits stale revision');
    assert.equal(row.form.hidden, false);
});

test('missing Nextcloud link reports an error without enabling save', async () => {
    const f = fixture({getResult: {ok: false, status: 404, body: {error: 'Conta Nextcloud não vinculada.'}}});
    await f.settle();
    const row = f.cells[0];
    assert.equal(row.value.textContent, 'Indisponível');
    assert.equal(row.form.hidden, true);
    assert.match(row.message.textContent, /não vinculada/);
    assert.equal(row.edit.textContent, 'Tentar novamente');
    assert.equal(f.httpCalls.length, 1);
});

test('does not initialize more than once on same DataTable', async () => {
    const f = fixture();
    await f.settle();
    const repeated = initialize(f.table, f.api, {fetch: async () => {throw Error('Should not refetch');}});
    assert.equal(repeated, null);
});
