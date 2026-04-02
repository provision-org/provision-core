<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('cloud_provider')->default('linode')->after('plan');
        });

        // Backfill existing teams to hetzner
        DB::table('teams')->whereNotNull('id')->update(['cloud_provider' => 'hetzner']);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('cloud_provider');
        });
    }
};
