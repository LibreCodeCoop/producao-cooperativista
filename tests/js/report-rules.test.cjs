// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    numericValue, advanceTotal, financialFacts, workEntries,
    renderCell, productionDetails, pendingInvoiceSummary,
    loadReportData, sourceFallbackMessage,
} = require('../../public/js/report-table.js');

for (const [label, value, expected] of [
    ['real zero', 0, 0], ['zero string', '0.00', 0],
    ['decimal string', '26.66', 26.66], ['negative refund', -8.25, -8.25],
    ['missing null', null, null], ['missing undefined', undefined, null],
    ['empty string', '', null], ['whitespace', '   ', null],
    ['boolean false is not zero', false, null], ['boolean true is not one', true, null],
    ['infinity string', 'Infinity', null], ['not a number', 'NaN', null],
    ['invalid object', {}, null],
    ['hexadecimal string is not money', '0x10', null],
    ['array is not a monetary amount', [], null],
    ['scientific notation from JSON', '2.5e2', 250],
]) {
    test(`financial numeric contract: ${label}`, () => {
        assert.equal(numericValue(value), expected);
    });
}

for (const [name, list, expected] of [
    ['empty advances are zero', [], 0],
    ['valid advances are summed without rounding individual values',
        [{amount:25.10}, {amount:12.50}], 37.6],
    ['JSON from PHP source', '[{"amount":40},{"amount":"12.5"}]', 52.5],
    ['negative adjustment', [{amount:-10},{amount:10}], 0],
    ['missing amounts are not zero', [{amount:10},{}], null],
    ['null is unknown', [{amount:null}], null],
    ['invalid amount is unknown', [{amount:'NaN'}], null],
    ['invalid JSON is unknown', '{wrong', null],
    ['non-array object is unknown', {amount:25}, null],
    ['missing JSON payload', null, null],
]) {
    test(`advances: ${name}`, () => {
        assert.equal(advanceTotal(list), expected);
    });
}

test('financial composition distinguishes source-only, missing and legitimate zero amounts', () => {
    const record = {base_producao:0,bruto:null,liquido:'250.00',adiantamento:'[{"amount":5}]',
        explanations:{liquido:{detail:'Líquido conferido',summary:'Sem redistribuição'}}};
    const facts = financialFacts(record);
    assert.deepEqual(facts.map(row => row.key), ['base_producao','adiantamento','liquido']);
    assert.deepEqual(facts.map(row => row.value), [0,5,250]);
    assert.equal(facts.at(-1).explanation.detail, 'Líquido conferido');
    const raw = financialFacts(record, true);
    assert.equal(raw.length, 10);
    assert.ok(raw.every(row => row.value === null && row.explanation === null));
    assert.match(productionDetails(record, {sourceOnly:true}), /A apurar/);
});

for (const [name, input, duration, percent] of [
    ['valid client hours', {nome:'Cliente A',trabalhado:'1:00',total_cliente:3,percentual_trabalhado:33.33},
        '3:00:00', '33,33%'],
    ['missing hours are not 0:00:00', {nome:'Cliente B',total_cliente:null,percentual_trabalhado:null},
        '—','—'],
    ['genuine zero is 0:00:00', {nome:'Cliente C',total_cliente:0,percentual_trabalhado:0},
        '0:00:00','0%'],
    ['invalid hours', {nome:'Cliente D',total_cliente:'bad',percentual_trabalhado:'NaN'},
        '—','—'],
]) {
    test(`work detail rendering: ${name}`, () => {
        const result = workEntries(JSON.stringify([input]));
        assert.equal(result[0].clientDuration, duration);
        assert.equal(result[0].participation, percent);
        assert.equal(result[0].name, input.nome);
    });
}

test('client names and financial notes are escaped, not parsed as markup', () => {
    const html = productionDetails({
        trabalhado: JSON.stringify([{nome:'<script>malicious</script>', total_cliente:0}]),
        liquido:0, explanations:{liquido:{detail:'<img onerror=alert(1)>',attention:true}},
    });
    assert.ok(!html.includes('<script>'));
    assert.match(html, /&lt;script&gt;/);
    assert.ok(!html.includes('<img'));
    assert.match(html, /&lt;img/);
    assert.match(html, /is-warning/);
});

for (const [name, val, expected] of [
    ['missing', null, ''], ['blank', '', ''], ['false is not zero', false, ''],
    ['valid zero', '0', 'R$'], ['decimal cents', 26.659999999999997, '26,66'],
    ['invalid numeric', 'invalid', ''],
]) {
    test(`capital-social summary amount: ${name}`, () => {
        const display = renderCell(val,'display','integralizado',{}, {capitalSocialSummary:true});
        assert.ok(display.includes(expected));
        if (expected === '') assert.equal(display,'');
    });
}

test('source-only production values never leak zero into exports', () => {
    for (const key of ['base_producao','bruto','liquido','inss','irpf']) {
        assert.equal(renderCell(0,'export',key,{}, {sourceOnly:true,productionMode:true}), '');
        assert.match(renderCell(0,'display',key,{}, {sourceOnly:true,productionMode:true}),/A apurar/);
    }
});

for (const [name, data, expected] of [
    ['known summary', {data:[{status_documento:'draft'},{status_documento:'sent'}],
        metadata:{total:2,amount:100,truncated:false}}, /2 faturas.*R\$.*100,00.*rascunho.*enviada/],
    ['truncated queue', {data:[{status_documento:'sent'}],
        metadata:{total:250,amount:100000,truncated:true}}, /Lista limitada às primeiras 100/],
    ['missing amount', {data:[],metadata:{total:0,amount:null}}, /Confira a situação/],
    ['invalid count', {data:[],metadata:{total:-1,amount:0}}, /Confira a situação/],
]) {
    test(`invoice summary business contract: ${name}`, () => {
        assert.match(pendingInvoiceSummary(data), expected);
    });
}

function requestResponse(ok, status, data) {
    return {ok, status, json:async()=>data};
}
function descriptor(overrides={}) {
    return {dataset:{url:'/api/v1/producao?ano-mes=2026-09',
        sourceUrl:'/api/v1/producao/fontes?ano-mes=2026-09',
        productionMode:'yes',competencia:'2026-09',...overrides}};
}

test('report loading does not perform HTTP when payload is supplied by controller', async () => {
    const provided = {data:[{id:1}]};
    let calls = 0;
    const result = await loadReportData(descriptor(),async()=>{calls++;},provided);
    assert.deepEqual(result, {payload:provided,sourceOnly:false,fallback:false});
    assert.equal(calls,0);
});

test('economic base failure falls back to source-only data without enabling publication', async () => {
    const attempts=[];
    const data = {data:[{kimai_user_id:5,liquido:null}],publication_allowed:false,
        competence:'2026-09',financial_month:'2026-10',hours:{entries:10,members:1}};
    const request = async (url,options)=>{
        attempts.push({url,options});
        return attempts.length===1 ? requestResponse(false,422,{code:'BASE_ECONOMICA'}) :
            requestResponse(true,200,data);
    };
    const result = await loadReportData(descriptor(),request);
    assert.deepEqual(result,{payload:data,sourceOnly:true,fallback:true});
    assert.equal(attempts.length,2);
    assert.equal(attempts[0].options.credentials,'same-origin');
    assert.equal(attempts[1].options.cache,'no-store');
    assert.match(sourceFallbackMessage(data), /A apurar/);
});

for (const [name, source] of [
    ['source attempts publication', {data:[],competence:'2026-09',publication_allowed:true}],
    ['stale competence', {data:[],competence:'2026-08',publication_allowed:false}],
]) {
    test(`source-only fallback fails closed: ${name}`, async () => {
        let n=0;
        await assert.rejects(loadReportData(descriptor(),async()=>++n===1
            ? requestResponse(false,422,{code:'BASE_ECONOMICA'})
            : requestResponse(true,200,source)), /competência diferente/);
        assert.equal(n,2);
    });
}

test('non-economic API failure never falls back to source review', async () => {
    let n=0;
    await assert.rejects(loadReportData(descriptor(),async()=>{
        n++;
        return requestResponse(false,503,{error:{message:'Sistema indisponível'},request_id:'ref-123'});
    }), /Sistema indisponível.*Identificador da falha: ref-123/s);
    assert.equal(n,1);
});
