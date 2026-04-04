<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('daemon_run_id')->nullable();
            $table->string('model');
            $table->bigInteger('input_tokens')->default(0);
            $table->bigInteger('output_tokens')->default(0);
            $table->string('source');
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'created_at']);
            $table->index(['agent_id', 'created_at']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
