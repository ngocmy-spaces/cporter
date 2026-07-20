<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track when an artifact's on-disk .zip was reclaimed. Once a release is extracted the zip is
 * dead weight (rollback re-points `current` at an existing release dir — it never re-extracts),
 * so cPorter deletes the file after the deploy is stable while KEEPING the DB row for reporting
 * (size / sha256 / filename power the summary + charts). A non-null pruned_at means the file at
 * storage_path is gone and storage_path is nulled (docs/SPEC.md §5, §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->timestamp('pruned_at')->nullable()->after('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropColumn('pruned_at');
        });
    }
};
