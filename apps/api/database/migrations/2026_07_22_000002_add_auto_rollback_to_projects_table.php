<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-rollback as an opt-in policy (docs/SPEC.md §21.2). Enabling it means "I want the site
 * green when the deploy finishes" — a failed post-activation health check then swaps `current`
 * back to a single valid previous release. Default false: a failed health check just marks the
 * deployment `failed` and the project `unhealthy`, without any automatic swap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('auto_rollback')->default(false)->after('keep_releases');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('auto_rollback');
        });
    }
};
