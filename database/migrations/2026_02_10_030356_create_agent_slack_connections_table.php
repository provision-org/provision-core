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
        Schema::create('agent_slack_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->text('bot_token')->nullable();
            $table->text('app_token')->nullable();
            $table->string('status')->default('disconnected');
            $table->json('allowed_channels')->nullable();
            $table->string('slack_team_id')->nullable();
            $table->string('slack_bot_user_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_slack_connections');
    }
};
