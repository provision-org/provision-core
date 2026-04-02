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
        Schema::table('agent_slack_connections', function (Blueprint $table) {
            $table->string('dm_policy')->default('open')->after('is_automated');
            $table->string('group_policy')->default('open')->after('dm_policy');
            $table->boolean('require_mention')->default(false)->after('group_policy');
            $table->string('reply_to_mode')->default('off')->after('require_mention');
            $table->string('dm_session_scope')->default('main')->after('reply_to_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_slack_connections', function (Blueprint $table) {
            $table->dropColumn([
                'dm_policy',
                'group_policy',
                'require_mention',
                'reply_to_mode',
                'dm_session_scope',
            ]);
        });
    }
};
