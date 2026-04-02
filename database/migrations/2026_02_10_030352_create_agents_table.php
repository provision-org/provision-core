<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('role')->default('custom');
            $table->string('status')->default('active');
            $table->string('model_primary')->nullable();
            $table->json('model_fallbacks')->nullable();
            $table->text('system_prompt')->nullable();
            $table->text('identity')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->string('openclaw_agent_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
