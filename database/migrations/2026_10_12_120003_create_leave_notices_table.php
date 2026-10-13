<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only notice thread on a leave request. Admin/HR/managers post
 * notices; the requesting employee sees every notice on the show page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['organization_id', 'leave_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_notices');
    }
};
