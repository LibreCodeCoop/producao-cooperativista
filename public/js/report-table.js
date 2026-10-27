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

    function safeLinkHtml(value) {
        if (typeof value !== 'string') {
            return null;
        }
        // Historic API sends an HTML anchor as the URL cell. Parse only the
        // narrowly supported shape; never insert arbitrary upstream HTML.
        const match = /^<a href="([^"]+)" target="_blank">([^<]*)<\/a>$/.exec(value);
        if (!match) {
            return null;
        }
        let url;
        try {
            url = new URL(match[1]);
        } catch {
            return null;
        }
        if (url.protocol !== 'https:' && url.protocol !== 'http:') {
            return null;
        }
        return '<a href="' + escapeHtml(url.href) + '" target="_blank" rel="noopener noreferrer">' +
            escapeHtml(match[2]) + '</a>';
    }

    function renderCell(value, type, column) {
        if (type !== 'display') {
            return value ?? '';
        }
        if (column === 'URL') {
            const link = safeLinkHtml(value);
            if (link !== null) {
                return link;
            }
        }
        return nestedTableHtml(value) ?? escapeHtml(value);
    }

    function buildColumns(rows, excluded = ['metadata']) {
        const keys = [...new Set(rows.flatMap(row =>
            row && typeof row === 'object' && !Array.isArray(row) ? Object.keys(row) : []
        ))];
        return keys.filter(key => !excluded.includes(key)).map(key => ({
            data: key,
            title: columnTitle(key),
            defaultContent: '',
            render: (value, type) => renderCell(value, type, key),
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
            const columns = buildColumns(payload.data, excluded);
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
