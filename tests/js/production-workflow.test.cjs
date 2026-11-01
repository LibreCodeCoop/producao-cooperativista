// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {money, hours, validatePreview, selectedMembers, initialize} =
    require('../../public/js/production-workflow.js');

const revision = 'a'.repeat(64);
function payload(overrides = {}) {
    return {
        metadata: {competence: '2026-08', revision},
        data: [
            {kimai_user_id: 5, name: 'Pessoa A', base_producao: 100.32500001,
                liquido: 73.25, hours_seconds: 7200},
            {kimai_user_id: 8, name: 'Pessoa B', base_producao: 50.1,
                liquido: 34.12, hours_seconds: 3600},
        ],
        ...overrides,
    };
}

test('Brazilian currency formatting never displays fractional cents', () => {
    assert.match(money(100.32500001), /R\$\s?100,33/);
    assert.match(money(0.000001), /R\$\s?0,00/);
    assert.equal(hours(7200), '2 h');
    assert.equal(hours(1800), '0,5 h');
    assert.equal(hours(-1), '—');
});

test('preview requires the same competence and valid revision', () => {
    const valid = validatePreview(payload(), '2026-08');
    assert.equal(valid.revision, revision);
    assert.deepEqual(valid.rows.map(row => row.id), [5, 8]);
    assert.deepEqual(valid.rows.map(row => row.net), [73.25, 34.12]);

    for (const invalid of [
        payload({metadata: {competence: '2026-07', revision}}),
        payload({metadata: {competence: '2026-08', revision: 'invalid'}}),
        payload({data: []}),
        payload({data: [{kimai_user_id: 5, name: 'A', base_producao: 1, liquido: 2, hours_seconds: 1},
            {kimai_user_id: 5, name: 'B', base_producao: 1, liquido: 2, hours_seconds: 1}]}),
        payload({data: [{kimai_user_id: -1, name: 'A', base_producao: 1, liquido: 1, hours_seconds: 1}]}),
        payload({data: [{kimai_user_id: 7, name: 'A', base_producao: 'NaN', liquido: 1, hours_seconds: 1}]}),
    ]) {
        assert.throws(() => validatePreview(invalid, '2026-08'));
    }
});

test('all cooperados included by default, exclusion does not redistribute amounts', () => {
    const rows = validatePreview(payload(), '2026-08').rows;
    let selected = selectedMembers(rows, []);
    assert.deepEqual(selected.included.map(row => row.id), [5, 8]);
    assert.deepEqual(selected.excluded, []);
    selected = selectedMembers(rows, [8]);
    assert.deepEqual(selected.included.map(row => row.id), [5]);
    assert.deepEqual(selected.excluded.map(row => row.id), [8]);
    assert.equal(selected.included[0].net, 73.25);
    assert.equal(rows.reduce((sum, row) => sum + row.net, 0), 107.37);
});

test('pages without wizard are left unchanged', () => {
    assert.equal(initialize({getElementById: () => null}), false);
});
