<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CleanupOrphanedDroplets extends Command
{
    protected $signature = 'app:cleanup-orphaned-droplets {--delete} {--droplet-id=} {--volume-id=}';

    protected $description = 'List and delete orphaned DigitalOcean droplets not tracked in the database';

    public function handle(): int
    {
        try {
            $token = config('cloud.digitalocean.api_token');

            if (! $token) {
                $this->error('DIGITALOCEAN_API_TOKEN not configured.');

                return self::FAILURE;
            }

            $this->info('Token found: '.substr($token, 0, 8).'...');

            $http = Http::baseUrl('https://api.digitalocean.com/v2')
                ->withToken($token)
                ->acceptJson();

            // If a specific volume ID is provided, just delete it
            if ($volumeId = $this->option('volume-id')) {
                $this->info("Deleting volume {$volumeId}...");
                $http->delete("/volumes/{$volumeId}")->throw();
                $this->info("Volume {$volumeId} deleted.");

                return self::SUCCESS;
            }

            // If a specific droplet ID is provided, just delete it
            if ($dropletId = $this->option('droplet-id')) {
                return $this->deleteDroplet($http, $dropletId);
            }

            // List all droplets
            $response = $http->get('/droplets', ['per_page' => 200]);
            $response->throw();

            $droplets = $response->json('droplets', []);

            if (empty($droplets)) {
                $this->info('No droplets found on DigitalOcean.');

                return self::SUCCESS;
            }

            // Get all known provider IDs from our DB
            $knownProviderIds = Server::whereNotNull('provider_server_id')
                ->pluck('provider_server_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $this->info('DigitalOcean Droplets:');
            $this->newLine();

            $orphaned = [];

            foreach ($droplets as $droplet) {
                $id = (string) $droplet['id'];
                $name = $droplet['name'];
                $status = $droplet['status'];
                $ip = collect($droplet['networks']['v4'] ?? [])
                    ->firstWhere('type', 'public')['ip_address'] ?? 'N/A';
                $tracked = in_array($id, $knownProviderIds);

                $label = $tracked ? 'TRACKED' : 'ORPHANED';

                $this->info("  [{$label}] #{$id} | {$name} | {$status} | IP: {$ip}");

                if (! $tracked) {
                    $orphaned[] = $droplet;
                }
            }

            $this->newLine();

            if (empty($orphaned)) {
                $this->info('No orphaned droplets found.');

                return self::SUCCESS;
            }

            $this->warn(count($orphaned).' orphaned droplet(s) found.');

            if (! $this->option('delete')) {
                $this->info('Run with --delete to remove orphaned droplets, or --droplet-id=ID to remove a specific one.');

                return self::SUCCESS;
            }

            foreach ($orphaned as $droplet) {
                $this->deleteDroplet($http, (string) $droplet['id']);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Exception: '.$e->getMessage());
            $this->error('File: '.$e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }
    }

    private function deleteDroplet($http, string $dropletId): int
    {
        $this->info("Deleting droplet #{$dropletId}...");

        try {
            // Get droplet info first
            $response = $http->get("/droplets/{$dropletId}");
            $response->throw();
            $droplet = $response->json('droplet');
            $this->info("  Name: {$droplet['name']} | Status: {$droplet['status']}");

            // Check for attached volumes
            $volumeIds = $droplet['volume_ids'] ?? [];
            if (! empty($volumeIds)) {
                $this->info('  Attached volumes: '.implode(', ', $volumeIds));
            }

            // Delete the droplet
            $http->delete("/droplets/{$dropletId}")->throw();
            $this->info("  Droplet #{$dropletId} deleted.");

            // Delete attached volumes
            foreach ($volumeIds as $volumeId) {
                $this->info("  Deleting volume {$volumeId}...");

                try {
                    $http->delete("/volumes/{$volumeId}")->throw();
                    $this->info("  Volume {$volumeId} deleted.");
                } catch (\Exception $e) {
                    $this->warn("  Failed to delete volume {$volumeId}: {$e->getMessage()}");
                }
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to delete droplet #{$dropletId}: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
