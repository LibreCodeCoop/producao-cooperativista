// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

(function (scope) {
    const brl = new Intl.NumberFormat('pt-BR', {
        style: 'currency', currency: 'BRL', minimumFractionDigits: 2, maximumFractionDigits: 2,
    });
    const hoursFormat = new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 2});

    function money(value) {
        return Number.isFinite(Number(value)) ? brl.format(Number(value)) : '—';
    }

    function hours(seconds) {
        const n = Number(seconds);
        return Number.isFinite(n) && n >= 0 ? hoursFormat.format(n / 3600) + ' h' : '—';
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
                id, name: row.name, base: Number(row.base_producao),
                net: Number(row.liquido), seconds: Number(row.hours_seconds),
            };
        });
        return {rows, revision: payload.metadata.revision};
    }

    function selectedMembers(rows, omitted) {
        const excluded = new Set(omitted);
        return {
            included: rows.filter(row => !excluded.has(row.id)),
            excluded: rows.filter(row => excluded.has(row.id)),
        };
    }

    function cents(rows, field) {
        return rows.reduce((sum, row) => sum + Math.round(row[field] * 100), 0);
    }

    function initialize(doc = scope.document, options = {}) {
        const wizard = doc?.getElementById('production-wizard');
        const form = doc?.getElementById('form-gera-producao');
        if (!wizard || !form) return false;

        const el = id => doc.getElementById(id);
        const win = options.window || scope;
        const request = options.fetch || win.fetch.bind(win);
        const progress = options.progress || win.ProductionProgress;
        const grid = options.reportTable || win.ReportTable;
        const messages = el('messages');
        const importButton = el('import-button');
        const publishButton = el('submit');
        const omitted = new Set();
        let preview = null;
        let table = null;
        let loading = false;
        let finished = false;
        let needsImport = false;

        const competence = () => el('year').value + '-' + el('month').value;
        const show = step => {
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
        const setAction = (operation) => {
            form.elements.year.value = el('year').value;
            form.elements.month.value = el('month').value;
            form.elements.operation.value = operation;
            form.elements.baixar_dados.value = operation === 'import' ? '1' : '0';
            form.elements.atualiza_producao.value = operation === 'publish' ? '1' : '0';
            form.elements.expected_revision.value = operation === 'publish' ? preview?.revision || '' : '';
            form.elements.omit_user_ids.value = operation === 'publish'
                ? [...omitted].sort((a, b) => a - b).join(',') : '';
        };
        const notice = message => progress.addMessage(messages, 'warning', message, doc);
        const updateLinks = () => {
            const period = competence();
            for (const link of wizard.querySelectorAll('[data-report-path]')) {
                link.href = link.dataset.reportPath + '?ano-mes=' + encodeURIComponent(period);
            }
        };
        const updateSummary = () => {
            if (!preview) return;
            const groups = selectedMembers(preview.rows, omitted);
            const included = groups.included.length;
            el('selection-summary').textContent = included +
                ' com documentos previstos (' + money(cents(groups.included, 'net') / 100) + ') · ' +
                groups.excluded.length + ' não publicados agora (' +
                money(cents(groups.excluded, 'net') / 100) +
                '). Seleção total, inclusive cooperados ocultos por filtros.';
            el('publication-summary').textContent = competence() + ': ' +
                included + ' documento(s) individuais previsto(s). ' +
                groups.excluded.length + ' não publicado(s) agora. ' +
                'Os valores não serão redistribuídos e nenhum pagamento bancário será executado.';
            publishButton.disabled = !preview || !included ||
                !el('publication-confirmation').checked || loading || finished || needsImport;
        };
        const invalidateApproval = () => {
            el('publication-confirmation').checked = false;
            publishButton.disabled = true;
        };
        const resetReview = () => {
            preview = null;
            omitted.clear();
            invalidateApproval();
        };
        const weightChanged = () => {
            needsImport = true;
            el('use-existing').disabled = true;
            resetReview();
            show(1);
            notice('O peso foi salvo no Nextcloud. Reimporte esta competência antes de conferir e publicar.');
        };

        const loadPreview = async () => {
            if (loading || finished || needsImport) return false;
            loading = true;
            resetReview();
            messages.replaceChildren();
            updateLinks();
            try {
                const response = await request(wizard.dataset.previewUrl + '?ano-mes=' +
                    encodeURIComponent(competence()), {
                    credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store',
                });
                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    throw new Error(payload?.error?.message || payload?.resolution ||
                        'Não foi possível carregar a prévia. Confira as fontes.');
                }
                const payload = await response.json();
                const valid = validatePreview(payload, competence());
                if (!grid || typeof grid.initializeReportTable !== 'function') {
                    throw new Error('O DataTables não carregou. Recarregue a página antes de publicar.');
                }
                show(2);
                const reportTable = el('example');
                if (reportTable?.dataset) {
                    reportTable.dataset.competencia = competence();
                    reportTable.dataset.url = wizard.dataset.previewUrl +
                        '?ano-mes=' + encodeURIComponent(competence());
                }
                if (table?.destroy) table.destroy();
                table = await grid.initializeReportTable(doc, {
                    ...options,
                    payload,
                    omitted,
                    onDecisionChange: (id, checked) => {
                        if (!valid.rows.some(row => row.id === id)) return;
                        if (checked) omitted.add(id);
                        else omitted.delete(id);
                        invalidateApproval();
                        updateSummary();
                    },
                    onEditing: invalidateApproval,
                    onSaved: weightChanged,
                });
                if (!table) throw new Error('Não foi possível montar a tabela de conferência.');
                preview = valid;
                el('review-competence').textContent = competence();
                el('metric-members').textContent = String(valid.rows.length);
                el('metric-hours').textContent = hours(valid.rows.reduce((sum, row) => sum + row.seconds, 0));
                el('metric-base').textContent = money(cents(valid.rows, 'base') / 100);
                el('metric-net').textContent = money(cents(valid.rows, 'net') / 100);
                invalidateApproval();
                updateSummary();
                return true;
            } catch (error) {
                resetReview();
                show(1);
                notice(error instanceof Error ? error.message : 'Falha ao carregar a conferência.');
                return false;
            } finally {
                loading = false;
            }
        };

        importButton.addEventListener('click', async () => {
            if (loading || finished || importButton.disabled) return;
            resetReview();
            setAction('import');
            messages.replaceChildren();
            if (await progress.submit(form, messages, importButton, options)) {
                needsImport = false;
                el('use-existing').disabled = false;
                await loadPreview();
            }
        });
        el('use-existing').addEventListener('click', () => {
            if (!needsImport) return loadPreview();
        });
        el('reload-preview').addEventListener('click', () => loadPreview());
        el('publication-confirmation').addEventListener('change', updateSummary);
        el('back-import').addEventListener('click', () => {
            if (finished) return;
            resetReview();
            show(1);
        });
        publishButton.addEventListener('click', async () => {
            if (!preview || finished || loading || needsImport ||
                !el('publication-confirmation').checked ||
                selectedMembers(preview.rows, omitted).included.length === 0) return;
            setAction('publish');
            messages.replaceChildren();
            const success = await progress.submit(form, messages, publishButton, options);
            if (success) {
                finished = true;
                publishButton.textContent = 'Publicação concluída';
                publishButton.disabled = true;
            } else {
                invalidateApproval();
                notice('Verifique no Akaunting o que foi gravado antes de tentar publicar novamente.');
            }
        });
        for (const input of [el('month'), el('year')]) {
            input.addEventListener('change', () => {
                resetReview();
                updateLinks();
                messages.replaceChildren();
                show(1);
            });
        }
        form.addEventListener('submit', event => event.preventDefault());
        updateLinks();
        show(1);
        return true;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {money, hours, validatePreview, selectedMembers, initialize};
    } else {
        scope.addEventListener('DOMContentLoaded', () => initialize());
    }
})(typeof window === 'undefined' ? {} : window);
