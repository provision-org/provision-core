<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original chat_conversations migration declared user_id as foreignId
 * (BIGINT), but users use ULIDs. Inserts truncated to zero and crashed every
 * chat send with "Data truncated for column 'user_id'".
 *
 * Switch user_id to CHAR(26) and re-link the foreign key + index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            return;
        }

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'user_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->char('user_id', 26)->change();
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['agent_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            return;
        }

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'user_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->change();
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['agent_id', 'user_id']);
        });
    }
};
