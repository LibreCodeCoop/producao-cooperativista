// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {duration, customerTotals, optionsFor, requestedFilters, filteredUrl, applyGridFilters, clearGridFilters, initialize} = require('../../public/js/timesheet-report.js');
const {buildTimesheetColumns, productionDetails, buildProductionColumns} = require('../../public/js/report-table.js');

const rows = [
    {member_id:1, nome:'Cooperada A', customer_id:21, cliente:'Cliente A', duracao_segundos:3600},
    {member_id:11, nome:'Cooperado B', customer_id:21, cliente:'Cliente A', duracao_segundos:59},
    {member_id:1, nome:'Cooperada A', customer_id:22, cliente:'Cliente B', duracao_segundos:185},
    {member_id:1, nome:'Cooperada A', customer_id:22, cliente:'Cliente B', duracao_segundos:null},
];

test('totalizers use integer seconds, all filtered entries and distinct customer identifiers', () => {
    const all = customerTotals(rows);
    assert.equal(all.entries, 4);
    assert.equal(all.seconds, 3844);
    assert.equal(all.missingDurations, 1);
    assert.deepEqual(all.customers.map(c => [c.id, c.entries, c.seconds]), [
        ['21', 2, 3659], ['22', 2, 185],
    ]);
    const oneCooperator = customerTotals(rows.filter(row => row.member_id === 1));
    assert.equal(oneCooperator.entries, 3);
    assert.equal(oneCooperator.seconds, 3785);
    assert.equal(duration(3785), '1:03:05');
    assert.equal(duration(900 * 3600), '900:00:00');
    assert.equal(duration(-5), '—');
});

test('member options keep IDs distinct and never merge identical customer names', () => {
    assert.deepEqual(optionsFor(rows, 'member_id', 'nome'), [['1', 'Cooperada A'], ['11', 'Cooperado B']]);
    const same = [{customer_id: 5, cliente:'Cliente'}, {customer_id: 6, cliente:'Cliente'}];
    assert.equal(customerTotals(same).customers.length, 2);
    assert.equal(customerTotals(same).missingDurations, 2);
});

test('detailed columns escape descriptions, preserve numeric order and link only trusted Kimai IDs', () => {
    const cols = buildTimesheetColumns({kimaiBase:'https://kimai.example.test/en/timesheet/'});
    assert.equal(cols.length, 12);
    assert.equal(cols[10].visible, false);
    assert.equal(cols[11].visible, false);
    assert.equal(cols[6].render(3661, 'display'), '1:01:01');
    assert.equal(cols[6].render(3661, 'sort'), 3661);
    assert.equal(cols[6].render(null, 'display'), '—');
    assert.match(cols[7].render('<img src=x onerror=alert(1)>', 'display'), /&lt;img/);
    assert.doesNotMatch(cols[7].render('<img src=x onerror=alert(1)>', 'display'), /<img/);
    assert.match(cols[9].render(82,'display'), /href="https:\/\/kimai.example.test\/en\/timesheet\/82\/edit"/);
    assert.equal(cols[9].render(-1,'display'), '—');
    const untrusted = buildTimesheetColumns({kimaiBase:'javascript:evil/en/timesheet/'});
    assert.doesNotMatch(untrusted[9].render(10,'display'), /href=/);
    const prod = buildProductionColumns({competence:'2026-09'});
    assert.match(prod[1].render(3600,'display',{kimai_user_id:1}), /cooperado=1/);
    assert.doesNotMatch(prod[1].render(3600,'display',{kimai_user_id:0}), /href=/);
    assert.match(productionDetails({kimai_user_id:1}, {competence:'2026-09'}), /Conferir todos os apontamentos/);
});

test('filter state is a pure function and rejects unsafe or malformed IDs', () => {
    const url = 'https://app.example.test/relatorios/apontamentos?ano-mes=2026-09&cooperado=1&cliente=0&dtpage=4#relatorio';
    assert.deepEqual(requestedFilters(url), {memberId:'1',customerId:'0'});
    assert.deepEqual(requestedFilters(url.replace('cooperado=1','cooperado=1|11').replace('cliente=0','cliente=-1')),
        {memberId:'',customerId:''});
    assert.equal(filteredUrl(url, {memberId:'11',customerId:'22'}),
        '/relatorios/apontamentos?ano-mes=2026-09&cooperado=11&cliente=22#relatorio');
    assert.equal(filteredUrl(url.replace('dtpage=4','dtpage=4&dtsearch=sensitive'), {}, true),
        '/relatorios/apontamentos?ano-mes=2026-09#relatorio');
});

test('DataTables filters target only member/customer columns and can be cleared independently', () => {
    const calls=[];
    const grid={
        search(value){calls.push(['global',value]);},
        column(i){return {search(...args){calls.push([i,...args]);}};},
    };
    applyGridFilters(grid,'1','22');
    assert.deepEqual(calls, [[10,'^1$',true,false],[11,'^22$',true,false]]);
    calls.length=0;
    clearGridFilters(grid);
    assert.deepEqual(calls[0],['global','']);
    assert.deepEqual(calls.slice(1).map(x=>x[0]),[0,1,2,3,4,5,6,7,8,9,10,11]);
});

test('interactive filters match exact numeric IDs and totals track all searched rows', async () => {
    function element(tag) {
        return {
            tag, value:'', textContent:'', children:[], listeners:{},
            replaceChildren(...children) {this.children=children;},
            addEventListener(type, cb) {this.listeners[type]=cb;},
            fire(type) {this.listeners[type]?.();},
        };
    }
    const table = element('table');
    table.dataset={url:'/api/v1/apontamentos?ano-mes=2026-09', competencia:'2026-09'};
    const member=element('select');
    const customer=element('select');
    const status=element('p');
    const body=element('tbody');
    const monthMember=element('input');
    const monthCustomer=element('input');
    const clear=element('button');
    const doc={
        createElement:element,
        querySelectorAll:()=>[],
        getElementById:key=>({example:table,'timesheet-member-filter':member,
            'timesheet-customer-filter':customer,'timesheet-month-member':monthMember,
            'timesheet-month-customer':monthCustomer,'timesheet-clear-filters':clear,
            'timesheet-summary':status,'timesheet-client-body':body})[key]||null,
    };
    const matches={10:'',11:''};
    const listeners={};
    const grid={
        column(index) {return {search(term){matches[index]=term; return this;}};},
        rows() {return {data() {return {toArray() {return rows.filter(row =>
            (!matches[10] || '^' + row.member_id + '$' === matches[10]) &&
            (!matches[11] || '^' + row.customer_id + '$' === matches[11]));}};}};},
        on(type,cb) {listeners[type]=cb;},
        draw() {listeners['draw.dt']?.();},
        search() {return this;},
    };
    const location=new URL('https://app.example.test/relatorios/apontamentos?ano-mes=2026-09&cooperado=1');
    const history={replaceState(_a,_b,url){location.href = new URL(url, location).href;}};
    const ret=await initialize(doc,{location,history,fetch:async()=>({ok:true,json:async()=>({competence:'2026-09', data:rows})}),
        report:{initializeReportTable:async()=>grid}});
    assert.equal(ret,grid);
    assert.equal(member.value,'1');
    assert.equal(matches[10],'^1$');
    assert.equal(status.textContent,'3 apontamentos filtrados · 1:03:05 horas registradas · 1 registro sem duração válida (não somado)');
    assert.equal(body.children.length,2);
    customer.value='22'; customer.fire('change');
    assert.equal(matches[11],'^22$');
    assert.equal(status.textContent,'2 apontamentos filtrados · 0:03:05 horas registradas · 1 registro sem duração válida (não somado)');
    assert.match(location.search,/cliente=22/);
    clear.fire('click');
    assert.equal(status.textContent,'4 apontamentos filtrados · 1:04:04 horas registradas · 1 registro sem duração válida (não somado)');
    assert.equal(location.searchParams.has('cooperado'),false);
    assert.equal(location.searchParams.has('cliente'),false);
});
