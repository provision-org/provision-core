<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Server;
use Illuminate\Support\Collection;

class ChannelConfigBuilder
{
    /**
     * Collect all channel accounts from agents on a server.
     *
     * @return array{slack: list<array>, telegram: list<array>, discord: list<array>}
     */
    public function collectAccounts(Server $server): array
    {
        $allAgents = $server->agents()
            ->with(['slackConnection', 'telegramConnection', 'discordConnection'])
            ->get();

        return $this->collectAccountsFromAgents($allAgents);
    }

    /**
     * Collect channel accounts from a given collection of agents.
     *
     * @return array{slack: list<array>, telegram: list<array>, discord: list<array>}
     */
    public function collectAccountsFromAgents(Collection $agents): array
    {
        $channelAccounts = ['slack' => [], 'telegram' => [], 'discord' => []];

        foreach ($agents as $agent) {
            $agentId = $agent->harness_agent_id;

            if ($agent->slackConnection?->bot_token && $agent->slackConnection?->app_token) {
                $channelAccounts['slack'][] = [
                    'agentId' => $agentId,
                    'botToken' => $agent->slackConnection->bot_token,
                    'appToken' => $agent->slackConnection->app_token,
                ];
            }

            if ($agent->telegramConnection?->bot_token) {
                $channelAccounts['telegram'][] = [
                    'agentId' => $agentId,
                    'botToken' => $agent->telegramConnection->bot_token,
                ];
            }

            if ($agent->discordConnection?->token) {
                $channelAccounts['discord'][] = [
                    'agentId' => $agentId,
                    'botToken' => $agent->discordConnection->token,
                ];
            }
        }

        return $channelAccounts;
    }

    /**
     * Determine the account ID for a given agent on a given channel.
     *
     * Always uses "{channel}-{agentId}" so adding a second agent later
     * doesn't require renaming the first agent's account key.
     */
    public function resolveAccountId(string $channel, string $agentId, array $channelAccounts): string
    {
        return "{$channel}-{$agentId}";
    }

    /**
     * Build the full channel config array (channels, bindings, plugins) for a server.
     *
     * @return array{channels: array, bindings: list<array>, plugins: array}
     */
    public function buildConfig(array $channelAccounts): array
    {
        $channels = [];
        $bindings = [];
        $plugins = [];

        foreach (['slack', 'telegram', 'discord'] as $channel) {
            $accounts = $channelAccounts[$channel];
            if (empty($accounts)) {
                continue;
            }

            $isSlack = $channel === 'slack';

            $channelConfig = [
                'enabled' => true,
                'dmPolicy' => 'open',
                'allowFrom' => ['*'],
                'accounts' => [],
                ...($isSlack ? [
                    'groupPolicy' => 'open',
                    'nativeStreaming' => true,
                    'streaming' => 'partial',
                    'userTokenReadOnly' => true,
                ] : []),
            ];

            $plugins[$channel] = ['enabled' => true];

            foreach ($accounts as $account) {
                $accountId = "{$channel}-{$account['agentId']}";

                $entry = [
                    'name' => $accountId,
                    'botToken' => $account['botToken'],
                    'dmPolicy' => 'open',
                    'allowFrom' => ['*'],
                ];

                if ($channel === 'slack') {
                    if (isset($account['appToken'])) {
                        $entry['appToken'] = $account['appToken'];
                    }
                    $entry['userTokenReadOnly'] = true;
                    $entry['nativeStreaming'] = true;
                    $entry['streaming'] = 'partial';
                }

                $channelConfig['accounts'][$accountId] = $entry;

                $bindings[] = [
                    'agentId' => $account['agentId'],
                    'match' => ['channel' => $channel, 'accountId' => $accountId],
                ];
            }

            $channels[$channel] = $channelConfig;
        }

        return [
            'channels' => $channels,
            'bindings' => $bindings,
            'plugins' => $plugins,
        ];
    }

    /**
     * Apply channel config to an existing openclaw.json config array.
     *
     * Clears all existing channel/binding/plugin entries and rebuilds from database.
     */
    public function applyToConfig(array &$config, Server $server): void
    {
        // Defensive: $config['channels'] may arrive as a stdClass (e.g. when a
        // caller decoded the on-disk JSON without `assoc=true`, since openclaw
        // serializes empty channels as `{}`). Normalize to a mutable array;
        // OpenClawConfig::toJson re-applies the `{}` cast at write time.
        if (! isset($config['channels']) || ! is_array($config['channels'])) {
            $config['channels'] = [];
        }

        // Clear existing channel config
        foreach (['slack', 'telegram', 'discord'] as $channel) {
            unset($config['channels'][$channel]);
            unset($config['plugins']['entries'][$channel]);
        }
        $config['bindings'] = [];

        $channelAccounts = $this->collectAccounts($server);
        $built = $this->buildConfig($channelAccounts);

        // Merge channels
        foreach ($built['channels'] as $channel => $channelConfig) {
            $config['channels'][$channel] = $channelConfig;
        }

        // Merge plugins
        if (! isset($config['plugins']) || ! is_array($config['plugins'])) {
            $config['plugins'] = [];
        }
        if (! isset($config['plugins']['entries']) || ! is_array($config['plugins']['entries']) || array_is_list($config['plugins']['entries'])) {
            $config['plugins']['entries'] = [];
        }
        foreach ($built['plugins'] as $channel => $pluginConfig) {
            $config['plugins']['entries'][$channel] = $pluginConfig;
        }

        $config['bindings'] = $built['bindings'];
    }

    /**
     * Resolve the best reply channel for an agent (for sending messages via CLI).
     *
     * @return array{channel: string, account: string}|null
     */
    public function resolveReplyChannel(Agent $agent): ?array
    {
        $server = $agent->server;
        if (! $server) {
            return null;
        }

        $agentId = $agent->harness_agent_id;
        $channelAccounts = $this->collectAccounts($server);

        if ($agent->slackConnection?->bot_token && $agent->slackConnection?->app_token) {
            return [
                'channel' => 'slack',
                'account' => $this->resolveAccountId('slack', $agentId, $channelAccounts),
            ];
        }

        if ($agent->telegramConnection?->bot_token) {
            return [
                'channel' => 'telegram',
                'account' => $this->resolveAccountId('telegram', $agentId, $channelAccounts),
            ];
        }

        if ($agent->discordConnection?->token) {
            return [
                'channel' => 'discord',
                'account' => $this->resolveAccountId('discord', $agentId, $channelAccounts),
            ];
        }

        return null;
    }
}
