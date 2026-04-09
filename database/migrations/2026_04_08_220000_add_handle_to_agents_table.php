<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('handle')->nullable()->after('name');
            $table->unique(['team_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'handle']);
            $table->dropColumn('handle');
        });
    }
};
