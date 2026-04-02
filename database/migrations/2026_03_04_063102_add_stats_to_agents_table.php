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
        Schema::table('agents', function (Blueprint $table) {
            $table->unsignedInteger('stats_total_sessions')->default(0);
            $table->unsignedInteger('stats_total_messages')->default(0);
            $table->unsignedBigInteger('stats_tokens_input')->default(0);
            $table->unsignedBigInteger('stats_tokens_output')->default(0);
            $table->timestamp('stats_last_active_at')->nullable();
            $table->timestamp('stats_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'stats_total_sessions',
                'stats_total_messages',
                'stats_tokens_input',
                'stats_tokens_output',
                'stats_last_active_at',
                'stats_synced_at',
            ]);
        });
    }
};
