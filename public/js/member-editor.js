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
        let searchTicket = 0;
        let searchTimer = null;
        const handleEditorInput = event => {
            const input = event.target;
            if (!input?.matches?.('[data-link-source]')) return;
            const ticket = ++searchTicket;
            if (searchTimer !== null) clearTimeout(searchTimer);
            const query = input.value.trim();
            const root = input.closest?.('[data-member-editor-standalone]') || content;
            const choices = root.querySelector('#' + input.getAttribute('list'));
            if (!choices) return;
            choices.replaceChildren();
            if (query.length < 2) return;
            const send = async () => {
                try {
                    const path = '/configuracao/vinculos/opcoes?source='
                        + encodeURIComponent(input.dataset.linkSource) + '&q=' + encodeURIComponent(query);
                    const response = await request(new URL(path, location.href).href, {
                        credentials: 'same-origin', cache: 'no-store',
                        headers: {Accept: 'application/json'},
                    });
                    if (!response.ok) throw new Error('A busca de vínculos falhou.');
                    const data = await response.json();
                    if (ticket !== searchTicket || !Array.isArray(data.items)) return;
                    choices.replaceChildren();
                    for (const option of data.items) {
                        const element = doc.createElement('option');
                        element.value = String(option.id);
                        element.label = String(option.label);
                        choices.appendChild(element);
                    }
                } catch (error) {
                    if (ticket === searchTicket) feedback.textContent = error.message;
                }
            };
            searchTimer = setTimeout(() => { void send(); }, 220);
        };
        dialog.addEventListener('input', handleEditorInput);
        standalone?.addEventListener('input', handleEditorInput);
        const submitLinks = async (form, url) => {
            const resultFeedback = form?.closest?.('[data-member-editor-standalone]')
                ?.querySelector?.('[data-standalone-feedback]') || feedback;
            const endpoint = new URL(url, location.href);
            const prepare = endpoint.pathname === '/configuracao/vinculos/preparar';
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
                panel.querySelector('[data-contact-provision-panel]').hidden = true;
                resultFeedback.textContent = 'Contato #' + id + ' selecionado. Clique em Salvar vínculos para associá-lo.';
                return true;
            }
            if (trigger) {
                const body = panel.querySelector('[data-contact-provision-panel]');
                body.hidden = false;
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
                if (create.disabled || !confirmed?.checked || !name?.value.trim()
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
                    panel.querySelector('[data-contact-provision-panel]').hidden = true;
                    resultFeedback.textContent = result.message || 'Contato criado. Salve o vínculo para associá-lo.';
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
        const handleEditorClick = event => {
            if (event.target?.closest?.('[data-contact-provision-open], [data-contact-pick], [data-contact-provision-create]')) {
                void contactAction(event);
                return;
            }
            if (event.target?.closest?.('[data-links-prepare]')) {
                const form = content.querySelector('.member-link-form');
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
                if (activeUrl) void submitLinks(form, form.action);
                return;
            }
            if (!form?.matches?.('.member-editor-field') || !activeUrl) return;
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
        module.exports = {editorUrl, contactEndpoint, initialize, bindFieldControls};
    } else {
        scope.addEventListener('DOMContentLoaded', () => initialize());
    }
})(typeof window === 'undefined' ? {} : window);
