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
        attributes: new Map(), scrolls: [],
        scrollIntoView(options) {this.scrolls.push(options);},
        classList: {toggle() {}},
        addEventListener(name, fn) {handlers.set(name, fn);},
        trigger(name) {return handlers.get(name)?.({target: this, preventDefault() {}});},
        replaceChildren() {this.children.length = 0;},
        setAttribute(key, value) {this.attributes.set(key, value);},
        removeAttribute(key) {this.attributes.delete(key);},
    };
}

function fixture({previewError = false, economicFailure = false, malformed = false, importSuccess = true,
    publishSuccess = true, gridSuccess = true, withdrawalGoal = null,
    importError = 'Falha na importação', initialWorkData = true,
    initialStagingData = true} = {}) {
    const items = new Map();
    for (const id of ['production-wizard','form-gera-producao','messages',
        'import-button','use-existing','import-availability-status',
        'reset-staging-link','reset-staging-help','review-competence',
        'metric-members','metric-hours','metric-base','metric-net','withdrawal-goal',
        'selection-summary','publication-summary','publication-confirmation',
        'import-status','import-status-text','import-elapsed','import-profile-link',
        'imported-data-review','imported-data-summary','source-financial-status',
        'calculate-financial-preview','pending-invoices-link','calculated-review',
        'production-publication-controls','calculated-review-intro','audit-title',
        'submit','year','month']) items.set(id, element());

    items.get('year').value = '2026';
    items.get('month').value = '08';
    items.get('submit').disabled = true;
    items.get('use-existing').hidden = !initialWorkData;
    items.get('use-existing').disabled = !initialWorkData;
    items.get('reset-staging-link').hidden = !initialStagingData;
    items.get('reset-staging-help').hidden = !initialStagingData;
    items.get('import-availability-status').dataset.state = initialWorkData
        ? 'available' : initialStagingData ? 'other' : 'empty';
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
    const links = ['/relatorios/producao','/relatorios/calculos','/relatorios/invoices'].map(path => {
        const node = element();
        node.dataset.reportPath = path;
        return node;
    });
    items.get('production-wizard').dataset.previewUrl = '/api/v1/producao';
    items.get('production-wizard').dataset.sourcesUrl = '/api/v1/producao/fontes';
    items.get('production-wizard').dataset.availabilityUrl =
        '/api/v1/producao/disponibilidade';
    items.get('production-wizard').dataset.importedWork = initialWorkData ? 'yes' : 'no';
    items.get('production-wizard').dataset.stagingData = initialStagingData ? 'yes' : 'no';
    items.get('production-wizard').querySelectorAll = selector => ({
        '[data-workflow-step]': sections,
        '[data-report-path]': links,
    })[selector] || [];
    const documentListeners = new Map();
    const doc = {
        addEventListener(name, handler) {documentListeners.set(name, handler);},
        getElementById: id => items.get(id),
        querySelectorAll: selector => selector === '[data-step-indicator]' ? indicators : [],
    };

    const calls = {
        fetch: [],availability: [],submit: [],notices: [],
        mount: [],sourceMount: [],destroy: 0,sourceDestroy: 0,
    };
    const availabilityByMonth = new Map([
        ['2026-08', {
            competence:'2026-08', has_work_data:initialWorkData,
            has_staging_data:initialStagingData,
            work_entries:initialWorkData ? 214 : 0,
            work_members:initialWorkData ? 2 : 0,
            latest_imported_competence:initialWorkData ? '2026-08' : null,
        }],
        ['2026-09', {
            competence:'2026-09', has_work_data:false,
            has_staging_data:initialStagingData,
            work_entries:0, work_members:0,
            latest_imported_competence:initialWorkData ? '2026-08' : null,
        }],
    ]);
    const rows = [
        {kimai_user_id: 5, name: 'Pessoa A', peso: 1.5,
            base_producao: 100.3200001, liquido: 75.23, hours_seconds: 7200},
        {kimai_user_id: 8, name: 'Pessoa B', peso: 2,
            base_producao: 50.15, liquido: 32.12, hours_seconds: 3600},
    ];
    const revision = 'c'.repeat(64);
    const reportTable = {
        async initializeReportTable(_doc, options) {
            if (options.sourceOnly) calls.sourceMount.push(options);
            else calls.mount.push(options);
            if (!gridSuccess) return null;
            return options.sourceOnly
                ? {destroy() {calls.sourceDestroy++;}}
                : {destroy() {calls.destroy++;}};
        },
    };
    const windowListeners = new Map();
    const options = {
        window: {addEventListener: (name, fn) => windowListeners.set(name, fn)},
        reportTable,
        fetch: async(url,settings) => {
            if (url.startsWith('/api/v1/producao/disponibilidade?')) {
                const month = new URL(url,'https://example.test').searchParams.get('ano-mes');
                calls.availability.push(month);
                const result = availabilityByMonth.get(month);
                return {
                    ok:true,
                    async json(){return result || {
                        competence:month,has_work_data:false,has_staging_data:false,
                        work_entries:0,work_members:0,latest_imported_competence:null,
                    };},
                };
            }
            calls.fetch.push({url,settings});
            if (url.startsWith('/api/v1/producao/fontes?')) {
                return {ok:true,async json() {return {
                    competence:'2026-08', financial_month:'2026-09', publication_allowed:false,
                    hours:{entries:214,members:2,seconds:10800,by_member:[
                        {id:5,name:'Pessoa A',weight:1.5,entries:100,seconds:7200},
                        {id:8,name:'Pessoa B',weight:2,entries:114,seconds:3600},
                    ]},
                    data:rows.map(row => ({
                        ...row,base_producao:null,bruto:null,liquido:null,trabalhado:'[]',
                    })),
                    income_transactions:{amount:0,count:0},
                    client_cost_transactions:{amount:2007.63,by_customer:[{label:'cliente A',amount:2007.63}]},
                    internal_expense_transactions:{amount:11818.04,by_category:[{label:'infra',amount:11818.04}]},
                    unconfirmed_invoices:{count:7,amount:78279.48,documents:[
                        {id:2329,amount:29877.75,status:'sent',due_date:'2026-09-20'},
                    ],truncated:false},
                };}};
            }
            return {
                ok: !previewError,
                async json() {
                    return previewError ? economicFailure ?
                        {code:'BASE_ECONOMICA',resolution:'Confira receitas elegíveis.'} :
                        {error:{message:'Erro da fonte fiscal'}} :
                        malformed ? {metadata:{competence:'2026-07'},data:rows} :
                            {metadata:{competence:'2026-08',revision,withdrawal_goal:withdrawalGoal},data:rows};
                },
            };
        },
        progress: {
            async submit(activeForm, _messages, _button, opts) {
                calls.submit.push(Object.fromEntries(
                    Object.entries(activeForm.elements).map(([k,v]) => [k,v.value])
                ));
                if (activeForm.elements.operation.value === 'import') {
                    if (importSuccess) {
                        availabilityByMonth.set(activeForm.elements.year.value + '-' +
                            activeForm.elements.month.value, {
                            competence:activeForm.elements.year.value + '-' +
                                activeForm.elements.month.value,
                            has_work_data:true,has_staging_data:true,
                            work_entries:214,work_members:2,
                            latest_imported_competence:activeForm.elements.year.value + '-' +
                                activeForm.elements.month.value,
                        });
                    }
                    opts.onStatus?.({state:'running', message:'Etapa 2 de 8: Cooperados do Kimai.'});
                    opts.onStatus?.({state:importSuccess ? 'completed' : 'failed',
                        message:importSuccess ? 'Importação concluída.' : importError});
                    return importSuccess;
                }
                return publishSuccess;
            },
            addMessage(_target, severity, message) {calls.notices.push({severity,message});},
        },
    };
    assert.equal(initialize(doc, options), true);

    const get = id => items.get(id);
    const step = () => sections.findIndex(node => !node.hidden) + 1;
    const click = id => get(id).trigger('click');
    const preview = async () => {
        await click('use-existing');
        return click('calculate-financial-preview');
    };
    const change = id => get(id).trigger('change');
    const decide = (id, excluded) => {
        const config = calls.mount.at(-1) || calls.sourceMount.at(-1);
        config.onDecisionChange(id, excluded);
    };
    const setConsent = value => {
        get('publication-confirmation').checked = value;
        change('publication-confirmation');
    };
    return {items,calls,rows,revision,links,indicators,form,step,get,click,preview,change,decide,setConsent,
        setAvailability: (month, state) => availabilityByMonth.set(month,{
            competence:month,has_work_data:false,has_staging_data:false,
            work_entries:0,work_members:0,latest_imported_competence:null,...state,
        }),
        memberChanged: () => documentListeners.get('member-source-changed')?.(),
        restorePage: () => windowListeners.get('pageshow')?.({persisted:true}),
        focusPage: () => windowListeners.get('focus')?.(),
    };
}

test('empty staging hides review and reset actions while keeping import available', async () => {
    const f=fixture({initialWorkData:false,initialStagingData:false});
    assert.equal(f.step(),1);
    assert.equal(f.get('use-existing').hidden,true);
    assert.equal(f.get('use-existing').disabled,true);
    assert.equal(f.get('reset-staging-link').hidden,true);
    assert.equal(f.get('reset-staging-help').hidden,true);
    assert.equal(f.get('import-button').disabled,false);
    assert.equal(f.indicators[1].disabled,true);
    await f.click('use-existing');
    assert.equal(f.calls.fetch.length,0,'No source or financial API requested on empty base');
    assert.equal(f.calls.submit.length,0);

    await f.click('import-button');
    assert.equal(f.step(),2,'Successful import unlocks review and opens the staged data');
    assert.equal(f.get('use-existing').hidden,false);
    assert.equal(f.get('reset-staging-link').hidden,false);
    assert.equal(f.get('import-availability-status').dataset.state,'available');
});

test('month changes distinguish staged data from imported work in another competence', async () => {
    const f=fixture();
    f.get('month').value='09';
    await f.change('month');
    assert.equal(f.step(),1);
    assert.deepEqual(f.calls.availability,['2026-09']);
    assert.equal(f.get('use-existing').hidden,true);
    assert.equal(f.get('use-existing').disabled,true);
    assert.equal(f.get('reset-staging-link').hidden,false,
        'Do not hide the global reset if another month still has imported data');
    assert.match(f.get('import-availability-status').textContent,/2026-08/);
    assert.equal(f.get('import-availability-status').dataset.state,'other');
    assert.equal(f.get('reset-staging-link').href,
        '/acoes/zerar-banco-local?retorno=2026-09');

    f.get('month').value='08';
    await f.change('month');
    assert.equal(f.get('use-existing').hidden,false);
    assert.equal(f.get('use-existing').disabled,false);
    assert.match(f.get('import-availability-status').textContent,/214 apontamentos/);
});

test('restoring a cached page after reset hides stale actions without importing', async () => {
    const f=fixture();
    f.setAvailability('2026-08',{});
    await f.restorePage();
    assert.equal(f.get('import-availability-status').dataset.state,'empty');
    assert.match(f.get('import-availability-status').textContent,/Base importada vazia/);
    assert.equal(f.get('use-existing').hidden,true);
    assert.equal(f.get('reset-staging-link').hidden,true);
    assert.equal(f.indicators[1].disabled,true);
    assert.equal(f.calls.submit.length,0);
    assert.equal(f.calls.fetch.length,0);
});

test('availability is rechecked before reviewing a previously enabled button', async () => {
    const f=fixture();
    f.setAvailability('2026-08',{});
    await f.click('use-existing');
    assert.deepEqual(f.calls.availability,['2026-08']);
    assert.equal(f.step(),1);
    assert.equal(f.calls.fetch.length,0);
    assert.equal(f.get('use-existing').hidden,true);
    assert.equal(f.get('reset-staging-link').hidden,true);
});

test('partially populated staging is not mistaken for a reviewable month', async () => {
    const f=fixture({initialWorkData:false,initialStagingData:true});
    assert.equal(f.get('use-existing').hidden,true);
    assert.equal(f.get('reset-staging-link').hidden,false);
    f.setAvailability('2026-08',{has_staging_data:true});
    await f.focusPage();
    assert.equal(f.get('import-availability-status').dataset.state,'other');
    assert.equal(f.get('use-existing').hidden,true);
    assert.equal(f.get('reset-staging-link').hidden,false);
});

test('import and review are the only two stages, with a sortable shared grid', async () => {
    const f = fixture();
    assert.equal(f.step(),1);
    assert.equal(f.indicators[0].attributes.get('aria-current'),'step');
    assert.equal(f.indicators[1].disabled,true);
    await f.preview();
    assert.equal(f.step(),2);
    assert.equal(f.indicators[1].disabled,false);
    assert.equal(f.calls.fetch.length,2,'Source inspection and financial preview are independent');
    assert.equal(f.calls.mount.length,1);
    assert.equal(f.calls.mount[0].payload.data.length,2);
    assert.equal(f.calls.mount[0].omitted.size,0);
    assert.equal(f.get('metric-members').textContent,'2');
    assert.match(f.get('metric-base').textContent,/R\$\s?150,47/);
    assert.match(f.get('metric-net').textContent,/R\$\s?107,35/);
    assert.equal(f.get('metric-hours').textContent,'3 h');
    assert.deepEqual(f.links.map(link=>link.href),[
        '/relatorios/producao?ano-mes=2026-08','/relatorios/calculos?ano-mes=2026-08',
        '/relatorios/invoices?ano-mes=2026-08',
    ]);
});

test('net withdrawal reference is displayed without changing selection or actual payout', async () => {
    const goal={mode:'simulation_only',net_target:50000,reference_hours:165,required_pool:130000,
        available_pool:120000,shortfall:10000,potential_surplus:0};
    const f=fixture({withdrawalGoal:goal});
    await f.preview();
    const info=f.get('withdrawal-goal');
    assert.equal(info.hidden,false);
    assert.match(info.textContent,/R\$\s?50\.000,00/);
    assert.match(info.textContent,/165 h/);
    assert.match(info.textContent,/10\.000,00/);
    assert.match(info.textContent,/rateio vigente/);
    assert.match(f.get('metric-net').textContent,/107,35/);
    assert.equal(f.calls.mount[0].payload.data[0].liquido,75.23);
});

test('tax table unavailable does not block financial review', async () => {
    const f=fixture({withdrawalGoal:{mode:'unavailable'}});
    await f.preview();
    assert.equal(f.step(),2);
    assert.match(f.get('withdrawal-goal').textContent,/indisponível/);
    assert.equal(f.get('submit').disabled,true);
});

test('exclude decisions persist outside page, sorting and column filters', async () => {
    const f=fixture();
    await f.preview();
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
    await f.preview();
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
    assert.equal(f.get('import-status').hidden,false);
    assert.equal(f.get('import-status').dataset.state,'completed');
    assert.match(f.get('import-status-text').textContent,/concluída/);
    assert.equal(f.get('import-button').textContent,'');
    const bad=fixture({importSuccess:false, importError:'Erro PROFILE_DEPENDENTS: Kimai #17'});
    await bad.click('import-button');
    assert.equal(bad.step(),1);
    assert.equal(bad.calls.fetch.length,0);
    assert.equal(bad.get('import-status').dataset.state,'failed');
    assert.equal(bad.get('import-profile-link').hidden,false);
    assert.match(bad.get('import-status-text').textContent,/Kimai #17/);
});

test('failed import offers an exact, readable correction link and hides it for network failures', async () => {
    const error = 'Execução run-001: erro PROFILE_KIMAI_LINK. ' +
        'Etapa: Cooperados do Kimai e perfis Nextcloud. ' +
        'Cooperado: Luis Souza (Kimai #40). Como resolver: conferir vínculo.';
    const failure = fixture({importSuccess:false, importError:error});
    failure.get('month').value = '10';
    await failure.click('import-button');
    const link = failure.get('import-profile-link');
    assert.equal(link.hidden, false);
    assert.equal(link.href, '/configuracao/cooperado/40/editar?competencia=2026-10');
    assert.equal(link.textContent, 'Abrir cadastro do cooperado (Kimai #40)');
    assert.match(failure.get('import-status-text').textContent, /Luis Souza/);
    const connectivity = fixture({importSuccess:false, importError:'Execução X: erro SERVICO_INACESSIVEL. Kimai #40'});
    await connectivity.click('import-button');
    assert.equal(connectivity.get('import-profile-link').hidden, true,
        'Do not send operators to edit a profile for an unrelated network failure.');
});

test('source inspection opens directly without the financial error or a page jump', async () => {
    const f=fixture({previewError:true,economicFailure:true});
    await f.click('use-existing');
    assert.equal(f.step(),2);
    assert.equal(f.calls.fetch.length,1,'The financial API must not be called');
    assert.equal(f.calls.fetch[0].url,'/api/v1/producao/fontes?ano-mes=2026-08');
    assert.equal(f.calls.notices.length,0,'No warning at the bottom of the page');
    assert.equal(f.get('imported-data-review').scrolls.length,1,'Focus the requested audit section');
    assert.equal(f.get('source-financial-status').hidden,true);
    assert.equal(f.get('imported-data-review').hidden,false);
    assert.equal(f.get('calculated-review').hidden,false,'The same member table remains visible');
    assert.equal(f.calls.sourceMount.length,1);
    assert.equal(f.calls.sourceMount[0].sourceOnly,true);
    assert.equal(f.calls.sourceMount[0].financialReview,true,'Omission control is available before settlement');
    assert.ok(f.calls.sourceMount[0].payload.data.every(row => row.liquido === null));
    assert.equal(f.get('production-publication-controls').hidden,true);
    assert.equal(f.get('pending-invoices-link').hidden,false);
    assert.equal(f.get('pending-invoices-link').href,
        '/relatorios/faturas-conferencia?ano-mes=2026-08');
    assert.match(f.get('pending-invoices-link').textContent,/7 faturas/);
    assert.match(f.get('imported-data-summary').textContent,/214/);
    assert.match(f.get('imported-data-summary').textContent,/2\.007,63/);
    assert.match(f.get('imported-data-summary').textContent,/2329/);
    assert.equal(f.indicators[1].disabled,false);
    f.decide(8,true);
    assert.deepEqual([...f.calls.sourceMount[0].omitted],[8]);
    assert.match(f.get('selection-summary').textContent,/1 cooperado/);

    await f.click('calculate-financial-preview');
    assert.equal(f.step(),2,'Economic deficits may not hide the imported evidence');
    assert.equal(f.calls.fetch.length,2,'The staged data can be reused for a financial error');
    assert.equal(f.calls.sourceMount.length,1,'Do not reset the source table');
    assert.deepEqual([...f.calls.sourceMount[0].omitted],[8],
        'The decision survives an unavailable financial calculation');
    assert.equal(f.calls.mount.length,0,'Must not fabricate a calculated table');
    assert.equal(f.get('source-financial-status').hidden,false);
    assert.match(f.get('source-financial-status').textContent,/A regra atual de cálculo não encontrou/);
    assert.match(f.get('source-financial-status').textContent,/7 faturas/);
    assert.equal(f.calls.notices.length,0,'No repeated generic financial warning');
    f.setConsent(true);
    assert.equal(f.get('submit').disabled,true);
    await f.click('submit');
    assert.equal(f.calls.submit.length,0);
    f.indicators[0].trigger('click');
    f.indicators[1].trigger('click');
    assert.equal(f.step(),2,'Read-only audit remains navigable');
});

test('source omission selections carry to calculated preview without authorizing publication', async () => {
    const f=fixture();
    await f.click('use-existing');
    f.decide(8,true);
    assert.equal(f.calls.mount.length,0);
    assert.equal(f.get('submit').disabled,true);
    await f.click('calculate-financial-preview');
    assert.equal(f.calls.mount.length,1);
    assert.equal(f.calls.mount[0].omitted.has(8),true,
        'Manual omissions must survive moving from source review to calculated preview');
    assert.match(f.get('selection-summary').textContent,/1 não publicados agora/);
    assert.equal(f.get('publication-confirmation').checked,false);
    assert.equal(f.get('submit').disabled,true);
    f.setConsent(true);
    await f.click('submit');
    assert.equal(f.calls.submit[0].omit_user_ids,'8');
});

test('successful source import shows audit state when finance cannot be calculated', async () => {
    const f=fixture({previewError:true,economicFailure:true,importSuccess:true});
    await f.click('import-button');
    assert.equal(f.calls.submit.length,1);
    assert.equal(f.calls.submit[0].operation,'import');
    assert.equal(f.step(),2);
    assert.equal(f.get('import-status').dataset.state,'completed');
    assert.match(f.get('import-status-text').textContent,/Dados de origem disponíveis/);
    assert.equal(f.calls.fetch.length,1,'Import cannot automatically calculate a blocked financial preview');
    assert.equal(f.calls.notices.length,0);
    assert.equal(f.get('submit').disabled,true);
});

test('freshness failures, missing grid and invalid data fail closed', async () => {
    for (const config of [{previewError:true},{malformed:true}]) {
        const f=fixture(config);
        await f.preview();
        assert.equal(f.step(),2,'Staged data must remain available when preview validation fails');
        assert.equal(f.get('imported-data-review').hidden,false);
        assert.equal(f.get('source-financial-status').hidden,false);
        assert.equal(f.get('submit').disabled,true);
        assert.equal(f.calls.notices.length,0);
    }
    const unavailable=fixture({gridSuccess:false});
    await unavailable.click('use-existing');
    assert.equal(unavailable.step(),1,'A missing DataTables library must fail closed');
    assert.equal(unavailable.get('submit').disabled,true);
    assert.ok(unavailable.calls.notices.length);
});

test('editing via member modal invalidates review and requires reimporting original sources', async () => {
    const f=fixture();
    await f.preview();
    f.setConsent(true);
    assert.equal(f.get('submit').disabled,false);
    f.memberChanged();
    assert.equal(f.step(),1);
    assert.equal(f.get('submit').disabled,true);
    assert.equal(f.get('use-existing').disabled,true);
    assert.equal(f.indicators[1].disabled,true);
    f.indicators[1].trigger('click');
    assert.equal(f.step(),1);
    await f.preview();
    assert.equal(f.calls.fetch.length,2,'Cannot bypass reimport within this session');
    await f.click('import-button');
    assert.equal(f.step(),2);
    assert.equal(f.get('use-existing').disabled,false);
    await f.click('calculate-financial-preview');
    assert.equal(f.calls.destroy,1);
});

test('step buttons allow returning to import and review without re-fetching or retaining approval', async () => {
    const f = fixture();
    assert.equal(f.indicators[1].disabled, true);
    f.indicators[1].trigger('click');
    assert.equal(f.step(), 1);
    await f.preview();
    f.decide(8, true);
    f.setConsent(true);
    assert.equal(f.get('submit').disabled, false);
    f.indicators[0].trigger('click');
    assert.equal(f.step(), 1);
    assert.equal(f.get('publication-confirmation').checked, false);
    f.indicators[1].trigger('click');
    assert.equal(f.step(), 2);
    assert.deepEqual([...f.calls.mount[0].omitted], [8]);
    assert.equal(f.calls.fetch.length, 2);
    assert.equal(f.get('submit').disabled, true);
});

test('returning to import and reviewing again resets decisions and approval', async () => {
    const f=fixture();
    await f.preview();
    f.decide(8,true);
    f.setConsent(true);
    f.indicators[0].trigger('click');
    assert.equal(f.step(),1);
    await f.preview();
    assert.equal(f.calls.destroy,1);
    assert.equal(f.calls.mount.at(-1).omitted.size,0);
    assert.equal(f.get('publication-confirmation').checked,false);
    assert.equal(f.get('submit').disabled,true);
    f.indicators[0].trigger('click');
    assert.equal(f.step(),1);
    f.get('month').value='09';
    f.change('month');
    assert.ok(f.links.every(link=>link.href.endsWith('ano-mes=2026-09')));
    assert.equal(f.get('submit').disabled,true);
});

test('failed publication requires explicit reconfirmation and warns about uncertain writes', async () => {
    const f=fixture({publishSuccess:false});
    await f.preview();
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
