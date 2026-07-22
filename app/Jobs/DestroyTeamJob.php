<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Enums\CloudProvider;
use App\Enums\ServerStatus;
use App\Models\AgentApiToken;
use App\Models\Team;
use App\Services\AwsService;
use App\Services\CloudServiceFactory;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\OpenRouterKeyService;
use App\Services\PublishArtifactService;
use App\Services\SlackAppCleanupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Provision\MailboxKit\Services\MailboxKitService;

class DestroyTeamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Teardown waits for the cloud instance to fully terminate before releasing
    // its firewall/security group (the network interface only detaches at
    // "terminated"), so allow generous headroom for a slow provider.
    public int $timeout = 600;

    public function __construct(public Team $team) {}

    public function handle(
        SlackAppCleanupService $slackCleanup,
        PublishArtifactService $artifacts,
    ): void {
        $team = $this->team;

        Log::info("Destroying team {$team->id} ({$team->name})");

        // Fence publishing before cleanup takes the shared server lock. This
        // also covers direct/CLI dispatches that bypass the web controller.
        $team->server?->update(['status' => ServerStatus::Destroying]);
        $team->agents()->update(['status' => AgentStatus::Paused->value]);
        AgentApiToken::query()->where('team_id', $team->id)->delete();

        // Resolve MailboxKitService only when the module is installed
        $mailboxKitService = class_exists(MailboxKitService::class)
            ? app(MailboxKitService::class)
            : null;

        $artifactCleanupFailures = [];

        // Clean up each agent's external resources
        foreach ($team->agents()->with(['emailConnection', 'slackConnection'])->get() as $agent) {
            try {
                // DNS cleanup is mandatory before releasing the server IP.
                // Server-local failures are logged because destroying the
                // whole server below is the definitive local cleanup.
                $artifacts->teardownAgent($agent, requireServerCleanup: false);
            } catch (\Throwable $e) {
                $artifactCleanupFailures[] = $e;
                Log::warning("Artifact cleanup on team destroy failed for agent {$agent->id}: {$e->getMessage()}");
            }

            $this->cleanupAgent($agent, $mailboxKitService, $slackCleanup);
        }

        if ($artifactCleanupFailures !== []) {
            throw new \RuntimeException('Artifact DNS cleanup failed; team retained for retry.', previous: $artifactCleanupFailures[0]);
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
            if (! $this->destroyCloudResources($server)) {
                throw new \RuntimeException("Cloud resource teardown failed for server {$server->id}; team retained for retry.");
            }
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

    private function destroyCloudResources($server): bool
    {
        try {
            match ($server->cloud_provider) {
                CloudProvider::Hetzner => $this->destroyHetzner($server),
                CloudProvider::DigitalOcean => $this->destroyDigitalOcean($server),
                CloudProvider::Linode => $this->destroyLinode($server),
                CloudProvider::Aws => $this->destroyAws($server),
            };

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to destroy cloud resources for server {$server->id}: {$e->getMessage()}");

            return false;
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

        try {
            $do->deleteDroplet($server->provider_server_id);
            Log::info("Deleted DO droplet {$server->provider_server_id}");
        } catch (RequestException $e) {
            if ($e->response?->status() !== 404) {
                throw $e;
            }

            Log::info("DO droplet {$server->provider_server_id} already gone (404)");
        }

        $volumeFailure = null;

        if ($server->provider_volume_id) {
            // DO returns 204 on droplet DELETE immediately but the volume
            // detach finishes asynchronously, so a follow-up volume DELETE
            // can race in with 409/422 "still attached". Retry with backoff;
            // treat 404 as already-gone.
            try {
                $this->deleteDoVolumeWithRetry($do, $server->provider_volume_id);
            } catch (\Throwable $e) {
                $volumeFailure = $e;
            }
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

        if ($volumeFailure) {
            throw $volumeFailure;
        }
    }

    private function deleteDoVolumeWithRetry(DigitalOceanService $do, string $volumeId): void
    {
        $delays = [2, 4, 8, 16]; // up to ~30s of patience for the detach
        $retryCount = 0;

        while (true) {
            try {
                $do->deleteVolume($volumeId);
                Log::info("Deleted DO volume {$volumeId}".($retryCount > 0 ? " (after {$retryCount} retries)" : ''));

                return;
            } catch (RequestException $e) {
                $status = $e->response?->status();

                if ($status === 404) {
                    Log::info("DO volume {$volumeId} already gone (404)");

                    return;
                }

                $delay = $delays[$retryCount] ?? null;
                $isDetachRace = in_array($status, [409, 422], true);

                if (! $isDetachRace || $delay === null) {
                    Log::warning("Failed to delete DO volume {$volumeId} (status {$status}): {$e->getMessage()}");

                    throw $e;
                }

                Log::info("DO volume {$volumeId} still detaching; retrying in {$delay}s");
                $retryCount++;
                Sleep::sleep($delay);
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
        // The group can't be deleted while its instance still holds a network
        // interface, so wait for the instance to reach "terminated" first —
        // otherwise DeleteSecurityGroup exhausts its DependencyViolation retries
        // on a slow teardown and leaves an orphan group behind. deleteSecurityGroup
        // still retries as a backstop and treats a missing group as already-gone.
        if ($server->provider_firewall_id) {
            try {
                $aws->waitForInstanceTerminated($server->provider_server_id);
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

        try {
            $linode->deleteInstance($server->provider_server_id);
            Log::info("Deleted Linode instance {$server->provider_server_id}");
        } catch (RequestException $e) {
            if ($e->response?->status() !== 404) {
                throw $e;
            }

            Log::info("Linode instance {$server->provider_server_id} already gone (404)");
        }

        $volumeFailure = null;

        if ($server->provider_volume_id) {
            // Deleting the instance already detaches its volumes, so this call is
            // only a fallback for the case where the instance was already gone.
            // It must never abort teardown — otherwise the firewall and the team
            // record are stranded behind a redundant step.
            try {
                $linode->detachVolume((int) $server->provider_volume_id);
            } catch (\Throwable $e) {
                Log::info("Linode volume {$server->provider_volume_id} detach skipped (likely already detached): {$e->getMessage()}");
            }

            // The detach settles asynchronously, so an immediate DELETE can race
            // in while the volume still reads as attached. Retry with backoff and
            // treat 404 as already-gone.
            try {
                $this->deleteLinodeVolumeWithRetry($linode, $server->provider_volume_id);
            } catch (\Throwable $e) {
                $volumeFailure = $e;
            }
        }

        // Release the per-server Cloud Firewall created during provisioning.
        // Without this, every destroyed Linode team leaves an orphan firewall —
        // and enough of those exhaust the account-wide Cloud Firewall cap, after
        // which NEW servers silently provision with no firewall at all.
        if ($server->provider_firewall_id) {
            try {
                $linode->deleteFirewall((int) $server->provider_firewall_id);
                Log::info("Deleted Linode firewall {$server->provider_firewall_id}");
            } catch (\Throwable $e) {
                // Non-fatal: log and move on.
                Log::warning("Failed to delete Linode firewall {$server->provider_firewall_id}: {$e->getMessage()}");
            }
        }

        if ($volumeFailure) {
            throw $volumeFailure;
        }
    }

    private function deleteLinodeVolumeWithRetry(LinodeService $linode, string $volumeId): void
    {
        $delays = [2, 4, 8, 16]; // up to ~30s of patience for the detach
        $retryCount = 0;

        while (true) {
            try {
                $linode->deleteVolume($volumeId);
                Log::info("Deleted Linode volume {$volumeId}".($retryCount > 0 ? " (after {$retryCount} retries)" : ''));

                return;
            } catch (RequestException $e) {
                $status = $e->response?->status();

                if ($status === 404) {
                    Log::info("Linode volume {$volumeId} already gone (404)");

                    return;
                }

                $delay = $delays[$retryCount] ?? null;
                // Linode answers 400 (and occasionally 409) while the volume is
                // still attached or otherwise busy.
                $isDetachRace = in_array($status, [400, 409], true);

                if (! $isDetachRace || $delay === null) {
                    Log::warning("Failed to delete Linode volume {$volumeId} (status {$status}): {$e->getMessage()}");

                    throw $e;
                }

                Log::info("Linode volume {$volumeId} still detaching; retrying in {$delay}s");
                $retryCount++;
                Sleep::sleep($delay);
            }
        }
    }
}
