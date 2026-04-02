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
        Schema::table('agents', function (Blueprint $table) {
            $table->string('harness_type', 20)->default('openclaw')->after('server_id');
            $table->renameColumn('openclaw_agent_id', 'harness_agent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->renameColumn('harness_agent_id', 'openclaw_agent_id');
            $table->dropColumn('harness_type');
        });
    }
};
