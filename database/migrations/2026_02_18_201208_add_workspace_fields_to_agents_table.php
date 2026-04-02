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
            $table->text('soul')->nullable()->after('identity');
            $table->text('tools_config')->nullable()->after('soul');
            $table->text('user_context')->nullable()->after('tools_config');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['soul', 'tools_config', 'user_context']);
        });
    }
};
