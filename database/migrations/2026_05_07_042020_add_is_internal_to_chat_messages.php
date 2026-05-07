<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_internal flags messages that should not render in the chat UI but
 * still flow to the agent — used by the silent-kickoff prompt that asks
 * the agent to introduce itself when a user lands on chat for the first
 * time after creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
