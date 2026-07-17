<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('status')->default('pending'); // App\Enums\ArtifactStatus
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
