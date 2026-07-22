<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continuous project health (docs/SPEC.md §21.1). Promotes health from a per-deploy check
 * into a persisted, continuously-monitored signal that is the single source of truth for
 * dashboard alerts, the deploy gate note, and the auto-rollback decision:
 *   - health_status      : healthy | unhealthy | unknown | paused (see App\Enums\ProjectHealth)
 *   - health_checked_at  : when cporter:check-health (or a deploy gate) last evaluated it
 *   - health_last_ok_at  : when the health check last passed (for "green since" reporting)
 * Defaults to `unknown` — a project is only ever `healthy`/`unhealthy` once actually polled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('health_status', 16)->default('unknown')->after('health_check_url');
            $table->timestamp('health_checked_at')->nullable()->after('health_status');
            $table->timestamp('health_last_ok_at')->nullable()->after('health_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['health_status', 'health_checked_at', 'health_last_ok_at']);
        });
    }
};
