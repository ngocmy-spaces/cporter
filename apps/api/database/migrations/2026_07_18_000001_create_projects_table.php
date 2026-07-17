<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('base_path');                 // absolute path, e.g. /home/user/learn.domain
            $table->string('type')->default('static');   // App\Enums\ProjectType
            $table->string('docroot_subpath')->nullable(); // e.g. 'public' for Laravel
            $table->string('php_binary')->nullable();    // target-app PHP CLI (cron-worker)
            $table->unsignedSmallInteger('keep_releases')->default(5);
            $table->string('health_check_url')->nullable();
            $table->json('shared_paths')->nullable();    // e.g. ['.env','storage']
            $table->json('hooks')->nullable();           // pre_activate / post_activate commands
            $table->string('status')->default('active'); // App\Enums\ProjectStatus
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
