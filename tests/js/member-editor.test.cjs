// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {editorUrl, initialize, bindFieldControls, copyTaxNumber} = require('../../public/js/member-editor.js');

function node() {
    const handlers = new Map();
    return {
        textContent:'', innerHTML:'', listeners:handlers, focused:false,
        addEventListener(name,cb) {handlers.set(name,cb);},
        fire(name,event={}) {return handlers.get(name)?.(event);},
        replaceChildren() {this.textContent='';this.innerHTML='';},
        querySelector() {return {focus() {}};},
        focus() {this.focused=true;},
    };
}

function fixture({failGet=false, failPost=false, changed=true, location='https://host.test/producao?ano-mes=2026-08'} = {}) {
    const dialog=node();
    dialog.open=false;
    dialog.showModal=()=>{dialog.open=true;};
    dialog.close=()=>{dialog.open=false;};
    const content=node(), feedback=node();
    const items={
        'member-editor-dialog':dialog,
        'member-editor-content':content,
        'member-editor-feedback':feedback,
    };
    const handlers=new Map(),dispatched=[];
    const doc={
        getElementById:id=>items[id],
        addEventListener:(type,cb)=>handlers.set(type,cb),
        fire:(type,event)=>handlers.get(type)?.(event),
        dispatchEvent:event=>{dispatched.push(event);},
    };
    const calls=[];
    const loc=new URL(location);
    const options={
        location:loc,
        window:{location:loc,CustomEvent:class {constructor(name,params){this.type=name;this.detail=params?.detail;}}},
        fetch:async(url,config)=>{
            calls.push({url,config});
            if(config.method==='POST'){
                return {
                    ok:!failPost, status:failPost?409:200,
                    text:async()=> 'Dados alterados na origem. Atualize a página.',
                    json:async()=>({changed,value:'2',expected:'new-revision',message:changed?'Salvo na origem.':'Sem alteração.'}),
                };
            }
            return {
                ok:!failGet,status:failGet?503:200,
                text:async()=>'<section data-member-editor-panel><form>Fake HTML trusted origin</form></section>',
            };
        },
    };
    const controller=initialize(doc,options);
    return {doc,dialog,content,feedback,calls,dispatched,controller,loc};
}

test('editor URL only accepts the same-origin, positive Kimai ID and known path', () => {
    const base='https://host.test/producao';
    assert.equal(editorUrl('/configuracao/cooperado/10/editar?competencia=2026-08',base).pathname,
        '/configuracao/cooperado/10/editar');
    for(const value of [
        'https://phishing.test/configuracao/cooperado/10/editar',
        '/configuracao/cooperado/0/editar',
        '/configuracao/cooperado/-1/editar',
        '/configuracao/cooperado/10/nextcloud',
        '/configuracao/cooperado/10/editar/extra',
        'http://[',
    ]) {
        assert.equal(editorUrl(value,base),null);
    }
});

test('missing modal is ignored; invalid editor path never opens it', async () => {
    assert.equal(initialize({getElementById:()=>null}),false);
    const f=fixture();
    assert.ok(f.controller);
    assert.equal(await f.controller.load('https://other.test/configuracao/cooperado/1/editar'),false);
    assert.equal(f.dialog.open,false);
    assert.equal(f.calls.length,0);
});

test('opens a lazy editor with protected request and restores focus on close', async () => {
    const f=fixture();
    const focus=node();
    assert.equal(await f.controller.load('/configuracao/cooperado/10/editar?competencia=2026-08',focus),true);
    assert.equal(f.dialog.open,true);
    assert.match(f.content.innerHTML,/data-member-editor-panel/);
    assert.equal(f.calls[0].config.headers['X-Requested-With'],'XMLHttpRequest');
    assert.equal(f.calls[0].config.credentials,'same-origin');
    f.controller.close();
    assert.equal(f.dialog.open,false);
    assert.equal(f.content.innerHTML,'');
    assert.equal(focus.focused,true);
    assert.equal(f.feedback.textContent,'');
});

test('delegated click opens only regular same-origin links and supports the close button', async () => {
    const f=fixture();
    let prevented=0;
    const focus=node();
    focus.href='https://host.test/configuracao/cooperado/20/editar?competencia=2026-08';
    await f.doc.fire('click',{target:{closest:()=>focus},preventDefault(){prevented++;},button:0});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(prevented,1);
    assert.equal(f.dialog.open,true);
    assert.equal(f.calls[0].url,focus.href);
    f.dialog.fire('click',{target:{closest:selector=>selector==='[data-member-editor-close]'?{}:null}});
    assert.equal(f.dialog.open,false);
});

test('GET errors and invalid redirects display a safe message instead of a blank editor', async () => {
    const f=fixture({failGet:true});
    const ok=await f.controller.load('/configuracao/cooperado/12/editar');
    assert.equal(ok,false);
    assert.match(f.feedback.textContent,/HTTP 503/);
    assert.equal(f.content.innerHTML,'');
    f.dialog.fire('cancel',{preventDefault(){}});
    assert.equal(f.dialog.open,false);
});

test('AJAX save uses the same source endpoint and notifies the production workflow', async () => {
    const f=fixture();
    await f.controller.load('/configuracao/cooperado/10/editar?competencia=2026-08');
    const originalFormData=globalThis.FormData;
    globalThis.FormData=class {constructor(form){this.form=form;this.valueWasEnabled=!form.querySelector('[name="value"]').disabled;}};
    try {
        const button={disabled:false}, value={value:'2',defaultValue:'1',disabled:false}, expected={value:'old'};
        const form={
            action:'https://host.test/configuracao/cooperado/10/nextcloud',
            matches:selector=>selector==='.member-editor-field',
            querySelector:selector=>selector.includes('submit')?button:selector.includes('expected')?expected:value,
        };
        await f.dialog.fire('submit',{target:form,preventDefault(){}});
        assert.equal(button.disabled,true,'Unmodified saved field cannot be resubmitted');
        assert.equal(value.defaultValue,'2');
        assert.equal(expected.value,'new-revision');
        assert.equal(f.calls.length,2,'Save returns only JSON; no redundant full editor reload');
        assert.equal(f.calls[1].config.method,'POST');
        assert.equal(f.calls[1].config.body.valueWasEnabled,true,'FormData must be assembled before disabling the edited input');
        assert.equal(f.calls[1].config.headers.Accept,'application/json');
        assert.equal(f.calls[1].config.redirect,'error');
        assert.equal(f.dispatched[0].type,'member-source-changed');
        assert.equal(f.dispatched[0].detail.source,'nextcloud');
        assert.equal(f.feedback.textContent,'Salvo na origem.');
    } finally {
        globalThis.FormData=originalFormData;
    }
});

test('unchanged values do not invalidate review; optimistic conflict does not signal success', async () => {
    const f=fixture({changed:false});
    await f.controller.load('/configuracao/cooperado/10/editar');
    const originalFormData=globalThis.FormData;
    globalThis.FormData=class {};
    try {
        const form={
            action:'https://host.test/configuracao/cooperado/10/kimai',
            matches:()=>true,querySelector:()=>({disabled:false}),
        };
        await f.dialog.fire('submit',{target:form,preventDefault(){}});
        assert.equal(f.dispatched.length,0);
        assert.equal(f.feedback.textContent,'Sem alteração.');
        const denied=fixture({failPost:true});
        await denied.controller.load('/configuracao/cooperado/12/editar');
        await denied.dialog.fire('submit',{target:form,preventDefault(){}});
        assert.match(denied.feedback.textContent,/Dados alterados/);
        assert.equal(denied.dispatched.length,0);
    } finally {
        globalThis.FormData=originalFormData;
    }
});

test('unauthorized write destination is blocked before POST and does not change sources', async () => {
    const f=fixture();
    await f.controller.load('/configuracao/cooperado/12/editar');
    const form={
        action:'https://outside.test/configuracao/cooperado/12/nextcloud',
        matches:()=>true,querySelector:()=>({disabled:false}),
    };
    await f.dialog.fire('submit',{target:form,preventDefault(){}});
    assert.match(f.feedback.textContent,/inválido/);
    assert.equal(f.calls.length,1);
});

test('field save buttons activate only after an actual edit', () => {
    const events=new Map();
    const input={value:'1,5',defaultValue:'1,5',addEventListener:(name,cb)=>events.set(name,cb)};
    const button={disabled:false};
    const form={querySelector:sel=>sel.includes('submit')?button:input};
    bindFieldControls({querySelectorAll:()=>[form]});
    assert.equal(button.disabled,true);
    input.value='1,75'; events.get('input')();
    assert.equal(button.disabled,false);
    input.value='1,5'; events.get('input')();
    assert.equal(button.disabled,true);
});

test('copying the Nextcloud document only fills the selected destination, without posting', () => {
    const source={value:'reference-value',defaultValue:'reference-value'};
    const target={value:'other-value',defaultValue:'other-value'};
    const submit={disabled:true};
    const root={querySelector:()=>source};
    const form={querySelector:selector=>selector.includes('submit')?submit:target};
    assert.equal(copyTaxNumber(root,form),true);
    assert.equal(target.value,'reference-value');
    assert.equal(target.defaultValue,'other-value');
    assert.equal(submit.disabled,false);
    source.value='unsaved-change';
    assert.equal(copyTaxNumber(root,form),false,'Only the confirmed Nextcloud value may be copied');
    assert.equal(target.value,'reference-value');
    assert.equal(copyTaxNumber({querySelector:()=>null},form),false);
});

test('legacy config ?cooperado deep links open an editor without eager roster queries', async () => {
    const f=fixture({location:'https://host.test/configuracao?cooperado=12&competencia=2026-08'});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(f.dialog.open,true);
    assert.equal(f.calls.length,1);
    assert.match(f.calls[0].url,/cooperado\/12\/editar\?competencia=2026-08/);
});
