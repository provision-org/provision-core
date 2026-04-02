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
        Schema::table('teams', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('name');
            $table->string('company_url', 500)->nullable()->after('company_name');
            $table->text('company_description')->nullable()->after('company_url');
            $table->string('target_market', 500)->nullable()->after('company_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_url', 'company_description', 'target_market']);
        });
    }
};
