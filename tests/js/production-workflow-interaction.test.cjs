// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {initialize} = require('../../public/js/production-workflow.js');

function element(tag = 'div') {
    const handlers = new Map();
    const node = {
        tagName: tag, dataset: {}, children: [], value: '', textContent: '',
        hidden: false, disabled: false, checked: false,
        classList: {values: new Set(), toggle(name, active) {
            if (active) this.values.add(name);
            else this.values.delete(name);
        }},
        attributes: new Map(),
        setAttribute(name, value) {this.attributes.set(name, value);},
        removeAttribute(name) {this.attributes.delete(name);},
        addEventListener(name, callback) {handlers.set(name, callback);},
        async trigger(name) {return handlers.get(name)?.({target: this, preventDefault() {}});},
        appendChild(child) {this.children.push(child); return child;},
        replaceChildren(...items) {this.children = items;},
        querySelectorAll(selector) {
            if (selector !== '[data-omit-user-id]:checked') return [];
            const found = [];
            const scan = item => {
                if (item.dataset?.omitUserId !== undefined && item.checked) found.push(item);
                for (const child of item.children || []) scan(child);
            };
            scan(this);
            return found;
        },
    };
    return node;
}

function fixture({badResponse = false, brokenJson = false, importSuccess = true, publicationSuccess = true} = {}) {
    const controls = new Map();
    for (const name of ['messages','production-summary','preview-rows','selection-rows',
        'selection-summary','publication-summary','import-button','use-existing',
        'review-confirmation','audit-next','selection-next','publication-confirmation',
        'submit','month','year']) {
        controls.set(name, element());
    }
    controls.get('month').value = '08';
    controls.get('year').value = '2026';
    controls.get('audit-next').disabled = true;
    controls.get('submit').disabled = true;
    const fields = {};
    for (const name of ['operation','baixar_dados','atualiza_producao',
        'expected_revision','omit_user_ids']) {
        fields[name] = {value: ''};
    }
    fields.month = controls.get('month');
    fields.year = controls.get('year');

    const form = element('form');
    form.elements = fields;
    controls.set('form-gera-producao', form);

    const sections = [1,2,3,4].map(n => {
        const node = element('section');
        node.dataset.workflowStep = String(n);
        return node;
    });
    const indicators = [1,2,3,4].map(n => {
        const node = element('span');
        node.dataset.stepIndicator = String(n);
        return node;
    });
    const links = ['/producao','/calculos','/invoices'].map(path => {
        const node = element('a');
        node.dataset.reportPath = path;
        return node;
    });
    const backs = [1,2,3].map(n => {
        const node = element('button');
        node.dataset.back = String(n);
        return node;
    });

    const wizard = {
        dataset: {previewUrl: '/api/v1/producao'},
        querySelectorAll(selector) {
            return ({
                '[data-workflow-step]': sections,
                '[data-report-path]': links,
                '[data-back]': backs,
            })[selector] || [];
        },
    };
    controls.set('production-wizard', wizard);
    const doc = {
        getElementById: name => controls.get(name),
        querySelectorAll: selector => selector === '[data-step-indicator]' ? indicators : [],
        createElement: element,
        createTextNode: text => ({textContent: text, children: []}),
    };

    const calls = {fetch: [], submit: [], notices: []};
    const revision = 'c'.repeat(64);
    const data = [
        {kimai_user_id: 5, name: 'Pessoa A', base_producao: 100.3200001,
            liquido: 75.23, hours_seconds: 7200},
        {kimai_user_id: 8, name: 'Pessoa B', base_producao: 50.15,
            liquido: 32.12, hours_seconds: 3600},
    ];
    const options = {
        fetch: async (url, config) => {
            calls.fetch.push({url, config});
            return {
                ok: !badResponse,
                json: async () => brokenJson
                    ? {metadata:{competence:'wrong'},data:[]}
                    : badResponse ? {error:{message:'Erro da fonte fiscal'}}
                        : {metadata:{competence:'2026-08',revision},data},
            };
        },
        progress: {
            async submit(activeForm) {
                calls.submit.push(Object.fromEntries(Object.entries(activeForm.elements)
                    .map(([key, value]) => [key, value.value])));
                return activeForm.elements.operation.value === 'import'
                    ? importSuccess : publicationSuccess;
            },
            addMessage(_messages, severity, message) {
                calls.notices.push({severity,message});
            },
        },
    };
    assert.equal(initialize(doc, options), true);
    const step = () => sections.findIndex(section => !section.hidden) + 1;
    const checkbox = position =>
        controls.get('selection-rows').children[position].children.at(-1).children[0].children[0];
    const click = async id => controls.get(id).trigger('click');
    const change = async id => controls.get(id).trigger('change');

    return {controls, fields, doc, calls, sections, indicators, links, backs,
        step, checkbox, click, change, revision, data};
}

test('wizard renders a real financial review and gates selection on confirmation', async () => {
    const f = fixture();
    assert.equal(f.step(),1);
    assert.equal(f.indicators[0].attributes.get('aria-current'), 'step');
    await f.click('use-existing');
    assert.equal(f.step(),2);
    assert.deepEqual(f.calls.fetch.map(item=>item.url), ['/api/v1/producao?ano-mes=2026-08']);
    assert.equal(f.controls.get('preview-rows').children.length,2);
    assert.equal(f.controls.get('selection-rows').children.length,2);
    assert.match(f.controls.get('production-summary').textContent,/2 cooperados apurados/);
    assert.match(f.controls.get('production-summary').textContent,/R\$\s?107,35/);
    assert.equal(f.controls.get('audit-next').disabled,true);
    assert.deepEqual(f.links.map(link=>link.href),[
        '/producao?ano-mes=2026-08','/calculos?ano-mes=2026-08',
        '/invoices?ano-mes=2026-08',
    ]);
    await f.click('audit-next');
    assert.equal(f.step(),2,'Cannot proceed without financial review');
    f.controls.get('review-confirmation').checked=true;
    await f.change('review-confirmation');
    assert.equal(f.controls.get('audit-next').disabled,false);
    await f.click('audit-next');
    assert.equal(f.step(),3);
    assert.equal(f.checkbox(0).checked,false);
    assert.equal(f.checkbox(1).checked,false);
    assert.match(f.controls.get('selection-summary').textContent,/2 com documentos previstos/);
});

test('exception selection does not redistribute amounts and reaches explicit final confirmation', async () => {
    const f = fixture();
    await f.click('use-existing');
    f.controls.get('review-confirmation').checked=true;
    await f.change('review-confirmation');
    await f.click('audit-next');
    f.checkbox(1).checked=true;
    await f.change('selection-rows');
    assert.match(f.controls.get('selection-summary').textContent,/1 não publicados agora/);
    assert.match(f.controls.get('selection-summary').textContent,/R\$\s?32,12/);
    await f.click('selection-next');
    assert.equal(f.step(),4);
    assert.match(f.controls.get('publication-summary').textContent,/1 com documentos individuais/);
    assert.match(f.controls.get('publication-summary').textContent,/não são redistribuídos/);
    assert.equal(f.controls.get('submit').disabled,true);
    await f.change('publication-confirmation');
    assert.equal(f.controls.get('submit').disabled,true);
    f.controls.get('publication-confirmation').checked=true;
    await f.change('publication-confirmation');
    assert.equal(f.controls.get('submit').disabled,false);
    await f.click('submit');
    assert.equal(f.calls.submit.length,1);
    assert.deepEqual({
        operation:f.calls.submit[0].operation,
        download:f.calls.submit[0].baixar_dados,
        publish:f.calls.submit[0].atualiza_producao,
        revision:f.calls.submit[0].expected_revision,
        omitted:f.calls.submit[0].omit_user_ids,
    }, {
        operation:'publish',download:'0',publish:'1',revision:f.revision,omitted:'8',
    });
    assert.equal(f.controls.get('submit').disabled,true);
    await f.click('submit');
    assert.equal(f.calls.submit.length,1,'Never repeat a successful financial write');
});

test('import is a separate operation and must finish successfully before review', async () => {
    const f=fixture();
    await f.click('import-button');
    assert.equal(f.calls.submit.length,1);
    assert.equal(f.calls.submit[0].operation,'import');
    assert.equal(f.calls.submit[0].baixar_dados,'1');
    assert.equal(f.calls.submit[0].atualiza_producao,'0');
    assert.equal(f.step(),2);
    assert.equal(f.calls.fetch.length,1);
    const failed=fixture({importSuccess:false});
    await failed.click('import-button');
    assert.equal(failed.step(),1);
    assert.equal(failed.calls.fetch.length,0,'Never review a failed import');
});

test('stale preview, empty input, and server errors never enable financial publication', async () => {
    for (const conditions of [{badResponse:true}, {brokenJson:true}]) {
        const f=fixture(conditions);
        await f.click('use-existing');
        assert.equal(f.step(),1);
        assert.equal(f.calls.notices.length,1);
        assert.equal(f.controls.get('submit').disabled,true);
    }
});

test('all-excluded selection is blocked, back navigation and competence changes reset review', async () => {
    const f=fixture();
    await f.click('use-existing');
    f.controls.get('review-confirmation').checked=true;
    await f.change('review-confirmation');
    await f.click('audit-next');
    f.checkbox(0).checked=true;
    f.checkbox(1).checked=true;
    await f.change('selection-rows');
    await f.click('selection-next');
    assert.equal(f.step(),3);
    assert.match(f.calls.notices.at(-1).message,/todos os cooperados desmarcados/);
    await f.backs[1].trigger('click');
    assert.equal(f.step(),2);
    f.controls.get('month').value='09';
    await f.change('month');
    assert.equal(f.step(),1);
    assert.equal(f.calls.fetch.length,1);
    assert.ok(f.links.every(link=>link.href.endsWith('ano-mes=2026-09')));
});
