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
        Schema::create('agent_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('cumulative_tokens_input')->default(0);
            $table->unsignedBigInteger('cumulative_tokens_output')->default(0);
            $table->unsignedInteger('cumulative_messages')->default(0);
            $table->unsignedInteger('cumulative_sessions')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_daily_stats');
    }
};
