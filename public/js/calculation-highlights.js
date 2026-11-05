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

    function bindHelpTooltips(doc = scope.document, viewport = scope) {
        if (!doc?.body || typeof doc.createElement !== 'function') return 0;
        const triggers = [...doc.querySelectorAll('.calc-definition[data-help]')];
        if (!triggers.length) return 0;

        const tooltip = doc.createElement('div');
        tooltip.className = 'calc-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        doc.body.appendChild(tooltip);
        let active = null;

        const place = () => {
            if (!active) return;
            const anchor = active.getBoundingClientRect();
            if (anchor.bottom <= 0 || anchor.top >= viewport.innerHeight) {
                active = null;
                tooltip.hidden = true;
                return;
            }
            const box = tooltip.getBoundingClientRect();
            const margin = 12;
            const gap = 9;
            const above = anchor.top - margin - gap;
            const below = viewport.innerHeight - anchor.bottom - margin - gap;
            const useAbove = above >= box.height || above > below;
            const top = useAbove ? anchor.top - box.height - gap : anchor.bottom + gap;

            tooltip.style.left = Math.round(Math.max(margin,
                Math.min(anchor.right - box.width, viewport.innerWidth - box.width - margin))) + 'px';
            tooltip.style.top = Math.round(Math.max(margin,
                Math.min(top, viewport.innerHeight - box.height - margin))) + 'px';
        };

        const show = trigger => {
            active = trigger;
            tooltip.textContent = trigger.dataset.help;
            tooltip.hidden = false;
            tooltip.style.visibility = 'hidden';
            place();
            tooltip.style.visibility = 'visible';
        };
        const hide = trigger => {
            if (active !== trigger) return;
            active = null;
            tooltip.hidden = true;
        };

        for (const trigger of triggers) {
            trigger.addEventListener('mouseenter', () => show(trigger));
            trigger.addEventListener('mouseleave', () => hide(trigger));
            trigger.addEventListener('focus', () => show(trigger));
            trigger.addEventListener('blur', () => hide(trigger));
        }
        viewport.addEventListener?.('resize', place);
        doc.addEventListener?.('scroll', place, true);
        return triggers.length;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { bindHighlights, bindHelpTooltips };
    } else {
        scope.addEventListener('DOMContentLoaded', () => {
            bindHighlights();
            bindHelpTooltips();
        });
    }
})(typeof window === 'undefined' ? {} : window);
