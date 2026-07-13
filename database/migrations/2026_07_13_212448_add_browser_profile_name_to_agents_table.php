<?php

use App\Models\Agent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Frozen browser profile identifier. Set once at install and never
            // recomputed, so renaming an agent can't drift the systemd units,
            // Caddy route, and OpenClaw profile key apart. Nullable: agents that
            // never provisioned a browser display leave it null.
            $table->string('browser_profile_name')->nullable()->after('browser_display_num');
        });

        // Backfill existing agents with the value their server already uses —
        // the current name-derived profile name — so nothing changes for agents
        // that haven't been renamed since install.
        Agent::query()
            ->whereNotNull('browser_display_num')
            ->whereNull('browser_profile_name')
            ->chunkById(200, function ($agents) {
                foreach ($agents as $agent) {
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', (string) $agent->name));
                    $prefix = $agent->harness_type?->value === 'hermes' ? 'hermes-' : 'agent-';
                    $agent->updateQuietly(['browser_profile_name' => $prefix.$slug]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('browser_profile_name');
        });
    }
};
