<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

// Run with an isolated, read-only mounted Akaunting vendor tree and SQLite in memory.
$autoload = getenv('AKAUNTING_VENDOR_PATH') ?: '';
if ($autoload === '' || !is_file($autoload)) {
    fwrite(STDERR, "Set AKAUNTING_VENDOR_PATH to an isolated Akaunting vendor/autoload.php\n");
    exit(2);
}
require $autoload;

$app = new Illuminate\Foundation\Application(__DIR__);
$database = new Illuminate\Database\Capsule\Manager($app);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$database->setAsGlobal();
$database->bootEloquent();
$app->instance('db', $database->getDatabaseManager());
$app->instance('db.schema', $database->getConnection()->getSchemaBuilder());
$app->instance('encrypter', new Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);

$migration = require __DIR__ . '/../Database/Migrations/2026_11_12_020000_create_cooperative_production_tables.php';
$migration->up();
require __DIR__ . '/../Domain/SnapshotStore.php';

$store = new Modules\CooperativeProduction\Domain\SnapshotStore();
$metadata = [
    'company_id' => 1,
    'worked_competence' => '2026-09',
    'financial_competence' => '2026-10',
    'payment_competence' => '2026-11',
    'source' => 'kimai',
    'external_revision' => 'test-revision-1',
    'schema_version' => 'v1',
    'source_sha256' => str_repeat('a', 64),
    'collected_at' => '2026-11-12 01:00:00',
];
$input = ['subject' => 'synthetic-person', 'entries' => [['id' => 1, 'seconds' => 3600]]];
$id = $store->capture($metadata, $input);
if ($id !== $store->capture($metadata, array_reverse($input, true))) {
    throw new RuntimeException('Identical source revisions must be idempotent.');
}

$connection = $database->getConnection();
$row = $connection->table('lcc_pdc_snapshots')->where('id', $id)->first();
if (str_contains((string) $row->encrypted_payload, 'synthetic-person')) {
    throw new RuntimeException('Sensitive input was saved as plaintext.');
}
if ($store->readForCompany(1, $id) !== [
    'entries' => [['id' => 1, 'seconds' => 3600]],
    'subject' => 'synthetic-person',
]) {
    throw new RuntimeException('Encrypted snapshot did not round-trip intact.');
}

try {
    $store->readForCompany(2, $id);
    throw new LogicException('Cross-company snapshot read allowed.');
} catch (DomainException) {
    // Expected: a different company never receives even the ciphertext.
}
try {
    $store->capture($metadata, ['altered' => true]);
    throw new LogicException('Conflicting source revision was accepted.');
} catch (DomainException) {
    // Expected: an earlier revision must remain immutable.
}
try {
    $store->capture([...$metadata, 'schema_version' => 'v2'], $input);
    throw new LogicException('The same source revision changed schema without conflict.');
} catch (DomainException) {
    // Source schema changes require a distinct revision.
}
try {
    $store->capture([...$metadata, 'collected_at' => '2026-02-31 01:00:00'], $input);
    throw new LogicException('Invalid source capture time was accepted.');
} catch (DomainException) {
    // Date overflow must not normalize an invalid provenance timestamp.
}
$connection->table('lcc_pdc_snapshots')->where('id', $id)->update([
    'payload_sha256' => str_repeat('b', 64),
]);
try {
    $store->readForCompany(1, $id);
    throw new LogicException('Corrupted source digest was accepted.');
} catch (RuntimeException $error) {
    if ($error instanceof LogicException) {
        throw $error;
    }
    // Expected: no plaintext is returned on fingerprint mismatch.
}

echo "PASS: encrypted snapshot, idempotent replay, revision conflicts, company scoping and digest check\n";
