<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Console\Command;

class CleanupHetznerCommand extends Command
{
    protected $signature = 'hetzner:cleanup';

    protected $description = 'Delete all Hetzner servers and volumes referenced in the database';

    public function handle(HetznerService $hetzner): int
    {
        $servers = Server::query()
            ->whereNotNull('provider_server_id')
            ->get();

        if ($servers->isEmpty()) {
            $this->info('No Hetzner servers found.');

            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            $this->info("Deleting Hetzner server {$server->provider_server_id} ({$server->name})...");

            try {
                $hetzner->deleteServer($server->provider_server_id);
                $this->info('  Server deleted.');
            } catch (\Throwable $e) {
                $this->warn("  Failed to delete server: {$e->getMessage()}");
            }

            if ($server->provider_volume_id) {
                $this->info("  Detaching volume {$server->provider_volume_id}...");

                try {
                    $hetzner->detachVolume($server->provider_volume_id);
                    sleep(5);
                    $hetzner->deleteVolume($server->provider_volume_id);
                    $this->info('  Volume deleted.');
                } catch (\Throwable $e) {
                    $this->warn("  Failed to delete volume: {$e->getMessage()}");
                }
            }
        }

        $this->info('Hetzner cleanup complete.');

        return self::SUCCESS;
    }
}
