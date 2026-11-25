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
        Schema::create('async_job_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('job_class', 180);
            $table->string('queue', 64);
            $table->string('subject_type', 32)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->foreignUuid('triggered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->smallInteger('attempt')->default(1);
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->jsonb('payload_summary')->default('{}');
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('queued_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->index(['status', 'queue'], 'idx_job_status_queue');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_job_subject ON async_job_runs (subject_type, subject_id, queued_at DESC)');
        } else {
            Schema::table('async_job_runs', function (Blueprint $table): void {
                $table->index(['subject_type', 'subject_id', 'queued_at'], 'idx_job_subject');
            });
        }

        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('uploaded_by_id')->constrained('users');
            $table->string('disk', 24)->default('s3');
            $table->string('bucket', 120);
            $table->string('object_key', 512)->unique();
            $table->string('original_filename', 255);
            $table->string('mime_type', 127);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 24)->default('clean');
            $table->timestampTz('created_at');
        });

        Schema::create('attachment_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('attachment_id')->constrained('attachments')->cascadeOnDelete();
            $table->string('attachable_type', 32);
            $table->uuid('attachable_id');
            $table->timestampTz('created_at');

            $table->unique(
                ['attachment_id', 'attachable_type', 'attachable_id'],
                'uq_attachment_links_target',
            );
            $table->index(['attachable_type', 'attachable_id'], 'idx_attachment_links_target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachment_links');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('async_job_runs');
    }
};
