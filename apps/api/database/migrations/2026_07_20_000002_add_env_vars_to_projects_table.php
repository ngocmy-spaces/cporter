<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted environment variables cPorter renders into shared/.env on deploy (docs/SPEC.md §5, §9).
 * Stored as a Laravel-encrypted string (Crypt / APP_KEY), NOT plain JSON — hence `text`, not `json`.
 * Nullable — null means "no env vars managed by cPorter" (the operator-managed shared/.env stays
 * the source of truth, exactly as before this feature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('env_vars')->nullable()->after('hooks');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('env_vars');
        });
    }
};
