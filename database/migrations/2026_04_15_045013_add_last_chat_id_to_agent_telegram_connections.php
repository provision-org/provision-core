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
        Schema::table('agent_telegram_connections', function (Blueprint $table) {
            $table->string('last_chat_id')->nullable()->after('dm_policy');
        });
    }

    public function down(): void
    {
        Schema::table('agent_telegram_connections', function (Blueprint $table) {
            $table->dropColumn('last_chat_id');
        });
    }
};
