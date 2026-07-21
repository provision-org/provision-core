<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('client_message_id', 100)->nullable()->after('upstream_id')->unique();
            $table->ulid('reply_to_message_id')->nullable()->after('client_message_id')->index();
            $table->timestamp('enqueued_at')->nullable()->after('reply_to_message_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique(['client_message_id']);
            $table->dropIndex(['reply_to_message_id']);
            $table->dropIndex(['enqueued_at']);
            $table->dropColumn(['client_message_id', 'reply_to_message_id', 'enqueued_at']);
        });
    }
};
