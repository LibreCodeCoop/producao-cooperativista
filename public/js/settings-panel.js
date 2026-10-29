// SPDX-License-Identifier: AGPL-3.0-or-later
// Purely local filtering; credentials and editable values are never stored.
document.addEventListener('DOMContentLoaded', () => {
    const search = document.querySelector('#member-search')
    if (!search) return
    const rows = [...document.querySelectorAll('[data-member-row]')]
    search.addEventListener('input', () => {
        const needle = search.value.trim().toLocaleLowerCase('pt-BR')
        for (const row of rows) {
            row.hidden = !String(row.getAttribute('data-search') || '').includes(needle)
        }
    })
})
