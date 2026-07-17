<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger')->default('api');   // App\Enums\DeploymentTrigger
            $table->string('status')->default('queued'); // App\Enums\DeploymentStatus
            $table->json('steps')->nullable();           // pipeline step log
            $table->string('actor')->nullable();         // api-key name / user email / 'cron'
            $table->string('idempotency_key')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->unique(['project_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
