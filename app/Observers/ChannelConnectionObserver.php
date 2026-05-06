<?php

namespace App\Observers;

use App\Services\AnalyticsService;
use Illuminate\Database\Eloquent\Model;

class ChannelConnectionObserver
{
    public function __construct(private AnalyticsService $analytics) {}

    public function created(Model $connection): void
    {
        $agent = $connection->agent;
        if (! $agent) {
            return;
        }

        $user = $agent->team?->owner;
        if (! $user) {
            return;
        }

        $channel = match (class_basename($connection)) {
            'AgentSlackConnection' => 'slack',
            'AgentTelegramConnection' => 'telegram',
            'AgentDiscordConnection' => 'discord',
            default => 'unknown',
        };

        $this->analytics->track($user, 'Channel Connected', [
            'channel' => $channel,
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
        ]);
    }
}
