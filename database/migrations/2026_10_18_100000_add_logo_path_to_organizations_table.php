<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company logo — uploaded by admins (settings.manage) from Company Settings
 * and rendered beside the company name in the sidebar brand row. The file
 * lives on the public disk under organizations/{id}/; the column stores the
 * path only (removal deletes the file, like user avatars).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
