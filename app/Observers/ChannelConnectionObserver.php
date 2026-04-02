<?php

namespace App\Observers;

use App\Services\MixpanelService;
use Illuminate\Database\Eloquent\Model;

class ChannelConnectionObserver
{
    public function __construct(private MixpanelService $mixpanel) {}

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

        $this->mixpanel->track($user, 'Channel Connected', [
            'channel' => $channel,
            'agent_name' => $agent->name,
        ]);
    }
}
