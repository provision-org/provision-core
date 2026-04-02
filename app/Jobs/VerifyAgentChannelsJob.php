<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Services\ChannelConfigBuilder;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyAgentChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public Agent $agent) {}

    public function handle(SshService $sshService, ChannelConfigBuilder $configBuilder): void
    {
        if ($this->agent->status !== AgentStatus::Active || ! $this->agent->server) {
            return;
        }

        $this->agent->loadMissing(['slackConnection', 'telegramConnection', 'discordConnection']);

        $hasChannels = $this->agent->slackConnection?->bot_token
            || $this->agent->telegramConnection?->bot_token
            || $this->agent->discordConnection?->token;

        if (! $hasChannels) {
            Log::info("Agent {$this->agent->id} has no channels configured — skipping verification");

            return;
        }

        $sshService->connect($this->agent->server);

        try {
            $configJson = $sshService->readFile('/root/.openclaw/openclaw.json');
            $config = json_decode($configJson, true);

            if (! $config) {
                $this->logIssue('Could not parse openclaw.json');

                return;
            }

            $issues = $this->verifyConfig($config, $configBuilder);

            if (empty($issues)) {
                Log::info("Agent {$this->agent->id} channel verification passed");
                $this->agent->server->events()->create([
                    'event' => 'agent_channels_verified',
                    'payload' => ['agent_id' => $this->agent->id],
                ]);

                return;
            }

            // Issues found — attempt auto-repair
            Log::warning("Agent {$this->agent->id} channel verification found issues", ['issues' => $issues]);

            $configBuilder->applyToConfig($config, $this->agent->server);
            $sshService->writeFile(
                '/root/.openclaw/openclaw.json',
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            RestartGatewayJob::dispatch($this->agent->server);

            $this->agent->server->events()->create([
                'event' => 'agent_channels_repaired',
                'payload' => [
                    'agent_id' => $this->agent->id,
                    'issues' => $issues,
                ],
            ]);

            Log::info("Agent {$this->agent->id} channel config repaired and gateway restart dispatched");
        } catch (\RuntimeException $e) {
            Log::warning("Channel verification failed for agent {$this->agent->id}: {$e->getMessage()}");
        } finally {
            $sshService->disconnect();
        }
    }

    /**
     * Verify the on-server config matches what the database expects.
     *
     * @return list<string> List of issues found (empty = all good)
     */
    private function verifyConfig(array $config, ChannelConfigBuilder $configBuilder): array
    {
        $issues = [];
        $agentId = $this->agent->harness_agent_id;

        // Check agent exists in agents.list
        $agentList = $config['agents']['list'] ?? [];
        $agentFound = collect($agentList)->contains('id', $agentId);
        if (! $agentFound) {
            $issues[] = "Agent {$agentId} not found in agents.list";
        }

        // Build expected config from database
        $channelAccounts = $configBuilder->collectAccounts($this->agent->server);
        $expected = $configBuilder->buildConfig($channelAccounts);

        // Check each expected binding exists
        foreach ($expected['bindings'] as $expectedBinding) {
            if ($expectedBinding['agentId'] !== $agentId) {
                continue; // Only verify this agent's bindings
            }

            $channel = $expectedBinding['match']['channel'];
            $expectedAccountId = $expectedBinding['match']['accountId'];

            // Verify channel is enabled
            if (! ($config['channels'][$channel]['enabled'] ?? false)) {
                $issues[] = "Channel {$channel} not enabled";
            }

            // Verify account exists
            $serverAccount = $config['channels'][$channel]['accounts'][$expectedAccountId] ?? null;
            if (! $serverAccount) {
                $issues[] = "Account {$expectedAccountId} missing from {$channel} channel";
            }

            // Verify binding exists
            $bindingFound = collect($config['bindings'] ?? [])->contains(function ($b) use ($agentId, $channel, $expectedAccountId) {
                return ($b['agentId'] ?? null) === $agentId
                    && ($b['match']['channel'] ?? null) === $channel
                    && ($b['match']['accountId'] ?? null) === $expectedAccountId;
            });

            if (! $bindingFound) {
                $issues[] = "Binding missing for {$channel}/{$expectedAccountId}";
            }

            // Verify dmPolicy is open (not pairing — doctor can reset this)
            $accountDmPolicy = $serverAccount['dmPolicy'] ?? null;
            if ($serverAccount && $accountDmPolicy !== 'open') {
                $issues[] = "Account {$expectedAccountId} has dmPolicy '{$accountDmPolicy}' instead of 'open'";
            }
        }

        return $issues;
    }

    private function logIssue(string $message): void
    {
        Log::warning("Agent {$this->agent->id} channel verification: {$message}");

        $this->agent->server?->events()->create([
            'event' => 'agent_channels_verification_failed',
            'payload' => [
                'agent_id' => $this->agent->id,
                'error' => $message,
            ],
        ]);
    }
}
