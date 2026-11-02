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
        dialog.addEventListener('input', event => {
            const input = event.target;
            if (!input?.matches?.('[data-link-source]')) return;
            const ticket = ++searchTicket;
            if (searchTimer !== null) clearTimeout(searchTimer);
            const query = input.value.trim();
            const choices = content.querySelector('#' + input.getAttribute('list'));
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
        });
        const submitLinks = async (form, url) => {
            const endpoint = new URL(url, location.href);
            const prepare = endpoint.pathname === '/configuracao/vinculos/preparar';
            const linking = /^\/configuracao\/cooperado\/[1-9]\d*\/vincular$/.test(endpoint.pathname);
            if (endpoint.origin !== new URL(location.href).origin || (!prepare && !linking)) {
                feedback.textContent = 'Destino de vínculo inválido.';
                return;
            }
            const button = form.querySelector('[type="submit"]');
            if (button) button.disabled = true;
            feedback.textContent = 'Conferindo vínculos...';
            try {
                const response = await request(endpoint.href, {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin',
                    cache: 'no-store', redirect: 'error',
                    headers: {Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'},
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'A origem não confirmou os vínculos.');
                feedback.textContent = data.message || 'Vínculos confirmados.';
                if (linking) {
                    options.onSaved?.();
                    if (activeUrl) await load(activeUrl, null, true);
                }
            } catch (error) {
                feedback.textContent = error.message || 'Não foi possível salvar vínculos.';
            } finally {
                if (button) button.disabled = false;
            }
        };
        dialog.addEventListener('click', event => {
            if (event.target?.closest?.('[data-links-prepare]')) {
                const form = content.querySelector('.member-link-form');
                if (form) void submitLinks(form, '/configuracao/vinculos/preparar');
                return;
            }
            if (event.target?.closest?.('[data-member-editor-close]') || event.target === dialog) close();
        });
        dialog.addEventListener('cancel', event => {
            event.preventDefault?.();
            close();
        });
        dialog.addEventListener('submit', async event => {
            const form = event.target;
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
                feedback.textContent = 'Destino de gravação inválido.';
                return;
            }
            const button = form.querySelector('[type="submit"]');
            if (button?.disabled) return;
            const input = form.querySelector('[name="value"]');
            const expected = form.querySelector('[name="expected"]');
            const payload = new FormData(form);
            if (button) button.disabled = true;
            if (input) input.disabled = true;
            feedback.textContent = 'Salvando na origem…';
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
                feedback.textContent = result.message || 'Operação concluída.';
            } catch (error) {
                feedback.textContent = error.message || 'Erro ao salvar. Confira a origem.';
            } finally {
                if (input) input.disabled = false;
                if (button) button.disabled = input ? input.value === input.defaultValue : false;
            }
        });

        return {load, close};
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {editorUrl, initialize, bindFieldControls};
    } else {
        scope.addEventListener('DOMContentLoaded', () => initialize());
    }
})(typeof window === 'undefined' ? {} : window);
