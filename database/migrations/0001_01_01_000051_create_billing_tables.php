<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide plan catalog (§54) — one row per plan, shared by all orgs.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->unsignedInteger('employee_limit')->nullable(); // null = unlimited
            $table->decimal('price_monthly', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One active subscription per organization.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active'); // trial|active|past_due|canceled
            $table->date('trial_ends_at')->nullable();
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->timestamps();
            $table->unique('organization_id');
        });

        // Billing history (§54) — issued when a paid plan is selected.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40)->unique();
            $table->decimal('amount', 8, 2);
            $table->string('currency', 8)->default('USD');
            $table->string('status', 20)->default('open'); // open|paid|void
            $table->date('period_start');
            $table->date('period_end');
            $table->date('paid_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
