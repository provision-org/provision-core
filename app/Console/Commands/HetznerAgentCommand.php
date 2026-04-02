<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\HetznerService;
use Illuminate\Console\Command;

class HetznerAgentCommand extends Command
{
    protected $signature = 'hetzner:agent {agent : The agent ID or name}';

    protected $description = 'Show Hetzner server details for a given agent';

    public function handle(HetznerService $hetzner): int
    {
        $search = $this->argument('agent');

        $agent = Agent::query()
            ->where('id', $search)
            ->orWhere('name', $search)
            ->first();

        if (! $agent) {
            $this->error("Agent not found: {$search}");

            return self::FAILURE;
        }

        $this->info("Agent: {$agent->name} (ID: {$agent->id})");
        $this->info("Status: {$agent->status->value}");
        $this->info("Team: {$agent->team->name} (ID: {$agent->team_id})");

        $server = $agent->server;

        if (! $server) {
            $this->warn('No server assigned to this agent.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('--- Local Server Record ---');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $server->id],
                ['Name', $server->name],
                ['Status', $server->status->value],
                ['Provider Server ID', $server->provider_server_id ?? '-'],
                ['Provider Volume ID', $server->provider_volume_id ?? '-'],
                ['IPv4', $server->ipv4_address ?? '-'],
                ['Type', $server->server_type ?? '-'],
                ['Region', $server->region ?? '-'],
                ['Provisioned At', $server->provisioned_at?->toDateTimeString() ?? '-'],
            ],
        );

        if (! $server->provider_server_id) {
            $this->warn('No provider server ID — cannot fetch remote details.');

            return self::SUCCESS;
        }

        try {
            $remote = $hetzner->getServer($server->provider_server_id);
            $rs = $remote['server'];

            $this->newLine();
            $this->info('--- Hetzner Remote ---');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Status', $rs['status']],
                    ['Type', $rs['server_type']['name'] ?? '-'],
                    ['Datacenter', $rs['datacenter']['name'] ?? '-'],
                    ['IPv4', $rs['public_net']['ipv4']['ip'] ?? '-'],
                    ['Created', $rs['created'] ?? '-'],
                    ['Volumes', implode(', ', $rs['volumes'] ?? []) ?: '-'],
                ],
            );
        } catch (\Throwable $e) {
            $this->error("Failed to fetch from Hetzner API: {$e->getMessage()}");
        }

        return self::SUCCESS;
    }
}
