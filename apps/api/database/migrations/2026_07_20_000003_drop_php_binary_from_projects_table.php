<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the per-project `php_binary` override. Hooks now run as raw shell commands verbatim
 * (docs/SPEC.md §9), so the operator specifies the interpreter inline
 * (e.g. `php artisan …` or `/opt/cpanel/ea-php83/root/usr/bin/php artisan …`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('php_binary');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('php_binary')->nullable()->after('docroot_subpath');
        });
    }
};
