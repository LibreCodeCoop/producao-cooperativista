// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

(function (scope) {
    function changedFields(form) {
        const changes = {};
        for (const input of form.querySelectorAll('[data-config-input]')) {
            if (input.value !== input.defaultValue) {
                changes[input.name] = input.value;
            }
        }
        return changes;
    }

    function initialize(doc = scope.document, request = scope.fetch, confirmChange = scope.confirm) {
        const panel = doc?.getElementById('admin-settings');
        if (!panel) return false;
        const forms = [...panel.querySelectorAll('[data-admin-settings-group]')];
        const token = panel.dataset.token;
        const post = async (path, params) => {
            const response = await request(path, {
                method: 'POST', body: new URLSearchParams({_token: token, ...params}),
                credentials: 'same-origin', redirect: 'error', cache: 'no-store',
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Não foi possível concluir a operação.');
            return data;
        };
        for (const form of forms) {
            const save = form.querySelector('[type="submit"]');
            const feedback = form.querySelector('.admin-settings-feedback');
            const refresh = () => {save.disabled = Object.keys(changedFields(form)).length === 0;};
            form.addEventListener('input', refresh);

            for (const reveal of form.querySelectorAll('[data-reveal-key]')) {
                reveal.addEventListener('click', async () => {
                    const input = form.querySelector('[name="' + reveal.dataset.revealKey + '"]');
                    if (reveal.getAttribute('aria-pressed') === 'true') {
                        input.type = 'password';
                        input.value = '';
                        input.defaultValue = '';
                        reveal.setAttribute('aria-pressed', 'false');
                        reveal.textContent = '👁 Mostrar';
                        refresh();
                        return;
                    }
                    reveal.disabled = true;
                    feedback.textContent = '';
                    try {
                        const data = await post('/configuracao/integracoes/revelar', {key: reveal.dataset.revealKey});
                        input.type = 'text';
                        input.value = data.value;
                        input.defaultValue = data.value;
                        reveal.setAttribute('aria-pressed', 'true');
                        reveal.textContent = 'Ocultar';
                    } catch (error) {
                        feedback.textContent = error.message;
                    } finally {
                        reveal.disabled = false;
                        refresh();
                    }
                });
            }

            form.addEventListener('submit', async event => {
                event.preventDefault();
                if (save.disabled) return;
                const changes = changedFields(form);
                const critical = [...form.querySelectorAll('[data-config-input]')]
                    .some(input => input.dataset.critical === 'yes' && input.name in changes);
                if (critical && !confirmChange('Esta alteração pode afetar o fechamento e as conexões. Confirmar?')) return;
                save.disabled = true;
                feedback.textContent = 'Salvando…';
                try {
                    const result = await post('/configuracao/integracoes/salvar', {
                        group: form.dataset.adminSettingsGroup, version: panel.dataset.version,
                        changes: JSON.stringify(changes),
                    });
                    panel.dataset.version = String(result.version);
                    for (const input of form.querySelectorAll('[data-config-input]')) {
                        if (input.dataset.secret === 'yes') {
                            input.type = 'password';
                            input.value = '';
                            input.defaultValue = '';
                            const reveal = form.querySelector('[data-reveal-key="' + input.name + '"]');
                            if (reveal) {
                                reveal.setAttribute('aria-pressed', 'false');
                                reveal.textContent = '👁 Mostrar';
                            }
                        } else {
                            input.defaultValue = input.value;
                        }
                    }
                    feedback.textContent = result.message;
                } catch (error) {
                    feedback.textContent = error.message;
                } finally {
                    refresh();
                }
            });
            refresh();
        }
        return true;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {initialize, changedFields};
    } else {
        scope.addEventListener('DOMContentLoaded', () => initialize());
    }
})(typeof window === 'undefined' ? {} : window);
