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

    function copyTaxNumber(root, targetForm) {
        const reference = root?.querySelector?.('form[data-source="nextcloud"][data-field="tax_number"] [name="value"]');
        const target = targetForm?.querySelector?.('[name="value"]');
        if (!reference?.value?.trim() || reference.value !== reference.defaultValue || !target) return false;
        target.value = reference.defaultValue;
        const button = targetForm.querySelector('[type="submit"]');
        if (button) button.disabled = target.value === target.defaultValue;
        return true;
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
        dialog.addEventListener('click', event => {
            const copy = event.target?.closest?.('[data-copy-tax-number]');
            if (copy) {
                if (copyTaxNumber(content, copy.closest('form'))) {
                    feedback.textContent = 'CPF/CNPJ copiado. Confira e salve separadamente nesta fonte.';
                } else {
                    feedback.textContent = 'Salve primeiro o CPF/CNPJ no Nextcloud antes de copiá-lo para outra fonte.';
                }
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
            if (!form?.matches?.('.member-editor-field') || !activeUrl) return;
            event.preventDefault();
            const action = new URL(form.action, location.href);
            if (action.origin !== new URL(location.href).origin ||
                !/^\/configuracao\/cooperado\/[1-9]\d*\/(nextcloud|kimai|akaunting)$/.test(action.pathname)) {
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

        const query = new URLSearchParams(location.search);
        const id = query.get('cooperado');
        if (location.pathname === '/configuracao' && /^[1-9]\d*$/.test(id || '')) {
            const competence = query.get('competencia') || '';
            void load('/configuracao/cooperado/' + id + '/editar?competencia=' +
                encodeURIComponent(competence));
        }
        return {load, close};
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {editorUrl, initialize, bindFieldControls, copyTaxNumber};
    } else {
        scope.addEventListener('DOMContentLoaded', () => initialize());
    }
})(typeof window === 'undefined' ? {} : window);
