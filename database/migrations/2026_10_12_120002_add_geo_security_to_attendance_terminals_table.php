<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §32 — terminal geo-security: optional GPS coordinates + radius for
 * location verification and an optional IP allowlist (exact or CIDR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_terminals', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('radius_meters')->default(100)->after('longitude');
            $table->boolean('require_location')->default(false)->after('radius_meters');
            $table->json('allowed_ips')->nullable()->after('require_location');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_terminals', function (Blueprint $table): void {
            $table->dropColumn([
                'latitude', 'longitude', 'radius_meters', 'require_location', 'allowed_ips',
            ]);
        });
    }
};
