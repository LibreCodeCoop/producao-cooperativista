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

function tooltipFixture(rects, width = 800, height = 600) {
    const { bindHelpTooltips } = require('../../public/js/calculation-highlights.js');
    const triggers = rects.map((rect, i) => {
        const listeners = new Map();
        return {
            dataset: {help: 'Explicação ' + (i + 1)},
            addEventListener(type, handler) { listeners.set(type, handler); },
            emit(type) { listeners.get(type)?.(); },
            getBoundingClientRect() { return rect; },
        };
    });
    const tooltip = {
        className: '', hidden: true, textContent: '', style: {},
        attributes: new Map(),
        setAttribute(name, value) { this.attributes.set(name, value); },
        getBoundingClientRect() { return {width: 340, height: 115}; },
    };
    const events = new Map();
    const doc = {
        body: { appendChild(node) { assert.equal(node, tooltip); } },
        createElement(tag) { assert.equal(tag, 'div'); return tooltip; },
        querySelectorAll(selector) {
            assert.equal(selector, '.calc-definition[data-help]');
            return triggers;
        },
        addEventListener(type, fn) { events.set(type, fn); },
    };
    const viewport = {
        innerWidth: width, innerHeight: height,
        addEventListener(type, fn) { events.set(type, fn); },
    };
    return { doc, viewport, triggers, tooltip, events, bindHelpTooltips };
}

test('top-row tooltip opens below its anchor without being clipped by table scrolling', () => {
    const f = tooltipFixture([{top: 30, bottom: 52, right: 150}]);
    assert.equal(f.bindHelpTooltips(f.doc, f.viewport), 1);
    f.triggers[0].emit('mouseenter');
    assert.equal(f.tooltip.hidden, false);
    assert.equal(f.tooltip.attributes.get('role'), 'tooltip');
    assert.equal(f.tooltip.textContent, 'Explicação 1');
    assert.equal(f.tooltip.style.top, '61px');
    assert.equal(f.tooltip.style.left, '12px');
    f.triggers[0].emit('mouseleave');
    assert.equal(f.tooltip.hidden, true);
});

test('last-row help opens above and remains inside the right and bottom viewport edges', () => {
    const f = tooltipFixture([{top: 555, bottom: 576, right: 795}]);
    f.bindHelpTooltips(f.doc, f.viewport);
    f.triggers[0].emit('focus');
    assert.equal(f.tooltip.style.top, '431px');
    assert.equal(f.tooltip.style.left, '448px');
    f.events.get('resize')();
    assert.equal(f.tooltip.style.left, '448px');
    f.triggers[0].emit('blur');
    assert.equal(f.tooltip.hidden, true);
});

test('only the currently hovered help target can dismiss the shared tooltip', () => {
    const f = tooltipFixture([
        {top: 40, bottom: 61, right: 220},
        {top: 350, bottom: 371, right: 780},
    ]);
    assert.equal(f.bindHelpTooltips(f.doc, f.viewport), 2);
    f.triggers[0].emit('mouseenter');
    f.triggers[1].emit('mouseenter');
    assert.equal(f.tooltip.textContent, 'Explicação 2');
    f.triggers[0].emit('mouseleave');
    assert.equal(f.tooltip.hidden, false);
    f.events.get('scroll')();
    assert.equal(f.tooltip.style.left, '440px');
    f.triggers[1].emit('mouseleave');
    assert.equal(f.tooltip.hidden, true);
});

test('tooltip binding is inert on pages without help controls', () => {
    const {bindHelpTooltips} = require('../../public/js/calculation-highlights.js');
    assert.equal(bindHelpTooltips(null), 0);
    const doc = {
        body: {},
        createElement() { assert.fail('No tooltip should be inserted'); },
        querySelectorAll() { return []; },
    };
    assert.equal(bindHelpTooltips(doc), 0);
});

test('scrolling a help target out of view dismisses the floating tooltip', () => {
    const rect = {top: 40, bottom: 61, right: 220};
    const f = tooltipFixture([rect]);
    f.bindHelpTooltips(f.doc, f.viewport);
    f.triggers[0].emit('mouseenter');
    assert.equal(f.tooltip.hidden, false);
    rect.top = -50;
    rect.bottom = -29;
    f.events.get('scroll')();
    assert.equal(f.tooltip.hidden, true);
});
