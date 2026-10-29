// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

// Lightweight inline editor for the Nextcloud weight field only.
// DataTables Editor is a separate licensed extension and is not required here.
(function (scope) {
    function validWeight(value) {
        const trimmed = String(value).trim();
        return /^(?:0|[1-9][0-9]{0,2})(?:[.,][0-9]{1,4})?$/.test(trimmed)
            && Number(trimmed.replace(',', '.')) > 0;
    }

    function displayWeight(value) {
        return String(value).trim() === '' ? 'Não definido' : String(value).replace('.', ',');
    }

    function initialize(table, api, options = {}) {
        const fetchRequest = options.fetch || scope.fetch?.bind(scope);
        if (!table || typeof fetchRequest !== 'function' || table.dataset?.weightEditorBound === 'yes') {
            return null;
        }

        if (table.dataset) table.dataset.weightEditorBound = 'yes';
        const pending = new WeakSet();

        function elements(cell) {
            return {
                value: cell.querySelector('[data-weight-current]'),
                edit: cell.querySelector('[data-weight-edit]'),
                form: cell.querySelector('[data-weight-form]'),
                feedback: cell.querySelector('[data-weight-message]'),
            };
        }

        async function readCurrent(cell, force = false) {
            if (!cell?.dataset?.weightUrl || pending.has(cell)) return false;
            if (!force && cell.dataset.weightLoaded === 'yes') return true;

            const ui = elements(cell);
            if (!ui.value || !ui.edit || !ui.form || !ui.feedback) return false;
            pending.add(cell);
            ui.edit.disabled = true;
            ui.value.textContent = 'Consultando…';
            ui.feedback.textContent = '';

            try {
                const response = await fetchRequest(cell.dataset.weightUrl, {
                    method: 'GET',
                    headers: {Accept: 'application/json'},
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                const payload = await response.json();
                if (!response.ok || typeof payload.value !== 'string'
                    || typeof payload.expected !== 'string' || payload.expected === '') {
                    throw new Error(typeof payload.error === 'string'
                        ? payload.error : 'O Nextcloud não retornou o peso atual.');
                }
                cell.dataset.weightLoaded = 'yes';
                cell.dataset.weightValue = payload.value;
                cell.dataset.weightExpected = payload.expected;
                ui.value.textContent = displayWeight(payload.value);
                ui.edit.textContent = 'Editar';
                ui.edit.disabled = false;
                return true;
            } catch (error) {
                cell.dataset.weightLoaded = 'failed';
                ui.value.textContent = 'Indisponível';
                ui.feedback.textContent = error instanceof Error ? error.message : 'Falha na consulta ao Nextcloud.';
                ui.edit.textContent = 'Tentar novamente';
                ui.edit.disabled = false;
                return false;
            } finally {
                pending.delete(cell);
            }
        }

        function visibleRows() {
            const rows = api.rows({page: 'current'}).nodes?.();
            return typeof rows?.toArray === 'function' ? rows.toArray()
                : (rows ? Array.from(rows) : []);
        }

        function loadVisible() {
            for (const row of visibleRows()) {
                const cell = row?.querySelector?.('[data-weight-url]');
                if (cell) void readCurrent(cell);
            }
        }

        async function edit(cell) {
            if (cell.dataset.weightLoaded !== 'yes' && !(await readCurrent(cell, true))) return;
            const ui = elements(cell);
            const input = ui.form?.querySelector('[name="value"]');
            const expected = ui.form?.querySelector('[name="expected"]');
            if (!input || !expected) return;
            expected.value = cell.dataset.weightExpected;
            input.value = cell.dataset.weightValue;
            ui.form.hidden = false;
            ui.edit.hidden = true;
            ui.feedback.textContent = 'O valor será gravado no Nextcloud, não diretamente no ETL.';
            input.focus();
        }

        function cancel(cell) {
            const ui = elements(cell);
            ui.form.hidden = true;
            ui.edit.hidden = false;
            ui.feedback.textContent = '';
        }

        async function save(cell, form) {
            if (pending.has(cell)) return;
            const ui = elements(cell);
            const input = form.querySelector('[name="value"]');
            if (!input || !validWeight(input.value)) {
                ui.feedback.textContent = 'Informe um peso maior que zero, até 999, com até quatro casas decimais.';
                return;
            }

            const body = options.formData
                ? options.formData(form)
                : new scope.FormData(form);
            const urlEncoded = new URLSearchParams(body);
            const submit = form.querySelector('[type="submit"]');
            pending.add(cell);
            if (submit) submit.disabled = true;
            ui.feedback.textContent = 'Salvando no Nextcloud…';

            try {
                const response = await fetchRequest(form.action, {
                    method: 'POST',
                    body: urlEncoded.toString(),
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                const payload = await response.json();
                if (!response.ok || typeof payload.value !== 'string'
                    || typeof payload.expected !== 'string') {
                    const error = new Error(typeof payload.error === 'string'
                        ? payload.error : 'O Nextcloud não confirmou a gravação.');
                    error.status = response.status;
                    throw error;
                }

                cell.dataset.weightValue = payload.value;
                cell.dataset.weightExpected = payload.expected;
                cell.dataset.weightLoaded = 'yes';
                ui.value.textContent = displayWeight(payload.value);
                ui.form.hidden = true;
                ui.edit.hidden = false;
                ui.feedback.textContent = payload.message ||
                    'Salvo no Nextcloud. Reimporte a competência para atualizar o ETL.';
                // Do not change the imported ETL snapshot visible under this field.
            } catch (error) {
                ui.feedback.textContent = error instanceof Error ? error.message
                    : 'Não foi possível salvar no Nextcloud.';
                if (error?.status === 409) {
                    cell.dataset.weightLoaded = 'failed';
                    ui.form.hidden = true;
                    ui.edit.hidden = false;
                    ui.edit.textContent = 'Atualizar valor';
                }
            } finally {
                if (submit) submit.disabled = false;
                pending.delete(cell);
            }
        }

        table.addEventListener('click', event => {
            const button = event.target?.closest?.('[data-weight-edit], [data-weight-cancel]');
            if (!button) return;
            const cell = button.closest('[data-weight-url]');
            if (!cell) return;
            if (button.matches('[data-weight-cancel]')) {
                cancel(cell);
            } else if (!button.disabled) {
                void edit(cell);
            }
        });

        table.addEventListener('submit', event => {
            const form = event.target?.closest?.('[data-weight-form]');
            if (!form) return;
            event.preventDefault();
            const cell = form.closest('[data-weight-url]');
            if (cell) void save(cell, form);
        });

        api.on('draw.dt', loadVisible);
        loadVisible();

        return {readCurrent, loadVisible};
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {initialize, validWeight, displayWeight};
    } else {
        scope.SettingsInlineWeight = {initialize};
    }
})(typeof window === 'undefined' ? {} : window);
