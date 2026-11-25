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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        }

        Schema::create('departments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->uuid('parent_id')->nullable();
            $table->timestampsTz();
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreign('parent_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('department_id')->constrained('departments');
            $table->string('name', 120);
            $table->uuid('lead_user_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('employee_code', 32)->unique();
            $table->string('name', 150);
            $table->string('email')->unique();
            $table->string('avatar_key', 255)->nullable();
            $table->foreignUuid('department_id')->constrained('departments');
            $table->foreignUuid('primary_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->uuid('manager_id')->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('status', 24)->default('active');
            $table->string('timezone', 64)->default('UTC');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('manager_id', 'idx_users_manager');
            $table->index(['department_id', 'status'], 'idx_users_dept_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('manager_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->foreign('lead_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('user_hierarchy_closures', function (Blueprint $table): void {
            $table->foreignUuid('ancestor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('descendant_id')->constrained('users')->cascadeOnDelete();
            $table->smallInteger('depth');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index('descendant_id', 'idx_closure_descendant');
        });

        Schema::create('entity_collaborators', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('collaboratable_type', 32);
            $table->uuid('collaboratable_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 16);
            $table->string('role_label', 32);
            $table->foreignUuid('granted_by_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['collaboratable_type', 'collaboratable_id', 'user_id'],
                'uq_entity_collaborators_target_user',
            );
            $table->index(
                ['user_id', 'collaboratable_type', 'collaboratable_id'],
                'idx_collab_user_lookup',
            );
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE password_reset_tokens ALTER COLUMN email TYPE citext');
        }

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('entity_collaborators');
        Schema::dropIfExists('user_hierarchy_closures');

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropForeign(['lead_user_id']);
        });

        Schema::dropIfExists('users');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('departments');
    }
};
