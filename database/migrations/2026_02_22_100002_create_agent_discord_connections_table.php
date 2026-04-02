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
        Schema::create('agent_discord_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->text('token')->nullable();
            $table->string('bot_username')->nullable();
            $table->string('application_id')->nullable();
            $table->string('guild_id')->nullable();
            $table->string('status')->default('disconnected');
            $table->string('dm_policy')->default('off');
            $table->string('group_policy')->default('allowlist');
            $table->boolean('require_mention')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_discord_connections');
    }
};
