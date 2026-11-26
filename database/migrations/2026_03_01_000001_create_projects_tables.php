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
            DB::statement('CREATE SEQUENCE IF NOT EXISTS project_code_seq START 1');
        }

        Schema::create('project_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->jsonb('default_stages');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by_id')->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('project_template_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_template_id')->constrained('project_templates')->cascadeOnDelete();
            $table->uuid('parent_template_task_id')->nullable();
            $table->string('stage_key', 64);
            $table->string('title', 200);
            $table->jsonb('description_json')->nullable();
            $table->integer('relative_due_days')->nullable();
            $table->string('assignee_role_hint', 32)->nullable();
            $table->integer('sort_order')->default(0);
        });

        Schema::table('project_template_tasks', function (Blueprint $table): void {
            $table->foreign('parent_template_task_id')
                ->references('id')
                ->on('project_template_tasks')
                ->nullOnDelete();
        });

        Schema::create('deal_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('deal_id')->constrained('deals');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('submitted_by_id')->constrained('users');
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users');
            $table->foreignUuid('assigned_pm_id')->nullable()->constrained('users');
            $table->foreignUuid('assigned_am_id')->nullable()->constrained('users');
            $table->foreignUuid('project_template_id')->nullable()->constrained('project_templates');
            $table->string('status', 24)->default('pending_review');
            $table->jsonb('scope_notes_json');
            $table->jsonb('handoff_checklist')->default('[]');
            $table->date('target_kickoff_date')->nullable();
            $table->text('review_feedback')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('provisioned_at')->nullable();
            $table->timestampsTz();

            $table->index('deal_id', 'idx_handoffs_deal');
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX idx_handoffs_queue ON deal_handoffs (status, submitted_at DESC)');
            DB::statement("CREATE UNIQUE INDEX uq_deal_handoffs_active_deal ON deal_handoffs (deal_id) WHERE status IN ('pending_review', 'changes_requested', 'approved')");
        } else {
            Schema::table('deal_handoffs', function (Blueprint $table): void {
                $table->index(['status', 'submitted_at'], 'idx_handoffs_queue');
            });
        }

        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 24)->unique();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('primary_deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->foreignUuid('deal_handoff_id')->nullable()->unique()->constrained('deal_handoffs');
            $table->foreignUuid('owner_id')->constrained('users');
            $table->foreignUuid('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('department_id')->constrained('departments');
            $table->string('name', 200);
            $table->jsonb('description_json')->nullable();
            $table->string('status', 24)->default('planning');
            $table->string('health', 16)->default('on_track');
            $table->date('start_date')->nullable();
            $table->date('target_end_date')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('custom_fields')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('company_id', 'idx_projects_company');
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX idx_projects_owner ON projects (owner_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_projects_am ON projects (account_manager_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_projects_cf ON projects USING GIN (custom_fields jsonb_path_ops)');
        } else {
            Schema::table('projects', function (Blueprint $table): void {
                $table->index('owner_id', 'idx_projects_owner');
                $table->index('account_manager_id', 'idx_projects_am');
            });
        }

        Schema::create('deal_project', function (Blueprint $table): void {
            $table->foreignUuid('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();

            $table->primary(['deal_id', 'project_id']);
        });

        Schema::create('project_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('category', 24);
            $table->integer('sort_order')->default(0);
            $table->smallInteger('wip_limit')->nullable();

            $table->index(['project_id', 'sort_order'], 'idx_project_stages_order');
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignUuid('project_stage_id')->nullable()->constrained('project_stages')->nullOnDelete();
            $table->uuid('parent_task_id')->nullable();
            $table->string('linkable_type', 24)->nullable();
            $table->uuid('linkable_id')->nullable();
            $table->foreignUuid('creator_id')->constrained('users');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->jsonb('description_json')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 24)->default('todo');
            $table->double('board_position')->default(65536);
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->integer('estimated_minutes')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['linkable_type', 'linkable_id'], 'idx_tasks_linkable');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreign('parent_task_id')
                ->references('id')
                ->on('tasks')
                ->nullOnDelete();
        });

        if ($isPgsql) {
            DB::statement('CREATE INDEX idx_tasks_board ON tasks (project_id, project_stage_id, board_position) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX idx_tasks_my_work ON tasks (assignee_id, status, due_at) WHERE deleted_at IS NULL');
        } else {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index(['project_id', 'project_stage_id', 'board_position'], 'idx_tasks_board');
                $table->index(['assignee_id', 'status', 'due_at'], 'idx_tasks_my_work');
            });
        }

        Schema::create('task_watchers', function (Blueprint $table): void {
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['task_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('project_stages');
        Schema::dropIfExists('deal_project');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('deal_handoffs');
        Schema::dropIfExists('project_template_tasks');
        Schema::dropIfExists('project_templates');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS project_code_seq');
        }
    }
};
