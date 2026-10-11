<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §15 risk events: one row per employee × rule × evaluation window when a
 * configured threshold is reached (weekly late-risk, [OT] overwork limits).
 * The unique key makes repeated notifications suppressible per window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // late_week | ot_week | ot_month | ot_streak
            $table->string('level', 40); // configurable label (HIGH RISK, NOTICE, …)
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('observed'); // late days / overtime minutes / streak days
            $table->unsignedInteger('threshold');
            $table->string('detail'); // human summary shown on Statistics/Dashboard
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'employee_id', 'type', 'period_start', 'period_end'],
                'risk_events_window_unique'
            );
            $table->index(['organization_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_events');
    }
};
