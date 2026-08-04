<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('openclaw_session_discoveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('session_key');
            $table->string('kind', 32)->default('unknown');
            $table->string('channel', 64)->nullable();
            $table->string('chat_type', 64)->nullable();
            $table->string('title')->nullable();
            $table->text('preview')->nullable();
            $table->boolean('has_active_run')->default(false);
            $table->json('active_run_ids')->nullable();
            $table->timestamp('upstream_updated_at')->nullable();
            $table->timestamp('discovered_at');
            $table->foreignUlid('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['server_id', 'session_key'], 'openclaw_discoveries_server_session_unique');
            $table->index(['agent_id', 'claimed_by_user_id'], 'openclaw_discoveries_agent_claimed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openclaw_session_discoveries');
    }
};
