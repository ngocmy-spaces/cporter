<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the project disk footprint into two figures and adds status columns so a manual
 * recompute can run off-request and be polled to completion (docs/SPEC.md §11):
 *   - disk_usage           : live footprint — active release (`current`) + shared/
 *   - releases_disk_usage  : all retained release directories (rollback history)
 * disk_usage_status guards against overlapping/duplicate recompute jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('releases_disk_usage')->default(0)->after('disk_usage');
            $table->string('disk_usage_status', 16)->default('idle')->after('releases_disk_usage');
            $table->timestamp('disk_usage_started_at')->nullable()->after('disk_usage_status');
            $table->timestamp('disk_usage_calculated_at')->nullable()->after('disk_usage_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'releases_disk_usage',
                'disk_usage_status',
                'disk_usage_started_at',
                'disk_usage_calculated_at',
            ]);
        });
    }
};
