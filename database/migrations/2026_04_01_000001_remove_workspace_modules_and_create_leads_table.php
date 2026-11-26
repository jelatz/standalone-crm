<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('project_stages');
        Schema::dropIfExists('deal_project');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('deal_handoffs');
        Schema::dropIfExists('project_template_tasks');
        Schema::dropIfExists('project_templates');

        if ($isPgsql) {
            DB::statement('DROP SEQUENCE IF EXISTS project_code_seq');
        }

        $obsoleteTypes = [
            'project',
            'project_template',
            'project_stage',
            'task',
            'deal_handoff',
        ];

        if (Schema::hasTable('entity_collaborators')) {
            DB::table('entity_collaborators')
                ->whereIn('collaboratable_type', $obsoleteTypes)
                ->delete();
        }

        if (Schema::hasTable('attachment_links')) {
            DB::table('attachment_links')
                ->whereIn('attachable_type', $obsoleteTypes)
                ->delete();
        }

        if (Schema::hasTable('activity_links')) {
            DB::table('activity_links')
                ->whereIn('linkable_type', $obsoleteTypes)
                ->delete();
        }

        if (Schema::hasTable('activities')) {
            DB::table('activities')
                ->whereIn('source_type', $obsoleteTypes)
                ->orWhereIn('activity_type', [
                    'task_created',
                    'task_completed',
                    'email_received',
                    'email_sent',
                    'chat_thread_created',
                    'handoff_submitted',
                    'handoff_approved',
                    'project_provisioned',
                ])
                ->delete();
        }

        if (Schema::hasTable('custom_field_definitions')) {
            DB::table('custom_field_definitions')
                ->where('entity_type', 'project')
                ->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where(function ($query): void {
                    $query->where('name', 'like', 'handoffs.%')
                        ->orWhere('name', 'like', 'projects.%')
                        ->orWhere('name', 'like', 'tasks.%')
                        ->orWhere('name', 'like', 'mailboxes.%')
                        ->orWhere('name', 'like', 'chat.%');
                })
                ->delete();
        }

        if (Schema::hasTable('companies')) {
            DB::table('companies')
                ->where('lifecycle_stage', 'lead')
                ->update(['lifecycle_stage' => 'prospect']);
        }

        if (! Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) use ($isPgsql): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('owner_id')->constrained('users');
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                if (! $isPgsql) {
                    $table->string('full_name', 201)->nullable();
                }
                $table->string('email', 255)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('job_title', 120)->nullable();
                $table->string('company_name', 200)->nullable();
                $table->string('website', 255)->nullable();
                $table->string('status', 24)->default('new');
                $table->string('source', 32)->default('inbound_web');
                $table->bigInteger('estimated_value_cents')->nullable();
                $table->char('currency', 3)->default('USD');
                $table->string('disqualified_reason', 255)->nullable();
                $table->timestampTz('converted_at')->nullable();
                $table->foreignUuid('converted_company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignUuid('converted_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->foreignUuid('converted_deal_id')->nullable()->constrained('deals')->nullOnDelete();
                $table->jsonb('custom_fields')->default('{}');
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->index(['status', 'updated_at'], 'idx_leads_status');
                $table->index('email', 'idx_leads_email');
            });

            if ($isPgsql) {
                DB::statement("ALTER TABLE leads ADD COLUMN full_name varchar(201) GENERATED ALWAYS AS (trim(first_name || ' ' || last_name)) STORED");
                DB::statement('ALTER TABLE leads ALTER COLUMN email TYPE citext');
                DB::statement('CREATE INDEX idx_leads_owner ON leads (owner_id) WHERE deleted_at IS NULL');
                DB::statement('CREATE INDEX idx_leads_cf ON leads USING GIN (custom_fields jsonb_path_ops)');
            } else {
                Schema::table('leads', function (Blueprint $table): void {
                    $table->index('owner_id', 'idx_leads_owner');
                });
            }
        }

        if (Schema::hasTable('deals') && ! Schema::hasColumn('deals', 'win_probability')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->smallInteger('win_probability')->nullable()->after('expected_close_date');
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('deals') && Schema::hasColumn('deals', 'win_probability')) {
            Schema::table('deals', function (Blueprint $table): void {
                $table->dropColumn('win_probability');
            });
        }

        Schema::dropIfExists('leads');
    }
};
