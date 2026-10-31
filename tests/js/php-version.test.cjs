'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const repository = path.resolve(__dirname, '../..');
const action = fs.readFileSync(
    path.join(repository, '.github/actions/php-version/action.yml'),
    'utf8'
);

function resolveScript() {
    const marker = '      run: |\n';
    const index = action.indexOf(marker);
    assert.notEqual(index, -1, 'Composite action must provide a shell command');
    return action.slice(index + marker.length)
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => {
            assert.ok(line.startsWith('        '), 'Shell line must be indented');
            return line.slice(8);
        })
        .join('\n');
}

function executeWithDockerfile(dockerfile) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'pdc-php-version-'));
    try {
        const docker = path.join(directory, '.docker/php');
        fs.mkdirSync(docker, { recursive: true });
        fs.writeFileSync(path.join(docker, 'Dockerfile'), dockerfile);
        const output = path.join(directory, 'github-output');
        fs.writeFileSync(output, '');
        const execution = spawnSync('bash', ['-e', '-c', resolveScript()], {
            cwd: directory,
            encoding: 'utf8',
            env: { ...process.env, GITHUB_OUTPUT: output },
        });
        if (execution.error) {
            throw execution.error;
        }
        return {
            status: execution.status,
            output: fs.readFileSync(output, 'utf8'),
        };
    } finally {
        fs.rmSync(directory, { recursive: true, force: true });
    }
}

test('extracts the PHP version and the complete image from a single Dockerfile ARG', () => {
    const result = executeWithDockerfile('ARG PHP_IMAGE=php:8.5-fpm\nFROM ${PHP_IMAGE}\n');
    assert.equal(result.status, 0);
    assert.equal(result.output, 'php=8.5\nimage=php:8.5-fpm\n');
});

test('accepts Renovate upgrades to newer minor and patch versions', () => {
    for (const version of ['8.6', '8.5.12']) {
        const image = 'php:' + version + '-fpm';
        const result = executeWithDockerfile('ARG PHP_IMAGE=' + image + '\n');
        assert.equal(result.status, 0);
        assert.equal(result.output, 'php=' + version + '\nimage=' + image + '\n');
    }
});

test('accepts digest-pinned PHP images for reproducible builds', () => {
    const image = 'php:8.5-fpm@sha256:' + 'a'.repeat(64);
    const result = executeWithDockerfile('ARG PHP_IMAGE=' + image + '\n');
    assert.equal(result.status, 0);
    assert.equal(result.output, 'php=8.5\nimage=' + image + '\n');
});

test('rejects absent, malformed, and ambiguous PHP image declarations', () => {
    assert.notEqual(executeWithDockerfile('FROM php:latest-fpm\n').status, 0);
    assert.notEqual(executeWithDockerfile('ARG PHP_IMAGE=php:latest-fpm\n').status, 0);
    assert.notEqual(executeWithDockerfile('ARG PHP_IMAGE=php:8.5-fpm\nARG PHP_IMAGE=php:8.6-fpm\n').status, 0);
});
