<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->timestamp('claude_connected_at')->nullable();
            $table->timestamp('claude_token_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['claude_connected_at', 'claude_token_expires_at']);
        });
    }
};
