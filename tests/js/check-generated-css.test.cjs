// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const test = require("node:test");
const sass = require("sass");
const { checkGeneratedCss } = require("../../scripts/check-generated-css.cjs");

function fixture(t) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), "scss-ci-"));
    t.after(() => fs.rmSync(root, { recursive: true, force: true }));
    const source = path.join(root, "scss");
    const output = path.join(root, "css");
    fs.mkdirSync(source);
    fs.mkdirSync(output);
    fs.writeFileSync(path.join(source, "app.scss"), ".demo { color: red; }\n");
    fs.writeFileSync(
        path.join(output, "app.css"),
        sass.compile(path.join(source, "app.scss"), {
            style: "compressed",
            sourceMap: false,
        }).css + "\n",
    );
    return { source, output };
}

test("accepts matching CSS without modifying files", (t) => {
    const { source, output } = fixture(t);
    const file = path.join(output, "app.css");
    const before = fs.readFileSync(file);
    assert.deepEqual(checkGeneratedCss(source, output), []);
    assert.deepEqual(fs.readFileSync(file), before);
});

test("rejects a manually changed generated CSS file without repairing it", (t) => {
    const { source, output } = fixture(t);
    const file = path.join(output, "app.css");
    fs.writeFileSync(file, ".demo{color:blue}\n");
    assert.match(checkGeneratedCss(source, output).join(" "), /tracked CSS differs/);
    assert.equal(fs.readFileSync(file, "utf8"), ".demo{color:blue}\n");
});

test("rejects stale CSS when SCSS changes", (t) => {
    const { source, output } = fixture(t);
    fs.writeFileSync(path.join(source, "app.scss"), ".demo { color: green; }\n");
    assert.match(checkGeneratedCss(source, output).join(" "), /tracked CSS differs/);
});

test("rejects missing and orphaned tracked CSS artifacts", (t) => {
    const { source, output } = fixture(t);
    fs.unlinkSync(path.join(output, "app.css"));
    fs.writeFileSync(path.join(output, "orphan.css"), ".orphan{}\n");
    const errors = checkGeneratedCss(source, output).join("\n");
    assert.match(errors, /app.css: generated CSS is missing/);
    assert.match(errors, /orphan.css: tracked CSS has no SCSS entrypoint/);
});

test("compiles nested entrypoints and ignores Sass partials", (t) => {
    const { source, output } = fixture(t);
    fs.mkdirSync(path.join(source, "reports"));
    fs.mkdirSync(path.join(output, "reports"));
    fs.writeFileSync(path.join(source, "reports", "_variables.scss"), "$accent: red;\n");
    fs.writeFileSync(
        path.join(source, "reports", "report.scss"),
        '@use "variables"; .x { color: variables.$accent; }\n',
    );
    const compiled =
        sass.compile(path.join(source, "reports", "report.scss"), { style: "compressed" }).css +
        "\n";
    fs.writeFileSync(path.join(output, "reports", "report.css"), compiled);
    assert.deepEqual(checkGeneratedCss(source, output), []);
});

test("reports syntax errors instead of accepting invalid SCSS", (t) => {
    const { source, output } = fixture(t);
    fs.writeFileSync(path.join(source, "app.scss"), ".demo { color: ;\n");
    assert.match(checkGeneratedCss(source, output).join(" "), /compilation failed/);
});
