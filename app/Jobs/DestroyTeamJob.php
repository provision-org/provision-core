<?php

namespace App\Jobs;

use App\Enums\CloudProvider;
use App\Models\Team;
use App\Services\AwsService;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\OpenRouterKeyService;
use App\Services\SlackAppCleanupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Provision\MailboxKit\Services\MailboxKitService;

class DestroyTeamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Team $team) {}

    public function handle(
        SlackAppCleanupService $slackCleanup,
    ): void {
        $team = $this->team;

        Log::info("Destroying team {$team->id} ({$team->name})");

        // Resolve MailboxKitService only when the module is installed
        $mailboxKitService = class_exists(MailboxKitService::class)
            ? app(MailboxKitService::class)
            : null;

        // Clean up each agent's external resources
        foreach ($team->agents()->with(['emailConnection', 'slackConnection'])->get() as $agent) {
            $this->cleanupAgent($agent, $mailboxKitService, $slackCleanup);
        }

        // Revoke OpenRouter managed key
        $managedKey = $team->managedApiKey;
        if ($managedKey?->openrouter_key_hash) {
            try {
                app(OpenRouterKeyService::class)->deleteKey($managedKey->openrouter_key_hash);
                $managedKey->delete();
                Log::info("Revoked OpenRouter key for team {$team->id}");
            } catch (\Throwable $e) {
                Log::warning("Failed to revoke OpenRouter key for team {$team->id}: {$e->getMessage()}");
            }
        }

        // Destroy cloud server and volume
        $server = $team->server;
        if ($server?->provider_server_id) {
            $this->destroyCloudResources($server);
        }

        // Delete the team (cascades to agents, server, keys, etc.)
        $team->delete();

        Log::info("Team {$team->id} destroyed");
    }

    private function cleanupAgent($agent, ?object $mailboxKitService, SlackAppCleanupService $slackCleanup): void
    {
        // Slack app cleanup
        try {
            $slackCleanup->cleanup($agent);
        } catch (\Throwable $e) {
            Log::warning("Failed Slack cleanup for agent {$agent->id}: {$e->getMessage()}");
        }

        // MailboxKit inbox + webhook cleanup
        $emailConnection = $agent->emailConnection;
        if ($mailboxKitService && $emailConnection?->mailboxkit_inbox_id) {
            try {
                $mailboxKitService->deleteInbox($emailConnection->mailboxkit_inbox_id);
            } catch (\Throwable $e) {
                Log::warning("Failed to delete MailboxKit inbox for agent {$agent->id}: {$e->getMessage()}");
            }

            if ($emailConnection->mailboxkit_webhook_id) {
                try {
                    $mailboxKitService->deleteWebhook($emailConnection->mailboxkit_webhook_id);
                } catch (\Throwable $e) {
                    Log::warning("Failed to delete MailboxKit webhook for agent {$agent->id}: {$e->getMessage()}");
                }
            }
        }
    }

    private function destroyCloudResources($server): void
    {
        try {
            match ($server->cloud_provider) {
                CloudProvider::Hetzner => $this->destroyHetzner($server),
                CloudProvider::DigitalOcean => $this->destroyDigitalOcean($server),
                CloudProvider::Linode => $this->destroyLinode($server),
                CloudProvider::Aws => $this->destroyAws($server),
            };
        } catch (\Throwable $e) {
            Log::error("Failed to destroy cloud resources for server {$server->id}: {$e->getMessage()}");
        }
    }

    private function destroyHetzner($server): void
    {
        /** @var HetznerService $hetzner */
        $hetzner = app(CloudServiceFactory::class)->makeFor($this->team, CloudProvider::Hetzner);

        $hetzner->deleteServer($server->provider_server_id);
        Log::info("Deleted Hetzner server {$server->provider_server_id}");

        if ($server->provider_volume_id) {
            $hetzner->deleteVolume($server->provider_volume_id);
            Log::info("Deleted Hetzner volume {$server->provider_volume_id}");
        }
    }

    private function destroyDigitalOcean($server): void
    {
        /** @var DigitalOceanService $do */
        $do = app(CloudServiceFactory::class)->makeFor($this->team, CloudProvider::DigitalOcean);

        $do->deleteDroplet($server->provider_server_id);
        Log::info("Deleted DO droplet {$server->provider_server_id}");

        if ($server->provider_volume_id) {
            // DO returns 204 on droplet DELETE immediately but the volume
            // detach finishes asynchronously, so a follow-up volume DELETE
            // can race in with 422 "still attached". Retry with backoff;
            // treat 404 as already-gone.
            $this->deleteDoVolumeWithRetry($do, $server->provider_volume_id);
        }

        // Release the per-server firewall created during provisioning.
        // Without this, every destroyed team leaves an orphan firewall
        // behind in DO — see issue #37.
        if ($server->provider_firewall_id) {
            try {
                $do->deleteFirewall($server->provider_firewall_id);
                Log::info("Deleted DO firewall {$server->provider_firewall_id}");
            } catch (RequestException $e) {
                // Non-fatal: log and move on. Cleanup command can sweep
                // any survivors later.
                Log::warning("Failed to delete DO firewall {$server->provider_firewall_id}: {$e->getMessage()}");
            }
        }
    }

    private function deleteDoVolumeWithRetry(DigitalOceanService $do, string $volumeId): void
    {
        $delays = [2, 4, 8, 16]; // up to ~30s of patience for the detach

        foreach ($delays as $i => $delay) {
            try {
                $do->deleteVolume($volumeId);
                Log::info("Deleted DO volume {$volumeId}".($i > 0 ? " (after {$i} retries)" : ''));

                return;
            } catch (RequestException $e) {
                $status = $e->response?->status();

                if ($status === 404) {
                    Log::info("DO volume {$volumeId} already gone (404)");

                    return;
                }

                $isLast = $i === count($delays) - 1;
                if ($status !== 422 || $isLast) {
                    Log::warning("Failed to delete DO volume {$volumeId} (status {$status}): {$e->getMessage()}");

                    return;
                }

                Log::info("DO volume {$volumeId} still detaching; retrying in {$delay}s");
                sleep($delay);
            }
        }
    }

    private function destroyAws($server): void
    {
        /** @var AwsService $aws */
        $aws = app(CloudServiceFactory::class)->makeFor($this->team, CloudProvider::Aws);

        $aws->terminateInstance($server->provider_server_id);
        Log::info("Terminated AWS instance {$server->provider_server_id}");

        // Release the per-server security group created during provisioning.
        // deleteSecurityGroup already retries DependencyViolation (the group
        // can't be deleted while the instance is still terminating) and
        // treats a missing group as already-gone.
        if ($server->provider_firewall_id) {
            try {
                $aws->deleteSecurityGroup($server->provider_firewall_id);
                Log::info("Deleted AWS security group {$server->provider_firewall_id}");
            } catch (\Throwable $e) {
                // Non-fatal: log and move on. Cleanup command can sweep
                // any survivors later.
                Log::warning("Failed to delete AWS security group {$server->provider_firewall_id}: {$e->getMessage()}");
            }
        }
    }

    private function destroyLinode($server): void
    {
        /** @var LinodeService $linode */
        $linode = app(CloudServiceFactory::class)->makeFor($this->team, CloudProvider::Linode);

        $linode->deleteInstance($server->provider_server_id);
        Log::info("Deleted Linode instance {$server->provider_server_id}");

        if ($server->provider_volume_id) {
            $linode->detachVolume($server->provider_volume_id);
            $linode->deleteVolume($server->provider_volume_id);
            Log::info("Deleted Linode volume {$server->provider_volume_id}");
        }
    }
}
