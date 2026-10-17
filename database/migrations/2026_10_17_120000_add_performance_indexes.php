<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Query-shape indexes found by the performance audit:
 *
 * - attendance_events: NFC history filters by nfc_card_id (+ occurred_at
 *   ordering); SQLite never auto-indexes foreign keys.
 * - daily_attendance: every per-employee range query (score, reports,
 *   salary estimate, statistics) filters employee_id + date, which cannot
 *   use the (organization_id, employee_id, date) unique — its leading
 *   column is absent from the predicate.
 * - audit_logs: the log index (and its distinct filter scans) look up by
 *   actor_user_id, which was unindexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_events', function (Blueprint $table): void {
            $table->index(['nfc_card_id', 'occurred_at'], 'attendance_events_nfc_card_id_occurred_at_index');
        });

        Schema::table('daily_attendance', function (Blueprint $table): void {
            $table->index(['employee_id', 'date'], 'daily_attendance_employee_id_date_index');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('actor_user_id', 'audit_logs_actor_user_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table): void {
            $table->dropIndex('attendance_events_nfc_card_id_occurred_at_index');
        });

        Schema::table('daily_attendance', function (Blueprint $table): void {
            $table->dropIndex('daily_attendance_employee_id_date_index');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_actor_user_id_index');
        });
    }
};
