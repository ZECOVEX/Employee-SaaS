<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §17 employee_monthly_status: one finalized snapshot per employee-month
 * with the score components, day counts and overtime totals. Generated at
 * month-end (after the pending-overtime sweep) and never changed after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_monthly_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->decimal('attendance_score', 6, 2);
            $table->decimal('attendance_rate', 6, 2);
            $table->decimal('punctuality_score', 6, 2);
            $table->decimal('working_hours_score', 6, 2);
            $table->decimal('leave_score', 6, 2);

            $table->unsignedSmallInteger('days_present');
            $table->unsignedSmallInteger('days_absent');
            $table->unsignedSmallInteger('days_late');
            $table->unsignedSmallInteger('days_on_leave');
            $table->unsignedInteger('total_work_minutes');
            $table->unsignedInteger('expected_work_minutes');

            $table->unsignedInteger('overtime_minutes_approved');
            $table->unsignedInteger('overtime_minutes_pending');
            $table->unsignedInteger('overtime_minutes_rejected');
            $table->unsignedSmallInteger('overtime_days');

            $table->string('status', 32);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'employee_id', 'year', 'month'],
                'employee_monthly_status_unique'
            );
            $table->index(['organization_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_monthly_statuses');
    }
};
