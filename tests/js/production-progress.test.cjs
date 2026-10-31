// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { SseParser, addMessage, submit, updateView, bind } = require('../../public/js/production-progress.js');

function node(tagName = 'div') {
    const listeners = new Map();
    return {
        tagName,
        textContent: '',
        className: '',
        children: [],
        disabled: false,
        addEventListener(event, callback) { listeners.set(event, callback); },
        emit(event, payload) { listeners.get(event)?.(payload); },
        appendChild(child) { this.children.push(child); return child; },
        scrollIntoView() { this.scrolled = true; },
    };
}

function setup({ publish = false, download = false, confirmed = true, fetch, action = '/acoes/do-make-producao' } = {}) {
    const doc = { createElement: node };
    const form = { ...node('form'), action, ownerDocument: doc };
    const messages = { ...node(), ownerDocument: doc };
    const button = node('button');
    const formData = {
        get(name) {
            if (name === 'year') return '2026';
            if (name === 'month') return '08';
            if (name === 'baixar_dados') return download ? '1' : '0';
            return name === 'atualiza_producao' && publish ? '1' : '0';
        },
    };
    const calls = { confirmation: 0, fetch: 0 };
    const request = fetch || (async () => response('event: done\ndata: "Concluído"\n\n'));
    const window = {
        confirm() { calls.confirmation++; return confirmed; },
    };
    const options = {
        window,
        document: doc,
        createFormData() { return formData; },
        now: () => new Date('2026-10-26T12:00:00Z'),
        async fetch(url, settings) {
            calls.fetch++;
            calls.request = { url, settings };
            return request(url, settings);
        },
    };
    return { doc, form, messages, button, formData, calls, options };
}

function response(text, status = 200, fragments = null) {
    const encoded = new TextEncoder().encode(text);
    const chunks = fragments || [encoded];
    let position = 0;
    return {
        ok: status >= 200 && status < 300,
        status,
        body: {
            getReader() {
                return {
                    async read() {
                        return position < chunks.length ?
                            { done: false, value: chunks[position++] } : { done: true };
                    },
                    releaseLock() { this.released = true; },
                };
            },
        },
    };
}

function alerts(messages) {
    return messages.children.filter(child => child.tagName === 'div')
        .map(child => ({ className: child.className, text: child.textContent }));
}

test('SSE events can be separated across partial chunks', () => {
    const events = [];
    const parser = new SseParser((event, data) => events.push([event, data]));
    parser.feed('event: info\ndata: "Início');
    assert.equal(events.length, 0);
    parser.feed('"\n\nevent: done\ndata: "Final"\n\n');
    assert.deepEqual(events, [['info', 'Início'], ['done', 'Final']]);
});

test('CRLF, comments, multiline data, and BOM are supported', () => {
    const events = [];
    const parser = new SseParser((event, data) => events.push([event, data]));
    parser.feed('\uFEFF: heartbeat\r\nevent: warning\r\ndata: primeira\r\ndata: segunda\r\n\r\n');
    assert.deepEqual(events, [['warning', 'primeira\nsegunda']]);
});

test('malformed JSON data stays text and events without data are ignored', () => {
    const events = [];
    const parser = new SseParser((event, data) => events.push([event, data]));
    parser.feed('event: info\n\nevent: alert\ndata: {bad json}\n\n');
    assert.deepEqual(events, [['alert', '{bad json}']]);
});

test('unbounded pending SSE event is rejected', () => {
    const parser = new SseParser(() => {});
    assert.throws(() => parser.feed('x'.repeat(1024 * 1024 + 1)), /excessivamente grande/);
});

test('alerts are added using textContent, never user-supplied HTML', () => {
    const doc = { createElement: node };
    const messages = { ...node(), ownerDocument: doc };
    const malicious = '<img src=x onerror=alert(1)>';
    const item = addMessage(messages, 'warning', malicious);
    assert.equal(item.textContent, malicious);
    assert.equal(item.className, 'alert warning');
    assert.equal(item.scrolled, true);
    assert.equal(item.innerHTML, undefined);
});

test('preview sends only one POST with SSE headers and never asks to confirm publication', async () => {
    const s = setup();
    assert.equal(await submit(s.form, s.messages, s.button, s.options), true);
    assert.equal(s.calls.confirmation, 0);
    assert.equal(s.calls.fetch, 1);
    assert.deepEqual(s.calls.request, {
        url: '/acoes/do-make-producao',
        settings: {
            method: 'POST', credentials: 'same-origin', body: s.formData,
            headers: { Accept: 'text/event-stream' }, cache: 'no-store',
        },
    });
    assert.equal(s.button.disabled, false);
    assert.deepEqual(alerts(s.messages), [{ className: 'alert success', text: 'Concluído' }]);
    assert.match(s.messages.children[0].textContent, /^Execução iniciada em/);
});

test('publishing requires explicit confirmation; declining performs no POST', async () => {
    const s = setup({ publish: true, confirmed: false });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
    assert.equal(s.calls.confirmation, 1);
    assert.equal(s.calls.fetch, 0);
    assert.deepEqual(s.messages.children, []);
    assert.equal(s.button.disabled, false);
});

test('publishing after confirmation produces a single request', async () => {
    const s = setup({ publish: true });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), true);
    assert.equal(s.calls.confirmation, 1);
    assert.equal(s.calls.fetch, 1);
});

test('a second submission is blocked until first SSE request finishes', async () => {
    let resolve;
    const pending = new Promise(done => { resolve = done; });
    const s = setup({ fetch: () => pending });
    const first = submit(s.form, s.messages, s.button, s.options);
    assert.equal(s.button.disabled, true);
    assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
    assert.equal(s.calls.fetch, 1);
    resolve(response('event: done\ndata: "OK"\n\n'));
    assert.equal(await first, true);
    assert.equal(s.button.disabled, false);
});

test('SSE warning and info retain order, failed is not a success', async () => {
    const s = setup({ fetch: () => response(
        'event: info\ndata: "Validando"\n\nevent: warning\ndata: "Incompleto"\n\n' +
        'event: failed\ndata: "Execução 123 com falha"\n\nevent: done\ndata: "NÃO"\n\n'
    ) });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
    assert.deepEqual(alerts(s.messages), [
        { className: 'alert info', text: 'Validando' },
        { className: 'alert warning', text: 'Incompleto' },
        { className: 'alert', text: 'Execução 123 com falha' },
    ]);
});

test('chunked Unicode SSE stream is decoded correctly across UTF-8 boundaries', async () => {
    const bytes = new TextEncoder().encode('event: done\ndata: "Produção concluída"\n\n');
    const index = bytes.findIndex(byte => byte >= 128);
    const s = setup({ fetch: () => response('', 200, [
        bytes.slice(0, index + 1), bytes.slice(index + 1, index + 2), bytes.slice(index + 2),
    ]) });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), true);
    assert.equal(alerts(s.messages)[0].text, 'Produção concluída');
});

for (const [status, fragment] of [
    [403, 'Acesso negado'],
    [401, 'HTTP 401'],
    [500, 'HTTP 500'],
]) {
    test('HTTP ' + status + ' presents actionable feedback and unlocks form', async () => {
        const s = setup({ fetch: () => response('', status) });
        assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
        assert.match(alerts(s.messages)[0].text, new RegExp(fragment));
        assert.equal(s.button.disabled, false);
    });
}

test('missing response stream is reported, not considered successful', async () => {
    const s = setup({ fetch: () => ({ ok: true, body: null }) });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
    assert.match(alerts(s.messages)[0].text, /fluxo de progresso/);
});

test('interrupted stream without terminal event warns about possible financial writes', async () => {
    const s = setup({ fetch: () => response('event: info\ndata: "Calculando"\n\n') });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
    assert.match(alerts(s.messages).at(-1).text, /podem existir documentos gravados/);
    assert.equal(s.button.disabled, false);
});

test('network errors unlock the button and display a message', async () => {
    const s = setup({ fetch: async () => { throw new Error('Conexão perdida'); } });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
    assert.equal(alerts(s.messages)[0].text, 'Conexão perdida');
    assert.equal(s.button.disabled, false);
});

test('bind is safe without a form and attaches a submit listener when available', () => {
    assert.equal(bind({ getElementById: () => null }), false);
    const s = setup();
    const elements = { 'form-gera-producao': s.form, messages: s.messages, submit: s.button };
    const doc = { getElementById: key => elements[key] };
    assert.equal(bind(doc, s.options), true);
    let prevented = false;
    s.form.emit('submit', { preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
});

test('import and publication cannot be sent together even if the UI is bypassed', async () => {
    const s = setup({ publish: true, download: true });
    assert.equal(await submit(s.form, s.messages, s.button, s.options), false);
    assert.equal(s.calls.fetch, 0);
    assert.equal(s.calls.confirmation, 0);
    assert.match(alerts(s.messages)[0].text, /operações separadas/);
});

test('workflow selection keeps the chosen month in review links and separates import from publishing', () => {
    const radios = {
        baixar_dados: [
            { name: 'baixar_dados', value: '0', checked: false },
            { name: 'baixar_dados', value: '1', checked: true },
        ],
        atualiza_producao: [
            { name: 'atualiza_producao', value: '0', checked: false },
            { name: 'atualiza_producao', value: '1', checked: true },
        ],
    };
    const form = {
        querySelector(selector) {
            const name = selector.match(/name="([^"]+)"/)?.[1];
            const value = selector.match(/value="([^"]+)"/)?.[1];
            return radios[name]?.find(input => value ? input.value === value : input.checked);
        },
    };
    const links = ['/relatorios/producao', '/relatorios/calculos', '/relatorios/invoices'].map(path => ({
        getAttribute() { return path; },
        href: '',
    }));
    const warning = { hidden: true };
    const hint = { textContent: '' };
    const button = { textContent: '', disabled: false };
    const controls = {
        year: { value: '2026' }, month: { value: '09' },
        'publication-warning': warning, 'production-action-hint': hint, submit: button,
    };
    const doc = {
        getElementById(id) { return controls[id]; },
        querySelectorAll() { return links; },
    };

    updateView(form, doc, 'atualiza_producao');
    assert.equal(radios.baixar_dados[0].checked, true);
    assert.equal(warning.hidden, false);
    assert.equal(button.textContent, 'Publicar documentos no Akaunting');
    assert.deepEqual(links.map(link => link.href), [
        '/relatorios/producao?ano-mes=2026-09', '/relatorios/calculos?ano-mes=2026-09',
        '/relatorios/invoices?ano-mes=2026-09',
    ]);

    radios.baixar_dados[0].checked = false;
    radios.baixar_dados[1].checked = true;
    updateView(form, doc, 'baixar_dados');
    assert.equal(radios.atualiza_producao[0].checked, true);
    assert.equal(warning.hidden, true);
    assert.equal(button.textContent, 'Calcular prévia');
});
