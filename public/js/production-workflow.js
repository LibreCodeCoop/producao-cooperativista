// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

(function (scope) {
    const formatMoney = new Intl.NumberFormat('pt-BR', {
        style: 'currency', currency: 'BRL',
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    });

    function money(value) {
        if (!Number.isFinite(Number(value))) return '—';
        return formatMoney.format(Number(value));
    }

    function hours(seconds) {
        const value = Number(seconds);
        return Number.isFinite(value) && value >= 0
            ? new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 2}).format(value / 3600) + ' h'
            : '—';
    }

    function validatePreview(payload, competence) {
        if (!Array.isArray(payload?.data) || payload.data.length === 0 ||
            payload?.metadata?.competence !== competence ||
            !/^[a-f0-9]{64}$/.test(payload?.metadata?.revision || '')) {
            throw new Error('Não há prévia válida para esta competência. Importe os dados e tente novamente.');
        }
        const ids = new Set();
        const rows = payload.data.map(row => {
            const id = Number(row.kimai_user_id);
            if (!Number.isSafeInteger(id) || id <= 0 || ids.has(id) ||
                typeof row.name !== 'string' ||
                !Number.isFinite(Number(row.base_producao)) ||
                !Number.isFinite(Number(row.liquido)) ||
                !Number.isFinite(Number(row.hours_seconds))) {
                throw new Error('A prévia contém um cooperado sem vínculo ou valores válidos. Corrija a importação.');
            }
            ids.add(id);
            return {
                id,
                name: row.name,
                base: Number(row.base_producao),
                net: Number(row.liquido),
                seconds: Number(row.hours_seconds),
            };
        });
        return {rows, revision: payload.metadata.revision};
    }

    function addCell(doc, row, value) {
        const cell = doc.createElement('td');
        cell.textContent = String(value);
        row.appendChild(cell);
        return cell;
    }

    function renderRows(doc, body, rows, withSelection = false) {
        body.replaceChildren();
        for (const data of rows) {
            const row = doc.createElement('tr');
            addCell(doc, row, data.name);
            addCell(doc, row, hours(data.seconds));
            if (!withSelection) addCell(doc, row, money(data.base));
            addCell(doc, row, money(data.net));
            if (withSelection) {
                const cell = doc.createElement('td');
                const label = doc.createElement('label');
                label.className = 'production-exclude-label';
                const checkbox = doc.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.dataset.omitUserId = String(data.id);
                label.appendChild(checkbox);
                label.appendChild(doc.createTextNode('Não publicar agora'));
                cell.appendChild(label);
                row.appendChild(cell);
            }
            body.appendChild(row);
        }
    }

    function sumCents(rows) {
        return rows.reduce((sum, row) => sum + Math.round(row.net * 100), 0);
    }

    function selectedMembers(rows, omitIds) {
        const exclude = new Set(omitIds);
        return {
            included: rows.filter(row => !exclude.has(row.id)),
            excluded: rows.filter(row => exclude.has(row.id)),
        };
    }

    function initialize(doc = scope.document, options = {}) {
        const wizard = doc?.getElementById('production-wizard');
        const form = doc?.getElementById('form-gera-producao');
        if (!wizard || !form) return false;

        const el = id => doc.getElementById(id);
        const win = options.window || scope;
        const request = options.fetch || win.fetch.bind(win);
        const progress = options.progress || win.ProductionProgress;
        const messages = el('messages');
        const importButton = el('import-button');
        const publishButton = el('submit');
        let currentStep = 1;
        let preview = null;
        let finished = false;

        const competence = () => form.elements.year.value + '-' + form.elements.month.value;
        const show = step => {
            currentStep = step;
            for (const section of wizard.querySelectorAll('[data-workflow-step]')) {
                section.hidden = Number(section.dataset.workflowStep) !== step;
            }
            for (const indicator of doc.querySelectorAll('[data-step-indicator]')) {
                const active = Number(indicator.dataset.stepIndicator) === step;
                indicator.classList.toggle('is-active', active);
                if (active) indicator.setAttribute('aria-current', 'step');
                else indicator.removeAttribute('aria-current');
            }
        };
        const periodLinks = () => {
            const period = competence();
            for (const link of wizard.querySelectorAll('[data-report-path]')) {
                link.href = link.dataset.reportPath + '?ano-mes=' + encodeURIComponent(period);
            }
        };
        const setAction = (operation, revision = '', omit = '') => {
            form.elements.operation.value = operation;
            form.elements.baixar_dados.value = operation === 'import' ? '1' : '0';
            form.elements.atualiza_producao.value = operation === 'publish' ? '1' : '0';
            form.elements.expected_revision.value = revision;
            form.elements.omit_user_ids.value = omit;
        };
        const notice = text => progress.addMessage(messages, 'warning', text, doc);
        const omitIds = () => [...el('selection-rows').querySelectorAll('[data-omit-user-id]:checked')]
            .map(input => Number(input.dataset.omitUserId));
        const updateSelection = () => {
            if (!preview) return;
            const groups = selectedMembers(preview.rows, omitIds());
            el('selection-summary').textContent =
                groups.included.length + ' com documentos previstos (' +
                money(sumCents(groups.included) / 100) + ') · ' +
                groups.excluded.length + ' não publicados agora (' +
                money(sumCents(groups.excluded) / 100) + ').';
        };
        const loadPreview = async () => {
            messages.replaceChildren();
            periodLinks();
            const response = await request(wizard.dataset.previewUrl + '?ano-mes=' +
                encodeURIComponent(competence()), {
                credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store',
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                throw new Error(payload?.error?.message || payload?.resolution ||
                    'Não foi possível calcular a prévia. Confira a importação e a memória de cálculo.');
            }
            preview = validatePreview(await response.json(), competence());
            renderRows(doc, el('preview-rows'), preview.rows);
            renderRows(doc, el('selection-rows'), preview.rows, true);
            el('production-summary').textContent = preview.rows.length +
                ' cooperados apurados · ' + money(sumCents(preview.rows) / 100) +
                ' de líquido previsto. Valores monetários apresentados em centavos.';
            el('review-confirmation').checked = false;
            el('audit-next').disabled = true;
            el('publication-confirmation').checked = false;
            publishButton.disabled = true;
            updateSelection();
            show(2);
        };

        importButton.addEventListener('click', async () => {
            if (finished || importButton.disabled) return;
            preview = null;
            setAction('import');
            messages.replaceChildren();
            const success = await progress.submit(form, messages, importButton, options);
            if (success) {
                try {
                    await loadPreview();
                } catch (error) {
                    notice(error.message);
                }
            }
        });
        el('use-existing').addEventListener('click', async () => {
            if (finished) return;
            try {
                await loadPreview();
            } catch (error) {
                notice(error.message);
            }
        });
        el('review-confirmation').addEventListener('change', event => {
            el('audit-next').disabled = !event.target.checked;
        });
        el('audit-next').addEventListener('click', () => {
            if (!preview || !el('review-confirmation').checked) return;
            show(3);
        });
        el('selection-rows').addEventListener('change', updateSelection);
        el('selection-next').addEventListener('click', () => {
            if (!preview) return;
            const groups = selectedMembers(preview.rows, omitIds());
            if (groups.included.length === 0) {
                notice('Não é possível publicar com todos os cooperados desmarcados.');
                return;
            }
            el('publication-summary').textContent = competence() + ': ' +
                groups.included.length + ' com documentos individuais (' +
                money(sumCents(groups.included) / 100) + ' de líquido previsto); ' +
                groups.excluded.length + ' sem publicação agora. Os valores não são redistribuídos.';
            el('publication-confirmation').checked = false;
            publishButton.disabled = true;
            show(4);
        });
        el('publication-confirmation').addEventListener('change', event => {
            publishButton.disabled = !event.target.checked || finished;
        });
        publishButton.addEventListener('click', async () => {
            if (!preview || finished || publishButton.disabled ||
                !el('publication-confirmation').checked) return;
            setAction('publish', preview.revision, omitIds().join(','));
            messages.replaceChildren();
            const success = await progress.submit(form, messages, publishButton, options);
            if (success) {
                finished = true;
                publishButton.textContent = 'Publicação concluída';
                publishButton.disabled = true;
            } else {
                notice('Antes de repetir a publicação, confira no Akaunting os documentos que possam ter sido gravados.');
            }
        });
        for (const button of wizard.querySelectorAll('[data-back]')) {
            button.addEventListener('click', () => {
                if (!finished) show(Number(button.dataset.back));
            });
        }
        for (const input of [el('month'), el('year')]) {
            input.addEventListener('change', () => {
                preview = null;
                periodLinks();
                show(1);
                messages.replaceChildren();
            });
        }
        form.addEventListener('submit', event => event.preventDefault());
        periodLinks();
        show(currentStep);
        return true;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {money, hours, validatePreview, selectedMembers, initialize};
    } else {
        scope.addEventListener('DOMContentLoaded', () => initialize());
    }
})(typeof window === 'undefined' ? {} : window);
