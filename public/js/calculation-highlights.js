// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

(function (scope) {
    function bindHighlights(doc = scope.document) {
        if (!doc) {
            return 0;
        }
        const groups = new Map();
        const pinned = { key: null };
        let hovered = null;

        function update() {
            for (const [key, elements] of groups) {
                const active = key === hovered || key === pinned.key;
                for (const element of elements) {
                    element.classList[active ? 'add' : 'remove']('active');
                }
            }
        }

        let bound = 0;
        for (const label of doc.querySelectorAll('[data-highlight-key]')) {
            const key = label.dataset.highlightKey;
            if (!key) {
                continue;
            }
            // Class lookup, not CSS interpolation: untrusted keys cannot alter selectors.
            const targets = new Set([
                ...doc.getElementsByClassName(key),
                ...doc.getElementsByClassName('label_' + key),
            ]);
            groups.set(key, targets);
            for (const target of targets) {
                target.addEventListener('mouseenter', () => {
                    hovered = key;
                    update();
                });
                target.addEventListener('mouseleave', () => {
                    if (hovered === key) hovered = null;
                    update();
                });
            }
            // Labels are keyboard-focusable; only a label pins/unpins the trace.
            label.addEventListener('focus', () => {
                hovered = key;
                update();
            });
            label.addEventListener('blur', () => {
                if (hovered === key) hovered = null;
                update();
            });
            const toggle = () => {
                pinned.key = pinned.key === key ? null : key;
                update();
            };
            label.addEventListener('click', toggle);
            label.addEventListener('keydown', event => {
                if (event?.key === 'Enter' || event?.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
            bound += 1;
        }
        if (typeof doc.addEventListener === 'function') {
            doc.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    pinned.key = null;
                    hovered = null;
                    update();
                }
            });
        }
        return bound;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { bindHighlights };
    } else {
        scope.addEventListener('DOMContentLoaded', () => bindHighlights());
    }
})(typeof window === 'undefined' ? {} : window);
