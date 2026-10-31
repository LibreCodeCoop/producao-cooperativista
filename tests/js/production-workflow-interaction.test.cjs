// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {initialize} = require('../../public/js/production-workflow.js');

function element() {
    const handlers = new Map();
    return {
        value: '', dataset: {}, children: [], textContent: '',
        checked: false, disabled: false, hidden: false,
        attributes: new Map(),
        classList: {toggle() {}},
        addEventListener(name, fn) {handlers.set(name, fn);},
        trigger(name) {return handlers.get(name)?.({target: this, preventDefault() {}});},
        replaceChildren() {this.children.length = 0;},
        setAttribute(key, value) {this.attributes.set(key, value);},
        removeAttribute(key) {this.attributes.delete(key);},
    };
}

function fixture({previewError = false, malformed = false, importSuccess = true,
    publishSuccess = true, gridSuccess = true} = {}) {
    const items = new Map();
    for (const id of ['production-wizard','form-gera-producao','messages',
        'import-button','use-existing','reload-preview','review-competence',
        'metric-members','metric-hours','metric-base','metric-net',
        'selection-summary','publication-summary','publication-confirmation',
        'submit','back-import','year','month']) items.set(id, element());

    items.get('year').value = '2026';
    items.get('month').value = '08';
    items.get('submit').disabled = true;
    const form = items.get('form-gera-producao');
    form.elements = {};
    for (const id of ['year','month','operation','baixar_dados',
        'atualiza_producao','expected_revision','omit_user_ids']) {
        form.elements[id] = {value: ''};
    }

    const sections = [1,2].map(n => {
        const node = element();
        node.dataset.workflowStep = String(n);
        return node;
    });
    const indicators = [1,2].map(n => {
        const node = element();
        node.dataset.stepIndicator = String(n);
        return node;
    });
    const links = ['/producao','/calculos','/invoices'].map(path => {
        const node = element();
        node.dataset.reportPath = path;
        return node;
    });
    items.get('production-wizard').dataset.previewUrl = '/api/v1/producao';
    items.get('production-wizard').querySelectorAll = selector => ({
        '[data-workflow-step]': sections,
        '[data-report-path]': links,
    })[selector] || [];
    const doc = {
        getElementById: id => items.get(id),
        querySelectorAll: selector => selector === '[data-step-indicator]' ? indicators : [],
    };

    const calls = {fetch: [],submit: [],notices: [],mount: [],destroy: 0};
    const rows = [
        {kimai_user_id: 5, name: 'Pessoa A', peso: 1.5,
            base_producao: 100.3200001, liquido: 75.23, hours_seconds: 7200},
        {kimai_user_id: 8, name: 'Pessoa B', peso: 2,
            base_producao: 50.15, liquido: 32.12, hours_seconds: 3600},
    ];
    const revision = 'c'.repeat(64);
    const reportTable = {
        async initializeReportTable(_doc, options) {
            calls.mount.push(options);
            return gridSuccess ? {destroy() {calls.destroy++;}} : null;
        },
    };
    const options = {
        reportTable,
        fetch: async(url,settings) => {
            calls.fetch.push({url,settings});
            return {
                ok: !previewError,
                async json() {
                    return previewError ? {error:{message:'Erro da fonte fiscal'}} :
                        malformed ? {metadata:{competence:'2026-07'},data:rows} :
                            {metadata:{competence:'2026-08',revision},data:rows};
                },
            };
        },
        progress: {
            async submit(activeForm) {
                calls.submit.push(Object.fromEntries(
                    Object.entries(activeForm.elements).map(([k,v]) => [k,v.value])
                ));
                return activeForm.elements.operation.value === 'import' ?
                    importSuccess : publishSuccess;
            },
            addMessage(_target, severity, message) {calls.notices.push({severity,message});},
        },
    };
    assert.equal(initialize(doc, options), true);

    const get = id => items.get(id);
    const step = () => sections.findIndex(node => !node.hidden) + 1;
    const click = id => get(id).trigger('click');
    const change = id => get(id).trigger('change');
    const decide = (id, excluded) => {
        const config = calls.mount.at(-1);
        config.onDecisionChange(id, excluded);
    };
    const setConsent = value => {
        get('publication-confirmation').checked = value;
        change('publication-confirmation');
    };
    return {items,calls,rows,revision,links,indicators,form,step,get,click,change,decide,setConsent};
}

test('import and review are the only two stages, with a sortable shared grid', async () => {
    const f = fixture();
    assert.equal(f.step(),1);
    assert.equal(f.indicators[0].attributes.get('aria-current'),'step');
    await f.click('use-existing');
    assert.equal(f.step(),2);
    assert.equal(f.calls.fetch.length,1);
    assert.equal(f.calls.mount.length,1);
    assert.equal(f.calls.mount[0].payload.data.length,2);
    assert.equal(f.calls.mount[0].omitted.size,0);
    assert.equal(f.get('metric-members').textContent,'2');
    assert.match(f.get('metric-base').textContent,/R\$\s?150,47/);
    assert.match(f.get('metric-net').textContent,/R\$\s?107,35/);
    assert.equal(f.get('metric-hours').textContent,'3 h');
    assert.deepEqual(f.links.map(link=>link.href),[
        '/producao?ano-mes=2026-08','/calculos?ano-mes=2026-08',
        '/invoices?ano-mes=2026-08',
    ]);
});

test('exclude decisions persist outside page, sorting and column filters', async () => {
    const f=fixture();
    await f.click('use-existing');
    f.decide(8,true);
    assert.deepEqual([...f.calls.mount[0].omitted],[8]);
    assert.match(f.get('selection-summary').textContent,/1 não publicados agora/);
    assert.match(f.get('selection-summary').textContent,/R\$\s?32,12/);
    assert.match(f.get('publication-summary').textContent,/1 documento/);
    f.setConsent(true);
    assert.equal(f.get('submit').disabled,false);
    await f.click('submit');
    assert.equal(f.calls.submit.length,1);
    assert.deepEqual({
        operation:f.calls.submit[0].operation,
        downloaded:f.calls.submit[0].baixar_dados,
        publish:f.calls.submit[0].atualiza_producao,
        revision:f.calls.submit[0].expected_revision,
        omission:f.calls.submit[0].omit_user_ids,
        period:f.calls.submit[0].year+'-'+f.calls.submit[0].month,
    }, {
        operation:'publish',downloaded:'0',publish:'1',revision:f.revision,
        omission:'8',period:'2026-08',
    });
    assert.equal(f.get('submit').disabled,true);
    await f.click('submit');
    assert.equal(f.calls.submit.length,1);
});

test('decision changes invalidate the previous financial authorization', async () => {
    const f=fixture();
    await f.click('use-existing');
    f.setConsent(true);
    assert.equal(f.get('submit').disabled,false);
    f.decide(5,true);
    assert.equal(f.get('submit').disabled,true);
    assert.equal(f.get('publication-confirmation').checked,false);
    f.decide(8,true);
    f.setConsent(true);
    assert.equal(f.get('submit').disabled,true,'All excluded must remain blocked');
    await f.click('submit');
    assert.equal(f.calls.submit.length,0);
});

test('import never publishes and requires the same preview to complete', async () => {
    const f=fixture();
    await f.click('import-button');
    assert.equal(f.calls.submit.length,1);
    assert.equal(f.calls.submit[0].operation,'import');
    assert.equal(f.calls.submit[0].baixar_dados,'1');
    assert.equal(f.calls.submit[0].atualiza_producao,'0');
    assert.equal(f.step(),2);
    const bad=fixture({importSuccess:false});
    await bad.click('import-button');
    assert.equal(bad.step(),1);
    assert.equal(bad.calls.fetch.length,0);
});

test('freshness failures, missing grid and invalid data fail closed', async () => {
    for (const config of [{previewError:true},{malformed:true},{gridSuccess:false}]) {
        const f=fixture(config);
        await f.click('use-existing');
        assert.equal(f.step(),1);
        assert.equal(f.get('submit').disabled,true);
        assert.ok(f.calls.notices.length);
    }
});

test('editing weight invalidates review and requires reimporting original sources', async () => {
    const f=fixture();
    await f.click('use-existing');
    f.setConsent(true);
    f.calls.mount[0].onEditing();
    assert.equal(f.get('submit').disabled,true);
    f.calls.mount[0].onSaved();
    assert.equal(f.step(),1);
    assert.equal(f.get('submit').disabled,true);
    assert.equal(f.get('use-existing').disabled,true);
    await f.click('use-existing');
    assert.equal(f.calls.fetch.length,1,'Cannot bypass reimport within this session');
    await f.click('import-button');
    assert.equal(f.step(),2);
    assert.equal(f.get('use-existing').disabled,false);
    assert.equal(f.calls.destroy,1);
});

test('reloading review and changing competence revoke approval and old selections', async () => {
    const f=fixture();
    await f.click('use-existing');
    f.decide(8,true);
    f.setConsent(true);
    await f.click('reload-preview');
    assert.equal(f.calls.destroy,1);
    assert.equal(f.calls.mount.at(-1).omitted.size,0);
    assert.equal(f.get('publication-confirmation').checked,false);
    assert.equal(f.get('submit').disabled,true);
    await f.click('back-import');
    assert.equal(f.step(),1);
    f.get('month').value='09';
    f.change('month');
    assert.ok(f.links.every(link=>link.href.endsWith('ano-mes=2026-09')));
    assert.equal(f.get('submit').disabled,true);
});

test('failed publication requires explicit reconfirmation and warns about uncertain writes', async () => {
    const f=fixture({publishSuccess:false});
    await f.click('use-existing');
    f.setConsent(true);
    await f.click('submit');
    assert.equal(f.calls.submit.length,1);
    assert.equal(f.get('publication-confirmation').checked,false);
    assert.equal(f.get('submit').disabled,true);
    assert.match(f.calls.notices.at(-1).message,/Akaunting/);
});

test('pages without a production wizard are unaffected', () => {
    assert.equal(initialize({getElementById:()=>null}),false);
});
