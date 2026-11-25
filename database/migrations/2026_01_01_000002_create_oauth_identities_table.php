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
        Schema::create('oauth_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('provider_tenant_id', 128);
            $table->string('provider_user_id', 128);
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->timestampsTz();

            $table->unique(['provider', 'provider_user_id'], 'uq_oauth_identities_provider_user');
            $table->index(['user_id', 'provider'], 'idx_oauth_identities_user_provider');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE oauth_identities ALTER COLUMN email TYPE citext');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }
};
