<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('openrouter_key_hash');
            $table->text('api_key');
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_api_keys');
    }
};
