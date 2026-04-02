<?php

namespace App\Console\Commands;

use App\Enums\CloudProvider;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Provision\Billing\Models\ManagedOpenRouterKey;
use Provision\Billing\Services\OpenRouterProvisioningService;
use Provision\MailboxKit\Services\MailboxKitService;

class ResetTestEnvironmentCommand extends Command
{
    protected $signature = 'app:reset-test-env {--force : Skip confirmation}';

    protected $description = 'Reset all external resources (Hetzner, DigitalOcean, MailboxKit, OpenRouter) and the local database';

    public function handle(
        HetznerService $hetzner,
        DigitalOceanService $digitalOcean,
    ): int {
        if (app()->isProduction()) {
            $this->error('This command cannot be run in production.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This will delete ALL servers, volumes, inboxes, API keys, and reset the database. Continue?')) {
            return self::SUCCESS;
        }

        $this->deleteHetznerResources($hetzner);
        $this->deleteDigitalOceanResources($digitalOcean);

        if (class_exists(MailboxKitService::class)) {
            $this->deleteMailboxKitInboxes(app(MailboxKitService::class));
        }

        if (class_exists(OpenRouterProvisioningService::class)) {
            $this->deleteOpenRouterKeys(app(OpenRouterProvisioningService::class));
        }

        $this->resetDatabase();

        $this->newLine();
        $this->info('Test environment reset complete.');

        return self::SUCCESS;
    }

    private function deleteHetznerResources(HetznerService $hetzner): void
    {
        $this->components->info('Cleaning up Hetzner servers and volumes...');

        $servers = Server::query()->whereNotNull('provider_server_id')->get();

        if ($servers->isEmpty()) {
            $this->line('  No Hetzner servers found.');

            return;
        }

        foreach ($servers as $server) {
            try {
                $hetzner->deleteServer($server->provider_server_id);
                $this->line("  Deleted server {$server->provider_server_id} ({$server->name})");
            } catch (\Throwable $e) {
                $this->warn("  Failed to delete server {$server->provider_server_id}: {$e->getMessage()}");
            }

            if ($server->provider_volume_id) {
                sleep(5);

                try {
                    $hetzner->detachVolume($server->provider_volume_id);
                } catch (\Throwable) {
                    // Volume may already be detached
                }

                try {
                    $hetzner->deleteVolume($server->provider_volume_id);
                    $this->line("  Deleted volume {$server->provider_volume_id}");
                } catch (\Throwable $e) {
                    $this->warn("  Failed to delete volume {$server->provider_volume_id}: {$e->getMessage()}");
                }
            }
        }
    }

    private function deleteDigitalOceanResources(DigitalOceanService $digitalOcean): void
    {
        $this->components->info('Cleaning up DigitalOcean droplets and volumes...');

        $servers = Server::query()
            ->where('cloud_provider', CloudProvider::DigitalOcean)
            ->whereNotNull('provider_server_id')
            ->get();

        if ($servers->isEmpty()) {
            $this->line('  No DigitalOcean servers found.');

            return;
        }

        foreach ($servers as $server) {
            try {
                $digitalOcean->deleteDroplet($server->provider_server_id);
                $this->line("  Deleted droplet {$server->provider_server_id} ({$server->name})");
            } catch (\Throwable $e) {
                $this->warn("  Failed to delete droplet {$server->provider_server_id}: {$e->getMessage()}");
            }

            if ($server->provider_volume_id) {
                sleep(5);

                try {
                    $digitalOcean->deleteVolume($server->provider_volume_id);
                    $this->line("  Deleted volume {$server->provider_volume_id}");
                } catch (\Throwable $e) {
                    $this->warn("  Failed to delete volume {$server->provider_volume_id}: {$e->getMessage()}");
                }
            }
        }
    }

    private function deleteMailboxKitInboxes(MailboxKitService $mailboxKit): void
    {
        $this->components->info('Cleaning up MailboxKit inboxes...');

        try {
            $inboxes = $mailboxKit->listInboxes();
            $items = $inboxes['data'] ?? [];

            if (empty($items)) {
                $this->line('  No inboxes found.');

                return;
            }

            foreach ($items as $inbox) {
                try {
                    $mailboxKit->deleteInbox($inbox['id']);
                    $label = $inbox['email_address'] ?? $inbox['name'] ?? $inbox['id'];
                    $this->line("  Deleted inbox {$inbox['id']} ({$label})");
                } catch (RequestException $e) {
                    if ($e->response->status() === 404) {
                        continue; // Already deleted
                    }
                    $this->warn("  Failed to delete inbox {$inbox['id']}: ".$e->getMessage());
                } catch (\Throwable $e) {
                    $this->warn("  Failed to delete inbox {$inbox['id']}: ".$e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->warn("  Failed to list inboxes: {$e->getMessage()}");
        }
    }

    private function deleteOpenRouterKeys(object $openRouter): void
    {
        $this->components->info('Cleaning up OpenRouter managed keys...');

        $managedKeyClass = ManagedOpenRouterKey::class;
        $keys = $managedKeyClass::all();

        if ($keys->isEmpty()) {
            $this->line('  No managed keys found.');

            return;
        }

        foreach ($keys as $key) {
            try {
                $openRouter->deleteKey($key->openrouter_key_hash);
                $this->line("  Deleted key {$key->openrouter_key_hash}");
            } catch (\Throwable $e) {
                $this->warn("  Failed to delete key: {$e->getMessage()}");
            }
        }
    }

    private function resetDatabase(): void
    {
        $this->components->info('Resetting database...');
        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }
}
