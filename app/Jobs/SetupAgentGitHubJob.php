<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SetupAgentGitHubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public Agent $agent) {}

    public function handle(SshService $sshService): void
    {
        if ($this->agent->status !== AgentStatus::Active || ! $this->agent->server) {
            Log::info("Skipping GitHub setup — agent {$this->agent->id} not active or no server");

            return;
        }

        $this->agent->loadMissing('emailConnection');

        if (! $this->agent->emailConnection?->email_address) {
            Log::info("Skipping GitHub setup — agent {$this->agent->id} has no email connection");

            return;
        }

        $agentId = $this->agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";

        $sshService->connect($this->agent->server);

        try {
            // Check if already authenticated — don't overwrite existing credentials
            $hostsFile = "{$agentDir}/.gh/hosts.yml";
            try {
                $existing = $sshService->readFile($hostsFile);
                if (! empty(trim($existing))) {
                    Log::info("Agent {$this->agent->id} already has GitHub credentials — skipping setup");

                    return;
                }
            } catch (\RuntimeException) {
                // File doesn't exist — proceed with setup
            }

            // Create .gh directory for isolated GitHub CLI config
            $sshService->exec("mkdir -p {$agentDir}/.gh");

            // Seed .gitconfig with agent name and email
            $email = $this->agent->emailConnection->email_address;
            $name = $this->agent->name;
            $sshService->writeFile("{$agentDir}/.gitconfig",
                "[user]\n    name = {$name}\n    email = {$email}\n");

            Log::info("GitHub environment scaffolded for agent {$this->agent->id}");
        } catch (\RuntimeException $e) {
            Log::warning("Failed to scaffold GitHub environment for agent {$this->agent->id}: {$e->getMessage()}");
        } finally {
            $sshService->disconnect();
        }
    }
}
