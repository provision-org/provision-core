<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('upstream_id')->nullable()->after('role');
            $table->unique(
                ['chat_conversation_id', 'upstream_id'],
                'chat_messages_conversation_upstream_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique('chat_messages_conversation_upstream_unique');
            $table->dropColumn('upstream_id');
        });
    }
};
