// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

// Reuse the same DataTables and Buttons dependencies as the financial reports.
(function (scope) {
    const FILTER_KEYS = ['dtname', 'dtuser', 'dtactivity', 'dtstatus', 'dtsearch',
        'dtpage', 'dtlength', 'dtorder'];
    const STATES = ['Ativo', 'Desativado', 'Conta de serviço', 'all'];
    const ACTIVITIES = ['all', 'Com horas', 'Sem horas'];
    const DEFAULT_STATUS = 'Ativo';

    function readState(search) {
        const params = new URLSearchParams(search || '');
        const status = params.get('dtstatus') || DEFAULT_STATUS;
        const activity = params.get('dtactivity') || 'all';
        const page = params.get('dtpage') || '0';
        const length = params.get('dtlength') || '10';
        const order = params.get('dtorder') || '2:desc';
        const orderMatch = /^([0-2]|[4-5]):(asc|desc)$/.exec(order);

        return {
            name: params.get('dtname') || '',
            user: params.get('dtuser') || '',
            activity: ACTIVITIES.includes(activity) ? activity : 'all',
            status: STATES.includes(status) ? status : DEFAULT_STATUS,
            search: params.get('dtsearch') || '',
            page: /^\d{1,4}$/.test(page) ? Number(page) : 0,
            length: ['10', '25', '50', '100'].includes(length) ? Number(length) : 10,
            order: orderMatch ? [Number(orderMatch[1]), orderMatch[2]] : [2, 'desc'],
        };
    }

    function tableStateUrl(href, state) {
        const url = new URL(href);
        for (const key of FILTER_KEYS) {
            url.searchParams.delete(key);
        }
        const entries = {
            dtname: state.name,
            dtuser: state.user,
            dtactivity: state.activity === 'all' ? '' : state.activity,
            dtstatus: state.status === DEFAULT_STATUS ? '' : state.status,
            dtsearch: state.search,
            dtpage: state.page > 0 ? String(state.page) : '',
            dtlength: state.length === 10 ? '' : String(state.length),
            dtorder: state.order?.[0] === 2 && state.order?.[1] === 'desc'
                ? '' : state.order?.join(':'),
        };
        for (const [key, value] of Object.entries(entries)) {
            if (value) {
                url.searchParams.set(key, value);
            }
        }
        return url.pathname + url.search + url.hash;
    }

    function exactFilter(value) {
        // Values originate from fixed, allowlisted <select> options.
        return value === 'all' ? '' : '^' + value + '$';
    }

    function columnFilters(api, fields) {
        api.column(0).search(fields.name.value.trim());
        api.column(1).search(fields.user.value.trim());
        api.column(4).search(exactFilter(fields.activity.value), true, false);
        api.column(5).search(exactFilter(fields.status.value), true, false);
    }

    function initializeSettingsTable(doc = scope.document, options = {}) {
        const table = doc?.getElementById('settings-members');
        if (!table) {
            return null;
        }
        const DataTableConstructor = options.DataTable || scope.DataTable;
        const error = doc.getElementById('settings-table-error');
        if (typeof DataTableConstructor !== 'function') {
            if (error) {
                error.textContent = 'O DataTables não carregou. Recarregue a página para habilitar filtros e paginação.';
            }
            return null;
        }

        const location = options.location || scope.location;
        const history = options.history || scope.history;
        const state = readState(location?.search);
        const fields = {
            name: doc.getElementById('settings-name-filter'),
            user: doc.getElementById('settings-user-filter'),
            activity: doc.getElementById('settings-hours-filter'),
            status: doc.getElementById('settings-status-filter'),
        };
        if (Object.values(fields).some(field => !field)) {
            if (error) error.textContent = 'Os campos de filtragem da tabela não estão disponíveis.';
            return null;
        }
        const clear = doc.getElementById('settings-clear-filters');
        const defaults = doc.getElementById('settings-default-filters');
        const count = doc.getElementById('settings-visible-count');
        let restoring = true;

        function currentState(api) {
            const order = api.order()[0] || [2, 'desc'];
            return {
                name: fields.name.value.trim(), user: fields.user.value.trim(),
                activity: fields.activity.value, status: fields.status.value,
                search: api.search(), page: api.page(), length: api.page.len(),
                order,
            };
        }

        function persist(api) {
            if (restoring || !location || !history) return;
            const url = tableStateUrl(location.href, currentState(api));
            if (url !== location.pathname + location.search + location.hash) {
                history.replaceState({}, '', url);
            }
        }

        function updateVisibleCount(api) {
            if (count) count.textContent = String(api.rows({search: 'applied'}).count());
        }

        function setup(api) {
            fields.name.value = state.name;
            fields.user.value = state.user;
            fields.activity.value = state.activity;
            fields.status.value = state.status;
            columnFilters(api, fields);
            api.search(state.search);
            api.page.len(state.length);
            api.order([state.order]);
            api.draw();
            if (state.page > 0 && state.page < api.page.info().pages) {
                api.page(state.page).draw('page');
            }

            const apply = () => {
                columnFilters(api, fields);
                api.page(0).draw();
                updateVisibleCount(api);
                persist(api);
            };
            fields.name.addEventListener('input', apply);
            fields.user.addEventListener('input', apply);
            fields.activity.addEventListener('change', apply);
            fields.status.addEventListener('change', apply);

            function reset(status) {
                fields.name.value = '';
                fields.user.value = '';
                fields.activity.value = 'all';
                fields.status.value = status;
                api.search('');
                api.order([[2, 'desc']]);
                api.page.len(10);
                apply();
            }
            clear?.addEventListener('click', () => reset('all'));
            defaults?.addEventListener('click', () => reset(DEFAULT_STATUS));
            api.on('draw.dt', () => updateVisibleCount(api));
            (options.inlineWeight || scope.SettingsInlineWeight)?.initialize?.(table, api, options);
            api.on('search.dt page.dt length.dt order.dt', () => persist(api));
            restoring = false;
            updateVisibleCount(api);
            // Keep filters and page when selecting an individual member.
            doc.querySelectorAll('[data-settings-member-link]').forEach(link => {
                link.addEventListener('click', () => {
                    const destination = new URL(link.href, location.href);
                    const current = new URL(location.href);
                    for (const key of FILTER_KEYS) {
                        if (current.searchParams.has(key)) {
                            destination.searchParams.set(key, current.searchParams.get(key));
                        } else {
                            destination.searchParams.delete(key);
                        }
                    }
                    link.href = destination.pathname + destination.search + destination.hash;
                });
            });
        }

        return new DataTableConstructor('#settings-members', {
            scrollX: true,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            order: [[2, 'desc']],
            columnDefs: [{targets: [3, 6], orderable: false, searchable: false}],
            layout: {
                topStart: {
                    // Live Nextcloud weights load per visible page. Never export
                    // partial values or hidden inline forms as if they were a full dataset.
                    buttons: [
                        'pageLength', 'colvis',
                        ...['copy', 'csv', 'excel', 'pdf', 'print'].map(extend => ({
                            extend,
                            exportOptions: {columns: [0, 1, 2, 4, 5]},
                        })),
                    ],
                },
            },
            language: {
                search: 'Buscar em todas as colunas:',
                emptyTable: 'Nenhum usuário encontrado no Kimai.',
                zeroRecords: 'Nenhum cooperado corresponde aos filtros.',
                info: 'Exibindo _START_ a _END_ de _TOTAL_ registros',
                infoEmpty: 'Nenhum registro',
                lengthMenu: 'Exibir _MENU_ registros',
                paginate: {first: 'Primeira', previous: 'Anterior', next: 'Próxima', last: 'Última'},
            },
            initComplete: function () {
                setup(this.api());
            },
        });
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            FILTER_KEYS, DEFAULT_STATUS, readState, tableStateUrl, exactFilter,
            columnFilters, initializeSettingsTable,
        };
    } else {
        scope.addEventListener('DOMContentLoaded', () => {
            initializeSettingsTable();
        });
    }
})(typeof window === 'undefined' ? {} : window);
