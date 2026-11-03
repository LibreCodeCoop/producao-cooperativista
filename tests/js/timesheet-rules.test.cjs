// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    normalizedId, importedSeconds, customerTotals, optionsFor,
    requestedFilters, filteredUrl, applyGridFilters,
} = require('../../public/js/timesheet-report.js');

for (const [name, value, allowZero, result] of [
    ['valid member ID', '17', false, '17'],
    ['valid numeric ID', 17, false, '17'],
    ['unlinked customer 0', '0', true, '0'],
    ['member zero invalid', '0', false, null],
    ['negative invalid', '-1', true, null],
    ['leading zero invalid', '017', true, null],
    ['unsafe 2^53 overflow invalid', '9007199254740993', true, null],
    ['regular expression injection invalid', '1|11', false, null],
    ['decimal invalid', '1.2', true, null],
    ['missing null invalid', null, true, null],
    ['hexadecimal invalid', '0x11', true, null],
]) {
    test(`timesheet numeric identity: ${name}`, () => {
        assert.equal(normalizedId(value, allowZero), result);
    });
}

for (const [name, input, result] of [
    ['zero is recorded', 0, 0],
    ['legitimate integer', 3601, 3601],
    ['numeric import string', '90', 90],
    ['missing null', null, null],
    ['empty string', '', null],
    ['false is not zero', false, null],
    ['negative duration', -4, null],
    ['fractional seconds', 1.5, null],
    ['infinite seconds', Infinity, null],
    ['hex numeric string', '0x10', null],
    ['exponential source string', '1e2', null],
    ['unsafe integer', '9007199254740993', null],
]) {
    test(`imported seconds: ${name}`, () => {
        assert.equal(importedSeconds(input), result);
    });
}

test('invalid imported timesheet durations are reported but not summed as zero hours', () => {
    const data = [
        {customer_id:21, cliente:'Cliente A',duracao_segundos:0},
        {customer_id:21, cliente:'Cliente A',duracao_segundos:3600},
        {customer_id:21, cliente:'Cliente A',duracao_segundos:null},
        {customer_id:22, cliente:'Cliente B',duracao_segundos:-10},
        {customer_id:null, cliente:'Sem cliente',duracao_segundos:'0x10'},
    ];
    const summary = customerTotals(data);
    assert.equal(summary.entries,5);
    assert.equal(summary.seconds,3600);
    assert.equal(summary.missingDurations,3);
    assert.deepEqual(summary.customers.map(c=>[c.id,c.entries,c.seconds,c.missingDurations]),[
        ['21',3,3600,1], ['22',1,0,1], ['0',1,0,1],
    ]);
    assert.deepEqual(optionsFor(data, 'customer_id', 'cliente'), [
        ['21','Cliente A'], ['22','Cliente B'],
    ]);
});

test('unlinked customer 0 stays visible as a distinct customer option', () => {
    assert.deepEqual(optionsFor([{customer_id:0,cliente:'Sem cliente'}], 'customer_id', 'cliente'),
        [['0','Sem cliente']]);
    assert.deepEqual(optionsFor([{member_id:0,nome:'Inválido'}], 'member_id', 'nome'), []);
});

test('invalid ID filter cannot broaden regex search into 1 or 11', () => {
    const patterns=[];
    const grid={column(i){return {search(term){patterns.push([i,term]);}};}};
    applyGridFilters(grid,'1|11','9007199254740993');
    assert.deepEqual(patterns, [[10,'(?!)'],[11,'(?!)']]);
    assert.equal(new RegExp(patterns[0][1]).test('1'),false);
    assert.equal(new RegExp(patterns[0][1]).test('11'),false);
});

test('safe numeric filter IDs are exact and survive browser query round-trip', () => {
    const patterns=[];
    const grid={column(i){return {search(term){patterns.push([i,term]);}};}};
    applyGridFilters(grid,'1','0');
    assert.deepEqual(patterns, [[10,'^1$'],[11,'^0$']]);
    assert.deepEqual(requestedFilters('https://host.test/relatorios?cooperado=9007199254740993&cliente=01'),
        {memberId:'',customerId:''});
    const href=filteredUrl('https://host.test/relatorios?dtpage=4&ano-mes=2026-10',
        {memberId:'1|11',customerId:'0'});
    assert.equal(href,'/relatorios?ano-mes=2026-10&cliente=0');
});
