<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §71 — login history: every login attempt (web, API, SSO, MFA) records the
 * client IP, a best-effort server-side GeoIP resolution (country / division /
 * area), user agent and outcome. user_id/organization_id are nullable so
 * unknown-email failures still land in the security trail. The users table
 * also gains denormalized "latest" columns for fast profile display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('geo_country', 80)->nullable();
            $table->string('geo_division', 120)->nullable();
            $table->string('geo_area', 120)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('login_status', 20); // SUCCESS / FAILED / MFA_FAILED
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('geo_division');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->string('last_login_geo_division', 120)->nullable()->after('last_login_ip');
            $table->string('last_login_geo_area', 120)->nullable()->after('last_login_geo_division');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_ip', 'last_login_geo_division', 'last_login_geo_area']);
        });

        Schema::dropIfExists('user_login_history');
    }
};
