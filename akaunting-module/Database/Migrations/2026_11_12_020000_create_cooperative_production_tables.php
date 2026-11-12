<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lcc_pdc_snapshots', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->string('worked_competence', 7);
            $table->string('financial_competence', 7);
            $table->string('payment_competence', 7);
            $table->string('source', 32);
            $table->string('external_revision', 128);
            $table->string('schema_version', 40);
            $table->char('source_sha256', 64);
            $table->char('payload_sha256', 64);
            $table->longText('encrypted_payload');
            $table->timestamp('collected_at');
            $table->timestamp('imported_at')->useCurrent();
            $table->unique(
                ['company_id', 'worked_competence', 'source', 'external_revision'],
                'lcc_pdc_snapshot_revision_unique',
            );
            $table->index(['company_id', 'worked_competence', 'source'], 'lcc_pdc_snapshot_scope');
        });

        Schema::create('lcc_pdc_approval_events', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->string('worked_competence', 7);
            $table->char('input_revision_sha256', 64);
            $table->unsignedBigInteger('actor_user_id');
            $table->string('decision', 24);
            $table->char('decision_sha256', 64);
            $table->timestamp('recorded_at')->useCurrent();
            $table->index(['company_id', 'worked_competence', 'recorded_at'], 'lcc_pdc_approval_scope');
        });

        Schema::create('lcc_pdc_publication_attempts', static function (Blueprint $table): void {
            $table->string('attempt_id', 32)->primary();
            $table->unsignedInteger('company_id');
            $table->char('operation_key', 64);
            $table->unsignedInteger('attempt_number');
            $table->string('method', 8);
            $table->string('endpoint', 96);
            $table->char('document_fingerprint', 64);
            $table->char('payload_fingerprint', 64);
            $table->char('input_revision_sha256', 64)->nullable();
            $table->string('state', 16);
            $table->unsignedBigInteger('remote_document_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->unique(
                ['company_id', 'operation_key', 'attempt_number'],
                'lcc_pdc_attempt_key_unique',
            );
            $table->index(['company_id', 'state', 'started_at'], 'lcc_pdc_attempt_review');
        });

        Schema::create('lcc_pdc_income_appropriations', static function (Blueprint $table): void {
            $table->unsignedInteger('company_id');
            $table->string('source_type', 12);
            $table->unsignedBigInteger('source_id');
            $table->string('competence', 7);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('source_total_cents');
            $table->char('revision', 64);
            $table->string('state', 16);
            $table->dateTime('created_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->primary(
                ['company_id', 'source_type', 'source_id', 'competence'],
                'lcc_pdc_appropriation_primary',
            );
            $table->index(['company_id', 'state', 'competence'], 'lcc_pdc_appropriation_review');
        });
    }

    public function down(): void
    {
        // Financial and approval history must not be destroyed by automatic rollback.
        // Restoring or removing this schema requires a separately approved operation.
    }
};
