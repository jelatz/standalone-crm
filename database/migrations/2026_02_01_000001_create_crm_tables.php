<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        }

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('domain', 255)->nullable();
            $table->string('lifecycle_stage', 24)->default('prospect');
            $table->string('industry', 100)->nullable();
            $table->string('employee_range', 32)->nullable();
            $table->bigInteger('annual_revenue_cents')->nullable();
            $table->jsonb('billing_address')->default('{}');
            $table->foreignUuid('owner_id')->constrained('users');
            $table->foreignUuid('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('custom_fields')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($isPgsql) {
            DB::statement('ALTER TABLE companies ALTER COLUMN domain TYPE citext');
            DB::statement('CREATE INDEX idx_companies_owner ON companies (owner_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_companies_am ON companies (account_manager_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_companies_domain ON companies (domain)');
            DB::statement('CREATE INDEX idx_companies_cf ON companies USING GIN (custom_fields jsonb_path_ops)');
        } else {
            Schema::table('companies', function (Blueprint $table): void {
                $table->index('owner_id', 'idx_companies_owner');
                $table->index('account_manager_id', 'idx_companies_am');
                $table->index('domain', 'idx_companies_domain');
            });
        }

        Schema::create('contacts', function (Blueprint $table) use ($isPgsql): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('owner_id')->constrained('users');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            if (! $isPgsql) {
                $table->string('full_name', 201)->nullable();
            }
            $table->string('primary_email', 255)->nullable();
            $table->jsonb('phones')->default('[]');
            $table->string('job_title', 120)->nullable();
            $table->boolean('is_primary_contact')->default(false);
            $table->jsonb('custom_fields')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('company_id', 'idx_contacts_company');
        });

        if ($isPgsql) {
            DB::statement("ALTER TABLE contacts ADD COLUMN full_name varchar(201) GENERATED ALWAYS AS (trim(first_name || ' ' || last_name)) STORED");
            DB::statement('ALTER TABLE contacts ALTER COLUMN primary_email TYPE citext');
            DB::statement('CREATE INDEX idx_contacts_owner ON contacts (owner_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_contacts_cf ON contacts USING GIN (custom_fields jsonb_path_ops)');
        } else {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->index('owner_id', 'idx_contacts_owner');
            });
        }

        Schema::create('contact_emails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('email', 255);
            $table->boolean('is_primary')->default(false);
        });

        if ($isPgsql) {
            DB::statement('ALTER TABLE contact_emails ALTER COLUMN email TYPE citext');
        }

        Schema::table('contact_emails', function (Blueprint $table): void {
            $table->unique(['contact_id', 'email'], 'uq_contact_emails_contact_email');
            $table->index('email', 'idx_contact_emails_lookup');
        });

        Schema::create('pipelines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('stage_type', 16)->default('open');
            $table->smallInteger('win_probability')->default(10);
            $table->smallInteger('rotting_days')->nullable();
            $table->jsonb('required_core_fields')->default('[]');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['pipeline_id', 'sort_order'], 'idx_pipeline_stages_order');
        });

        Schema::create('custom_field_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('entity_type', 24);
            $table->foreignUuid('pipeline_id')->nullable()->constrained('pipelines')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label', 120);
            $table->string('field_type', 24);
            $table->jsonb('options')->nullable();
            $table->jsonb('validation_rules')->default('{}');
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['entity_type', 'key'], 'uq_custom_field_definitions_entity_key');
        });

        Schema::create('stage_required_fields', function (Blueprint $table): void {
            $table->foreignUuid('pipeline_stage_id')->constrained('pipeline_stages')->cascadeOnDelete();
            $table->foreignUuid('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();

            $table->primary(['pipeline_stage_id', 'custom_field_definition_id']);
        });

        Schema::create('deals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('pipeline_id')->constrained('pipelines');
            $table->foreignUuid('stage_id')->constrained('pipeline_stages');
            $table->foreignUuid('owner_id')->constrained('users');
            $table->foreignUuid('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->bigInteger('amount_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->date('expected_close_date')->nullable();
            $table->timestampTz('stage_entered_at');
            $table->timestampTz('won_at')->nullable();
            $table->timestampTz('lost_at')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->double('board_position')->default(65536);
            $table->jsonb('custom_fields')->default('{}');
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('company_id', 'idx_deals_company');
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX idx_deals_kanban ON deals (pipeline_id, stage_id, board_position) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_deals_owner ON deals (owner_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_deals_cf ON deals USING GIN (custom_fields jsonb_path_ops)');
        } else {
            Schema::table('deals', function (Blueprint $table): void {
                $table->index(['pipeline_id', 'stage_id', 'board_position'], 'idx_deals_kanban');
                $table->index('owner_id', 'idx_deals_owner');
            });
        }

        Schema::create('deal_contacts', function (Blueprint $table): void {
            $table->foreignUuid('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('stakeholder_role', 32);

            $table->primary(['deal_id', 'contact_id']);
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('author_id')->constrained('users');
            $table->jsonb('body_json');
            $table->text('body_text');
            $table->boolean('is_pinned')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 40);
            $table->string('source_type', 32);
            $table->uuid('source_id');
            $table->string('visibility_mode', 32)->default('source_policy');
            $table->string('summary', 255);
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['source_type', 'source_id'], 'idx_activities_source');
        });

        Schema::create('activity_links', function (Blueprint $table): void {
            $table->string('linkable_type', 24);
            $table->uuid('linkable_id');
            $table->timestampTz('occurred_at');
            $table->foreignUuid('activity_id')->constrained('activities')->cascadeOnDelete();

            $table->primary(['linkable_type', 'linkable_id', 'activity_id']);
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX idx_activity_links_timeline ON activity_links (linkable_type, linkable_id, occurred_at DESC, activity_id DESC)');
        } else {
            Schema::table('activity_links', function (Blueprint $table): void {
                $table->index(['linkable_type', 'linkable_id', 'occurred_at', 'activity_id'], 'idx_activity_links_timeline');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_links');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('deal_contacts');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('stage_required_fields');
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipelines');
        Schema::dropIfExists('contact_emails');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }
};
