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
        Schema::table('agent_templates', function (Blueprint $table) {
            $table->json('recommended_tools')->nullable()->after('model_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_templates', function (Blueprint $table) {
            $table->dropColumn('recommended_tools');
        });
    }
};
