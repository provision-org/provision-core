<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Console\Command;

class HetznerServersCommand extends Command
{
    protected $signature = 'hetzner:servers';

    protected $description = 'List all active Hetzner servers with their attached volumes';

    public function handle(HetznerService $hetzner): int
    {
        $data = $hetzner->listServers();
        $servers = $data['servers'] ?? [];

        if (empty($servers)) {
            $this->info('No servers found on Hetzner.');

            return self::SUCCESS;
        }

        $volumes = collect(($hetzner->listVolumes())['volumes'] ?? [])
            ->keyBy('id');

        $rows = [];

        foreach ($servers as $s) {
            $ip = $s['public_net']['ipv4']['ip'] ?? '-';
            $attachedVols = collect($s['volumes'] ?? [])
                ->map(fn ($vId) => $volumes->has($vId)
                    ? "{$vId} ({$volumes[$vId]['name']}, {$volumes[$vId]['size']}GB)"
                    : (string) $vId
                )
                ->implode(', ') ?: '-';

            $localServer = Server::query()
                ->where('provider_server_id', $s['id'])
                ->first();

            $rows[] = [
                $s['id'],
                $s['name'],
                $s['status'],
                $s['server_type']['name'] ?? '-',
                $s['datacenter']['name'] ?? '-',
                $ip,
                $attachedVols,
                $localServer ? $localServer->id : 'orphaned',
            ];
        }

        $this->table(
            ['Hetzner ID', 'Name', 'Status', 'Type', 'DC', 'IPv4', 'Volumes', 'Local DB'],
            $rows,
        );

        $orphanedVolumes = $volumes->filter(fn ($v) => $v['server'] === null);
        if ($orphanedVolumes->isNotEmpty()) {
            $this->newLine();
            $this->warn('Detached volumes:');
            $this->table(
                ['Volume ID', 'Name', 'Size'],
                $orphanedVolumes->map(fn ($v) => [$v['id'], $v['name'], "{$v['size']}GB"])->values()->all(),
            );
        }

        return self::SUCCESS;
    }
}
