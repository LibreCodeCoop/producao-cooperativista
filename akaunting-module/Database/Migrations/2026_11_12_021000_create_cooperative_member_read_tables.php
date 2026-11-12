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
        Schema::create('lcc_pdc_member_links', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedBigInteger('akaunting_user_id');
            $table->unsignedBigInteger('akaunting_contact_id');
            $table->unsignedBigInteger('kimai_user_id');
            $table->string('nextcloud_user_id', 200);
            $table->char('source_revision_sha256', 64);
            $table->string('state', 16);
            $table->dateTime('verified_at');
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->unique(['company_id', 'akaunting_user_id'], 'lcc_pdc_member_user_unique');
            $table->unique(['company_id', 'akaunting_contact_id'], 'lcc_pdc_member_contact_unique');
            $table->unique(['company_id', 'kimai_user_id'], 'lcc_pdc_member_kimai_unique');
            $table->index(['company_id', 'state', 'expires_at'], 'lcc_pdc_member_link_state');
        });

        Schema::create('lcc_pdc_member_link_events', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedBigInteger('akaunting_user_id');
            $table->char('source_revision_sha256', 64);
            $table->string('event', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->index(['company_id', 'akaunting_user_id', 'recorded_at'], 'lcc_pdc_member_link_audit');
        });

        Schema::create('lcc_pdc_member_statements', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id');
            $table->unsignedBigInteger('akaunting_contact_id');
            $table->char('source_revision_sha256', 64);
            $table->string('worked_competence', 7);
            $table->string('financial_competence', 7);
            $table->string('payment_competence', 7);
            $table->string('status', 24);
            $table->unsignedBigInteger('observed_seconds')->nullable();
            $table->bigInteger('eligible_production_cents')->nullable();
            $table->bigInteger('approved_gross_cents')->nullable();
            $table->bigInteger('deductions_cents')->nullable();
            $table->bigInteger('approved_net_cents')->nullable();
            $table->bigInteger('billed_cents')->nullable();
            $table->bigInteger('paid_cents')->nullable();
            $table->dateTime('observed_at');
            $table->timestamp('recorded_at')->useCurrent();
            $table->unique(
                ['company_id', 'akaunting_contact_id', 'worked_competence', 'source_revision_sha256'],
                'lcc_pdc_member_statement_revision',
            );
            $table->index(
                ['company_id', 'akaunting_contact_id', 'worked_competence'],
                'lcc_pdc_member_statement_history',
            );
        });
    }

    public function down(): void
    {
        // Do not drop identity audit, member financial statements or their revisions.
    }
};
