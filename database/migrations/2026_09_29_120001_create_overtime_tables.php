<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Overtime policy (§73-G) — effective-dated versions like work schedules
        // (§11), so a policy change never rewrites history.
        Schema::create('overtime_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('mode', 24)->default('ABOVE_EXPECTED_HOURS'); // AFTER_OFFICE_END | ABOVE_EXPECTED_HOURS
            $table->unsignedInteger('start_threshold_minutes')->default(15);
            $table->unsignedInteger('rounding_minutes')->default(15);
            $table->string('rounding_method', 8)->default('NEAREST'); // UP | DOWN | NEAREST
            $table->unsignedInteger('daily_cap_minutes')->default(120);
            $table->unsignedInteger('weekly_cap_minutes')->default(720);
            $table->unsignedInteger('monthly_cap_minutes')->nullable();
            $table->unsignedInteger('max_shift_minutes')->default(960);
            $table->string('approval_mode', 16)->default('AUTO'); // AUTO | MANAGER | MANAGER_HR
            $table->unsignedSmallInteger('pending_expiry_days')->default(7);
            $table->string('pending_expiry_action', 16)->default('ESCALATE'); // AUTO_APPROVE | AUTO_REJECT | ESCALATE
            $table->decimal('weekday_multiplier', 4, 2)->default(1.50);
            $table->decimal('weekend_multiplier', 4, 2)->default(2.00);
            $table->decimal('holiday_multiplier', 4, 2)->default(2.00);
            $table->time('night_window_start')->nullable();
            $table->time('night_window_end')->nullable();
            $table->decimal('night_multiplier', 4, 2)->nullable();
            $table->string('rate_base', 8)->default('GROSS'); // GROSS | BASIC | CUSTOM
            $table->boolean('offset_late_with_overtime')->default(false);
            $table->boolean('weekend_all_overtime')->default(true);
            $table->string('compensation_type', 10)->default('PAID'); // PAID | COMP_TIME
            $table->boolean('require_pre_approval')->default(false);
            $table->boolean('show_pay_to_employee')->default(true);
            $table->boolean('show_hours_to_employee')->default(true);
            $table->unsignedInteger('overtime_score_bonus_max')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'effective_from']);
        });

        // Derived overtime detail (§73-H) — the source overtime is approved and
        // paid from; daily_attendance.overtime_minutes mirrors the approved value.
        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('day_type', 16)->default('WORKING_DAY'); // WORKING_DAY | WEEKEND | HOLIDAY | LEAVE_DAY
            $table->foreignId('policy_id')->nullable()->constrained('overtime_policies')->nullOnDelete();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('expected_minutes')->default(0);
            $table->integer('raw_minutes')->default(0);
            $table->unsignedInteger('late_offset_minutes')->default(0);
            $table->unsignedInteger('countable_minutes')->default(0);
            $table->decimal('multiplier', 4, 2)->default(1.00);
            $table->decimal('hourly_rate_snapshot', 12, 4)->nullable();
            $table->decimal('estimated_pay', 12, 2)->default(0);
            $table->string('status', 16)->default('NONE'); // NONE | PENDING | AUTO_APPROVED | APPROVED | REJECTED | FLAGGED
            $table->string('flag_reason', 48)->nullable();
            $table->string('employee_note', 500)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'work_date']);
        });

        // Approval trail (§73-E) — every decision, adjustment or escalation.
        Schema::create('overtime_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('overtime_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 12); // APPROVED | REJECTED | ADJUSTED | ESCALATED
            $table->unsignedInteger('minutes_before')->nullable();
            $table->unsignedInteger('minutes_after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'overtime_record_id']);
        });

        // Per-employee override (§73-G): exclude salaried managers etc.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('overtime_eligibility', 16)
                ->default('POLICY_DEFAULT')
                ->after('work_location');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('overtime_eligibility');
        });

        Schema::dropIfExists('overtime_approvals');
        Schema::dropIfExists('overtime_records');
        Schema::dropIfExists('overtime_policies');
    }
};
