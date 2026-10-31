// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {initialize, changedFields} = require('../../public/js/admin-settings.js');

function fixture(options = {}) {
    const handlers = {};
    const field = {name:'PERCENTUAL_MAXIMO', value:'25', defaultValue:'25',
        dataset:{critical:'yes',secret:'no'}};
    const secret = {name:'KIMAI_API_TOKEN',value:'',defaultValue:'',type:'password',
        dataset:{critical:'no',secret:'yes'}};
    const save = {disabled:false};
    const feedback = {textContent:''};
    const toggleHandlers = {};
    const attrs = {'aria-pressed':'false'};
    const reveal = {dataset:{revealKey:'KIMAI_API_TOKEN'}, disabled:false,textContent:'Mostrar',
        addEventListener:(name,callback)=>{toggleHandlers[name]=callback;},
        getAttribute:key=>attrs[key],setAttribute:(key,value)=>{attrs[key]=value;}};
    const inputs = [field,secret];
    const form = {dataset:{adminSettingsGroup:'1'},
        querySelectorAll:sel=>sel==='[data-config-input]'?inputs:sel==='[data-reveal-key]'?[reveal]:[],
        querySelector:sel=>sel==='[type="submit"]'?save:
            sel==='.admin-settings-feedback'?feedback:sel.startsWith('[data-reveal-key=')?reveal:sel.includes('KIMAI_API_TOKEN')?secret:null,
        addEventListener:(name,callback)=>{handlers[name]=callback;}};
    const panel={dataset:{token:'csrf-test',version:'0'},
        querySelectorAll:()=>[form]};
    const doc={getElementById:id=>id==='admin-settings'?panel:null};
    const requests=[];
    const request=async (path,init)=>{
        requests.push({path,init});
        return options.fail ? {ok:false,json:async()=>({error:'Falha de gravação'})} :
            {ok:true,json:async()=>path.endsWith('/revelar')?{value:'test-token'}:
                {version:1,changedKeys:['PERCENTUAL_MAXIMO'],message:'Configuração salva.'}};
    };
    let confirms=0;
    const confirmChange=()=>{confirms++;return options.confirm!==false;};
    const result=initialize(doc,request,confirmChange);
    return {handlers,field,secret,save,feedback,reveal,toggleHandlers,
        attrs,requests,panel,form,confirms:()=>confirms,result};
}

test('admin settings are inert without the panel and initially require a changed field', ()=>{
    assert.equal(initialize({getElementById:()=>null}),false);
    const f=fixture();
    assert.equal(f.result,true);
    assert.equal(f.save.disabled,true);
    assert.deepEqual(changedFields(f.form),{});
    f.field.value='30';
    f.handlers.input();
    assert.equal(f.save.disabled,false);
    assert.deepEqual(changedFields(f.form),{PERCENTUAL_MAXIMO:'30'});
});

test('save submits changed values only with CSRF and requires confirmation for critical rules',async()=>{
    const blocked=fixture({confirm:false});
    blocked.field.value='30';blocked.handlers.input();
    await blocked.handlers.submit({preventDefault(){}});
    assert.equal(blocked.requests.length,0);
    assert.equal(blocked.confirms(),1);

    const f=fixture();
    f.field.value='30';f.handlers.input();
    await f.handlers.submit({preventDefault(){}});
    assert.equal(f.requests.length,1);
    assert.equal(f.requests[0].path,'/configuracao/integracoes/salvar');
    const body=f.requests[0].init.body;
    assert.equal(body.get('_token'),'csrf-test');
    assert.equal(body.get('version'),'0');
    assert.deepEqual(JSON.parse(body.get('changes')),{PERCENTUAL_MAXIMO:'30'});
    assert.equal(f.field.defaultValue,'30');
    assert.equal(f.panel.dataset.version,'1');
    assert.equal(f.save.disabled,true);
    assert.equal(f.feedback.textContent,'Configuração salva.');
});

test('eye reveals a secret only on demand and hides it without modifying the configuration',async()=>{
    const f=fixture();
    assert.equal(f.secret.value,'');
    await f.toggleHandlers.click();
    assert.equal(f.requests[0].path,'/configuracao/integracoes/revelar');
    assert.equal(f.requests[0].init.body.get('key'),'KIMAI_API_TOKEN');
    assert.equal(f.secret.value,'test-token');
    assert.equal(f.secret.type,'text');
    assert.equal(f.save.disabled,true);
    await f.toggleHandlers.click();
    assert.equal(f.secret.type,'password');
    assert.equal(f.secret.value,'');
    assert.equal(f.attrs['aria-pressed'],'false');
    assert.equal(f.requests.length,1);
});

test('failed configuration request does not claim success',async()=>{
    const f=fixture({fail:true});
    f.field.value='30';f.handlers.input();
    await f.handlers.submit({preventDefault(){}});
    assert.equal(f.panel.dataset.version,'0');
    assert.equal(f.field.defaultValue,'25');
    assert.equal(f.feedback.textContent,'Falha de gravação');
    assert.equal(f.save.disabled,false);
    await f.toggleHandlers.click();
    assert.equal(f.secret.type,'password');
});
