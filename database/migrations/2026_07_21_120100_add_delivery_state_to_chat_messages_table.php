<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('delivery_status')->nullable()->after('outbound_to_agent_at')->index();
            $table->string('upstream_run_id')->nullable()->after('delivery_status')->index();
            $table->text('delivery_error')->nullable()->after('upstream_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['delivery_status']);
            $table->dropIndex(['upstream_run_id']);
            $table->dropColumn(['delivery_status', 'upstream_run_id', 'delivery_error']);
        });
    }
};
