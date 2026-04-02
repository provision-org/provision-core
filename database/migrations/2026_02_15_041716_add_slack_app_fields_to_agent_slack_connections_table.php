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
            $table->string('slack_app_id')->nullable()->after('agent_id');
            $table->text('client_id')->nullable()->after('slack_bot_user_id');
            $table->text('client_secret')->nullable()->after('client_id');
            $table->text('signing_secret')->nullable()->after('client_secret');
            $table->string('oauth_state')->nullable()->after('signing_secret');
            $table->boolean('is_automated')->default(false)->after('oauth_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_slack_connections', function (Blueprint $table) {
            $table->dropColumn([
                'slack_app_id',
                'client_id',
                'client_secret',
                'signing_secret',
                'oauth_state',
                'is_automated',
            ]);
        });
    }
};
