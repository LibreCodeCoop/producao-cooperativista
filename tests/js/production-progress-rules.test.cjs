// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {operationIntent, readExecutionStream, SseParser} = require('../../public/js/production-progress.js');

for (const [name, publish, importFlag, expected] of [
    ['preview without reimport', '0', '0', {publishing:false, conflicts:false}],
    ['explicit import', '0', '1', {publishing:false, conflicts:false}],
    ['explicit financial publication', '1', '0', {publishing:true, conflicts:false}],
    ['publishing and importing is prohibited', '1', '1', {publishing:true, conflicts:true}],
    ['missing flags cannot request a publication', null, null, {publishing:false, conflicts:false}],
    ['arbitrary values do not enable publication', 'yes', '1', {publishing:false, conflicts:false}],
]) {
    test(`execution intent: ${name}`, () => {
        assert.deepEqual(operationIntent(publish, importFlag), expected);
    });
}

function stream(chunks, error = null) {
    let index = 0;
    let released = false;
    return {
        body: {getReader() {return {
            async read() {
                if (error) throw error;
                return index === chunks.length ? {done:true} : {done:false,value:chunks[index++]};
            },
            releaseLock() {released = true;},
        };}},
        wasReleased: () => released,
    };
}

for (const [name, separator] of [
    ['LF', '\n'], ['CRLF', '\r\n'], ['CR', '\r'],
]) {
    test(`SSE parser recognizes ${name} boundary split at any character`, () => {
        const msg = `event: warning${separator}data: Atenção${separator}${separator}`;
        for (let split = 1; split < msg.length; split++) {
            const events = [];
            const parser = new SseParser((type,value)=>events.push([type,value]));
            parser.feed(msg.slice(0,split));
            parser.feed(msg.slice(split));
            assert.deepEqual(events,[['warning','Atenção']],`split=${split}`);
        }
    });
}

test('stream reader decodes UTF-8 across chunks and releases the reader', async () => {
    const encoded = new TextEncoder().encode('event: info\ndata: "Início"\n\nevent: done\ndata: "Concluído"\n\n');
    const packets = [...encoded].map(value=>Uint8Array.of(value));
    const response=stream(packets), events=[];
    await readExecutionStream(response,(type,value)=>events.push([type,value]));
    assert.deepEqual(events,[['info','Início'],['done','Concluído']]);
    assert.equal(response.wasReleased(),true);
});

test('stream failures release the reader even when parsing is interrupted', async () => {
    const error=new Error('Conexão encerrada');
    const response=stream([],error);
    await assert.rejects(readExecutionStream(response,()=>{}), /Conexão encerrada/);
    assert.equal(response.wasReleased(),true);
});

test('missing stream rejects before installing a parser', async () => {
    await assert.rejects(readExecutionStream({body:null},()=>{}), /fluxo de progresso/);
});
