// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    editorUrl, validLinkId, linkActionState, searchCandidates,
    contactProvisionPlan, identityRows,
} = require('../../public/js/member-editor.js');

for (const [id, expected] of [
    ['1', true], ['35', true], [35, true], ['9007199254740991', true],
    ['0', false], [0, false], ['-1', false], ['01', false], ['1.2', false],
    ['NaN', false], ['Infinity', false], ['', false], [null, false],
    ['9007199254740992', false], ['9007199254740993', false], [true, false],
]) {
    test(`technical link identifier ${JSON.stringify(id)} is ${expected ? 'valid' : 'rejected'}`, () => {
        assert.equal(validLinkId(id), expected);
    });
}

for (const [name, uid, kimai, akaunting, confirmed, saved] of [
    ['only Kimai, contact absent', 'cooperada.b', '39', '', true, false],
    ['both source IDs selected', 'cooperada.b', '39', '35', true, true],
    ['missing account', '', '39', '35', false, false],
    ['spaces are not an account', '   ', '39', '35', false, false],
    ['missing Kimai', 'cooperada.b', '', '35', false, false],
    ['unsafe Kimai', 'cooperada.b', '999999999999999999999', '35', false, false],
    ['unsafe Accounting', 'cooperada.b', '39', '9007199254740992', true, false],
    ['invalid Accounting', 'cooperada.b', '39', '35x', true, false],
]) {
    test(`link actions: ${name}`, () => {
        assert.deepEqual(linkActionState(uid, kimai, akaunting), {
            canConfirmKimai: confirmed,
            canSaveLinks: saved,
            hasContact: validLinkId(akaunting),
        });
    });
}

test('the member editor rejects an unsafe large ID even on a valid same-origin route', () => {
    const base = 'https://host.test/configuracao/cooperados';
    assert.equal(editorUrl('/configuracao/cooperado/9007199254740993/editar', base), null);
    assert.ok(editorUrl('/configuracao/cooperado/39/editar', base));
});

test('search results normalize, deduplicate and cap options without changing the origin payload', () => {
    const original = [{id: 35, label: 'Cooperada B'},
        {id:'35',label:'Duplicado'}, {id:'36',label:'Cooperada 2'},
        {id:'0',label:'Inválido'}, {id:'9007199254740993',label:'Grande'},
        null, {label: 'Sem ID'}];
    const copy = structuredClone(original);
    assert.deepEqual(searchCandidates('akaunting', original), [
        {id:'35',label:'Cooperada B'}, {id:'36',label:'Cooperada 2'},
    ]);
    assert.deepEqual(original, copy);
    const many = Array.from({length:22}, (_, i) => ({id:i+1}));
    const options = searchCandidates('akaunting', many);
    assert.equal(options.length, 12);
    assert.equal(options.at(-1).id, '12');
    assert.deepEqual(searchCandidates('unknown', original), []);
});

for (const [name, items, ids] of [
    ['empty list', [], []],
    ['known inactive Nextcloud UID', [{id:'cooperada.inativa',label:'Cooperada Inativa (inativa)'}], ['cooperada.inativa']],
    ['duplicates and empty accounts', [{id:'cooperada.b'}, {id:'cooperada.b'}, {id:''}], ['cooperada.b']],
    ['ID is exactly 128 chars', [{id:'a'.repeat(128)}], ['a'.repeat(128)]],
    ['ID longer than 128 chars', [{id:'a'.repeat(129)}], []],
]) {
    test(`Nextcloud search candidates: ${name}`, () => {
        assert.deepEqual(searchCandidates('nextcloud', items).map(item => item.id), ids);
    });
}

for (const [name, response, uid, expectedKind, text] of [
    ['already linked', {status:'linked'}, 'cooperada.inativa', 'linked', null],
    ['contact exists', {status:'existing',contacts:[
        {id:172,name:'Cooperada Inativa',type:'vendor'},
        {id:172,name:'Duplicado',type:'employee'},
        {id:35,name:'Cooperada B',type:'employee'},
    ]}, 'cooperada.inativa', 'existing', null],
    ['new contact requires verified fiscal hint', {status:'available',uid:'cooperada.inativa',
        name:'Cooperada Inativa',tax_hint:'CPF final 725'}, 'cooperada.inativa', 'available', null],
    ['mismatched Nextcloud UID', {status:'available',uid:'other.user',
        tax_hint:'CPF final 725'}, 'cooperada.inativa', null, /conta Nextcloud selecionada mudou/],
    ['missing fiscal hint', {status:'available',uid:'cooperada.inativa'}, 'cooperada.inativa', null, /Resposta de consulta inesperada/],
    ['unrecognized response', {status:'unknown'}, 'cooperada.inativa', null, /Resposta de consulta inesperada/],
    ['invalid existing contacts', {status:'existing',contacts:[{id:'0'}]}, 'cooperada.inativa', null, /Nenhum contato válido/],
]) {
    test(`contact provisioning is read-only decision: ${name}`, () => {
        const original = structuredClone(response);
        if (text) assert.throws(() => contactProvisionPlan(response, uid), text);
        else {
            const plan = contactProvisionPlan(response, uid);
            assert.equal(plan.kind, expectedKind);
            if (expectedKind === 'existing') {
                assert.deepEqual(plan.contacts.map(c => c.id), ['172','35']);
                assert.deepEqual(plan.contacts.map(c => c.type), ['vendor','employee']);
            }
            if (expectedKind === 'available') {
                assert.equal(plan.taxHint, 'CPF final 725');
                assert.equal(plan.uid, uid);
            }
        }
        assert.deepEqual(response, original);
    });
}

for (const [name, source, identity, uid, kimaiId, expectedWarning, expectedRows] of [
    ['inactive Nextcloud can still be linked historically', 'nextcloud',
        {name:'Cooperada Inativa',username:'cooperada.inativa',enabled:false,linked_account:''},
        'cooperada.inativa', '27', /Conta inativa/, ['Login','Situação','Vínculo existente']],
    ['conflicting Kimai account must warn', 'nextcloud',
        {name:'Cooperada B',username:'cooperada.b',enabled:true,linked_account:'Kimai #99'},
        'cooperada.b', '39', /outro ID Kimai/, ['Vínculo existente']],
    ['matching fiscal contact', 'akaunting',
        {name:'Cooperada Inativa',enabled:null,kind:'Fornecedor',tax_match:'match',
            tax_hint:'CPF final 725',linked_account:'cooperada.inativa'},
        'cooperada.inativa', '27', /^$/, ['Documento','CPF\/CNPJ','Tipo']],
    ['different contact and fiscal ID', 'akaunting',
        {name:'Cooperada B',kind:'Fornecedor',tax_match:'different',tax_hint:'CPF final 725',linked_account:'other.user'},
        'cooperada.b', '39', /outra conta Nextcloud. Identificação fiscal divergente/, ['CPF\/CNPJ']],
    ['missing fiscal hint', 'akaunting',
        {name:'Cooperada B',kind:'Fornecedor',tax_match:'missing',linked_account:''},
        'cooperada.b', '39', /Sem CPF\/CNPJ válido/, ['Documento']],
]) {
    test(`identity inspection: ${name}`, () => {
        const original = structuredClone(identity);
        const result = identityRows(source, identity, uid, kimaiId);
        assert.match(result.warning, expectedWarning);
        for (const expected of expectedRows) {
            const matcher = new RegExp('^' + expected + '$');
            assert.ok(result.rows.some(row => matcher.test(row[0])), expected);
        }
        assert.deepEqual(identity, original);
    });
}
