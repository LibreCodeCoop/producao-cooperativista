// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

// Runs synchronously in <head>, before CSS, to avoid flashing the wrong theme.
(function (scope) {
    const STORAGE_KEY = 'librecode.producao-cooperativista.theme';
    const SYSTEM = 'system';
    const DARK = 'dark';
    const LIGHT = 'light';

    function validMode(value) {
        return value === DARK || value === LIGHT ? value : SYSTEM;
    }

    function readMode(storage) {
        try {
            return validMode(storage?.getItem(STORAGE_KEY));
        } catch {
            return SYSTEM; // Blocked storage must not prevent rendering.
        }
    }

    function preferredTheme(mode, prefersDark) {
        return mode === SYSTEM ? (prefersDark ? DARK : LIGHT) : mode;
    }

    function createThemeManager(win) {
        const document = win?.document;
        const html = document?.documentElement;
        if (!html) return null;

        let storage;
        try {
            storage = win.localStorage;
        } catch {
            storage = null;
        }

        let media = null;
        try {
            media = typeof win.matchMedia === 'function'
                ? win.matchMedia('(prefers-color-scheme: dark)')
                : null;
        } catch {
            // Unsupported or unavailable media query: fall back to light.
        }

        let mode = readMode(storage);
        let theme = LIGHT;
        let bound = false;

        function renderControls() {
            const button = document.getElementById('theme-toggle');
            const reset = document.getElementById('theme-system');
            const label = document.getElementById('theme-toggle-label');
            const icon = document.getElementById('theme-toggle-icon');
            const status = document.getElementById('theme-status');
            const next = theme === DARK ? LIGHT : DARK;

            if (button) {
                const action = next === DARK ? 'Ativar tema escuro' : 'Ativar tema claro';
                button.setAttribute('aria-label', action);
                button.title = action;
            }
            if (label) label.textContent = next === DARK ? 'Tema escuro' : 'Tema claro';
            if (icon) icon.textContent = next === DARK ? '☾' : '☀';
            if (reset) reset.hidden = mode === SYSTEM;
            if (status) {
                const currentLabel = theme === DARK ? 'Escuro' : 'Claro';
                status.textContent = mode === SYSTEM ? 'Navegador · ' + currentLabel : currentLabel;
            }
        }

        function apply() {
            theme = preferredTheme(mode, Boolean(media?.matches));
            html.setAttribute('data-theme', theme);
            // DataTables supports html.dark in its own stylesheet.
            html.classList.toggle('dark', theme === DARK);
            html.style.colorScheme = theme;
            renderControls();
        }

        function select(next) {
            mode = validMode(next);
            try {
                if (mode === SYSTEM) {
                    storage?.removeItem(STORAGE_KEY);
                } else {
                    storage?.setItem(STORAGE_KEY, mode);
                }
            } catch {
                // Keep the theme working in memory if storage is blocked.
            }
            apply();
        }

        function bindControls() {
            if (bound) return;
            bound = true;
            document.getElementById('theme-toggle')?.addEventListener('click', () => {
                select(theme === DARK ? LIGHT : DARK);
            });
            document.getElementById('theme-system')?.addEventListener('click', () => {
                select(SYSTEM);
            });
            renderControls();
        }

        // Apply before any stylesheet is parsed.
        apply();

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindControls, {once: true});
        } else {
            bindControls();
        }

        const systemChanged = () => {
            if (mode === SYSTEM) apply();
        };
        if (typeof media?.addEventListener === 'function') {
            media.addEventListener('change', systemChanged);
        } else if (typeof media?.addListener === 'function') {
            media.addListener(systemChanged);
        }

        win.addEventListener?.('storage', (event) => {
            if (event.key === STORAGE_KEY || event.key === null) {
                mode = event.key === null ? readMode(storage) : validMode(event.newValue);
                apply();
            }
        });

        return {
            getMode: () => mode,
            getTheme: () => theme,
            setMode: select,
        };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {STORAGE_KEY, readMode, preferredTheme, createThemeManager};
    } else {
        createThemeManager(scope);
    }
})(typeof window === 'undefined' ? {} : window);
