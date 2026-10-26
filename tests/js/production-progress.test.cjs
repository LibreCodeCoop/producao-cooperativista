'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { SseParser } = require('../../public/js/production-progress.js');

test('SSE events can be separated across partial byte chunks', () => {
    const events = [];
    const parser = new SseParser((event, data) => events.push([event, data]));
    parser.feed('event: info\ndata: "Início');
    assert.equal(events.length, 0);
    parser.feed('"\n\nevent: done\ndata: "Final"\n\n');
    assert.deepEqual(events, [['info', 'Início'], ['done', 'Final']]);
});

test('CRLF events and unknown levels do not corrupt the stream', () => {
    const events = [];
    const parser = new SseParser((event, data) => events.push([event, data]));
    parser.feed('event: warning\r\ndata: "Atenção"\r\n\r\n');
    assert.deepEqual(events, [['warning', 'Atenção']]);
});

test('a failed event remains distinguishable from a done event', () => {
    const events = [];
    const parser = new SseParser((event, data) => events.push([event, data]));
    parser.feed('event: failed\ndata: "Erro"\n\n');
    assert.deepEqual(events, [['failed', 'Erro']]);
});
