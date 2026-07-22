<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evolve auto-rollback from a single on/off flag into an explicit, per-trigger policy (docs/SPEC.md
 * §21.2): `auto_rollback_on` is a JSON list of the failures that should roll back (see
 * App\Enums\RollbackTrigger — `health_check`, `post_activate_hook`). Empty/null = never auto-roll-back.
 *
 * The prior `auto_rollback` boolean (migration 000002, since removed) shipped only within v1.1's own
 * development window, so there is no meaningful data to preserve — it is dropped where present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('auto_rollback_on')->nullable()->after('keep_releases');
        });

        // Drop the superseded boolean where it exists (environments that ran the interim 000002).
        if (Schema::hasColumn('projects', 'auto_rollback')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('auto_rollback');
            });
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('auto_rollback_on');
            $table->boolean('auto_rollback')->default(false)->after('keep_releases');
        });
    }
};
