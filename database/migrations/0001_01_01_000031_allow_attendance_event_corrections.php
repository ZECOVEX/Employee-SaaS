<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            // §9/§24: events are never overwritten — an admin correction creates a
            // replacement event and points the original at it (full audit trail).
            $table->foreignId('superseded_by')->nullable()
                ->after('created_by')
                ->constrained('attendance_events')
                ->nullOnDelete();

            // Removal is a soft delete so the raw entry stays inspectable.
            $table->softDeletes()->after('superseded_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by');
            $table->dropSoftDeletes();
        });
    }
};
