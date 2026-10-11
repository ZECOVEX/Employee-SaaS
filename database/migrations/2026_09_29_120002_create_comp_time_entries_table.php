<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §73-F COMP_TIME: leave-balance-style ledger. One row per overtime record
 * that accrued compensatory time instead of pay; balance = sum(minutes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comp_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('overtime_record_id')->constrained('overtime_records')->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('minutes');
            $table->timestamps();

            $table->unique('overtime_record_id');
            $table->index(['organization_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_time_entries');
    }
};
