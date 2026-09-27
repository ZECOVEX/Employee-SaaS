<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Standard');
            $table->time('start_time')->default('09:00:00');
            $table->time('end_time')->default('18:00:00');
            $table->unsignedInteger('grace_minutes')->default(15);
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->json('work_days')->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'date', 'name']);
        });

        Schema::create('nfc_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('card_token', 64);
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('available'); // available | active | blocked | revoked
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'card_token']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('attendance_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('key_hash', 64);
            $table->string('status')->default('active'); // active | revoked
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained('attendance_terminals')->nullOnDelete();
            $table->foreignId('nfc_card_id')->nullable()->constrained('nfc_cards')->nullOnDelete();
            $table->string('event_type', 20); // CHECK_IN | CHECK_OUT | BREAK_START | BREAK_END | MANUAL_IN | MANUAL_OUT
            $table->timestamp('occurred_at');
            $table->string('timezone', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_identifier', 120)->nullable();
            $table->string('source')->default('nfc'); // nfc | manual
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
        });

        Schema::create('daily_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('first_check_in')->nullable();
            $table->timestamp('last_check_out')->nullable();
            $table->unsignedInteger('total_work_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('status')->default('PRESENT'); // PRESENT | LATE | ABSENT | HALF_DAY | LEAVE | HOLIDAY | WEEKEND | REMOTE
            $table->boolean('is_manual')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'employee_id', 'date']);
            $table->index(['organization_id', 'date', 'status']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->unsignedSmallInteger('default_days_per_year')->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days');
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('status')->default('PENDING'); // PENDING | APPROVED | REJECTED | CANCELLED
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'start_date']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('total_days')->default(0);
            $table->unsignedSmallInteger('used_days')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('daily_attendance');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_terminals');
        Schema::dropIfExists('nfc_cards');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('work_schedules');
    }
};
