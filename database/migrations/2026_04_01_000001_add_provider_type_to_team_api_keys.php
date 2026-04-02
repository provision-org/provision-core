<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_api_keys', function (Blueprint $table) {
            $table->string('provider_type')->default('llm')->after('team_id');
        });

        Schema::table('team_api_keys', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropUnique(['team_id', 'provider']);
            $table->unique(['team_id', 'provider_type', 'provider']);
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_api_keys', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropUnique(['team_id', 'provider_type', 'provider']);
            $table->unique(['team_id', 'provider']);
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->dropColumn('provider_type');
        });
    }
};
