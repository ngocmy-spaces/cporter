<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('prefix', 16);            // fast lookup segment (non-secret)
            $table->string('hash', 64)->unique();    // sha256 of the full token (secret)
            $table->json('scopes')->nullable();      // ['read','deploy','rollback','admin']
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
