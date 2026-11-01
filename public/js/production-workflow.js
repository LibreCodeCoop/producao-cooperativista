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
        const importStatus = el('import-status');
        const importStatusText = el('import-status-text');
        const importElapsed = el('import-elapsed');
        const importProfileLink = el('import-profile-link');
        const publishButton = el('submit');
        const omitted = new Set();
        let preview = null;
        let table = null;
        let loading = false;
        let processing = false;
        let finished = false;
        let needsImport = false;

        const competence = () => el('year').value + '-' + el('month').value;
        const stepButtons = [...doc.querySelectorAll('[data-step-indicator]')];
        const activeStep = () => Number([...wizard.querySelectorAll('[data-workflow-step]')].find(section => !section.hidden)?.dataset.workflowStep || 1);
        const syncNavigation = () => {
            for (const button of stepButtons) {
                const step = Number(button.dataset.stepIndicator);
                button.disabled = loading || processing || finished || (step === 2 && (!preview || needsImport));
                const active = step === activeStep();
                button.classList.toggle('is-active', active);
                if (active) button.setAttribute('aria-current', 'step');
                else button.removeAttribute('aria-current');
            }
        };
        const show = step => {
            for (const section of wizard.querySelectorAll('[data-workflow-step]')) {
                section.hidden = Number(section.dataset.workflowStep) !== step;
            }
            syncNavigation();
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
        const goToImport = () => {
            if (loading || processing || finished) return;
            invalidateApproval();
            show(1);
        };
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
            notice('O cadastro foi alterado na origem. Reimporte esta competência antes de conferir e publicar.');
        };
        doc.addEventListener?.('member-source-changed', weightChanged);

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
                });
                if (!table) throw new Error('Não foi possível montar a tabela de conferência.');
                preview = valid;
                syncNavigation();
                el('review-competence').textContent = competence();
                el('metric-members').textContent = String(valid.rows.length);
                el('metric-hours').textContent = hours(valid.rows.reduce((sum, row) => sum + row.seconds, 0));
                el('metric-base').textContent = money(cents(valid.rows, 'base') / 100);
                el('metric-net').textContent = money(cents(valid.rows, 'net') / 100);
                const goal = el('withdrawal-goal');
                if (goal && payload.metadata?.withdrawal_goal?.mode === 'simulation_only') {
                    const target = payload.metadata.withdrawal_goal;
                    goal.hidden = false;
                    goal.textContent = 'Meta de referência: ' + money(target.net_target) + ' líquidos por ' +
                        target.reference_hours + ' h. Base necessária estimada: ' + money(target.required_pool) +
                        '. Base distribuída nesta competência: ' + money(target.available_pool) +
                        (target.shortfall > 0
                            ? '. Diferença para a meta: ' + money(target.shortfall)
                            : '. Excedente potencial: ' + money(target.potential_surplus)) +
                        '. Somente pessoas físicas, sem adiantamentos e descontos individuais. ' +
                        'A simulação não altera o rateio vigente.';
                } else if (goal && payload.metadata?.withdrawal_goal?.mode === 'unavailable') {
                    goal.hidden = false;
                    goal.textContent = 'Simulação da meta indisponível para as regras tributárias desta competência. ' +
                        'O cálculo e o rateio atuais permanecem válidos.';
                }
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
                syncNavigation();
            }
        };

        importButton.addEventListener('click', async () => {
            if (loading || processing || finished || importButton.disabled) return;
            processing = true;
            resetReview();
            syncNavigation();
            setAction('import');
            messages.replaceChildren();
            const buttonLabel = importButton.textContent;
            const started = (options.nowMs || Date.now)();
            const renderElapsed = () => {
                if (importElapsed) importElapsed.textContent =
                    Math.max(0, Math.floor(((options.nowMs || Date.now)() - started) / 1000)) + ' s';
            };
            const updateStatus = ({state, message}) => {
                if (!importStatus) return;
                importStatus.hidden = false;
                importStatus.dataset.state = state;
                if (importStatusText) importStatusText.textContent = message;
                if (importProfileLink) importProfileLink.hidden =
                    state !== 'failed' || !/PROFILE_DEPENDENTS|PROFILE_SOURCE/.test(message);
                renderElapsed();
            };
            importButton.textContent = 'Importando…';
            updateStatus({state: 'running', message: 'Conectando às fontes. Aguardando resposta do servidor…'});
            importStatus?.scrollIntoView?.({block: 'nearest'});
            const timer = setInterval(renderElapsed, 1000);
            try {
                const success = await progress.submit(form, messages, importButton, {
                    ...options,
                    onStatus: updateStatus,
                });
                if (success) {
                    updateStatus({state: 'completed', message: 'Importação concluída. Carregando conferência…'});
                    needsImport = false;
                    el('use-existing').disabled = false;
                    await loadPreview();
                } else if (importStatus?.dataset.state === 'running') {
                    updateStatus({state: 'failed', message: 'Importação interrompida. Confira o erro abaixo.'});
                }
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Falha ao importar dados.';
                updateStatus({state: 'failed', message});
                notice(message);
            } finally {
                clearInterval(timer);
                importButton.textContent = buttonLabel;
                processing = false;
                syncNavigation();
            }
        });
        el('use-existing').addEventListener('click', () => {
            if (!needsImport) return loadPreview();
        });
        for (const button of stepButtons) {
            button.addEventListener('click', () => {
                if (button.disabled || loading || processing || finished) return;
                if (button.dataset.stepIndicator === '1') goToImport();
                else if (preview && !needsImport) show(2);
                wizard.scrollIntoView?.({block: 'start'});
            });
        }
        el('publication-confirmation').addEventListener('change', updateSummary);
        publishButton.addEventListener('click', async () => {
            if (!preview || finished || loading || needsImport ||
                !el('publication-confirmation').checked ||
                selectedMembers(preview.rows, omitted).included.length === 0) return;
            processing = true;
            syncNavigation();
            setAction('publish');
            messages.replaceChildren();
            try {
                const success = await progress.submit(form, messages, publishButton, options);
                if (success) {
                    finished = true;
                    publishButton.textContent = 'Publicação concluída';
                    publishButton.disabled = true;
                } else {
                    invalidateApproval();
                    notice('Verifique no Akaunting o que foi gravado antes de tentar publicar novamente.');
                }
            } finally {
                processing = false;
                syncNavigation();
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
