<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project disk footprint (bytes) — sum of the retained release directories on disk.
 * Recomputed after each deploy's prune step (docs/SPEC.md §6, §11). Cached here so the
 * projects list/detail pages don't have to walk the filesystem on every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('disk_usage')->default(0)->after('keep_releases');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('disk_usage');
        });
    }
};
