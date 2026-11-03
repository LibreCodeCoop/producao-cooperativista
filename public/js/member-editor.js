// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

(function (scope) {
    const EDIT_PATH = /^\/configuracao\/cooperado\/[1-9]\d*\/editar$/;

    function editorUrl(href, base) {
        try {
            const url = new URL(href, base);
            if (url.origin !== new URL(base).origin || !EDIT_PATH.test(url.pathname)) {
                return null;
            }
            return url;
        } catch {
            return null;
        }
    }

    function contactEndpoint(form, page, operation) {
        if (!['proposta', 'criar'].includes(operation)) return null;
        try {
            const current = new URL(page);
            const action = new URL(form.action, page);
            const match = /^\/configuracao\/cooperado\/([1-9]\d*)\/vincular$/.exec(action.pathname);
            if (!match || action.origin !== current.origin ||
                !Number.isSafeInteger(Number(match[1]))) return null;
            return new URL('/configuracao/cooperado/' + match[1] + '/contato/' + operation, current).href;
        } catch {
            return null;
        }
    }

    function identityEndpoint(form, page, source, identifier, uid = '') {
        if (!['nextcloud', 'kimai', 'akaunting'].includes(source)) return null;
        const validForm = contactEndpoint(form, page, 'proposta');
        if (!validForm || !identifier || identifier.length > 128 || uid.length > 128) return null;
        if (source !== 'nextcloud' && (!/^[1-9]\d*$/.test(identifier)
            || !Number.isSafeInteger(Number(identifier)))) return null;
        const url = new URL('/configuracao/vinculos/conferir', validForm);
        url.searchParams.set('source', source);
        url.searchParams.set('id', identifier);
        if (source === 'akaunting' && uid) url.searchParams.set('uid', uid);
        return url.href;
    }

    function kimaiLinkEndpoint(form, page) {
        const proposal = contactEndpoint(form, page, 'proposta');
        return proposal ? proposal.replace(/\/contato\/proposta$/, '/vincular-kimai') : null;
    }

    function bindFieldControls(root) {
        for (const form of root?.querySelectorAll?.('.member-editor-field') || []) {
            const input = form.querySelector('[name="value"]');
            const button = form.querySelector('[type="submit"]');
            if (!input || !button) continue;
            const refresh = () => { button.disabled = input.value === input.defaultValue; };
            input.addEventListener('input', refresh);
            refresh();
        }
    }

    function initialize(doc = scope.document, options = {}) {
        const dialog = doc?.getElementById('member-editor-dialog');
        if (!dialog) return false;
        const standalone = doc.querySelector?.('[data-member-editor-standalone]') || null;
        const content = doc.getElementById('member-editor-content');
        const feedback = doc.getElementById('member-editor-feedback');
        const win = options.window || scope;
        const location = options.location || win.location;
        const request = options.fetch || win.fetch?.bind(win);
        if (!content || !feedback || !location || !request) return false;

        let activeUrl = null;
        let opener = null;
        let revision = 0;
        bindFieldControls(doc);

        const close = () => {
            revision++;
            dialog.close?.();
            content.replaceChildren();
            feedback.textContent = '';
            const focus = opener;
            opener = null;
            focus?.focus?.();
        };

        const load = async (href, source = null, keepFeedback = false) => {
            const url = editorUrl(href, location.href);
            if (!url) return false;
            if (source) opener = source;
            activeUrl = url.href;
            const current = ++revision;
            if (!dialog.open) dialog.showModal?.();
            if (!keepFeedback) feedback.textContent = '';
            content.textContent = 'Consultando Nextcloud, Kimai e Akaunting… Aguarde a resposta das fontes de cadastro.';

            try {
                const response = await request(url.href, {
                    credentials: 'same-origin', cache: 'no-store',
                    headers: {Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
                });
                if (!response.ok) throw new Error('As fontes não puderam ser consultadas (HTTP ' + response.status + ').');
                const html = await response.text();
                if (current !== revision) return false;
                content.innerHTML = html;
                bindFieldControls(content);
                bindSearchDefaults(content);
                bindIdentityPreviews(content);
                content.querySelector('input:not([type="hidden"])')?.focus?.();
                return true;
            } catch (error) {
                if (current === revision) {
                    content.replaceChildren();
                    feedback.textContent = error.message || 'Não foi possível abrir o cadastro.';
                }
                return false;
            }
        };

        doc.addEventListener('click', event => {
            const openerLink = event.target?.closest?.('[data-member-editor-open]');
            if (openerLink) {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
                if (!editorUrl(openerLink.href, location.href)) return;
                event.preventDefault();
                void load(openerLink.href, openerLink);
            }
        });
        // Identity inspection is read-only. Every result is bound to its exact
        // identifier; stale responses must never describe a different account.
        const inspectionTickets = new WeakMap();
        const fieldNames = {
            nextcloud:'nextcloud_uid', kimai:'kimai_user_id', akaunting:'akaunting_contact_id',
        };
        const identityRows = (source, identity, selectedUid, kimaiId) => {
            const rows = [];
            const add = (label, value) => {
                if (value !== null && value !== undefined && String(value).trim() !== '') {
                    rows.push([label, String(value)]);
                }
            };
            add('Nome', identity.name || 'Não informado');
            if (source !== 'akaunting') add('Login', identity.username);
            add('E-mail', identity.email);
            if (identity.enabled !== null && identity.enabled !== undefined) {
                add('Situação', identity.enabled ? 'Ativa' : 'Inativa');
            }
            if (source === 'akaunting') {
                add('Tipo', identity.kind);
                add('Documento', identity.tax_hint || 'CPF/CNPJ não informado');
                if (identity.tax_match === 'match') add('CPF/CNPJ', 'Coincide com o cadastro Nextcloud selecionado');
                if (identity.tax_match === 'different') add('CPF/CNPJ', 'Divergente do cadastro Nextcloud selecionado');
                if (identity.tax_match === 'missing') add('CPF/CNPJ', 'Sem dados suficientes para comparar');
            }
            const link = String(identity.linked_account || '');
            if (source === 'nextcloud') add('Vínculo existente', link || 'Nenhum Kimai registrado');
            if (source === 'kimai' || source === 'akaunting') {
                add('Nextcloud vinculado', link || 'Nenhuma conta vinculada');
            }
            const alerts = [];
            if (source === 'nextcloud' && link && link !== 'Kimai #' + kimaiId) {
                alerts.push('Atenção: esta conta Nextcloud já está ligada a outro ID Kimai.');
            } else if ((source === 'kimai' || source === 'akaunting') && link && selectedUid && link !== selectedUid) {
                alerts.push('Atenção: este registro já está vinculado a outra conta Nextcloud.');
            }
            if (source === 'akaunting' && identity.tax_match === 'different') {
                alerts.push('Identificação fiscal divergente. Não salve o vínculo sem corrigir as origens.');
            }
            if (source === 'akaunting' && identity.tax_match === 'missing') {
                alerts.push(identity.tax_hint
                    ? 'O contato tem documento fiscal, mas falta CPF/CNPJ válido no Nextcloud. Preencha em Dados cadastrais antes de salvar.'
                    : 'Sem CPF/CNPJ válido nas duas origens para comparar. Confira os cadastros antes de salvar.');
            }
            if ((source === 'nextcloud' || source === 'kimai') && identity.enabled === false) {
                alerts.push('Conta inativa. Um vínculo técnico para competências históricas não reativa o acesso.');
            }
            return {rows, warning: alerts.join(' ')};
        };
        const clearIdentity = (form, source, message) => {
            const preview = form?.querySelector?.('[data-identity-preview="' + source + '"]');
            const output = preview?.querySelector?.('[data-identity-result="' + source + '"]');
            if (!output) return;
            inspectionTickets.set(output, (inspectionTickets.get(output) || 0) + 1);
            output.replaceChildren();
            output.textContent = message;
        };
        const inspectIdentity = async (form, source) => {
            const preview = form?.querySelector?.('[data-identity-preview="' + source + '"]');
            const output = preview?.querySelector?.('[data-identity-result="' + source + '"]');
            const input = form?.querySelector?.('[name="' + fieldNames[source] + '"]');
            const uid = form?.querySelector?.('[name="nextcloud_uid"]')?.value.trim() || '';
            const kimaiId = form?.querySelector?.('[name="kimai_user_id"]')?.value.trim() || '';
            if (!output || !input) return false;
            const identifier = input.value.trim();
            const ticket = (inspectionTickets.get(output) || 0) + 1;
            inspectionTickets.set(output, ticket);
            output.replaceChildren();
            const url = identityEndpoint(form, location.href, source, identifier, uid);
            if (!url) {
                output.textContent = 'Selecione um resultado da busca para conferir a identidade na origem.';
                return false;
            }
            preview.classList?.add?.('is-busy');
            output.textContent = 'Consultando a identidade na origem…';
            try {
                const response = await request(url, {
                    credentials:'same-origin', cache:'no-store',
                    headers:{Accept:'application/json'},
                });
                const data = await response.json();
                const stale = inspectionTickets.get(output) !== ticket || input.value.trim() !== identifier
                    || (source === 'akaunting' && uid !== (form.querySelector('[name="nextcloud_uid"]')?.value.trim() || ''));
                if (stale) return false;
                if (!response.ok || data.identity?.source !== source || data.identity?.id !== identifier) {
                    throw new Error(data.error || 'A origem não confirmou esse identificador.');
                }
                output.replaceChildren();
                const details = doc.createElement('dl');
                details.className = 'member-identity-facts';
                const {rows, warning} = identityRows(source, data.identity, uid, kimaiId);
                for (const [label, value] of rows) {
                    const row = doc.createElement('div');
                    const term = doc.createElement('dt');
                    const description = doc.createElement('dd');
                    term.textContent = label;
                    description.textContent = value;
                    row.appendChild(term);
                    row.appendChild(description);
                    details.appendChild(row);
                }
                output.appendChild(details);
                const note = doc.createElement('p');
                note.className = 'member-identity-note' + (warning ? ' is-warning' : '');
                note.textContent = warning || 'Registro localizado na origem. Confira a identidade antes de vincular.';
                output.appendChild(note);
                return true;
            } catch (error) {
                if (inspectionTickets.get(output) === ticket) {
                    output.replaceChildren();
                    output.textContent = error.message || 'Não foi possível conferir a conta.';
                }
                return false;
            } finally {
                if (inspectionTickets.get(output) === ticket) preview.classList?.remove?.('is-busy');
            }
        };
        const bindIdentityPreviews = root => {
            const form = root?.querySelector?.('.member-link-form');
            if (!form || typeof form.querySelector !== 'function') return;
            for (const source of ['nextcloud', 'kimai', 'akaunting']) {
                if (form.querySelector('[data-identity-preview="' + source + '"]')) {
                    void inspectIdentity(form, source);
                }
            }
        };
        bindIdentityPreviews(standalone);

        // Search is a separate action from selecting the technical ID. Native
        // datalist widgets silently accepted arbitrary text (and did not open
        // after delayed results), which looked like a saved association.
        const searchTickets = new WeakMap();
        const searchTimers = new WeakMap();
        const searchField = {nextcloud: 'nextcloud_uid', akaunting: 'akaunting_contact_id'};
        const showContactCreation = (form, canCreate) => {
            const panel = form?.querySelector?.('[data-contact-provision]');
            if (panel) panel.hidden = !canCreate;
        };
        const setSearchStatus = (form, source, message, warning = false) => {
            const status = form?.querySelector?.('[data-link-status="' + source + '"]');
            if (status) {
                status.textContent = message;
                status.classList?.toggle?.('is-warning', warning);
            }
        };
        const syncLinkActions = form => {
            if (!form) return;
            const uid = form.querySelector('[name="nextcloud_uid"]')?.value.trim() || '';
            const kimaiId = form.querySelector('[name="kimai_user_id"]')?.value.trim() || '';
            const contactId = form.querySelector('[name="akaunting_contact_id"]')?.value.trim() || '';
            const kimaiValid = /^[1-9]\d*$/.test(kimaiId);
            const contactValid = /^[1-9]\d*$/.test(contactId);
            const confirm = form.querySelector('[data-kimai-confirm]');
            if (confirm) confirm.disabled = !uid || !kimaiValid;
            const save = form.querySelector('[data-links-save]');
            if (save) save.disabled = !uid || !kimaiValid || !contactValid;
            if (contactValid) showContactCreation(form, false);
        };
        const findOptions = (input, immediate = false) => {
            const source = input?.dataset?.linkSource;
            if (!searchField[source]) return;
            const form = input.closest?.('.member-link-form');
            const choices = form?.querySelector?.('[data-link-results="' + source + '"]');
            if (!choices) return;
            const previous = searchTimers.get(input);
            if (previous !== undefined) clearTimeout(previous);
            const ticket = (searchTickets.get(input) || 0) + 1;
            searchTickets.set(input, ticket);
            choices.replaceChildren();
            const query = source === 'akaunting'
                ? input.value.trim().replace(/^#/, '') : input.value.trim();
            if (query.length < 2) {
                if (source === 'akaunting') showContactCreation(form, true);
                setSearchStatus(form, source, 'Digite pelo menos 2 caracteres para pesquisar.');
                return;
            }
            setSearchStatus(form, source, 'Pesquisando na origem…');
            const send = async () => {
                try {
                    const url = new URL('/configuracao/vinculos/opcoes', location.href);
                    url.searchParams.set('source', source);
                    url.searchParams.set('q', query);
                    const response = await request(url.href, {
                        credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'},
                    });
                    const result = await response.json();
                    const current = source === 'akaunting'
                        ? input.value.trim().replace(/^#/, '') : input.value.trim();
                    if (searchTickets.get(input) !== ticket || current !== query) return;
                    if (!response.ok || !Array.isArray(result.items)) {
                        throw new Error(result.error || 'Não foi possível consultar a origem.');
                    }
                    choices.replaceChildren();
                    for (const item of result.items.slice(0, 12)) {
                        const id = String(item.id || '').trim();
                        if (!id || id.length > 128 ||
                            (source === 'akaunting' && (!/^[1-9]\d*$/.test(id)
                                || !Number.isSafeInteger(Number(id))))) continue;
                        const button = doc.createElement('button');
                        button.type = 'button';
                        button.className = 'button member-link-search-choice';
                        button.dataset.linkPick = id;
                        button.dataset.linkSource = source;
                        button.textContent = String(item.label || id);
                        choices.appendChild(button);
                    }
                    const count = choices.children?.length || 0;
                    if (source === 'akaunting') showContactCreation(form, count === 0);
                    setSearchStatus(form, source, count
                        ? 'Escolha um dos ' + count + ' resultados abaixo. Digitar não registra o vínculo.'
                        : (source === 'nextcloud'
                            ? 'Nenhuma conta Nextcloud encontrada. Confira o login ou o nome.'
                            : 'Nenhum contato financeiro encontrado. Confira a grafia ou utilize Verificar antes de criar.'),
                        count === 0);
                } catch (error) {
                    if (searchTickets.get(input) === ticket) {
                        choices.replaceChildren();
                        if (source === 'akaunting') showContactCreation(form, false);
                        setSearchStatus(form, source,
                            error.message || 'A pesquisa falhou. Tente novamente.', true);
                    }
                }
            };
            searchTimers.set(input, setTimeout(() => { void send(); }, immediate ? 0 : 220));
        };
        const pickOption = (button) => {
            const source = button?.dataset?.linkSource;
            const id = String(button?.dataset?.linkPick || '');
            if (!searchField[source] || !id || id.length > 128 ||
                (source === 'akaunting' && (!/^[1-9]\d*$/.test(id)
                    || !Number.isSafeInteger(Number(id))))) return false;
            const form = button.closest?.('.member-link-form');
            const search = form?.querySelector?.('[data-link-source="' + source + '"]');
            const selected = form?.querySelector?.('[name="' + searchField[source] + '"]');
            const results = form?.querySelector?.('[data-link-results="' + source + '"]');
            if (!search || !selected || !results) return false;
            // Invalidate any in-flight request before choosing the option.
            searchTickets.set(search, (searchTickets.get(search) || 0) + 1);
            const timer = searchTimers.get(search);
            if (timer !== undefined) clearTimeout(timer);
            selected.value = id;
            search.value = source === 'nextcloud' ? id : button.textContent;
            results.replaceChildren();
            setSearchStatus(form, source, 'Selecionado: ' + button.textContent
                + '. Confira a identidade abaixo; o vínculo ainda não foi salvo.');
            syncLinkActions(form);
            void inspectIdentity(form, source);
            if (source === 'nextcloud') void inspectIdentity(form, 'akaunting');
            return true;
        };
        const bindSearchDefaults = root => {
            const form = root?.querySelector?.('.member-link-form');
            if (!form || typeof form.querySelector !== 'function') return;
            syncLinkActions(form);
            for (const source of ['nextcloud', 'akaunting']) {
                const selected = form.querySelector('[name="' + searchField[source] + '"]');
                const input = form.querySelector('[data-link-source="' + source + '"]');
                // Suggest the existing Kimai username/full name as a search;
                // never silently select by name or associate a disabled user.
                if (input && selected && !selected.value.trim() && input.value.trim()) {
                    findOptions(input, true);
                }
            }
        };
        bindSearchDefaults(standalone);
        const handleEditorInput = event => {
            const input = event.target;
            const name = input?.name;
            const form = input?.closest?.('.member-link-form');
            if (name === 'kimai_user_id' && form) {
                clearIdentity(form, 'kimai', 'ID alterado. Confira a identidade na origem.');
                syncLinkActions(form);
                return;
            }
            const source = input?.dataset?.linkSource;
            if (!searchField[source] || !form) return;
            const selected = form.querySelector('[name="' + searchField[source] + '"]');
            if (selected) selected.value = '';
            if (source === 'akaunting') showContactCreation(form, false);
            clearIdentity(form, source, 'Pesquisa alterada. Selecione um resultado e confira a identidade.');
            if (source === 'nextcloud') {
                clearIdentity(form, 'akaunting', 'Conta Nextcloud alterada. Confira novamente a identificação fiscal.');
            }
            syncLinkActions(form);
            findOptions(input);
        };
        dialog.addEventListener('input', handleEditorInput);
        standalone?.addEventListener('input', handleEditorInput);
        // The technical identifiers only change via explicit result selection,
        // not by blur/change on a text search box.
        const handleIdentityChange = event => {
            if (event.target?.dataset?.linkSource) return;
            const field = event.target?.name;
            const source = field === 'nextcloud_uid' ? 'nextcloud'
                : field === 'kimai_user_id' ? 'kimai'
                    : field === 'akaunting_contact_id' ? 'akaunting' : null;
            const form = event.target?.closest?.('.member-link-form');
            if (!source || !form) return;
            void inspectIdentity(form, source);
            if (source === 'nextcloud') {
                void inspectIdentity(form, 'akaunting');
            }
        };
        dialog.addEventListener('change', handleIdentityChange);
        standalone?.addEventListener('change', handleIdentityChange);
        const submitLinks = async (form, url) => {
            const resultFeedback = form?.closest?.('[data-member-editor-standalone]')
                ?.querySelector?.('[data-standalone-feedback]') || feedback;
            const endpoint = new URL(url, location.href);
            const prepare = endpoint.pathname === '/configuracao/vinculos/preparar';
            if (!prepare && form?.querySelector?.('[name="nextcloud_uid"]')
                && (!form.querySelector('[name="nextcloud_uid"]').value.trim()
                    || !/^[1-9]\d*$/.test(form.querySelector('[name="akaunting_contact_id"]')?.value || ''))) {
                resultFeedback.textContent = 'Selecione uma conta Nextcloud e um contato Akaunting nos resultados da busca. Para confirmar só o Kimai, use o botão do cartão Kimai.';
                return;
            }
            const linking = /^\/configuracao\/cooperado\/[1-9]\d*\/vincular$/.test(endpoint.pathname);
            if (endpoint.origin !== new URL(location.href).origin || (!prepare && !linking)) {
                resultFeedback.textContent = 'Destino de vínculo inválido.';
                return;
            }
            const button = form.querySelector('[type="submit"]');
            if (button) button.disabled = true;
            resultFeedback.textContent = 'Conferindo vínculos...';
            try {
                const response = await request(endpoint.href, {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin',
                    cache: 'no-store', redirect: 'error',
                    headers: {Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'},
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'A origem não confirmou os vínculos.');
                resultFeedback.textContent = data.message || 'Vínculos confirmados.';
                if (linking) {
                    options.onSaved?.();
                    if (activeUrl) await load(activeUrl, null, true);
                    else if (standalone) win.location?.reload?.();
                }
            } catch (error) {
                resultFeedback.textContent = error.message || 'Não foi possível salvar vínculos.';
            } finally {
                if (button) button.disabled = false;
            }
        };
        // Creating a contact is deliberately not the same as linking it.
        // The first request is read-only and always checks the fiscal ID in
        // the Akaunting company. A separate explicit POST creates the contact.
        const contactAction = async event => {
            const trigger = event.target?.closest?.('[data-contact-provision-open]');
            const pick = event.target?.closest?.('[data-contact-pick]');
            const create = event.target?.closest?.('[data-contact-provision-create]');
            if (!trigger && !pick && !create) return false;
            const panel = event.target?.closest?.('[data-contact-provision]');
            const form = panel?.closest?.('.member-link-form');
            const resultFeedback = form?.closest?.('[data-member-editor-standalone]')
                ?.querySelector?.('[data-standalone-feedback]') || feedback;
            const output = panel?.querySelector?.('[data-contact-provision-status]');
            const existing = panel?.querySelector?.('[data-contact-provision-existing]');
            const newContact = panel?.querySelector?.('[data-contact-provision-new]');
            const input = form?.querySelector?.('[name="akaunting_contact_id"]');
            const uid = form?.querySelector?.('[name="nextcloud_uid"]');
            const name = panel?.querySelector?.('[data-contact-provision-name]');
            const confirmed = panel?.querySelector?.('[data-contact-provision-confirm]');
            const createButton = panel?.querySelector?.('[data-contact-provision-create]');
            const endpoint = contactEndpoint(form || {}, location.href, create ? 'criar' : 'proposta');
            if (!panel || !input || !uid || !output || !endpoint) {
                resultFeedback.textContent = 'Cadastro de contato indisponível. Atualize a página.';
                return true;
            }
            if (pick) {
                const id = String(pick.dataset.contactPick || '');
                if (!/^[1-9]\d*$/.test(id) || !Number.isSafeInteger(Number(id))) return true;
                input.value = id;
                const search = form.querySelector('[data-link-source="akaunting"]');
                if (search) search.value = 'Contato #' + id;
                syncLinkActions(form);
                if (existing) existing.hidden = true;
                if (newContact) newContact.hidden = true;
                if (createButton) createButton.disabled = true;
                output.textContent = 'Contato #' + id + ' selecionado. Salve os vínculos para associá-lo.';
                resultFeedback.textContent = 'Contato #' + id + ' selecionado. Clique em Salvar vínculos para associá-lo.';
                void inspectIdentity(form, 'akaunting');
                return true;
            }
            if (trigger) {
                if (input.value.trim() !== '') {
                    output.textContent = 'Já há um contato selecionado. Limpe o campo para verificar outro.';
                    return true;
                }
                if (confirmed) confirmed.checked = false;
                if (createButton) createButton.disabled = true;
                if (existing) existing.hidden = true;
                if (newContact) newContact.hidden = true;
                output.textContent = 'Consultando contatos do Akaunting…';
                trigger.disabled = true;
                try {
                    const proposalUrl = new URL(endpoint);
                    proposalUrl.searchParams.set('uid', uid.value.trim());
                    const response = await request(proposalUrl.href, {credentials:'same-origin', cache:'no-store',
                        headers:{Accept:'application/json'}});
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Não foi possível conferir os contatos.');
                    if (result.status === 'linked') {
                        output.textContent = 'Já existe vínculo financeiro. Reabra o cadastro.';
                    } else if (result.status === 'existing' && Array.isArray(result.contacts)) {
                        output.textContent = 'Já existe contato com este CPF/CNPJ. Selecione o existente:';
                        existing.replaceChildren();
                        for (const candidate of result.contacts) {
                            const id = Number(candidate.id);
                            if (!Number.isSafeInteger(id) || id <= 0) continue;
                            const button = doc.createElement('button');
                            button.type = 'button';
                            button.className = 'member-contact-choice';
                            button.dataset.contactPick = String(id);
                            button.textContent = '#' + id + ' · ' + String(candidate.name || '') +
                                ' (' + (candidate.type === 'employee' ? 'Funcionário' : 'Fornecedor') + ')';
                            existing.appendChild(button);
                        }
                        existing.hidden = false;
                    } else if (result.status === 'available' && result.uid && result.tax_hint) {
                        if (uid.value.trim() !== result.uid) {
                            throw new Error('A conta Nextcloud selecionada mudou. Salve o vínculo correto antes de criar.');
                        }
                        panel.dataset.confirmedUid = result.uid;
                        output.textContent = 'Kimai: ' + String(result.name || '') + ' · Nextcloud: ' +
                            result.uid + ' · ' + result.tax_hint + '. Nenhum contato encontrado.';
                        if (name) name.value = String(result.name || '');
                        if (newContact) newContact.hidden = false;
                    } else {
                        throw new Error('Resposta de consulta inesperada. Nenhum contato foi criado.');
                    }
                } catch (error) {
                    output.textContent = error.message || 'Não foi possível conferir o Akaunting.';
                } finally {
                    trigger.disabled = false;
                }
                return true;
            }
            if (create) {
                if (create.disabled || !confirmed?.checked || !name?.value.trim() || input.value.trim() !== ''
                    || uid.value.trim() !== panel.dataset.confirmedUid) return true;
                create.disabled = true;
                output.textContent = 'Criando o contato no Akaunting…';
                try {
                    const body = new FormData(form);
                    body.set('contact_name', name.value.trim());
                    body.set('identity_confirmed', '1');
                    const response = await request(endpoint, {method:'POST', body,
                        credentials:'same-origin', cache:'no-store', redirect:'error',
                        headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}});
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Contato não confirmado. Consulte o Akaunting.');
                    const id = Number(result.id);
                    if (!Number.isSafeInteger(id) || id <= 0) {
                        throw new Error('O Akaunting não confirmou o ID. Verifique a origem antes de tentar de novo.');
                    }
                    input.value = String(id);
                    const search = form.querySelector('[data-link-source="akaunting"]');
                    if (search) search.value = 'Contato #' + id;
                    syncLinkActions(form);
                    if (newContact) newContact.hidden = true;
                    output.textContent = 'Contato #' + id + ' criado. Salve os vínculos para associá-lo.';
                    resultFeedback.textContent = result.message || 'Contato criado. Salve o vínculo para associá-lo.';
                    void inspectIdentity(form, 'akaunting');
                    if (win.CustomEvent) doc.dispatchEvent?.(new win.CustomEvent('member-source-changed', {
                        detail:{source:'akaunting'},
                    }));
                    options.onSaved?.();
                } catch (error) {
                    output.textContent = (error.message || 'Falha na criação.') +
                        ' Atualize a consulta antes de tentar outra vez.';
                    if (newContact) newContact.hidden = true;
                }
                return true;
            }
            return false;
        };
        const confirmKimai = async (form, button) => {
            const resultFeedback = form?.closest?.('[data-member-editor-standalone]')
                ?.querySelector?.('[data-standalone-feedback]') || feedback;
            const endpoint = kimaiLinkEndpoint(form || {}, location.href);
            if (!endpoint) {
                resultFeedback.textContent = 'Cadastro inválido. Reabra o cooperado antes de confirmar.';
                return;
            }
            const uid = form.querySelector('[name="nextcloud_uid"]')?.value.trim();
            const kimaiId = form.querySelector('[name="kimai_user_id"]')?.value.trim();
            if (!uid || !/^[1-9]\d*$/.test(kimaiId || '')) {
                resultFeedback.textContent = 'Confirme o ID Kimai e selecione a conta Nextcloud correta.';
                return;
            }
            button.disabled = true;
            resultFeedback.textContent = 'Confirmando vínculo Kimai–Nextcloud…';
            try {
                const response = await request(endpoint, {
                    method:'POST', body:new FormData(form), credentials:'same-origin',
                    cache:'no-store', redirect:'error',
                    headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'},
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'O Nextcloud não confirmou o vínculo.');
                resultFeedback.textContent = result.message || 'Vínculo confirmado. Confira os dados cadastrais.';
                if (win.CustomEvent) doc.dispatchEvent?.(new win.CustomEvent('member-source-changed', {
                    detail:{source:'nextcloud'},
                }));
                options.onSaved?.();
                if (activeUrl) await load(activeUrl, null, true);
                else if (standalone) win.location?.reload?.();
            } catch (error) {
                resultFeedback.textContent = error.message || 'Não foi possível confirmar o vínculo.';
            } finally {
                button.disabled = false;
            }
        };

        const handleEditorClick = event => {
            const option = event.target?.closest?.('[data-link-pick]');
            if (option) {
                pickOption(option);
                return;
            }
            const identityRefresh = event.target?.closest?.('[data-identity-verify]');
            if (identityRefresh) {
                const source = identityRefresh.dataset.identityVerify;
                const form = identityRefresh.closest?.('.member-link-form');
                if (form && fieldNames[source]) void inspectIdentity(form, source);
                return;
            }
            if (event.target?.closest?.('[data-contact-provision-open], [data-contact-pick], [data-contact-provision-create]')) {
                void contactAction(event);
                return;
            }
            const confirm = event.target?.closest?.('[data-kimai-confirm]');
            if (confirm) {
                const form = confirm.closest?.('.member-link-form');
                if (form) void confirmKimai(form, confirm);
                return;
            }
            if (event.target?.closest?.('[data-links-prepare]')) {
                const form = event.target.closest?.('.member-link-form') || content.querySelector('.member-link-form');
                if (form) void submitLinks(form, '/configuracao/vinculos/preparar');
                return;
            }
            if (event.target?.closest?.('[data-member-editor-close]') || event.target === dialog) close();
        };
        dialog.addEventListener('click', handleEditorClick);
        standalone?.addEventListener('click', handleEditorClick);
        const handleContactConfirmation = event => {
            if (!event.target?.matches?.('[data-contact-provision-confirm]')) return;
            const panel = event.target.closest('[data-contact-provision]');
            const button = panel?.querySelector('[data-contact-provision-create]');
            if (button) button.disabled = !event.target.checked;
        };
        dialog.addEventListener('change', handleContactConfirmation);
        standalone?.addEventListener('change', handleContactConfirmation);
        dialog.addEventListener('cancel', event => {
            event.preventDefault?.();
            close();
        });
        const handleEditorSubmit = async event => {
            const form = event.target;
            const resultFeedback = form?.closest?.('[data-member-editor-standalone]')
                ?.querySelector?.('[data-standalone-feedback]') || feedback;
            if (form?.classList?.contains?.('member-link-form')) {
                event.preventDefault();
                if (activeUrl || standalone) void submitLinks(form, form.action);
                return;
            }
            if (!form?.matches?.('.member-editor-field') || (!activeUrl && !standalone)) return;
            event.preventDefault();
            const action = new URL(form.action, location.href);
            if (action.origin !== new URL(location.href).origin ||
                !/^\/configuracao\/cooperado\/[1-9]\d*\/nextcloud$/.test(action.pathname)) {
                resultFeedback.textContent = 'Destino de gravação inválido.';
                return;
            }
            const button = form.querySelector('[type="submit"]');
            if (button?.disabled) return;
            const input = form.querySelector('[name="value"]');
            const expected = form.querySelector('[name="expected"]');
            const payload = new FormData(form);
            if (button) button.disabled = true;
            if (input) input.disabled = true;
            resultFeedback.textContent = 'Salvando na origem…';
            try {
                const response = await request(action.href, {
                    method: 'POST', body: payload, credentials: 'same-origin',
                    cache: 'no-store', redirect: 'error',
                    headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                });
                if (!response.ok) {
                    const message = (await response.text()).trim().slice(0, 220);
                    throw new Error(message || 'A origem não confirmou a alteração.');
                }
                const result = await response.json();
                if (!result || typeof result.changed !== 'boolean' ||
                    typeof result.value !== 'string' || typeof result.expected !== 'string') {
                    throw new Error('Resposta de gravação inesperada.');
                }
                if (result.changed) {
                    if (win.CustomEvent) {
                        doc.dispatchEvent?.(new win.CustomEvent('member-source-changed', {
                            detail: {source: action.pathname.split('/').at(-1)},
                        }));
                    }
                    options.onSaved?.();
                }
                if (input) {
                    input.value = result.value;
                    input.defaultValue = result.value;
                }
                if (expected) expected.value = result.expected;
                resultFeedback.textContent = result.message || 'Operação concluída.';
            } catch (error) {
                resultFeedback.textContent = error.message || 'Erro ao salvar. Confira a origem.';
            } finally {
                if (input) input.disabled = false;
                if (button) button.disabled = input ? input.value === input.defaultValue : false;
            }
        };
        dialog.addEventListener('submit', handleEditorSubmit);
        standalone?.addEventListener('submit', handleEditorSubmit);
        standalone && bindFieldControls(standalone);

        return {load, close};
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {editorUrl, contactEndpoint, kimaiLinkEndpoint, identityEndpoint, initialize, bindFieldControls};
    } else {
        scope.addEventListener('DOMContentLoaded', () => initialize());
    }
})(typeof window === 'undefined' ? {} : window);
