<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->ulid('target_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'created_at']);
            $table->index(['actor_type', 'actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
