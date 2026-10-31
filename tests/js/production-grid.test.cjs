// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    duration, productionDetails, buildProductionColumns, stateUrl, setupFilters,
    initializeReportTable,
} = require('../../public/js/report-table.js');

function row(id, name = 'Pessoa') {
    return {
        kimai_user_id: id, name, peso: 2.5, hours_seconds: 3600,
        base_producao: 125.322222222, bruto: 101.50, liquido: 90.13,
        auxilio: 12.2, frra: 3.4, inss: 9.6, irpf: 0,
        base_irpf: 120, liquid_discount: 0,
        trabalhado: JSON.stringify([{
            nome: '<Cliente A>', trabalhado: '0:40',
            percentual_trabalhado: 50.121212121,
            total_cliente: 1.3333333333333333,
        }]),
    };
}

test('total cliente renders as exact duration, without repeating decimal hours', () => {
    assert.equal(duration(4800), '1:20:00');
    assert.equal(duration(4800.0000001), '1:20:00');
    assert.equal(duration(3599), '0:59:59');
    assert.equal(duration(0), '0:00:00');
    assert.equal(duration(Number.NaN), '—');
    assert.equal(duration(-1), '—');
    const html = productionDetails(row(5));
    assert.match(html, /1:20:00/);
    assert.match(html, /50,12%/);
    assert.ok(!html.includes('1.333333333333'));
    assert.ok(!html.includes('50.121212121'));
    assert.match(html, /&lt;Cliente A&gt;/);
    assert.match(html, /R\$\s?12,20/);
    assert.match(html, /Composição financeira/);
    assert.ok(!html.includes('<Cliente A>'));
    assert.match(productionDetails({...row(5),trabalhado:'not-json'}), /Sem horas/);
});

test('a single compact production grid keeps currency numeric for sorting and safe inline editing', () => {
    const opts = {
        inlineWeight:true, csrf:'<csrf>', competence:'2026-08', financialReview:true,
        omitted:new Set([8]),
    };
    const cols = buildProductionColumns(opts);
    assert.deepEqual(cols.map(col => col.title), [
        'Cooperado','Horas','Peso','Base de produção','Bruto','Líquido',
        'Conferir','Exceção',
    ]);
    assert.match(cols[0].render('<script>', 'display', row(5)), /&lt;script&gt;/);
    assert.match(cols[0].render('Pessoa A', 'display', row(5)), /data-member-editor-open/);
    assert.match(cols[0].render('Pessoa A', 'display', row(5)), /\/cooperado\/5\/editar/);
    assert.equal(cols[1].render(3600, 'display', row(5)), '1:00:00');
    assert.equal(cols[1].render(3600, 'sort', row(5)), 3600);
    const weight = cols[2].render(2.5, 'display', row(5));
    assert.match(weight, /data-weight-url="\/configuracao\/cooperado\/5\/peso"/);
    assert.match(weight, /&lt;csrf&gt;/);
    assert.equal(cols[3].render(125.322222222, 'sort', row(5)), 125.322222222);
    assert.match(cols[3].render(125.322222222, 'display', row(5)), /125,32/);
    assert.equal(cols[3].render(125.322222222, 'export', row(5)), '125.32');
    assert.equal(cols[3].render(1.005, 'export', row(5)), '1.01');
    assert.equal(cols[3].render(null, 'export', row(5)), '');
    assert.match(cols[6].render(null, 'display', row(5)), /data-production-details/);
    assert.equal(cols[6].render(null, 'sort', row(5)), '');
    assert.match(cols[7].render(null, 'display', row(8)), /checked/);
    assert.ok(!cols[7].render(null, 'display', row(5)).includes('checked'));
    assert.equal(cols[7].render(null, 'filter', row(8)), '');
    assert.equal(buildProductionColumns({}).length,7);
});

test('production details reject HTML in names and tolerate missing financial detail', () => {
    const html = productionDetails({
        trabalhado: JSON.stringify([{nome:'<img src=x onerror=alert(1)>',trabalhado:'<script>',
            total_cliente:0.0002777777777777778}]),
        auxilio:null, irpf:'', frra:1.25,
    });
    assert.match(html,/0:00:01/);
    assert.match(html,/&lt;script&gt;/);
    assert.ok(!html.includes('<img src='));
    assert.match(html,/1,25/);
    assert.ok(!html.includes('NaN'));
});

test('query string state preserves competence and restores sorting/pagination without raw HTML', () => {
    const url = stateUrl(
        'https://host.test/producao?ano-mes=2026-08&dtorder=99:asc&dtpage=5',
        {name:'Pessoa A',peso:''}, 'cooperado', 2, 50, [5,'desc']
    );
    const params = new URL('https://host.test'+url).searchParams;
    assert.equal(params.get('ano-mes'),'2026-08');
    assert.equal(params.get('name'),'Pessoa A');
    assert.equal(params.get('dtpage'),'2');
    assert.equal(params.get('dtlength'),'50');
    assert.equal(params.get('dtorder'),'5:desc');
    const cleared = stateUrl('https://host.test/producao?ano-mes=2026-08&dtlength=25&dtorder=5:desc',
        {name:''}, '', 0, 10, [0,'asc']);
    assert.ok(!cleared.includes('dtlength='));
    assert.ok(!cleared.includes('dtorder='));
    assert.ok(cleared.includes('ano-mes=2026-08'));
});

function makeGrid({
    location = 'https://host.test/acoes/make-producao?ano-mes=2026-08',
    payload = {data:[row(5),row(8)],metadata:{competence:'2026-08'}},
    tableMode = true, selectedIds = new Set(), fetchCalled = false,
    errorPayload = null,
} = {}) {
    const handlers = new Map();
    const footer = {children:[],replaceChildren(...children) {this.children=children;}};
    const table = {
        dataset: {
            url:'/api/v1/producao?ano-mes=2026-08',filterColumns:'["metadata"]',
            inlineWeight:'yes',csrf:'csrf',competencia:'2026-08',
            productionMode:tableMode?'yes':'no',financialReview:tableMode?'yes':'no',
        },
        tFoot:{rows:[footer]},
        addEventListener(name,callback) {handlers.set(name,callback);},
        querySelectorAll() {return [];},
    };
    const messages={textContent:''};
    const doc = {
        getElementById(name) {return {example:table,erros:messages}[name]||null;},
        querySelector() {return null;},
        createElement() {
            const callbacks=new Map();
            return {
                name:'',value:'', placeholder:'',children:[],
                addEventListener(name,callback) {callbacks.set(name,callback);},
                replaceChildren(...children) {this.children=children;},
                on:callbacks,
            };
        },
    };
    const history = {urls:[],replaceState(_a,_b,url) {this.urls.push(url);}};
    const loc = new URL(location);
    const evidence={columnDefs:null,table:null,inline:0,decisions:[],childHtml:null,childShown:false};
    class DataTable {
        constructor(selector,opts) {
            evidence.table=this;
            this.selector=selector;
            this.options=opts;
            const columns=opts.columns.map((_,i)=>{
                let value='';
                return {
                    footer:()=>footer.children[i],
                    search(next) {
                        if(next===undefined) return value;
                        value=next;
                        return this;
                    },
                    draw() {},
                };
            });
            let search='';
            let page=0;
            let length=opts.pageLength;
            let order=opts.order;
            const fnPage = (next)=>{
                if(next===undefined) return page;
                page=next;return api;
            };
            fnPage.len=(next)=>{
                if(next===undefined)return length;
                length=next;return api;
            };
            const child = function(html) {
                evidence.childHtml=html;
                return {show() {evidence.childShown=true;}};
            };
            child.isShown=()=>evidence.childShown;
            child.hide=()=>{evidence.childShown=false;};
            const api = {
                columns:()=>({every:cb=>columns.forEach((col,i)=>cb.call(col,i))}),
                search(next) {if(next===undefined)return search;search=next;return api;},
                page:fnPage,
                order(next) {if(next===undefined)return order;order=next;return api;},
                draw(){return api;},
                on(name,callback) {this.events.set(name,callback);return api;},
                events:new Map(),
                row(node) {evidence.node=node;return {
                    child, data:()=>payload.data[0],
                };},
            };
            this.apiInstance=api;
            this.on=(event,handler)=>api.on(event,handler);
            this.row=node=>api.row(node);
            opts.initComplete.call({api:()=>api});
        }
    }
    const options = {
        payload,
        DataTable,
        history,
        location:loc,
        omitted:selectedIds,
        inlineWeight:{initialize(){evidence.inline++;}},
        onDecisionChange(id,checked){evidence.decisions.push({id,checked});},
        async fetch() {
            if(!fetchCalled) throw Error('Must not fetch when payload provided');
            return {ok:false,status:500,json:async()=>errorPayload};
        },
    };
    return {doc,table,evidence,messages,options,handlers,loc,history};
}

test('shared DataTables adapter mounts reviewed rows, excludes interactive columns from export', async () => {
    const f=makeGrid({selectedIds:new Set([8])});
    const api=await initializeReportTable(f.doc,f.options);
    assert.ok(api);
    assert.equal(f.evidence.inline,1);
    assert.equal(f.evidence.table.options.columns.length,8);
    assert.equal(f.evidence.table.options.buttons,undefined);
    const buttons=f.evidence.table.options.layout.topStart.buttons;
    assert.equal(buttons.length,7);
    assert.deepEqual(buttons.at(-1).exportOptions.columns,[0,1,2,3,4,5]);
    assert.equal(buttons.at(-1).exportOptions.orthogonal,'export');
    assert.ok(f.handlers.has('change'));
    assert.ok(f.handlers.has('click'));
    const checkbox={dataset:{omitUserId:'8'},checked:true};
    f.handlers.get('change')({target:{closest:()=>checkbox}});
    assert.deepEqual(f.evidence.decisions,[{id:8,checked:true}]);
    const expand={setAttribute() {},closest:sel=>sel==='tr'?{}:null};
    f.handlers.get('click')({target:{closest:()=>expand}});
    assert.equal(f.evidence.childShown,true);
    assert.match(f.evidence.childHtml,/Tempo total do cliente/);
    f.handlers.get('click')({target:{closest:()=>expand}});
    assert.equal(f.evidence.childShown,false);
    assert.equal(f.messages.textContent,'');
});

test('DataTables restores safe sort, length and columns from the link', async () => {
    const f=makeGrid({location:'https://host.test/producao?ano-mes=2026-08&dtorder=5:desc&dtlength=50&name=Pessoa'});
    const result=await initializeReportTable(f.doc,f.options);
    assert.ok(result);
    assert.deepEqual(result.apiInstance.order(),[[5,'desc']]);
    assert.equal(result.apiInstance.page.len(),50);
    assert.ok(f.evidence.table.options.columns.every(col => col.name));
});

test('disallowed URL ordering or corrupted source does not corrupt financial display', async () => {
    const unsafe=makeGrid({location:'https://host.test/producao?dtorder=999:desc&dtlength=999'});
    const instance=await initializeReportTable(unsafe.doc,unsafe.options);
    assert.ok(instance);
    assert.deepEqual(instance.apiInstance.order(),[[0,'asc']]);
    assert.equal(instance.apiInstance.page.len(),10);
    const missing=makeGrid({payload:{message:'bad',data:'not-array'}});
    assert.equal(await initializeReportTable(missing.doc,missing.options),null);
    assert.match(missing.messages.textContent,/inválido/);
});
