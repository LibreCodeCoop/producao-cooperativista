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
            '<small class="settings-weight-snapshot">Peso no ETL (última importação)</small>' +
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

    function renderCell(value, type, column, row = null, options = {}) {
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

    function stateUrl(href, filters, globalSearch, page) {
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
        return url.pathname + url.search + url.hash;
    }

    function setupFilters(api, columns, doc, location, history) {
        const params = new URLSearchParams(location.search);
        const inputs = new Map();
        let restoring = true;
        const save = () => {
            if (restoring) {
                return;
            }
            const filters = Object.fromEntries([...inputs].map(([key, input]) => [key, input.value]));
            const url = stateUrl(location.href, filters, api.search(), api.page());
            if (url !== location.pathname + location.search + location.hash) {
                history.replaceState({}, '', url);
            }
        };

        api.columns().every(function (index) {
            const column = this;
            const footer = column.footer();
            if (!footer) {
                return;
            }
            const input = doc.createElement('input');
            input.name = columns[index].data;
            input.placeholder = columns[index].title;
            input.value = params.get(input.name) || '';
            footer.replaceChildren(input);
            inputs.set(input.name, input);
            if (input.value) {
                column.search(input.value);
            }
            input.addEventListener('keyup', () => {
                if (column.search() !== input.value) {
                    column.search(input.value).draw();
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
        restoring = false;

        const globalInput = doc.querySelector('#example_wrapper .dt-search input') ||
            doc.querySelector('#example_filter input');
        globalInput?.addEventListener('input', save);
        api.on?.('search.dt page.dt', save);
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
            const excluded = JSON.parse(table.dataset.filterColumns || '["metadata"]');
            if (!Array.isArray(excluded) || !excluded.every(item => typeof item === 'string')) {
                throw new Error('A configuração das colunas do relatório está inválida.');
            }
            if (!table.dataset.url || typeof request !== 'function') {
                throw new Error('A URL da API do relatório não está disponível.');
            }
            const response = await request(table.dataset.url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) {
                let details = '';
                try {
                    details = apiErrorDetails(await response.json());
                } catch {
                    // Non-JSON proxy errors still need an actionable HTTP status.
                }
                throw new Error('Não foi possível carregar o relatório (HTTP ' + response.status + ').' +
                    (details ? '\n' + details : ''));
            }
            const payload = await response.json();
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
            };
            const columns = buildColumns(payload.data, excluded, reportOptions);
            if (!columns.length || !table.tFoot?.rows[0]) {
                throw new Error('Não foi possível montar as colunas do relatório.');
            }
            if (typeof DataTableConstructor !== 'function') {
                throw new Error('O componente de tabela não foi carregado. Recarregue a página.');
            }

            const row = table.tFoot.rows[0];
            row.replaceChildren(...columns.map(() => doc.createElement('th')));
            return new DataTableConstructor('#example', {
                scrollX: true,
                data: payload.data,
                columns,
                fixedHeader: { header: false, footer: true },
                layout: {
                    topStart: {
                        buttons: ['pageLength', 'colvis', 'copy', 'csv', 'excel', 'pdf', 'print'],
                    },
                },
                initComplete: function () {
                    setupFilters(this.api(), columns, doc, location, history);
                    if (reportOptions.inlineWeight) {
                        (options.inlineWeight || scope.SettingsInlineWeight)?.initialize?.(table, this.api(), options);
                    }
                },
            });
        } catch (error) {
            showError(errors, error.message || 'Não foi possível carregar o relatório.');
            return null;
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            escapeHtml, columnTitle, nestedTableHtml, safeLinkHtml, renderCell, buildColumns,
            stateUrl, setupFilters, apiErrorDetails, initializeReportTable,
        };
    } else {
        scope.addEventListener('DOMContentLoaded', () => {
            void initializeReportTable();
        });
    }
})(typeof window === 'undefined' ? {} : window);
