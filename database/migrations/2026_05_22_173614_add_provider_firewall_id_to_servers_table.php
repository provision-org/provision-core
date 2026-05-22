<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist the cloud-provider firewall ID for each server so the destroy
     * paths (DestroyTeamJob) can release it. Without this, every provisioned
     * DigitalOcean droplet leaves an orphan firewall behind on team
     * deletion. Fixes issue #37.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->string('provider_firewall_id')
                ->nullable()
                ->after('provider_volume_id');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('provider_firewall_id');
        });
    }
};
