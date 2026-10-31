// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

(function (scope) {
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[character]);
    }

    function columnTitle(name) {
        const label = String(name).replace(/_/g, ' ');
        return label.charAt(0).toUpperCase() + label.slice(1);
    }

    function nestedTableHtml(value) {
        let records = value;
        if (typeof value === 'string') {
            try {
                records = JSON.parse(value);
            } catch {
                return null;
            }
        }
        if (!Array.isArray(records) || !records.length ||
            !records.every(row => row && typeof row === 'object' && !Array.isArray(row))) {
            return null;
        }
        const columns = [...new Set(records.flatMap(row => Object.keys(row)))];
        if (!columns.length) {
            return null;
        }

        const heading = columns.map(key => '<th>' + escapeHtml(columnTitle(key)) + '</th>').join('');
        const body = records.map(row => '<tr>' + columns.map(key =>
            '<td>' + escapeHtml(row[key]) + '</td>'
        ).join('') + '</tr>').join('');
        return '<table border="1"><thead><tr>' + heading +
            '</tr></thead><tbody>' + body + '</tbody></table>';
    }

    function safeLinkHtml(value, allowRelative = false) {
        if (typeof value !== 'string') {
            return null;
        }
        // Legacy APIs return anchors as data. Only accept the known shape
        // and never reuse upstream HTML attributes or untrusted protocols.
        const match = /^<a href="([^"]+)"( target="_blank")?>([^<]*)<\/a>$/.exec(value);
        if (!match) {
            return null;
        }
        const href = match[1];
        const relative = allowRelative && href.startsWith('/') && !href.startsWith('//') &&
            !/[\\\x00-\x1f]/.test(href);
        let destination = href;
        if (!relative) {
            try {
                const url = new URL(href);
                if (url.protocol !== 'https:' && url.protocol !== 'http:') {
                    return null;
                }
                destination = url.href;
            } catch {
                return null;
            }
        }
        const external = match[2] === ' target="_blank"';
        if (allowRelative && !relative) {
            return null;
        }
        return '<a href="' + escapeHtml(destination) + '"' +
            (external ? ' target="_blank" rel="noopener noreferrer"' : '') +
            '>' + escapeHtml(match[3]) + '</a>';
    }

    const MONEY_COLUMNS = new Set([
        'base_producao', 'bruto', 'liquido', 'auxilio', 'frra', 'inss',
        'irpf', 'base_irpf', 'liquid_discount',
    ]);
    const brl = new Intl.NumberFormat('pt-BR', {
        style: 'currency', currency: 'BRL', minimumFractionDigits: 2, maximumFractionDigits: 2,
    });
    const decimalExport = new Intl.NumberFormat('en-US', {
        useGrouping: false, minimumFractionDigits: 2, maximumFractionDigits: 2,
    });
    const percentFormat = new Intl.NumberFormat('pt-BR', {
        maximumFractionDigits: 2,
    });

    function weightEditor(row, token, competence) {
        const id = Number(row?.kimai_user_id);
        if (!Number.isSafeInteger(id) || id <= 0) {
            return escapeHtml(row?.peso ?? '');
        }
        const base = '/configuracao/cooperado/' + id;
        const snapshot = escapeHtml(row.peso ?? '');
        return '<div class="settings-weight-cell" data-weight-url="' + base + '/peso">' +
            '<div class="settings-weight-display"><span data-weight-current>' + snapshot + '</span>' +
            '<button type="button" class="settings-weight-edit" data-weight-edit>Editar</button></div>' +
            '<form data-weight-form action="' + base + '/nextcloud" method="post" hidden>' +
            '<input type="hidden" name="_token" value="' + escapeHtml(token) + '">' +
            '<input type="hidden" name="field" value="weight">' +
            '<input type="hidden" name="competencia" value="' + escapeHtml(competence) + '">' +
            '<input type="hidden" name="expected" value="">' +
            '<label>Peso atual no Nextcloud</label>' +
            '<input type="text" name="value" inputmode="decimal" maxlength="8" required>' +
            '<button type="submit">Salvar</button><button type="button" data-weight-cancel>Cancelar</button>' +
            '</form><span data-weight-message role="status" aria-live="polite"></span></div>';
    }

    function duration(seconds) {
        const n = Number(seconds);
        if (!Number.isFinite(n) || n < 0) return '—';
        const whole = Math.round(n);
        return Math.floor(whole / 3600) + ':' +
            String(Math.floor((whole % 3600) / 60)).padStart(2, '0') + ':' +
            String(whole % 60).padStart(2, '0');
    }

    function productionDetails(record) {
        let rows = [];
        try {
            const parsed = JSON.parse(record.trabalhado || '[]');
            if (Array.isArray(parsed)) rows = parsed;
        } catch {
            // A damaged detail field must not expose HTML or break the report.
        }
        const work = rows.filter(item => item && typeof item === 'object').map(item => {
            const percent = Number(item.percentual_trabalhado);
            const share = item.percentual_trabalhado !== null &&
                item.percentual_trabalhado !== undefined && Number.isFinite(percent)
                ? percentFormat.format(percent) + '%' : '—';
            return '<tr><td>' + escapeHtml(item.nome || '—') + '</td>' +
                '<td>' + escapeHtml(item.trabalhado || '—') + '</td>' +
                '<td>' + escapeHtml(duration(Number(item.total_cliente) * 3600)) + '</td>' +
                '<td>' + escapeHtml(share) + '</td></tr>';
        }).join('');
        const moneyFields = [
            ['Base da produção', 'base_producao'], ['Auxílio', 'auxilio'],
            ['FRRA', 'frra'], ['Bruto', 'bruto'], ['INSS', 'inss'],
            ['Base IRPF', 'base_irpf'], ['IRPF', 'irpf'],
            ['Desconto líquido', 'liquid_discount'],
            ['Adiantamentos', 'adiantamento'], ['Líquido', 'liquido'],
        ];
        const facts = moneyFields.filter(([,key]) => record[key] !== undefined &&
            record[key] !== null && record[key] !== '').map(([label,key]) => {
            const explanation = record.explanations?.[key];
            let amount = Number(record[key]);
            if (key === 'adiantamento') {
                try {
                    const values = typeof record[key] === 'string'
                        ? JSON.parse(record[key]) : record[key];
                    amount = Array.isArray(values)
                        ? values.reduce((sum, value) => sum + Number(value.amount || 0), 0) : 0;
                } catch {
                    amount = NaN;
                }
            }
            const value = Number.isFinite(amount)
                ? escapeHtml(brl.format(Number(amount))) : '—';
            const help = explanation && typeof explanation.detail === 'string'
                ? '<details class="finance-help' + (explanation.attention ? ' is-warning' : '') +
                    '"><summary aria-label="Como é calculado ' + escapeHtml(label) +
                    '" title="Entender ' + escapeHtml(label) + '">?</summary><div>' +
                    '<strong>' + escapeHtml(explanation.summary || label) + '</strong><p>' +
                    escapeHtml(explanation.detail) + '</p></div></details>' : '';
            return '<div><dt>' + escapeHtml(label) + help + '</dt><dd>' +
                value + '</dd></div>';
        }).join('');
        return '<div class="production-row-details"><div><h4>Horas por cliente</h4>' +
            (work ? '<div class="production-detail-scroll"><table><thead><tr><th>Cliente</th>' +
            '<th>Horas do cooperado</th><th>Tempo total do cliente</th>' +
            '<th>Participação no cliente</th></tr></thead>' +
            '<tbody>' + work + '</tbody></table></div>' : '<p>Sem horas de clientes detalhadas.</p>') +
            '</div><div><h4>Composição financeira</h4><dl>' + facts + '</dl></div></div>';
    }

    function buildProductionColumns(options = {}) {
        const cell = (key, title) => ({
            data: key, title, name: key, defaultContent: '',
            render: (value, type, row) => {
                if (key === 'name' && type === 'display') {
                    const id = Number(row?.kimai_user_id);
                    if (!Number.isSafeInteger(id) || id <= 0) return escapeHtml(value);
                    const href = '/configuracao/cooperado/' + id + '/editar?competencia=' +
                        encodeURIComponent(options.competence || '');
                    return '<a class="production-member-link" data-member-editor-open href="' +
                        escapeHtml(href) + '" title="Conferir cadastro e editar fontes">' +
                        escapeHtml(value) + '<span aria-hidden="true">↗</span></a>';
                }
                return renderCell(value, type, key, row, options);
            },
        });
        const columns = [
            cell('name', 'Cooperado'),
            {
                data: 'hours_seconds', title: 'Horas', name: 'hours_seconds', defaultContent: '',
                render: (value, type) => type === 'display' || type === 'export'
                    ? escapeHtml(duration(value)) : Number(value || 0),
            },
            cell('peso', 'Peso'),
            cell('base_producao', 'Base de produção'),
            cell('bruto', 'Bruto'),
            cell('liquido', 'Líquido'),
            {
                data: null, title: 'Conferir', name: 'detalhes',
                orderable: false, searchable: false, defaultContent: '',
                render: (_value, type) => type === 'display'
                    ? '<button type="button" data-production-details class="production-detail-button" aria-expanded="false">Detalhes</button>'
                    : '',
            },
        ];
        if (options.financialReview) {
            columns.push({
                data: null, title: 'Exceção', name: 'decisao',
                orderable: false, searchable: false, defaultContent: '',
                render: (_value, type, row) => {
                    if (type !== 'display') return '';
                    const id = Number(row.kimai_user_id);
                    if (!Number.isSafeInteger(id) || id <= 0) return '';
                    return '<label class="production-exclude-label" title="Não publicar agora">' +
                        '<input type="checkbox" data-omit-user-id="' + id +
                        '" aria-label="Não publicar agora: ' + escapeHtml(row.name || '') + '"' +
                        (options.omitted?.has(id) ? ' checked' : '') +
                        '><span>Omitir</span></label>';
                },
            });
        }
        return columns;
    }

    function renderCell(value, type, column, row = null, options = {}) {
        if (type === 'export' && options.inlineWeight && MONEY_COLUMNS.has(column)) {
            return value !== null && value !== '' && Number.isFinite(Number(value))
                ? decimalExport.format(Number(value)) : '';
        }
        if (type !== 'display') {
            return value ?? '';
        }
        if (options.inlineWeight && column === 'peso') {
            return weightEditor(row, options.csrf, options.competence);
        }
        if (options.inlineWeight && MONEY_COLUMNS.has(column) && value !== null
            && value !== '' && Number.isFinite(Number(value))) {
            return '<span class="money-cell">' + escapeHtml(brl.format(Number(value))) + '</span>';
        }
        if (column === 'URL' || column === 'nome') {
            const link = safeLinkHtml(value, column === 'nome');
            if (link !== null) {
                return link;
            }
        }
        return nestedTableHtml(value) ?? escapeHtml(value);
    }

    function buildColumns(rows, excluded = ['metadata'], options = {}) {
        const keys = [...new Set(rows.flatMap(row =>
            row && typeof row === 'object' && !Array.isArray(row) ? Object.keys(row) : []
        ))];
        return keys.filter(key => !excluded.includes(key)).map(key => ({
            data: key,
            title: columnTitle(key),
            defaultContent: '',
            render: (value, type, row) => renderCell(value, type, key, row, options),
        }));
    }

    function stateUrl(href, filters, globalSearch, page, length = 10, order = null) {
        const url = new URL(href);
        for (const [key, value] of Object.entries(filters)) {
            if (value) {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.delete(key);
            }
        }
        if (globalSearch) {
            url.searchParams.set('dtsearch', globalSearch);
        } else {
            url.searchParams.delete('dtsearch');
        }
        if (Number.isSafeInteger(page) && page > 0) {
            url.searchParams.set('dtpage', String(page));
        } else {
            url.searchParams.delete('dtpage');
        }
        if (length !== 10 && [25, 50, 100].includes(length)) {
            url.searchParams.set('dtlength', String(length));
        } else {
            url.searchParams.delete('dtlength');
        }
        if (order && !(order[0] === 0 && order[1] === 'asc')) {
            url.searchParams.set('dtorder', order.join(':'));
        } else {
            url.searchParams.delete('dtorder');
        }
        return url.pathname + url.search + url.hash;
    }

    function setupFilters(api, columns, doc, location, history, cleanups = []) {
        const params = new URLSearchParams(location.search);
        const inputs = new Map();
        let restoring = true;
        const save = () => {
            if (restoring) {
                return;
            }
            const filters = Object.fromEntries([...inputs].map(([key, input]) => [key, input.value]));
            const url = stateUrl(
                location.href, filters, api.search(), api.page(),
                api.page?.len?.() ?? 10, api.order?.()?.[0] ?? null,
            );
            if (url !== location.pathname + location.search + location.hash) {
                history.replaceState({}, '', url);
            }
        };

        api.columns().every(function (index) {
            const column = this;
            const footer = column.footer();
            if (!footer || columns[index].searchable === false) {
                return;
            }
            const input = doc.createElement('input');
            input.name = columns[index].data;
            input.placeholder = columns[index].title;
            input.value = params.get(input.name) || '';
            footer.replaceChildren(input);
            inputs.set(input.name, input);
            if (input.value) {
                column.search(input.value, false, false);
            }
            input.addEventListener('keyup', () => {
                if (column.search() !== input.value) {
                    column.search(input.value, false, false).draw();
                }
                save();
            });
        });

        const search = params.get('dtsearch');
        if (search) {
            api.search(search);
        }
        if ([...inputs.values()].some(input => input.value) || search) {
            api.draw(false);
        }
        const page = params.get('dtpage');
        if (page && /^\d{1,5}$/.test(page) && Number(page) > 0) {
            api.page(Number(page)).draw(false);
        }
        const length = params.get('dtlength');
        if (['25', '50', '100'].includes(length) && api.page?.len) {
            api.page.len(Number(length));
            api.draw(false);
        }
        const match = /^(\d{1,2}):(asc|desc)$/.exec(params.get('dtorder') || '');
        if (match && api.order) {
            const index = Number(match[1]);
            if (columns[index] && columns[index].orderable !== false) {
                api.order([[index, match[2]]]).draw(false);
            }
        }
        restoring = false;

        const globalInput = doc.querySelector('#example_wrapper .dt-search input') ||
            doc.querySelector('#example_filter input');
        globalInput?.addEventListener('input', save);
        // DataTables scrollX mirrors tfoot into its own scroll footer. DOM
        // listeners on the original footer are not copied to that visible clone.
        // Delegation keeps filtering functional after sorting and redraws.
        const onFooterInput = event => {
            const target = event.target;
            const index = columns.findIndex(column =>
                column.data === target?.name && column.searchable !== false
            );
            if (index < 0 || !target?.closest?.('tfoot') ||
                !target.closest('#example_wrapper')) {
                return;
            }
            const column = api.column(index);
            if (column.search() !== target.value) {
                column.search(target.value, false, false).draw();
            }
            save();
        };
        doc.addEventListener?.('input', onFooterInput);
        cleanups.push(() => doc.removeEventListener?.('input', onFooterInput));
        api.on?.('search.dt page.dt length.dt order.dt', save);
    }

    function apiErrorDetails(payload) {
        const raw = payload?.error;
        if (raw === null || raw === undefined || raw === '' ||
            (Array.isArray(raw) && raw.length === 0)) {
            return '';
        }
        const errors = Array.isArray(raw) ? raw : [raw];
        const messages = errors.map(error =>
            typeof error === 'string' ? error : error?.message || 'Falha no cálculo do relatório.'
        );
        if (typeof payload.resolution === 'string' && payload.resolution) {
            messages.push(payload.resolution);
        }
        if (typeof payload.request_id === 'string' && payload.request_id) {
            messages.push('Identificador da falha: ' + payload.request_id);
        }
        return messages.join('\n');
    }

    function showError(target, message) {
        if (target) {
            target.textContent = String(message);
        }
    }

    async function initializeReportTable(doc = scope.document, options = {}) {
        const table = doc?.getElementById('example');
        if (!table) {
            return null;
        }
        const errors = doc.getElementById('erros');
        const request = options.fetch || scope.fetch?.bind(scope);
        const DataTableConstructor = options.DataTable || scope.DataTable;
        const location = options.location || scope.location;
        const history = options.history || scope.history;

        try {
            table.reportTableCleanup?.();
            const excluded = JSON.parse(table.dataset.filterColumns || '["metadata"]');
            if (!Array.isArray(excluded) || !excluded.every(item => typeof item === 'string')) {
                throw new Error('A configuração das colunas do relatório está inválida.');
            }
            if (!table.dataset.url || typeof request !== 'function') {
                throw new Error('A URL da API do relatório não está disponível.');
            }
            const response = options.payload ? null : await request(table.dataset.url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (response && !response.ok) {
                let details = '';
                try {
                    details = apiErrorDetails(await response.json());
                } catch {
                    // Non-JSON proxy errors still need an actionable HTTP status.
                }
                throw new Error('Não foi possível carregar o relatório (HTTP ' + response.status + ').' +
                    (details ? '\n' + details : ''));
            }
            const payload = options.payload || await response.json();
            const details = apiErrorDetails(payload);
            if (details) {
                throw new Error(details);
            }
            if (!Array.isArray(payload?.data)) {
                throw new Error('A API retornou um formato de relatório inválido.');
            }
            if (!payload.data.length) {
                showError(errors, 'Nenhum registro encontrado para os filtros informados.');
                return null;
            }
            const reportOptions = {
                inlineWeight: table.dataset.inlineWeight === 'yes',
                csrf: table.dataset.csrf || '',
                competence: table.dataset.competencia || '',
                financialReview: table.dataset.financialReview === 'yes',
                omitted: options.omitted,
            };
            const productionMode = table.dataset.productionMode === 'yes';
            const columns = productionMode ?
                buildProductionColumns(reportOptions) : buildColumns(payload.data, excluded, reportOptions);
            if (!columns.length || !table.tFoot?.rows[0]) {
                throw new Error('Não foi possível montar as colunas do relatório.');
            }
            if (typeof DataTableConstructor !== 'function') {
                throw new Error('O componente de tabela não foi carregado. Recarregue a página.');
            }

            const cleanups = [];
            const cleanup = () => {
                for (const fn of cleanups) fn();
                table.weightEditorBinding?.destroy();
                if (table.reportTableCleanup === cleanup) delete table.reportTableCleanup;
            };
            table.reportTableCleanup = cleanup;
            const row = table.tFoot.rows[0];
            row.replaceChildren(...columns.map(() => doc.createElement('th')));
            const selected = new DataTableConstructor('#example', {
                scrollX: true,
                data: payload.data,
                columns,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [[0, 'asc']],
                fixedHeader: { header: false, footer: true },
                layout: {
                    topStart: {
                        buttons: productionMode ? [
                            'pageLength', 'colvis',
                            ...['copy', 'csv', 'excel', 'pdf', 'print'].map(extend => ({
                                extend, exportOptions: {columns: [0, 1, 2, 3, 4, 5], orthogonal: 'export'},
                            })),
                        ] : ['pageLength', 'colvis', 'copy', 'csv', 'excel', 'pdf', 'print'],
                    },
                },
                language: {
                    search: 'Buscar cooperados e valores:',
                    buttons: {
                        pageLength: {'_':'Exibir %d registros','-1':'Exibir todos'},
                        colvis:'Colunas', copy:'Copiar', csv:'CSV',
                        excel:'Excel', pdf:'PDF', print:'Imprimir',
                    },
                    emptyTable: 'Nenhum registro nesta competência.',
                    zeroRecords: 'Nenhum registro corresponde aos filtros.',
                    info: 'Exibindo _START_ a _END_ de _TOTAL_ registros',
                    infoEmpty: 'Nenhum registro',
                    lengthMenu: 'Exibir _MENU_ registros',
                    paginate: {first: 'Primeira', previous: 'Anterior', next: 'Próxima', last: 'Última'},
                },
                initComplete: function () {
                    setupFilters(this.api(), columns, doc, location, history, cleanups);
                    if (reportOptions.inlineWeight) {
                        (options.inlineWeight || scope.SettingsInlineWeight)?.initialize?.(table, this.api(), options);
                    }
                },
            });
            if (productionMode && typeof table.addEventListener === 'function') {
                const onDetailsClick = event => {
                    const button = event.target?.closest?.('[data-production-details]');
                    if (!button) return;
                    const row = selected.row(button.closest('tr'));
                    if (!row || !row.child) return;
                    if (row.child.isShown()) {
                        row.child.hide();
                        button.setAttribute('aria-expanded', 'false');
                    } else {
                        row.child(productionDetails(row.data())).show();
                        button.setAttribute('aria-expanded', 'true');
                    }
                };
                table.addEventListener('click', onDetailsClick);
                cleanups.push(() => table.removeEventListener?.('click', onDetailsClick));
            }
            if (reportOptions.financialReview) {
                const onDecisionChange = event => {
                    const checkbox = event.target?.closest?.('[data-omit-user-id]');
                    if (!checkbox) return;
                    const id = Number(checkbox.dataset.omitUserId);
                    if (Number.isSafeInteger(id) && id > 0) {
                        options.onDecisionChange?.(id, checkbox.checked);
                    }
                };
                table.addEventListener('change', onDecisionChange);
                cleanups.push(() => table.removeEventListener?.('change', onDecisionChange));
                selected.on('draw.dt', () => {
                    for (const checkbox of table.querySelectorAll('[data-omit-user-id]')) {
                        checkbox.checked = options.omitted?.has(Number(checkbox.dataset.omitUserId)) || false;
                    }
                });
            }
            return selected;
        } catch (error) {
            showError(errors, error.message || 'Não foi possível carregar o relatório.');
            return null;
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            escapeHtml, columnTitle, nestedTableHtml, safeLinkHtml, renderCell, buildColumns,
            stateUrl, setupFilters, apiErrorDetails, initializeReportTable,
            duration, productionDetails, buildProductionColumns,
        };
    } else {
        scope.ReportTable = {initializeReportTable, duration};
        scope.addEventListener('DOMContentLoaded', () => {
            if (scope.document?.getElementById('example')?.dataset?.manualInit !== 'yes') {
                void initializeReportTable();
            }
        });
    }
})(typeof window === 'undefined' ? {} : window);
