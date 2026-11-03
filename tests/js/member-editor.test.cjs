// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {editorUrl, contactEndpoint, kimaiLinkEndpoint, identityEndpoint, initialize, bindFieldControls} = require('../../public/js/member-editor.js');

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

function fixture({failGet=false, failPost=false, changed=true,
    contactStatus='available', contactCreateFails=false, contactUid='luis.souza', contacts=[],
    kimaiConfirmFails=false, identityResponder=null, searchResponder=null,
    standalone=false, location='https://host.test/relatorios/producao?ano-mes=2026-08'} = {}) {
    const dialog=node();
    dialog.open=false;
    dialog.showModal=()=>{dialog.open=true;};
    dialog.close=()=>{dialog.open=false;};
    const content=node(), feedback=node(), standaloneElement=node(), standaloneFeedback=node();
    let reloads=0;
    standaloneElement.querySelector=sel=>sel==='[data-standalone-feedback]'?standaloneFeedback:null;
    const items={
        'member-editor-dialog':dialog,
        'member-editor-content':content,
        'member-editor-feedback':feedback,
    };
    const handlers=new Map(),dispatched=[];
    const doc={
        getElementById:id=>items[id],
        querySelector:sel=>standalone && sel==='[data-member-editor-standalone]'?standaloneElement:null,
        createElement:() => ({...node(), dataset:{}, children:[],
            appendChild(child){this.children.push(child);}, replaceChildren(...children){this.children=children;}}),
        addEventListener:(type,cb)=>handlers.set(type,cb),
        fire:(type,event)=>handlers.get(type)?.(event),
        dispatchEvent:event=>{dispatched.push(event);},
    };
    const calls=[];
    const loc=new URL(location);
    loc.reload=()=>{reloads++;};
    const options={
        location:loc,
        window:{location:loc,CustomEvent:class {constructor(name,params){this.type=name;this.detail=params?.detail;}}},
        fetch:async(url,config)=>{
            calls.push({url,config});
            if(url.includes('/vinculos/conferir')) {
                if (identityResponder) return identityResponder(url, config);
                const search=new URL(url).searchParams;
                const source=search.get('source'), id=search.get('id');
                return {ok:true, status:200, json:async()=>({identity:{
                    source,id,name:'Renata Demétrio',username:source==='nextcloud'?'renata.demetrio':'renata',
                    email:'renata@example.test',enabled:true,kind:'Fornecedor',
                    tax_hint:'CPF final 725',tax_match:'match',linked_account:'renata.demetrio',
                }})};
            }
            if(url.includes('/vinculos/opcoes')) {
                if(searchResponder) return searchResponder(url,config);
                return {ok:true,status:200,json:async()=>({items:[]})};
            }
            if(url.includes('/contato/proposta')) {
                return {ok:true, status:200, json:async()=>({status:contactStatus,
                    uid:contactUid,name:'Luis Souza',tax_hint:'CPF final 725',contacts})};
            }
            if(url.includes('/contato/criar')) {
                return {ok:!contactCreateFails, status:contactCreateFails?409:201,
                    json:async()=>contactCreateFails ? {error:'Contato já existente no Akaunting.'} :
                        {id:91,message:'Contato criado. Salve os vínculos.'}};
            }
            if(url.includes('/vincular-kimai')) {
                return {ok:!kimaiConfirmFails, status:kimaiConfirmFails?409:200,
                    json:async()=>kimaiConfirmFails ?
                        {error:'Conta Nextcloud já vinculada a outro usuário Kimai.'} :
                        {message:'Vínculo Kimai-Nextcloud confirmado. Edite os dados cadastrais.'}};
            }
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
    return {doc,dialog,content,feedback,calls,dispatched,controller,loc,standaloneElement,standaloneFeedback,
        reloadCount:()=>reloads};
}

test('editor URL only accepts the same-origin, positive Kimai ID and known path', () => {
    const base='https://host.test/relatorios/producao';
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

test('identity preview uses only the admin same-origin URL and known source', () => {
    const base='https://host.test/configuracao/cooperados';
    const form={action:'/configuracao/cooperado/39/vincular'};
    const parsed=new URL(identityEndpoint(form,base,'akaunting','35','renata.demetrio'));
    assert.equal(parsed.pathname,'/configuracao/vinculos/conferir');
    assert.equal(parsed.searchParams.get('source'),'akaunting');
    assert.equal(parsed.searchParams.get('id'),'35');
    assert.equal(parsed.searchParams.get('uid'),'renata.demetrio');
    assert.equal(identityEndpoint(form,base,'kimai','../../secret'),null);
    assert.equal(identityEndpoint(form,base,'anything','39'),null);
    assert.equal(identityEndpoint({action:'https://bad.test/configuracao/cooperado/39/vincular'},base,'kimai','39'),null);
});

function makeIdentityWidget(f, source='kimai') {
    const output={...node(),children:[],
        replaceChildren(){this.children=[];this.textContent='';},
        appendChild(child){this.children.push(child);},
    };
    const preview={
        querySelector:sel=>sel==='[data-identity-result="'+source+'"]'?output:null,
        classList:{add(){},remove(){}},
    };
    const inputs={
        nextcloud_uid:{value:'renata.demetrio',name:'nextcloud_uid'},
        kimai_user_id:{value:'39',name:'kimai_user_id'},
        akaunting_contact_id:{value:'35',name:'akaunting_contact_id'},
    };
    const form={
        action:'https://host.test/configuracao/cooperado/39/vincular',
        querySelector:sel=>{
            if(sel==='[data-identity-preview="'+source+'"]') return preview;
            const match=/^\[name="([a-z_]+)"\]$/.exec(sel);
            return match?inputs[match[1]]||null:null;
        },
    };
    const target={dataset:{identityVerify:source},
        closest:sel=>sel==='[data-identity-verify]'?target:
            sel==='.member-link-form'?form:null,
    };
    return {output,preview,inputs,form,target};
}

function identityRenderedText(node) {
    return [node.textContent || '',...(node.children || []).map(identityRenderedText)].join(' ');
}

test('linked Kimai identity displays the actual account name, login, email and linked Nextcloud', async () => {
    const f=fixture({standalone:true});
    const view=makeIdentityWidget(f,'kimai');
    f.standaloneElement.fire('click',{target:view.target});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(f.calls.length,1);
    assert.equal(new URL(f.calls[0].url).searchParams.get('id'),'39');
    const text=identityRenderedText(view.output);
    assert.match(text,/Renata Demétrio/);
    assert.match(text,/renata@example.test/);
    assert.match(text,/renata.demetrio/);
    assert.match(text,/Registro localizado na origem/);
    assert.doesNotMatch(text,/Cadastro confirmado automaticamente/);
});

test('Akaunting contact shows fiscal agreement without exposing the full CPF', async () => {
    const f=fixture({standalone:true});
    const view=makeIdentityWidget(f,'akaunting');
    f.standaloneElement.fire('click',{target:view.target});
    await new Promise(resolve=>setImmediate(resolve));
    const text=identityRenderedText(view.output);
    assert.match(text,/Fornecedor/);
    assert.match(text,/CPF final 725/);
    assert.match(text,/Coincide com o cadastro Nextcloud/);
    assert.doesNotMatch(text,/52998224725/);
});

test('missing fiscal data names Nextcloud as the next action without inventing values', async () => {
    const f=fixture({standalone:true,identityResponder:async url=>({
        ok:true,status:200,json:async()=>({identity:{
            source:'akaunting',id:new URL(url).searchParams.get('id'),name:'Crisciany Souza',
            username:'',email:'',enabled:null,kind:'Fornecedor',tax_hint:'CPF final 725',
            tax_match:'missing',linked_account:'',
        }}),
    })});
    const view=makeIdentityWidget(f,'akaunting');
    f.standaloneElement.fire('click',{target:view.target});
    await new Promise(resolve=>setImmediate(resolve));
    const text=identityRenderedText(view.output);
    assert.match(text,/falta CPF\/CNPJ válido no Nextcloud/);
    assert.match(text,/Preencha em Dados cadastrais/);
    assert.doesNotMatch(text,/52998224725/);
});

test('identity conflict warns without claiming that two accounts are the same person', async () => {
    const f=fixture({standalone:true,identityResponder:async url=>({ok:true,status:200,
        json:async()=>({identity:{source:'kimai',id:new URL(url).searchParams.get('id'),
            name:'Pessoa',username:'conta',email:'',enabled:true,
            kind:'Usuário',tax_hint:'',tax_match:'',linked_account:'outro.usuario'}}),
    })});
    const view=makeIdentityWidget(f,'kimai');
    f.standaloneElement.fire('click',{target:view.target});
    await new Promise(resolve=>setImmediate(resolve));
    assert.match(identityRenderedText(view.output),/Atenção: este registro já está vinculado a outra conta Nextcloud/);
});

test('stale lookup cannot replace the identity for a newly selected account', async () => {
    let finishOld;
    const pending=new Promise(resolve=>{finishOld=resolve;});
    const f=fixture({standalone:true,identityResponder:async url=>{
        const id=new URL(url).searchParams.get('id');
        if (id==='35') await pending;
        return {ok:true,status:200,json:async()=>({identity:{source:'akaunting',id,
            name:id==='35'?'Conta errada':'Conta certa',username:'',email:'',
            enabled:null,kind:'Fornecedor',tax_hint:'CPF final 725',tax_match:'match',linked_account:''}})};
    }});
    const view=makeIdentityWidget(f,'akaunting');
    f.standaloneElement.fire('click',{target:view.target});
    view.inputs.akaunting_contact_id.value='36';
    f.standaloneElement.fire('click',{target:view.target});
    await new Promise(resolve=>setImmediate(resolve));
    finishOld();
    await new Promise(resolve=>setImmediate(resolve));
    const text=identityRenderedText(view.output);
    assert.match(text,/Conta certa/);
    assert.doesNotMatch(text,/Conta errada/);
});

function searchWidget(f) {
    const names=['nextcloud','akaunting'];
    const hidden={nextcloud:{value:''},akaunting:{value:''}};
    const kimai={value:'27'};
    const buttons={confirm:{disabled:false},save:{disabled:false}};
    const provision={hidden:false};
    const inputs={};
    const statuses={};
    const results={};
    const form={
        action:'https://host.test/configuracao/cooperado/27/vincular',
        querySelector:sel=>{
            const m=/^\[name="([a-z_]+)"\]$/.exec(sel);
            if(m){return {'nextcloud_uid':hidden.nextcloud,'akaunting_contact_id':hidden.akaunting,
                'kimai_user_id':kimai}[m[1]]||null;}
            const source=/^\[data-link-(?:results|status|source)="([a-z]+)"\]$/.exec(sel);
            if(source) return sel.startsWith('[data-link-results')?results[source[1]]:
                sel.startsWith('[data-link-status')?statuses[source[1]]:inputs[source[1]];
            if(sel==='[data-kimai-confirm]')return buttons.confirm;
            if(sel==='[data-links-save]')return buttons.save;
            if(sel==='[data-contact-provision]')return provision;
            return null;
        },
    };
    for(const source of names){
        inputs[source]={value:'',dataset:{linkSource:source},
            closest:sel=>sel==='.member-link-form'?form:null};
        statuses[source]={textContent:'',classList:{toggle() {}}};
        results[source]={children:[],replaceChildren(){this.children=[];},
            appendChild(child){
                child.closest=sel=>sel==='[data-link-pick]'?child:
                    sel==='.member-link-form'?form:null;
                this.children.push(child);
            },
        };
    }
    return {form,inputs,hidden,statuses,results,buttons,provision,kimai,search(source,query){
        inputs[source].value=query;
        f.standaloneElement.fire('input',{target:inputs[source]});
    },choose(source,index=0){
        const item=results[source].children[index];
        f.standaloneElement.fire('click',{target:item});
        return item;
    }};
}

const searchResponse=items=>({ok:true,status:200,json:async()=>({items})});

test('typing a Nextcloud login is not choosing it, and inactive accounts can be inspected explicitly', async () => {
    const f=fixture({standalone:true,searchResponder:async url=>{
        assert.equal(new URL(url).searchParams.get('source'),'nextcloud');
        return searchResponse([{id:'crisciany.souza',label:'crisciany.souza'}]);
    },identityResponder:async url=>{
        const parsed=new URL(url);
        return {ok:true,status:200,json:async()=>({identity:{
            source:parsed.searchParams.get('source'),id:parsed.searchParams.get('id'),
            name:'Crisciany Souza',username:'crisciany.souza',email:'crisciany@example.test',
            enabled:false,kind:'Usuário',tax_hint:'',tax_match:'',linked_account:'',
        }})};
    }});
    const w=searchWidget(f);
    w.search('nextcloud','crisciany');
    assert.equal(w.hidden.nextcloud.value,'');
    assert.equal(w.buttons.confirm.disabled,true);
    assert.equal(w.buttons.save.disabled,true);
    await new Promise(resolve=>setTimeout(resolve,260));
    assert.equal(w.results.nextcloud.children.length,1);
    assert.match(w.statuses.nextcloud.textContent,/Escolha um dos 1 resultados/);
    w.choose('nextcloud');
    assert.equal(w.hidden.nextcloud.value,'crisciany.souza');
    assert.equal(w.inputs.nextcloud.value,'crisciany.souza');
    assert.equal(w.buttons.confirm.disabled,false);
    assert.equal(w.buttons.save.disabled,true,'Saving both links requires a selected financial contact');
    assert.match(w.statuses.nextcloud.textContent,/ainda não foi salvo/);
    assert.equal(f.calls.some(c=>c.config?.method==='POST'),false,'Search and choice are read-only');
});

test('Akaunting name search shows the real Crisciany contact, but a misspelling cannot be saved', async () => {
    const f=fixture({standalone:true,searchResponder:async url=>{
        const q=new URL(url).searchParams.get('q');
        return searchResponse(q==='Crisciany'
            ? [{id:172,label:'Crisciany Souza · Fornecedor'}] : []);
    }});
    const w=searchWidget(f);
    w.search('akaunting','Cristiane');
    await new Promise(resolve=>setTimeout(resolve,265));
    assert.equal(w.results.akaunting.children.length,0);
    assert.equal(w.hidden.akaunting.value,'');
    assert.match(w.statuses.akaunting.textContent,/Nenhum contato financeiro encontrado/);
    assert.equal(w.provision.hidden,false,'Only absent contacts permit a separate creation action');
    w.search('akaunting','Crisciany');
    await new Promise(resolve=>setTimeout(resolve,265));
    assert.equal(w.results.akaunting.children.length,1);
    assert.equal(w.provision.hidden,true,'Creating a duplicate is not offered when a contact exists');
    w.choose('akaunting');
    assert.equal(w.hidden.akaunting.value,'172');
    assert.match(w.inputs.akaunting.value,/Crisciany Souza/);
    assert.equal(w.buttons.save.disabled,true,'Nextcloud confirmation still required');
    assert.equal(f.calls.some(c=>c.config?.method==='POST'),false);
});

test('changing an already chosen search invalidates the technical ID and both save buttons', async () => {
    const f=fixture({standalone:true,searchResponder:async()=>searchResponse([])});
    const w=searchWidget(f);
    w.hidden.nextcloud.value='crisciany.souza';
    w.hidden.akaunting.value='172';
    w.search('nextcloud','outra.conta');
    assert.equal(w.hidden.nextcloud.value,'');
    assert.equal(w.hidden.akaunting.value,'172');
    assert.equal(w.buttons.confirm.disabled,true);
    assert.equal(w.buttons.save.disabled,true);
    await new Promise(resolve=>setTimeout(resolve,260));
});

test('old asynchronous suggestions cannot replace newer search results', async () => {
    let resolveOld;
    const f=fixture({standalone:true,searchResponder:async url=>{
        const q=new URL(url).searchParams.get('q');
        if(q==='old')return new Promise(resolve=>{resolveOld=resolve;});
        return searchResponse([{id:172,label:'Crisciany Souza'}]);
    }});
    const w=searchWidget(f);
    w.search('akaunting','old');
    await new Promise(resolve=>setTimeout(resolve,245));
    assert.equal(typeof resolveOld,'function');
    w.search('akaunting','Crisciany');
    await new Promise(resolve=>setTimeout(resolve,245));
    resolveOld(searchResponse([{id:99,label:'Conta errada'}]));
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(w.results.akaunting.children.length,1);
    assert.equal(w.results.akaunting.children[0].dataset.linkPick,'172');
    assert.equal(w.hidden.akaunting.value,'');
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
            action:'https://host.test/configuracao/cooperado/10/nextcloud',
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

function provisionWidget(f) {
    const contact={value:''}, uid={value:'luis.souza'},
        name={value:''},confirm={checked:false},create={disabled:true};
    const body={hidden:false},newFields={hidden:true},status={textContent:''},
        existing={hidden:true,children:[],appendChild(child){this.children.push(child);},
            replaceChildren(...items){this.children=items;}};
    const form={
        action:'https://host.test/configuracao/cooperado/40/vincular',
        querySelector:selector=>({
            '[name="akaunting_contact_id"]':contact,
            '[name="nextcloud_uid"]':uid,
        })[selector] || null,
    };
    const panel={dataset:{},
        closest:selector=>selector==='.member-link-form'?form:null,
        querySelector:selector=>({
            '[data-contact-provision-panel]':body,
            '[data-contact-provision-status]':status,
            '[data-contact-provision-existing]':existing,
            '[data-contact-provision-new]':newFields,
            '[data-contact-provision-name]':name,
            '[data-contact-provision-confirm]':confirm,
            '[data-contact-provision-create]':create,
        })[selector] || null,
    };
    function control(marker, item) {
        return {
            ...item,
            closest(selector){
                if(selector === '[data-contact-provision]') return panel;
                if(selector.includes(marker)) return this;
                return null;
            },
        };
    }
    const trigger=control('[data-contact-provision-open]', {disabled:false});
    const submit=create;
    submit.closest=selector=>selector==='[data-contact-provision]'?panel:
        selector.includes('[data-contact-provision-create]')?submit:null;
    const checkbox=confirm;
    checkbox.closest=()=>panel;
    checkbox.matches=selector=>selector==='[data-contact-provision-confirm]';
    const fire=async control=>{
        f.dialog.fire('click',{target:control});
        await new Promise(resolve=>setImmediate(resolve));
    };
    return {form,panel,trigger,submit,checkbox,status,body,existing,newFields,name,uid,contact,create,
        fire};
}

test('creation endpoints accept only a known same-origin cooperado form', () => {
    const base='https://host.test/configuracao/cooperados';
    assert.equal(contactEndpoint({action:'/configuracao/cooperado/40/vincular'},base,'proposta'),
        'https://host.test/configuracao/cooperado/40/contato/proposta');
    assert.equal(contactEndpoint({action:'/configuracao/cooperado/40/vincular'},base,'criar'),
        'https://host.test/configuracao/cooperado/40/contato/criar');
    assert.equal(contactEndpoint({action:'/configuracao/cooperado/40/vincular'},base,'delete'),null);
    assert.equal(contactEndpoint({action:'https://external.test/configuracao/cooperado/40/vincular'},base,'criar'),null);
    assert.equal(contactEndpoint({action:'/configuracao/cooperado/0/vincular'},base,'criar'),null);
});

test('creating a new Akaunting contact is explicit and never auto-links Nextcloud', async () => {
    const f=fixture();
    await f.controller.load('/configuracao/cooperado/40/editar');
    const widget=provisionWidget(f);
    await widget.fire(widget.trigger);
    assert.equal(widget.body.hidden,false);
    assert.equal(widget.newFields.hidden,false);
    assert.match(widget.status.textContent,/Nextcloud: luis.souza/);
    assert.match(widget.status.textContent,/CPF final 725/);
    assert.equal(widget.name.value,'Luis Souza');
    assert.equal(widget.create.disabled,true,'Cannot create before explicit identity confirmation');
    widget.checkbox.checked=true;
    f.dialog.fire('change',{target:widget.checkbox});
    assert.equal(widget.create.disabled,false);
    widget.name.value='Luiz Amorim';
    const originalFormData=globalThis.FormData;
    globalThis.FormData=class {
        constructor(form){this.form=form;this.fields={};}
        set(key,value){this.fields[key]=value;}
    };
    try {
        await widget.fire(widget.submit);
        assert.equal(f.calls.length,3,'Read editor, inspect contact, then write only once');
        assert.equal(f.calls[1].config.method,undefined,'Proposal must be read-only');
        assert.equal(new URL(f.calls[1].url).searchParams.get('uid'),'luis.souza');
        assert.equal(f.calls[2].config.method,'POST');
        assert.equal(f.calls[2].config.body.fields.contact_name,'Luiz Amorim');
        assert.equal(f.calls[2].config.body.fields.identity_confirmed,'1');
        assert.equal(widget.contact.value,'91');
        assert.equal(widget.body.hidden,false, 'The financial contact section does not collapse');
        assert.equal(widget.newFields.hidden,true, 'Creation inputs disappear after confirmed creation');
        assert.equal(widget.create.disabled,true);
        assert.match(f.feedback.textContent,/Salve os vínculos/);
        assert.equal(f.dispatched.at(-1).type,'member-source-changed');
        assert.equal(f.dispatched.at(-1).detail.source,'akaunting');
        assert.equal(f.calls.filter(call=>call.url.includes('/vincular')).length,0,
            'Creating the contact must not automatically associate it');
    } finally {globalThis.FormData=originalFormData;}
});

test('existing financial contact can be selected without invoking creation', async () => {
    const f=fixture({contactStatus:'existing',contacts:[
        {id:77,name:'Luiz Amorim',type:'vendor'},
        {id:80,name:'Luiz A.',type:'employee'},
    ]});
    await f.controller.load('/configuracao/cooperado/40/editar');
    const w=provisionWidget(f);
    await w.fire(w.trigger);
    assert.equal(w.newFields.hidden,true);
    assert.equal(w.existing.hidden,false);
    assert.equal(w.existing.children.length,2);
    assert.match(w.existing.children[0].textContent,/Fornecedor/);
    const pick={dataset:{contactPick:'77'},closest:sel=>sel==='[data-contact-provision]'?w.panel:
        sel.includes('[data-contact-pick]')?pick:null};
    await w.fire(pick);
    assert.equal(w.contact.value,'77');
    assert.equal(w.body.hidden,false, 'No collapsible panel is used for selecting existing contacts');
    assert.equal(w.existing.hidden,true);
    assert.match(w.status.textContent,/Salve os vínculos/);
    assert.match(f.feedback.textContent,/Salvar vínculos/);
    assert.equal(f.calls.length,2,'No financial write when selecting an existing contact');
});

test('creation check does not run when an existing contact is already selected', async () => {
    const f=fixture();
    await f.controller.load('/configuracao/cooperado/40/editar');
    const w=provisionWidget(f);
    w.contact.value='92';
    await w.fire(w.trigger);
    assert.equal(f.calls.length,1,'Do not issue a financial lookup while contact ID is filled');
    assert.equal(w.newFields.hidden,true);
    assert.match(w.status.textContent,/Já há um contato selecionado/);
});

test('profile identity mismatch and denied contact creation fail closed', async () => {
    const mismatch=fixture({contactUid:'different-account'});
    await mismatch.controller.load('/configuracao/cooperado/40/editar');
    const w=provisionWidget(mismatch);
    await w.fire(w.trigger);
    assert.equal(w.newFields.hidden,true);
    assert.match(w.status.textContent,/conta Nextcloud selecionada mudou/);
    assert.equal(mismatch.calls.length,2);

    const denied=fixture({contactCreateFails:true});
    await denied.controller.load('/configuracao/cooperado/40/editar');
    const v=provisionWidget(denied);
    await v.fire(v.trigger);
    v.checkbox.checked=true;
    denied.dialog.fire('change',{target:v.checkbox});
    const originalFormData=globalThis.FormData;
    globalThis.FormData=class {set() {}};
    try {
        await v.fire(v.submit);
        assert.equal(v.contact.value,'');
        assert.equal(v.create.disabled,true,'Never repeat a failed financial write automatically');
        assert.match(v.status.textContent,/Atualize a consulta antes de tentar outra vez/);
        assert.equal(denied.calls.length,3);
    } finally {globalThis.FormData=originalFormData;}
});

test('technical link endpoint is limited to the selected same-origin cooperado', () => {
    const base='https://host.test/configuracao/cooperados';
    assert.equal(kimaiLinkEndpoint({action:'/configuracao/cooperado/39/vincular'}, base),
        'https://host.test/configuracao/cooperado/39/vincular-kimai');
    assert.equal(kimaiLinkEndpoint({action:'https://bad.test/configuracao/cooperado/39/vincular'}, base), null);
    assert.equal(kimaiLinkEndpoint({action:'/configuracao/cooperado/0/vincular'}, base), null);
});

function kimaiConfirmationButton(form) {
    const button={disabled:false};
    button.closest=selector=>selector.includes('[data-kimai-confirm]') ? button :
        selector==='.member-link-form'?form:null;
    return button;
}

test('new cooperado can confirm Kimai-Nextcloud without financial contact or fiscal data', async () => {
    const f=fixture({standalone:true});
    const input={value:'39'}, uid={value:'renata'};
    const form={
        action:'https://host.test/configuracao/cooperado/39/vincular',
        querySelector:sel=>({
            '[name="kimai_user_id"]':input, '[name="nextcloud_uid"]':uid,
        })[sel] || null,
        closest:sel=>sel==='[data-member-editor-standalone]'?f.standaloneElement:null,
    };
    const button=kimaiConfirmationButton(form);
    const previous=globalThis.FormData;
    globalThis.FormData=class {constructor(form){this.form=form;}};
    try {
        f.standaloneElement.fire('click',{target:button});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(f.calls.length,1);
        assert.equal(f.calls[0].config.method,'POST');
        assert.equal(f.calls[0].url,'https://host.test/configuracao/cooperado/39/vincular-kimai');
        assert.equal(f.calls[0].config.body.form,form);
        assert.equal(f.reloadCount(),1,'The editor reloads so the fiscal fields become available');
        assert.equal(button.disabled,false);
        assert.equal(f.dialog.open,false);
        assert.equal(f.dispatched.at(-1).detail.source,'nextcloud');
        assert.equal(f.calls.filter(c=>c.url.includes('contato')).length,0);
    } finally {globalThis.FormData=previous;}
});

test('technical link conflict is shown without modifying financial records', async () => {
    const f=fixture({standalone:true, kimaiConfirmFails:true});
    const form={
        action:'https://host.test/configuracao/cooperado/39/vincular',
        querySelector:sel=>sel==='[name="nextcloud_uid"]'?{value:'renata'}:
            sel==='[name="kimai_user_id"]'?{value:'39'}:null,
        closest:sel=>sel==='[data-member-editor-standalone]'?f.standaloneElement:null,
    };
    const button=kimaiConfirmationButton(form);
    const previous=globalThis.FormData;
    globalThis.FormData=class {constructor(form){this.form=form;}};
    try {
        f.standaloneElement.fire('click',{target:button});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(f.reloadCount(),0);
        assert.match(f.standaloneFeedback.textContent,/outro usuário Kimai/);
        assert.equal(button.disabled,false);
        assert.equal(f.calls.length,1);
    } finally {globalThis.FormData=previous;}
});

test('standalone administration page can edit fiscal data after confirmation', async () => {
    const f=fixture({standalone:true});
    const button={disabled:false};
    const input={value:'12345678901', defaultValue:''};
    const expected={value:'old-revision'};
    const form={
        action:'https://host.test/configuracao/cooperado/39/nextcloud',
        matches:sel=>sel==='.member-editor-field',
        classList:{contains:()=>false},
        querySelector:sel=>({
            '[type="submit"]':button, '[name="value"]':input,
            '[name="expected"]':expected,
        })[sel]||null,
        closest:sel=>sel==='[data-member-editor-standalone]'?f.standaloneElement:null,
    };
    const previous=globalThis.FormData;
    globalThis.FormData=class {constructor(form){this.form=form;}};
    try {
        let prevented=false;
        f.standaloneElement.fire('submit',{target:form,preventDefault(){prevented=true;}});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(prevented,true);
        assert.equal(f.calls.length,1);
        assert.equal(f.calls[0].config.method,'POST');
        assert.equal(input.disabled,false);
        assert.match(f.standaloneFeedback.textContent,/Salvo na origem/);
    } finally {globalThis.FormData=previous;}
});

test('standalone administration page saves links without opening the modal', async () => {
    const f=fixture({standalone:true});
    const originalFormData=globalThis.FormData;
    globalThis.FormData=class {constructor(form){this.form=form;}};
    try {
        let prevented=false;
        const button={disabled:false};
        const form={
            action:'https://host.test/configuracao/cooperado/40/vincular',
            classList:{contains:sel=>sel==='member-link-form'},
            querySelector:sel=>({
                '[type="submit"]':button,
                '[name="nextcloud_uid"]':{value:'luis.souza'},
                '[name="akaunting_contact_id"]':{value:'35'},
            })[sel]||null,
            closest:sel=>sel==='[data-member-editor-standalone]'?f.standaloneElement:null,
        };
        f.standaloneElement.fire('submit',{target:form,preventDefault(){prevented=true;}});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(prevented,true);
        assert.equal(f.calls.length,1);
        assert.equal(f.calls[0].config.method,'POST');
        assert.equal(f.reloadCount(),1);
        assert.equal(f.dialog.open,false);
        assert.equal(button.disabled,false);
    } finally {globalThis.FormData=originalFormData;}
});

test('link form saves through a same-origin endpoint and reloads the editor only after confirmation', async () => {
    const f=fixture();
    await f.controller.load('/configuracao/cooperado/10/editar');
    const originalFormData=globalThis.FormData;
    globalThis.FormData=class {constructor(form){this.form=form;}};
    try {
        const button={disabled:false};
        const form={
            action:'https://host.test/configuracao/cooperado/10/vincular',
            classList:{contains:name=>name==='member-link-form'},
            querySelector:sel=>({
                '[type="submit"]':button,
                '[name="nextcloud_uid"]':{value:'luis.souza'},
                '[name="akaunting_contact_id"]':{value:'35'},
            })[sel]||null,
        };
        f.dialog.fire('submit',{target:form,preventDefault(){}});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(f.calls.length,3,'GET editor, POST link, GET updated editor');
        assert.equal(f.calls[1].config.method,'POST');
        assert.equal(f.calls[1].config.redirect,'error');
        assert.equal(f.calls[1].config.credentials,'same-origin');
        assert.equal(button.disabled,false);
        form.action='https://another.example.test/configuracao/cooperado/10/vincular';
        f.dialog.fire('submit',{target:form,preventDefault(){}});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(f.calls.length,3,'Cross-origin link update must be refused');
        assert.match(f.feedback.textContent,/inválido/);
    } finally {
        globalThis.FormData=originalFormData;
    }
});
