<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-Phase 21 — MFA method choice (authenticator app vs email codes) and
 * opt-in login-alert emails (IP, location, device on every successful sign-in).
 * `mfa_method` is null whenever MFA is off; login alerts default off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mfa_method', 10)->nullable()->after('mfa_enabled');
            $table->boolean('login_alerts_enabled')->default(false)->after('mfa_method');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_method', 'login_alerts_enabled']);
        });
    }
};
