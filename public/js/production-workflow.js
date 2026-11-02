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

    function validateSourceReview(payload, competence) {
        if (payload?.competence !== competence || payload?.publication_allowed !== false ||
            !/^\d{4}-(0[1-9]|1[0-2])$/.test(payload?.financial_month || '') ||
            !Number.isSafeInteger(Number(payload?.hours?.entries)) || Number(payload.hours.entries) <= 0 ||
            !Number.isSafeInteger(Number(payload?.hours?.members)) || Number(payload.hours.members) <= 0 ||
            !Number.isFinite(Number(payload?.hours?.seconds)) ||
            !Array.isArray(payload?.hours?.by_member) ||
            !Number.isFinite(Number(payload?.income_transactions?.amount)) ||
            !Number.isFinite(Number(payload?.client_cost_transactions?.amount)) ||
            !Number.isFinite(Number(payload?.internal_expense_transactions?.amount)) ||
            !Array.isArray(payload?.client_cost_transactions?.by_customer) ||
            !Array.isArray(payload?.internal_expense_transactions?.by_category) ||
            !Array.isArray(payload?.unconfirmed_invoices?.documents) ||
            !Array.isArray(payload?.data) ||
            payload.data.length !== Number(payload.hours.members) ||
            !payload.data.every(row => Number.isSafeInteger(row?.kimai_user_id) &&
                row.kimai_user_id > 0 && Number.isFinite(Number(row?.hours_seconds)) &&
                row?.base_producao === null && row?.bruto === null && row?.liquido === null) ||
            !Number.isSafeInteger(Number(payload?.unconfirmed_invoices?.count)) ||
            !Number.isFinite(Number(payload?.unconfirmed_invoices?.amount))) {
            throw new Error('Não foi possível verificar as fontes importadas desta competência.');
        }
        return payload;
    }

    function sourceReviewText(source) {
        const lines = [
            'Horas trabalhadas: ' + source.competence,
            'Apontamentos: ' + source.hours.entries + ' · Cooperados: ' + source.hours.members +
                ' · Horas: ' + hours(source.hours.seconds),
            'Movimentações financeiras: ' + source.financial_month + ' (mês seguinte ao trabalho)',
            'Recebimentos de clientes identificados nas transações: ' +
                money(source.income_transactions.amount) + ' (' + source.income_transactions.count + ' lançamentos)',
            'Custos de clientes em transações: ' + money(source.client_cost_transactions.amount),
            'Despesas internas em transações: ' + money(source.internal_expense_transactions.amount),
            'Faturas para conferir (emissão ou recebimento, inclui rascunhos): ' +
                source.unconfirmed_invoices.count + ' · valor nominal ' +
                money(source.unconfirmed_invoices.amount) + ' (não é saldo em atraso)',
        ];
        if (source.hours.by_member.length) {
            lines.push('', 'Horas por cooperado (Kimai):');
            for (const member of source.hours.by_member) {
                lines.push('  #' + member.id + ' · ' + member.name + ' · ' +
                    hours(member.seconds) + ' · ' + member.entries + ' apontamentos');
            }
        }
        if (source.unconfirmed_invoices.documents.length) {
            lines.push('', 'Faturas que precisam de conferência no Akaunting:');
            for (const invoice of source.unconfirmed_invoices.documents) {
                const status = {draft: 'rascunho', sent: 'enviada', partial: 'parcial', overdue: 'vencida'};
                lines.push('  #' + invoice.id + ' · ' + money(invoice.amount) + ' · ' +
                    (status[invoice.status] || invoice.status) +
                    (invoice.due_date ? ' · vencimento ' + invoice.due_date : ''));
            }
        }
        if (source.unconfirmed_invoices.truncated) lines.push('Lista limitada aos 100 primeiros documentos.');
        if (source.client_cost_transactions.by_customer.length) {
            lines.push('', 'Custos por cliente (transações importadas):');
            for (const item of source.client_cost_transactions.by_customer) {
                lines.push('  ' + item.label + ' · ' + money(item.amount));
            }
        }
        if (source.internal_expense_transactions.by_category.length) {
            lines.push('', 'Despesas internas por categoria (transações importadas):');
            for (const item of source.internal_expense_transactions.by_category) {
                lines.push('  ' + item.label + ' · ' + money(item.amount));
            }
        }
        lines.push('', 'Valores monetários por cooperado serão apresentados após o cálculo financeiro.');
        return lines.join('\n');
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
        const sourceReviewPanel = el('imported-data-review');
        const calculatedReview = el('calculated-review');
        const publicationControls = el('production-publication-controls');
        const omitted = new Set();
        let preview = null;
        let sourceReview = null;
        let table = null;
        let tableMode = null;
        let loading = false;
        let processing = false;
        let finished = false;
        let needsImport = false;
        let availabilityRequest = 0;
        let availability = {
            has_work_data: wizard.dataset.importedWork === 'yes',
            has_staging_data: wizard.dataset.stagingData === 'yes',
        };

        const competence = () => el('year').value + '-' + el('month').value;
        const stepButtons = [...doc.querySelectorAll('[data-step-indicator]')];
        const activeStep = () => Number([...wizard.querySelectorAll('[data-workflow-step]')].find(section => !section.hidden)?.dataset.workflowStep || 1);
        const syncNavigation = () => {
            for (const button of stepButtons) {
                const step = Number(button.dataset.stepIndicator);
                button.disabled = loading || processing || finished || (step === 2 && ((!preview && !sourceReview) || needsImport));
                const active = step === activeStep();
                button.classList.toggle('is-active', active);
                if (active) button.setAttribute('aria-current', 'step');
                else button.removeAttribute('aria-current');
            }
            const reviewButton = el('use-existing');
            if (reviewButton) {
                reviewButton.disabled = !availability?.has_work_data ||
                    needsImport || loading || processing || finished;
            }
            for (const picker of [el('month'), el('year')]) {
                if (picker) picker.disabled = processing || finished;
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
        const setAvailability = state => {
            availability = state;
            const period = competence();
            const reviewButton = el('use-existing');
            const resetLink = el('reset-staging-link');
            const resetHelp = el('reset-staging-help');
            const summary = el('import-availability-status');
            const hasWork = state?.has_work_data === true;
            const hasAny = state?.has_staging_data === true;
            if (reviewButton) reviewButton.hidden = !hasWork || needsImport;
            if (resetLink) {
                resetLink.hidden = !hasAny;
                resetLink.href = '/acoes/zerar-banco-local?retorno=' + encodeURIComponent(period);
            }
            if (resetHelp) resetHelp.hidden = !hasAny;
            if (!hasWork && (preview || sourceReview)) {
                resetReview();
                show(1);
            }
            if (summary) {
                summary.dataset.state = !state ? 'unavailable' :
                    hasWork ? 'available' : hasAny ? 'other' : 'empty';
                summary.textContent = needsImport && hasWork
                    ? 'O cadastro de cooperados foi alterado. Reimporte ' + period +
                        ' antes de conferir ou publicar.'
                    : !state
                        ? 'Não foi possível verificar a base importada. Atualize a página antes de conferir.'
                        : hasWork
                            ? 'Dados disponíveis para conferência: ' + state.work_entries +
                                ' apontamentos de ' + state.work_members + ' cooperados em ' + period + '.'
                            : hasAny
                                ? 'Ainda não há apontamentos importados para ' + period + '.' +
                                    (state.latest_imported_competence &&
                                    state.latest_imported_competence !== period
                                        ? ' Última competência com horas: ' +
                                            state.latest_imported_competence + '.'
                                        : '') + ' Confira o mês ou importe novamente.'
                                : 'Base importada vazia. Selecione a competência e clique em Importar competência.';
            }
            syncNavigation();
        };
        const refreshAvailability = async () => {
            const selected = competence();
            const token = ++availabilityRequest;
            const reviewButton = el('use-existing');
            if (reviewButton) {
                reviewButton.hidden = true;
                reviewButton.disabled = true;
            }
            const summary = el('import-availability-status');
            if (summary) {
                summary.dataset.state = 'checking';
                summary.textContent = 'Verificando os dados importados para ' + selected + '…';
            }
            try {
                const response = await request(wizard.dataset.availabilityUrl + '?ano-mes=' +
                    encodeURIComponent(selected), {
                    credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store',
                });
                if (!response.ok) throw new Error('Falha na consulta de disponibilidade.');
                const state = await response.json();
                if (state?.competence !== selected ||
                    typeof state?.has_work_data !== 'boolean' ||
                    typeof state?.has_staging_data !== 'boolean' ||
                    !Number.isSafeInteger(state?.work_entries) ||
                    !Number.isSafeInteger(state?.work_members)) {
                    throw new Error('Estado da importação inválido.');
                }
                if (token !== availabilityRequest || selected !== competence()) return null;
                setAvailability(state);
                return state;
            } catch {
                if (token !== availabilityRequest || selected !== competence()) return null;
                setAvailability(null);
                return null;
            }
        };
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
        const updateSourceSelection = () => {
            if (!sourceReview) return;
            const members = sourceReview.data.map(row => row.kimai_user_id);
            const excluded = members.filter(id => omitted.has(id)).length;
            el('selection-summary').textContent = excluded + ' cooperado(s) marcados para ' +
                'não publicar agora. Essa escolha será mantida ao calcular a prévia, ' +
                'mas nenhum documento será criado sem revisão financeira e autorização. ' +
                'A seleção não é salva entre recarregamentos da página.';
        };
        const resetReview = (preserveSelection = false) => {
            preview = null;
            sourceReview = null;
            if (!preserveSelection) omitted.clear();
            invalidateApproval();
            if (el('source-financial-status')) el('source-financial-status').hidden = true;
            if (sourceReviewPanel) sourceReviewPanel.hidden = true;
            if (calculatedReview) calculatedReview.hidden = false;
            if (publicationControls) publicationControls.hidden = false;
            if (el('selection-summary')) el('selection-summary').hidden = false;
            if (el('calculated-review-intro')) el('calculated-review-intro').hidden = false;
            if (el('audit-title')) el('audit-title').textContent =
                'Conferir a apuração e selecionar exceções';
        };
        const weightChanged = () => {
            needsImport = true;
            setAvailability(availability);
            resetReview();
            show(1);
            notice('O cadastro foi alterado na origem. Reimporte esta competência antes de conferir e publicar.');
        };
        doc.addEventListener?.('member-source-changed', weightChanged);

        const showSourceReview = async (payload, financialProblem = null, reuseTable = false) => {
            if (!grid || typeof grid.initializeReportTable !== 'function') {
                throw new Error('A tabela de cooperados não carregou. Recarregue a página.');
            }
            show(2);
            if (!reuseTable || tableMode !== 'source' || !table) {
                if (table?.destroy) table.destroy();
                table = null;
                tableMode = null;
                const reportTable = el('example');
                if (reportTable?.dataset) {
                    reportTable.dataset.competencia = competence();
                }
                const mounted = await grid.initializeReportTable(doc, {
                    ...options, payload, omitted, sourceOnly: true, financialReview: true,
                    onDecisionChange: (id, checked) => {
                        if (!payload.data.some(row => row.kimai_user_id === id)) return;
                        if (checked) omitted.add(id);
                        else omitted.delete(id);
                        invalidateApproval();
                        updateSourceSelection();
                    },
                });
                if (!mounted) throw new Error('Não foi possível montar a tabela de cooperados.');
                table = mounted;
                tableMode = 'source';
            }
            sourceReview = payload;
            if (sourceReviewPanel) sourceReviewPanel.hidden = false;
            if (calculatedReview) calculatedReview.hidden = false;
            if (publicationControls) publicationControls.hidden = true;
            if (el('selection-summary')) el('selection-summary').hidden = false;
            updateSourceSelection();
            if (el('calculated-review-intro')) el('calculated-review-intro').hidden = true;
            if (el('withdrawal-goal')) el('withdrawal-goal').hidden = true;
            if (el('audit-title')) el('audit-title').textContent = 'Conferir produção por cooperado';
            const pendingLink = el('pending-invoices-link');
            if (pendingLink) {
                const pending = payload.unconfirmed_invoices;
                pendingLink.hidden = pending.count === 0;
                pendingLink.href = '/relatorios/faturas-conferencia?ano-mes=' +
                    encodeURIComponent(competence());
                pendingLink.textContent = 'Conferir ' + pending.count + ' ' +
                    (pending.count === 1 ? 'fatura' : 'faturas') +
                    ' no Akaunting (' + money(pending.amount) + ' em valores nominais)';
            }
            el('imported-data-summary').textContent = sourceReviewText(payload);
            el('review-competence').textContent = competence();
            el('metric-members').textContent = String(payload.hours.members);
            el('metric-hours').textContent = hours(payload.hours.seconds);
            el('metric-base').textContent = 'A apurar';
            el('metric-net').textContent = 'A apurar';
            const issue = el('source-financial-status');
            if (issue) {
                issue.hidden = !financialProblem;
                issue.textContent = !financialProblem ? '' :
                    financialProblem.code === 'BASE_ECONOMICA'
                        ? 'A regra atual de cálculo não encontrou recebimentos suficientes na ' +
                            'competência financeira ' + payload.financial_month + '. Há ' +
                            payload.unconfirmed_invoices.count + ' ' +
                            (payload.unconfirmed_invoices.count === 1 ? 'fatura' : 'faturas') +
                            ' que precisam de revisão de emissão ou recebimento no Akaunting. ' +
                            'O valor nominal conjunto é ' + money(payload.unconfirmed_invoices.amount) +
                            ', não necessariamente um saldo em atraso. ' +
                            'Consulte a situação e o próximo passo no relatório de faturas. ' +
                            'A conferência das horas continua disponível.'
                        : 'Não foi possível concluir o cálculo de retirada. Os dados importados ' +
                            'permanecem disponíveis para conferência. Consulte o relatório de ' +
                            'movimentações e os logs técnicos antes de tentar publicar.';
            }
            invalidateApproval();
            syncNavigation();
            // Focus the familiar table, not the technical financial movements.
            sourceReviewPanel?.scrollIntoView?.({block: 'start'});
        };

        const loadSourceReview = async financialProblem => {
            const url = wizard.dataset.sourcesUrl + '?ano-mes=' + encodeURIComponent(competence());
            const response = await request(url, {
                credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store',
            });
            if (!response.ok) throw new Error('Não foi possível consultar os lançamentos importados.');
            const payload = validateSourceReview(await response.json(), competence());
            await showSourceReview(payload, financialProblem);
            return true;
        };

        const reviewImportedData = async (verifyAvailability = true) => {
            if (loading || finished || needsImport) return false;
            if (verifyAvailability) {
                const current = await refreshAvailability();
                if (!current?.has_work_data) return false;
            }
            loading = true;
            resetReview();
            messages.replaceChildren();
            updateLinks();
            try {
                return await loadSourceReview();
            } catch (error) {
                resetReview();
                show(1);
                notice(error instanceof Error ? error.message : 'Falha na consulta das fontes.');
                return false;
            } finally {
                loading = false;
                syncNavigation();
            }
        };

        const loadPreview = async () => {
            if (loading || finished || needsImport) return false;
            const previouslyImported = sourceReview;
            loading = true;
            resetReview(Boolean(previouslyImported));
            messages.replaceChildren();
            updateLinks();
            try {
                const response = await request(wizard.dataset.previewUrl + '?ano-mes=' +
                    encodeURIComponent(competence()), {
                    credentials: 'same-origin', headers: {Accept: 'application/json'}, cache: 'no-store',
                });
                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    if (payload?.code === 'BASE_ECONOMICA') {
                        if (previouslyImported?.competence === competence() && tableMode === 'source' && table) {
                            await showSourceReview(previouslyImported, payload, true);
                            return true;
                        }
                        return await loadSourceReview(payload);
                    }
                    throw new Error(payload?.error?.message ||
                        (typeof payload?.error === 'string' ? payload.error : '') ||
                        payload?.resolution || 'Não foi possível carregar a prévia. Confira as fontes.');
                }
                const payload = await response.json();
                const valid = validatePreview(payload, competence());
                const currentMemberIds = new Set(valid.rows.map(row => row.id));
                for (const id of omitted) {
                    if (!currentMemberIds.has(id)) omitted.delete(id);
                }
                if (sourceReviewPanel) sourceReviewPanel.hidden = true;
                if (calculatedReview) calculatedReview.hidden = false;
                if (publicationControls) publicationControls.hidden = false;
                if (el('selection-summary')) el('selection-summary').hidden = false;
                if (el('calculated-review-intro')) el('calculated-review-intro').hidden = false;
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
                table = null;
                tableMode = null;
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
                tableMode = 'calculated';
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
                resetReview(Boolean(previouslyImported));
                if (previouslyImported?.competence === competence()) {
                    await showSourceReview(previouslyImported, {code: 'PREVIEW_FAILED'},
                        tableMode === 'source' && !!table);
                    return false;
                }
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
                    updateStatus({state: 'completed', message: 'Importação concluída. Verificando dados disponíveis…'});
                    needsImport = false;
                    const available = await refreshAvailability();
                    const ready = available?.has_work_data === true
                        ? await reviewImportedData(false) : false;
                    updateStatus({state: ready ? 'completed' : 'failed', message: ready
                        ? 'Importação concluída. Dados de origem disponíveis para conferência.'
                        : available
                            ? 'A importação terminou sem apontamentos desta competência. Verifique as fontes e importe novamente.'
                            : 'Importação encerrada, mas a base não pôde ser verificada. Atualize a página antes de conferir.'});
                } else {
                    needsImport = true;
                    if (importStatus?.dataset.state === 'running') {
                        updateStatus({state: 'failed', message: 'Importação interrompida. Confira o erro abaixo.'});
                    }
                    await refreshAvailability();
                }
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Falha ao importar dados.';
                updateStatus({state: 'failed', message});
                needsImport = true;
                await refreshAvailability();
                notice(message);
            } finally {
                clearInterval(timer);
                importButton.textContent = buttonLabel;
                processing = false;
                syncNavigation();
            }
        });
        el('use-existing').addEventListener('click', () => {
            if (!needsImport && !el('use-existing').disabled &&
                !el('use-existing').hidden) return reviewImportedData();
        });
        el('calculate-financial-preview').addEventListener('click', () => {
            if (sourceReview && !processing && !needsImport) return loadPreview();
        });
        for (const button of stepButtons) {
            button.addEventListener('click', () => {
                if (button.disabled || loading || processing || finished) return;
                if (button.dataset.stepIndicator === '1') goToImport();
                else if ((preview || sourceReview) && !needsImport) show(2);
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
                if (processing || finished) return;
                resetReview();
                updateLinks();
                messages.replaceChildren();
                show(1);
                return refreshAvailability();
            });
        }
        // Browser back/forward can restore the old page after another tab
        // cleared the ETL: verify again instead of trusting cached controls.
        win.addEventListener?.('pageshow', event => {
            if (event.persisted && !processing && !finished) return refreshAvailability();
        });
        win.addEventListener?.('focus', () => {
            if (!processing && !finished) return refreshAvailability();
        });
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
