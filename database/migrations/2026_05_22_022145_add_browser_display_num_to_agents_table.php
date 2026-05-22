<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist the per-agent Xvfb display number assigned at install time so the
     * gateway-config rebuild path (AgentUpdateScriptService) can re-emit
     * `browser.profiles` with the correct CDP URL without scanning systemd
     * units on the server.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->unsignedInteger('browser_display_num')
                ->nullable()
                ->after('harness_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn('browser_display_num');
        });
    }
};
