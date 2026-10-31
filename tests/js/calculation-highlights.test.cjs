// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { bindHighlights } = require('../../public/js/calculation-highlights.js');

function fixture(keys = ['receita', 'custos']) {
    const elements = [];
    const labels = [];
    function make(classes = [], highlightKey = '') {
        const listeners = new Map();
        const active = new Set(classes);
        const el = {
            dataset: highlightKey ? { highlightKey } : {},
            classList: {
                contains: name => active.has(name),
                add: name => active.add(name),
                remove: name => active.delete(name),
            },
            addEventListener(name, callback) { listeners.set(name, callback); },
            emit(name) { listeners.get(name)?.(); },
            listeners,
        };
        elements.push(el);
        return el;
    }
    for (const key of keys) {
        labels.push(make(['label_' + key], key));
        make([key]);
        make([key]);
    }
    const doc = {
        querySelectorAll(selector) {
            assert.equal(selector, '[data-highlight-key]');
            return labels;
        },
        getElementsByClassName(name) {
            return elements.filter(el => el.classList.contains(name));
        },
    };
    return { elements, labels, doc };
}

test('a formula and its label highlight together on enter and clear on leave', () => {
    const { doc, labels, elements } = fixture();
    assert.equal(bindHighlights(doc), 2);
    labels[0].emit('mouseenter');
    assert.equal(elements.filter(el => el.classList.contains('active')).length, 3);
    assert.ok(!labels[1].classList.contains('active'));
    labels[0].emit('mouseleave');
    assert.equal(elements.filter(el => el.classList.contains('active')).length, 0);
});

test('hovering a formula term highlights its matching label, not another formula', () => {
    const { doc, labels, elements } = fixture();
    bindHighlights(doc);
    elements[2].emit('mouseenter');
    assert.equal(labels[0].classList.contains('active'), true);
    assert.equal(labels[1].classList.contains('active'), false);
    elements[2].emit('mouseleave');
    assert.equal(labels[0].classList.contains('active'), false);
});

test('empty keys and missing document are ignored without throwing', () => {
    assert.equal(bindHighlights(null), 0);
    const { doc, labels } = fixture(['']);
    assert.equal(bindHighlights(doc), 0);
    assert.equal(labels[0].listeners.size, 0);
});

test('special characters in formula names never enter a CSS selector', () => {
    const key = 'valor.[x]#y';
    const { doc, labels, elements } = fixture([key]);
    assert.equal(bindHighlights(doc), 1);
    labels[0].emit('mouseenter');
    assert.equal(elements.filter(el => el.classList.contains('active')).length, 3);
});

test('click pins the highlighted lineage until clicked again', () => {
    const { doc, labels, elements } = fixture();
    bindHighlights(doc);
    labels[0].emit('click');
    assert.equal(elements.filter(el => el.classList.contains('active')).length, 3);
    labels[0].emit('mouseleave');
    assert.equal(elements.filter(el => el.classList.contains('active')).length, 3);
    labels[0].emit('click');
    assert.equal(elements.filter(el => el.classList.contains('active')).length, 0);
});
