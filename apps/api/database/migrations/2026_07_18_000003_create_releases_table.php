<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artifact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('version')->nullable();       // ref / tag / short SHA
            $table->string('path');                      // releases/<id> directory
            $table->string('state')->default('pending'); // App\Enums\ReleaseState
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
