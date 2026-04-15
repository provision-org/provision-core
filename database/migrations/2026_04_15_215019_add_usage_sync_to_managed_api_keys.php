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
        Schema::table('managed_api_keys', function (Blueprint $table) {
            $table->integer('credit_limit_cents')->nullable()->after('name');
            $table->integer('last_synced_usage_cents')->default(0)->after('credit_limit_cents');
            $table->timestamp('last_synced_at')->nullable()->after('last_synced_usage_cents');
        });
    }

    public function down(): void
    {
        Schema::table('managed_api_keys', function (Blueprint $table) {
            $table->dropColumn(['credit_limit_cents', 'last_synced_usage_cents', 'last_synced_at']);
        });
    }
};
