<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('auth_provider', 20)->default('openrouter')->after('model_fallbacks');
            $table->string('chatgpt_email')->nullable()->after('auth_provider');
            $table->string('chatgpt_plan_type', 32)->nullable()->after('chatgpt_email');
            $table->string('chatgpt_account_id')->nullable()->after('chatgpt_plan_type');
            $table->timestamp('chatgpt_connected_at')->nullable()->after('chatgpt_account_id');
            $table->timestamp('chatgpt_token_expires_at')->nullable()->after('chatgpt_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'auth_provider',
                'chatgpt_email',
                'chatgpt_plan_type',
                'chatgpt_account_id',
                'chatgpt_connected_at',
                'chatgpt_token_expires_at',
            ]);
        });
    }
};
