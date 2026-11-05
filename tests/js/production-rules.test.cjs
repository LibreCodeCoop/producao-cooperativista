// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    validatePreview, validateSourceReview, nextFinancialMonth,
    selectedMembers, allocationSummary, availabilitySummary,
} = require('../../public/js/production-workflow.js');

const REVISION = 'b'.repeat(64);

function preview() {
    return {
        metadata: {competence: '2026-11', revision: REVISION},
        data: [
            {kimai_user_id: 7, name: 'Cooperada A', base_producao: 100.125, liquido: 73.255, hours_seconds: 3600},
            {kimai_user_id: 8, name: 'Cooperado B', base_producao: 90.115, liquido: 52.124, hours_seconds: 1800},
        ],
    };
}

function source() {
    return {
        competence: '2026-11', financial_month: '2026-12', publication_allowed: false,
        hours: {entries: 3, members: 2, seconds: 5400, by_member: [
            {id: 7, name: 'Cooperada A', entries: 2, seconds: 3600},
            {id: 8, name: 'Cooperado B', entries: 1, seconds: 1800},
        ]},
        data: [
            {kimai_user_id: 7, name: 'Cooperada A', hours_seconds: 3600, base_producao: null, bruto: null, liquido: null},
            {kimai_user_id: 8, name: 'Cooperado B', hours_seconds: 1800, base_producao: null, bruto: null, liquido: null},
        ],
        income_transactions: {amount: 1200, count: 1},
        client_cost_transactions: {amount: 450, by_customer: []},
        internal_expense_transactions: {amount: 50, by_category: []},
        unconfirmed_invoices: {count: 1, amount: 900, documents: [], truncated: true},
    };
}

// Table-driven cases are data providers: each independent business invariant
// gets its own name and failure instead of a single opaque loop assertion.
const invalidPreviews = [
    ['empty selection', p => {p.data = [];}],
    ['wrong competence', p => {p.metadata.competence = '2026-10';}],
    ['missing signed revision', p => {p.metadata.revision = null;}],
    ['duplicate user', p => {p.data[1].kimai_user_id = 7;}],
    ['non-safe Kimai ID', p => {p.data[1].kimai_user_id = '9007199254740993';}],
    ['hexadecimal Kimai ID', p => {p.data[1].kimai_user_id = '0x10';}],
    ['missing name', p => {p.data[0].name = ' ';}],
    ['missing monetary value', p => {p.data[0].liquido = null;}],
    ['blank amount is not zero', p => {p.data[0].base_producao = '';}],
    ['boolean is not a monetary number', p => {p.data[0].liquido = false;}],
    ['nonfinite value', p => {p.data[0].liquido = 'Infinity';}],
    ['missing duration is not zero', p => {p.data[0].hours_seconds = null;}],
    ['negative duration', p => {p.data[0].hours_seconds = -5;}],
    ['fractional duration', p => {p.data[0].hours_seconds = 1.5;}],
];
for (const [name, mutate] of invalidPreviews) {
    test(`financial preview fails closed: ${name}`, () => {
        const payload = preview();
        mutate(payload);
        assert.throws(() => validatePreview(payload, '2026-11'), /prévia/);
    });
}

test('numeric zero and string numbers from imported JSON are not classified as missing', () => {
    const payload = preview();
    payload.data[0].base_producao = 0;
    payload.data[1].liquido = '0.00';
    payload.data[0].hours_seconds = '0';
    const result = validatePreview(payload, '2026-11');
    assert.equal(result.rows[0].base, 0);
    assert.equal(result.rows[1].net, 0);
    assert.equal(result.rows[0].seconds, 0);
});

test('cents round per cooperado; excluding a member never redistributes to others', () => {
    const rows = validatePreview(preview(), '2026-11').rows;
    const amount = allocationSummary(rows, new Set([8, 999]));
    assert.deepEqual(amount.included.map(row => row.id), [7]);
    assert.deepEqual(amount.excluded.map(row => row.id), [8]);
    assert.equal(amount.includedBaseCents, 10013);
    assert.equal(amount.includedNetCents, 7326);
    assert.equal(amount.excludedBaseCents, 9012);
    assert.equal(amount.excludedNetCents, 5212);
    assert.deepEqual(rows.map(row => row.net), [73.255, 52.124]);
    assert.equal(selectedMembers(rows, [7, 8]).included.length, 0);
});

for (const [month, next] of [
    ['2026-01', '2026-02'], ['2026-11', '2026-12'], ['2026-12', '2027-01'],
    ['2026-00', null], ['2026-13', null], ['2026-9', null],
]) {
    test(`financial competence follows work ${month} -> ${next}`, () => {
        assert.equal(nextFinancialMonth(month), next);
    });
}

const invalidSource = [
    ['wrong financial month', p => {p.financial_month = '2027-01';}],
    ['cannot authorize publication from raw sources', p => {p.publication_allowed = true;}],
    ['missing source-only monetary data must stay null', p => {p.data[0].liquido = 0;}],
    ['missing income not zero', p => {p.income_transactions.amount = null;}],
    ['unknown internal expenses', p => {p.internal_expense_transactions.amount = '';}],
    ['duplicate member', p => {p.data[1].kimai_user_id = 7;}],
    ['negative hours', p => {p.data[0].hours_seconds = -60;}],
    ['missing hours', p => {p.data[0].hours_seconds = null;}],
    ['invalid invoice amount', p => {p.unconfirmed_invoices.amount = 'NaN';}],
    ['invalid invoice count', p => {p.unconfirmed_invoices.count = -1;}],
    ['malformed customer allocation', p => {p.client_cost_transactions.by_customer = {};}],
    ['member count mismatch', p => {p.hours.members = 3;}],
    ['work item hours disagree with summary', p => {p.hours.by_member[0].seconds = 3000;}],
    ['work entries disagree with summary', p => {p.hours.by_member[1].entries = 7;}],
    ['duplicate source member summary', p => {p.hours.by_member[1].id = 7;}],
    ['untrusted source member identifier', p => {p.hours.by_member[0].id = '0x7';}],
    ['invalid transaction grouping amount', p => {p.client_cost_transactions.by_customer = [{label:'A',amount:null}];}],
    ['invalid internal grouping amount', p => {p.internal_expense_transactions.by_category = [{label:'A',amount:'NaN'}];}],
    ['invalid invoice document', p => {p.unconfirmed_invoices.documents = [{id:0,amount:100}];}],
    ['hexadecimal count is not valid', p => {p.hours.entries = '0x10';}],
    ['more members than entries', p => {p.hours.entries = 1;}],
    ['not-a-count entries', p => {p.hours.entries = true;}],
];
for (const [name, mutate] of invalidSource) {
    test(`source-only review rejects: ${name}`, () => {
        const payload = source();
        mutate(payload);
        assert.throws(() => validateSourceReview(payload, '2026-11'), /fontes importadas/);
    });
}

test('valid source-only review preserves documents and never manufactures net withdrawal', () => {
    const payload = source();
    assert.equal(validateSourceReview(payload, '2026-11'), payload);
    assert.equal(payload.data[0].liquido, null);
    assert.equal(payload.publication_allowed, false);
});

const availabilityCases = [
    ['failed lookup', null, false, 'unavailable', /Não foi possível verificar/],
    ['empty imported staging', {has_work_data: false, has_staging_data: false}, false, 'empty', /Base importada vazia/],
    ['other month only', {has_work_data: false, has_staging_data: true,
        latest_imported_competence: '2026-10'}, false, 'other', /Última competência com horas: 2026-10/],
    ['reviewable month', {has_work_data: true, has_staging_data: true,
        work_entries: 17, work_members: 2}, false, 'available', /17 apontamentos de 2 cooperados/],
    ['stale source after profile edit', {has_work_data: true, has_staging_data: true,
        work_entries: 17, work_members: 2}, true, 'available', /Reimporte 2026-11/],
];
for (const [name, state, stale, status, text] of availabilityCases) {
    test(`availability is a pure presentation decision: ${name}`, () => {
        const previous = structuredClone(state);
        const result = availabilitySummary(state, '2026-11', stale);
        assert.equal(result.status, status);
        assert.match(result.text, text);
        assert.deepEqual(state, previous, 'No mutation of source availability');
    });
}
