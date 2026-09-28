<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('revision')->default(1);
            $table->string('currency', 8);

            // Earnings snapshot from the salary record in effect for the period.
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('gross_salary', 12, 2);

            // Attendance deduction snapshot (rules in effect when finalized).
            $table->decimal('late_deduction', 12, 2)->default(0);
            $table->decimal('absence_deduction', 12, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);
            $table->decimal('attendance_deduction_total', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2);

            // Month-end context (§52) — historical rows never change afterward.
            $table->unsignedInteger('scheduled_days')->default(0);
            $table->unsignedInteger('completed_days')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('daily_rate', 12, 2)->default(0);
            $table->decimal('hourly_rate', 12, 2)->default(0);
            $table->boolean('capped')->default(false);
            $table->json('snapshot'); // full calculator output for reproducibility
            $table->string('notes', 500)->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('payslips')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
