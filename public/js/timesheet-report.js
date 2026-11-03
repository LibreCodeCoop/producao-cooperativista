// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

(function (scope) {
    function duration(seconds) {
        if (!Number.isSafeInteger(seconds) || seconds < 0) return '—';
        return Math.floor(seconds / 3600) + ':' +
            String(Math.floor((seconds % 3600) / 60)).padStart(2, '0') + ':' +
            String(seconds % 60).padStart(2, '0');
    }

    function customerTotals(rows) {
        const groups = new Map();
        let seconds = 0;
        let missingDurations = 0;
        for (const row of rows) {
            const id = Number(row.customer_id);
            const key = Number.isSafeInteger(id) && id > 0 ? String(id) : '0';
            if (!groups.has(key)) {
                groups.set(key, {id: key, name: String(row.cliente || 'Cliente não vinculado'),
                    entries: 0, seconds: 0, missingDurations: 0});
            }
            const group = groups.get(key);
            group.entries++;
            const value = row.duracao_segundos;
            const n = value === null || value === '' || value === undefined ? NaN : Number(value);
            if (!Number.isSafeInteger(n) || n < 0) {
                group.missingDurations++;
                missingDurations++;
            } else {
                group.seconds += n;
                seconds += n;
            }
        }
        return {entries: rows.length, seconds, missingDurations,
            customers: [...groups.values()].sort((a, b) => b.seconds - a.seconds ||
                a.name.localeCompare(b.name, 'pt-BR') || Number(a.id) - Number(b.id))};
    }

    function optionsFor(rows, idKey, labelKey) {
        const names = new Map();
        for (const row of rows) {
            const id = Number(row[idKey]);
            if (!Number.isSafeInteger(id) || id < 0 || (idKey === 'member_id' && id === 0)) continue;
            if (!names.has(String(id))) names.set(String(id), String(row[labelKey] || '#' + id));
        }
        return [...names].sort((a, b) => a[1].localeCompare(b[1], 'pt-BR') || Number(a[0]) - Number(b[0]));
    }

    function fillSelect(doc, select, entries, emptyLabel, requested) {
        const blank = doc.createElement('option');
        blank.value = '';
        blank.textContent = emptyLabel;
        const options = [blank];
        for (const [value, label] of entries) {
            const option = doc.createElement('option');
            option.value = value;
            option.textContent = label;
            options.push(option);
        }
        if (requested && !entries.some(([value]) => value === requested)) {
            const option = doc.createElement('option');
            option.value = requested;
            option.textContent = 'ID #' + requested + ' (sem registros nesta competência)';
            options.push(option);
        }
        select.replaceChildren(...options);
        select.value = requested || '';
    }

    function displayTotals(doc, rows) {
        const summary = customerTotals(rows);
        const status = doc.getElementById('timesheet-summary');
        const body = doc.getElementById('timesheet-client-body');
        const warning = summary.missingDurations
            ? ' · ' + summary.missingDurations + (summary.missingDurations === 1 ? ' registro sem duração válida (não somado)' : ' registros sem duração válida (não somados)')
            : '';
        status.textContent = summary.entries + ' apontamentos filtrados · ' +
            duration(summary.seconds) + ' horas registradas' + warning;
        const cells = summary.customers.map(customer => {
            const tr = doc.createElement('tr');
            const name = doc.createElement('td');
            name.textContent = customer.name;
            const count = doc.createElement('td');
            count.textContent = String(customer.entries);
            const time = doc.createElement('td');
            time.textContent = duration(customer.seconds) +
                (customer.missingDurations ? ' *' : '');
            tr.replaceChildren(name, count, time);
            return tr;
        });
        if (!cells.length) {
            const tr = doc.createElement('tr');
            const td = doc.createElement('td');
            td.colSpan = 3;
            td.textContent = 'Nenhum apontamento corresponde aos filtros.';
            tr.replaceChildren(td);
            cells.push(tr);
        }
        body.replaceChildren(...cells);
    }

    // Keep URL state and DataTables column filtering independent of the DOM.
    // These functions are deterministic and can be tested without a browser.
    function requestedFilters(href) {
        const params = new URL(href).searchParams;
        const member = params.get('cooperado') || '';
        const customer = params.get('cliente') || '';
        return {
            memberId: /^[1-9]\d*$/.test(member) ? member : '',
            customerId: /^(0|[1-9]\d*)$/.test(customer) ? customer : '',
        };
    }

    function filteredUrl(href, {memberId = '', customerId = ''} = {}, clearSearch = false) {
        const url = new URL(href);
        for (const [key, value] of [['cooperado', memberId], ['cliente', customerId]]) {
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        }
        url.searchParams.delete('dtpage');
        if (clearSearch) url.searchParams.delete('dtsearch');
        return url.pathname + url.search + url.hash;
    }

    function applyGridFilters(grid, memberId, customerId) {
        grid.column(10).search(memberId ? '^' + memberId + '$' : '', true, false);
        grid.column(11).search(customerId ? '^' + customerId + '$' : '', true, false);
    }

    function clearGridFilters(grid) {
        grid.search('');
        for (let i = 0; i < 12; i++) grid.column(i).search('');
    }

    async function initialize(doc = scope.document, env = {}) {
        const table = doc?.getElementById('example');
        const selectMember = doc?.getElementById('timesheet-member-filter');
        const selectCustomer = doc?.getElementById('timesheet-customer-filter');
        const monthMember = doc?.getElementById('timesheet-month-member');
        const monthCustomer = doc?.getElementById('timesheet-month-customer');
        const clear = doc?.getElementById('timesheet-clear-filters');
        const location = env.location || scope.location;
        const history = env.history || scope.history;
        const request = env.fetch || scope.fetch?.bind(scope);
        const report = env.report || scope.ReportTable;
        if (!table || !selectMember || !selectCustomer || !location || !history || !request || !report) {
            return null;
        }
        try {
            const response = await request(table.dataset.url, {
                credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'},
            });
            if (!response.ok) throw new Error('Não foi possível consultar os apontamentos (HTTP ' + response.status + ').');
            const payload = await response.json();
            if (!Array.isArray(payload?.data) || payload.competence !== table.dataset.competencia) {
                throw new Error('Dados importados ou competência inválidos na resposta da origem.');
            }
            const data = payload.data;
            const {memberId, customerId} = requestedFilters(location.href);
            fillSelect(doc, selectMember, optionsFor(data, 'member_id', 'nome'), 'Todos os cooperados', memberId);
            fillSelect(doc, selectCustomer, optionsFor(data, 'customer_id', 'cliente'), 'Todos os clientes', customerId);
            if (monthMember) monthMember.value = selectMember.value;
            if (monthCustomer) monthCustomer.value = selectCustomer.value;

            const grid = await report.initializeReportTable(doc, {payload});
            const visibleRows = () => grid ? grid.rows({search: 'applied'}).data().toArray() : [];
            if (!grid) {
                displayTotals(doc, []);
                return null;
            }
            const update = () => displayTotals(doc, visibleRows());
            const filter = (save) => {
                const selected = {memberId: selectMember.value, customerId: selectCustomer.value};
                applyGridFilters(grid, selected.memberId, selected.customerId);
                if (monthMember) monthMember.value = selected.memberId;
                if (monthCustomer) monthCustomer.value = selected.customerId;
                if (save) history.replaceState({}, '', filteredUrl(location.href, selected));
                grid.draw();
                update();
            };
            grid.on('draw.dt', update);
            selectMember.addEventListener('change', () => filter(true));
            selectCustomer.addEventListener('change', () => filter(true));
            clear?.addEventListener('click', () => {
                selectMember.value = '';
                selectCustomer.value = '';
                clearGridFilters(grid);
                for (const input of doc.querySelectorAll('#example_wrapper input')) {
                    if (input.type === 'search' || input.closest('tfoot')) input.value = '';
                }
                history.replaceState({}, '', filteredUrl(location.href, {}, true));
                filter(false);
            });
            filter(false);
            return grid;
        } catch (error) {
            const status = doc.getElementById('timesheet-summary');
            status.textContent = error.message || 'Não foi possível carregar os apontamentos.';
            doc.getElementById('timesheet-client-body').replaceChildren();
            return null;
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {duration, customerTotals, optionsFor, fillSelect, displayTotals,
            requestedFilters, filteredUrl, applyGridFilters, clearGridFilters, initialize};
    } else {
        scope.addEventListener('DOMContentLoaded', () => { void initialize(); });
    }
})(typeof window === 'undefined' ? {} : window);
