// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

(function (scope) {
    function bindHighlights(doc = scope.document) {
        if (!doc) {
            return 0;
        }
        let bound = 0;
        for (const label of doc.querySelectorAll('[data-highlight-key]')) {
            const key = label.dataset.highlightKey;
            if (!key) {
                continue;
            }
            const targets = new Set([
                ...doc.getElementsByClassName(key),
                ...doc.getElementsByClassName('label_' + key),
            ]);
            for (const target of targets) {
                target.addEventListener('mouseenter', () => {
                    for (const element of targets) {
                        element.classList.add('active');
                    }
                });
                target.addEventListener('mouseleave', () => {
                    for (const element of targets) {
                        element.classList.remove('active');
                    }
                });
            }
            bound += 1;
        }
        return bound;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { bindHighlights };
    } else {
        scope.addEventListener('DOMContentLoaded', () => bindHighlights());
    }
})(typeof window === 'undefined' ? {} : window);
