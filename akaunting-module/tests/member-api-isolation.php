<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Execute only in an isolated PHP 8.3 Akaunting runtime with SQLite.
 * Example: AKAUNTING_VENDOR_PATH=/akaunting/vendor/autoload.php php ...
 * This script cannot reach external services or modify persistent databases.
 */
$autoload = getenv('AKAUNTING_VENDOR_PATH') ?: '';
if ($autoload === '' || !is_file($autoload)) {
    fwrite(STDERR, "Missing AKAUNTING_VENDOR_PATH to an isolated Akaunting vendor/autoload.php\n");
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
Illuminate\Support\Facades\Facade::setFacadeApplication($app);

$auth = new class () {
    public int $userId = 10;
    public bool $authenticated = true;

    public function check(): bool
    {
        return $this->authenticated;
    }

    public function id(): ?int
    {
        return $this->authenticated ? $this->userId : null;
    }
};
$app->instance('auth', $auth);
$app->instance(Illuminate\Contracts\Auth\Factory::class, $auth);

$company = new App\Models\Common\Company();
$company->id = 1;
$app->instance(App\Models\Common\Company::class, $company);

$schema = $database->getConnection()->getSchemaBuilder();
$schema->create('contacts', static function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->increments('id');
    $table->integer('company_id');
    $table->string('type');
    $table->softDeletes();
});
$schema->create('user_companies', static function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->integer('user_id');
    $table->integer('company_id');
});

$migration = require __DIR__ . '/../Database/Migrations/2026_11_12_021000_create_cooperative_member_read_tables.php';
$migration->up();
foreach (['lcc_pdc_member_links', 'lcc_pdc_member_link_events', 'lcc_pdc_member_statements'] as $table) {
    if (!$schema->hasTable($table)) {
        throw new RuntimeException('Missing expected table ' . $table);
    }
}

$db = $database->getConnection();
$db->table('user_companies')->insert([
    ['user_id' => 10, 'company_id' => 1],
    ['user_id' => 20, 'company_id' => 1],
    ['user_id' => 10, 'company_id' => 2],
]);
$db->table('contacts')->insert([
    ['id' => 100, 'company_id' => 1, 'type' => 'vendor'],
    ['id' => 200, 'company_id' => 1, 'type' => 'vendor'],
    ['id' => 300, 'company_id' => 2, 'type' => 'vendor'],
]);
$future = (new DateTimeImmutable())->modify('+1 day')->format('Y-m-d H:i:s');
$past = (new DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s');
$db->table('lcc_pdc_member_links')->insert([
    [
        'company_id' => 1, 'akaunting_user_id' => 10, 'akaunting_contact_id' => 100,
        'kimai_user_id' => 1000, 'nextcloud_user_id' => 'example-a',
        'source_revision_sha256' => str_repeat('a', 64), 'state' => 'active',
        'verified_at' => $past, 'expires_at' => $future,
    ],
    [
        'company_id' => 1, 'akaunting_user_id' => 20, 'akaunting_contact_id' => 200,
        'kimai_user_id' => 2000, 'nextcloud_user_id' => 'example-b',
        'source_revision_sha256' => str_repeat('b', 64), 'state' => 'active',
        'verified_at' => $past, 'expires_at' => $future,
    ],
    [
        'company_id' => 2, 'akaunting_user_id' => 10, 'akaunting_contact_id' => 300,
        'kimai_user_id' => 1000, 'nextcloud_user_id' => 'example-a',
        'source_revision_sha256' => str_repeat('c', 64), 'state' => 'active',
        'verified_at' => $past, 'expires_at' => $future,
    ],
]);
foreach ([
    [1, 100, 'a', 100, null],
    [1, 200, 'b', 200, 200],
    [2, 300, 'c', 300, null],
] as [$tenant, $contact, $revision, $eligible, $paid]) {
    $db->table('lcc_pdc_member_statements')->insert([
        'company_id' => $tenant,
        'akaunting_contact_id' => $contact,
        'source_revision_sha256' => str_repeat($revision, 64),
        'worked_competence' => '2026-09',
        'financial_competence' => '2026-10',
        'payment_competence' => '2026-11',
        'status' => 'approved',
        'observed_seconds' => 3600,
        'eligible_production_cents' => $eligible,
        'approved_gross_cents' => null,
        'deductions_cents' => null,
        'approved_net_cents' => null,
        'billed_cents' => null,
        'paid_cents' => $paid,
        'observed_at' => $past,
    ]);
}

require __DIR__ . '/../Domain/MemberHistoryFilters.php';
require __DIR__ . '/../Domain/MemberRead.php';
$reader = new Modules\CooperativeProduction\Domain\MemberRead();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectError = static function (int $code, mixed $from = null, mixed $to = null) use ($reader, $assert): void {
    try {
        $reader->history($from, $to);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        $assert($error->getStatusCode() === $code, 'Wrong denial status');
        return;
    }
    throw new RuntimeException('Expected denied request');
};

$data = $reader->history()['data'];
$assert(count($data) === 1, 'Member 10 can see other member entries');
$assert($data[0]['money']['eligible_production_cents'] === 100, 'Wrong company 1 amount');
$assert($data[0]['money']['paid_cents'] === null, 'Unknown payment must be null');
$assert(!array_key_exists('akaunting_contact_id', $data[0]), 'Contact identifier leaked');

$auth->userId = 20;
$data = $reader->history()['data'];
$assert(count($data) === 1 && $data[0]['money']['paid_cents'] === 200, 'Member 20 isolation failed');

$auth->authenticated = false;
$expectError(401);
$auth->authenticated = true;

$auth->userId = 10;
$company->id = 2;
$data = $reader->history()['data'];
$assert(count($data) === 1 && $data[0]['money']['eligible_production_cents'] === 300, 'Cross-company data leakage');

$company->id = 3;
$expectError(403);
$company->id = 1;

$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['expires_at' => $past]);
$expectError(409);

$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['expires_at' => $future, 'state' => 'disabled']);
$expectError(403);

// Re-enable only the synthetic link to test malformed and stale evidence.
$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['expires_at' => $future, 'state' => 'active']);

$expectError(422, '2026-00');
$expectError(422, '2026-12', '2026-01');
$expectError(422, ['2026-01']);
$expectError(422, '2026-01 OR 1=1');

$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['nextcloud_user_id' => '']);
$expectError(409);
$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['nextcloud_user_id' => 'example-a', 'verified_at' => $future]);
$expectError(409);
$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['verified_at' => $past, 'kimai_user_id' => 0]);
$expectError(409);
$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['kimai_user_id' => 1000, 'source_revision_sha256' => str_repeat('z', 64)]);
$expectError(409);
$db->table('lcc_pdc_member_links')->where('company_id', 1)
    ->where('akaunting_user_id', 10)->update(['source_revision_sha256' => str_repeat('a', 64)]);

// Older month must survive even if the newest month has 125 revisions.
$statement = [
    'company_id' => 1, 'akaunting_contact_id' => 100,
    'worked_competence' => '2025-12', 'financial_competence' => '2026-01',
    'payment_competence' => '2026-02', 'status' => 'approved',
    'observed_seconds' => 7200, 'eligible_production_cents' => 321,
    'paid_cents' => null, 'observed_at' => $past,
    'source_revision_sha256' => hash('sha256', 'older synthetic month'),
];
$db->table('lcc_pdc_member_statements')->insert($statement);
for ($i = 1; $i <= 125; $i++) {
    $db->table('lcc_pdc_member_statements')->insert([
        ...$statement,
        'worked_competence' => '2026-09',
        'financial_competence' => '2026-10',
        'payment_competence' => '2026-11',
        'eligible_production_cents' => $i,
        'paid_cents' => 0,
        'source_revision_sha256' => hash('sha256', 'latest synthetic revision ' . $i),
    ]);
}
$data = $reader->history()['data'];
$assert(count($data) === 2, 'Older month hidden by many newer revisions');
$assert($data[0]['money']['eligible_production_cents'] === 125, 'Latest revision not selected');
$assert($data[0]['money']['paid_cents'] === 0, 'Known zero payment was replaced with unavailable');
$assert($data[1]['worked_competence'] === '2025-12', 'Older month absent');
$assert(count($reader->history('2026-09', '2026-09')['data']) === 1, 'Month filter not applied');
$assert($reader->history('2025-12', '2025-12')['data'][0]['money']['eligible_production_cents'] === 321,
    'Historical filter lost old month');

$auth->userId = 20;
$assert(count($reader->history()['data']) === 1, 'Member 20 inherited member 10 history');
$auth->userId = 10;
$company->id = 2;
$assert($reader->history()['data'][0]['money']['eligible_production_cents'] === 300,
    'Cross-company history leaked after tenant switch');
$company->id = 1;

echo "PASS: isolated member API schema, tenant/member/link denial, missing vs zero, revision window, filters\n";
