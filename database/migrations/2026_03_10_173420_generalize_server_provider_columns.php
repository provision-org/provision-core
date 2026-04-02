<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->renameColumn('hetzner_server_id', 'provider_server_id');
            $table->renameColumn('hetzner_volume_id', 'provider_volume_id');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('cloud_provider')->default('hetzner')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('cloud_provider');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->renameColumn('provider_server_id', 'hetzner_server_id');
            $table->renameColumn('provider_volume_id', 'hetzner_volume_id');
        });
    }
};
