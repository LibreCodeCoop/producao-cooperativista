// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
"use strict";

const fs = require("node:fs");
const path = require("node:path");
const sass = require("sass");

const root = path.resolve(__dirname, "..");

function styleFiles(directory, extension) {
    if (!fs.existsSync(directory)) return [];
    return fs
        .readdirSync(directory, { withFileTypes: true })
        .flatMap((entry) => {
            if (entry.name.startsWith("_")) return [];
            const full = path.join(directory, entry.name);
            if (entry.isDirectory()) {
                return styleFiles(full, extension).map((file) => path.join(entry.name, file));
            }
            return entry.isFile() && entry.name.endsWith(extension) ? [entry.name] : [];
        })
        .sort();
}

// Verify the tracked artifacts in memory; never alter the worktree during CI.
function checkGeneratedCss(sourceDirectory, outputDirectory) {
    const errors = [];
    const sourceFiles = styleFiles(sourceDirectory, ".scss");
    const outputs = new Set();
    if (sourceFiles.length === 0) errors.push("No SCSS entrypoints found.");

    for (const sourceFile of sourceFiles) {
        const outputFile = sourceFile.replace(/\.scss$/, ".css");
        outputs.add(outputFile);
        const outputPath = path.join(outputDirectory, outputFile);
        try {
            // Sass CLI's compressed directory build adds a final newline.
            const expected =
                sass.compile(path.join(sourceDirectory, sourceFile), {
                    style: "compressed",
                    sourceMap: false,
                }).css + "\n";
            if (!fs.existsSync(outputPath)) {
                errors.push(`${outputFile}: generated CSS is missing.`);
            } else if (fs.readFileSync(outputPath, "utf8") !== expected) {
                errors.push(`${outputFile}: tracked CSS differs from the compiled SCSS.`);
            }
        } catch (error) {
            errors.push(`${sourceFile}: compilation failed (${error.message.split("\n")[0]}).`);
        }
    }

    for (const trackedFile of styleFiles(outputDirectory, ".css")) {
        if (!outputs.has(trackedFile)) {
            errors.push(`${trackedFile}: tracked CSS has no SCSS entrypoint.`);
        }
    }
    return errors;
}

if (require.main === module) {
    const errors = checkGeneratedCss(
        path.join(root, "resources/scss"),
        path.join(root, "public/css"),
    );
    if (errors.length !== 0) {
        console.error("Generated CSS is out of sync:");
        for (const error of errors) console.error(`- ${error}`);
        console.error('Run "npm run css:build" and commit the generated public/css files.');
        process.exitCode = 1;
    } else {
        console.log("All tracked CSS matches Sass compilation.");
    }
}

module.exports = { checkGeneratedCss };
