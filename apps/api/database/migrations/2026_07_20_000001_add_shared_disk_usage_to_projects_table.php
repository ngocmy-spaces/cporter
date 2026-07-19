<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shared-path disk sizes, keyed by the entry's relative path (docs/SPEC.md §11).
 * Computed alongside disk_usage in the same recompute flow, so it stays in sync with the
 * aggregate figures. Nullable — null means "not computed yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('shared_disk_usage')->nullable()->after('disk_usage_calculated_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('shared_disk_usage');
        });
    }
};
