<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — TOTP two-factor authentication: encrypted secret, enabled flag,
 * hashed recovery codes (json list of sha256 hashes, shown once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('must_change_password');
            $table->boolean('mfa_enabled')->default(false)->after('mfa_secret');
            $table->json('mfa_recovery_codes')->nullable()->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled', 'mfa_recovery_codes']);
        });
    }
};
