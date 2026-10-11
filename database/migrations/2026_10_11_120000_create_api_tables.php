<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — org-scoped API keys for /api/v1 (`emsak_…`, hashed, abilities
 * = permission-key subset or `*`) and SCIM bearer tokens for /api/scim/v2
 * user/group provisioning. Both are shown exactly once at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('prefix', 12); // non-secret lookup prefix
            $table->string('key_hash', 64)->unique(); // sha256 of the full key
            $table->json('abilities')->nullable(); // null or ['*'] = all org abilities
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::create('scim_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique(); // sha256 of the full token
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_tokens');
        Schema::dropIfExists('api_keys');
    }
};
