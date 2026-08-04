<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropUnique(['session_key']);
            $table->unique(['agent_id', 'session_key']);
            $table->string('source')->default('dashboard')->after('session_key');
            $table->string('source_channel')->nullable()->after('source');
            $table->boolean('is_read_only')->default(false)->after('source_channel');
            $table->timestamp('last_reconciled_at')->nullable()->after('last_message_at');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('upstream_event_sequence')->nullable()->after('upstream_run_id');
            $table->timestamp('last_gateway_event_at')->nullable()->after('upstream_event_sequence')->index();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('daemon_version')->nullable()->after('daemon_token');
            $table->json('daemon_capabilities')->nullable()->after('daemon_version');
            $table->json('daemon_active_runs')->nullable()->after('daemon_capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['daemon_version', 'daemon_capabilities', 'daemon_active_runs']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['last_gateway_event_at']);
            $table->dropColumn(['upstream_event_sequence', 'last_gateway_event_at']);
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropUnique(['agent_id', 'session_key']);
            $table->unique('session_key');
            $table->dropColumn(['source', 'source_channel', 'is_read_only', 'last_reconciled_at']);
        });
    }
};
