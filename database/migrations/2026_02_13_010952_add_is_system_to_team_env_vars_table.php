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
        Schema::table('team_env_vars', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_env_vars', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
