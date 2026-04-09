<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cron_expression');
            $table->string('timezone')->default('UTC');
            $table->string('status')->default('active');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
