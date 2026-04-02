<?php

namespace App\Services;

use App\Exceptions\SlackApiException;
use App\Models\Agent;
use Illuminate\Support\Facades\Log;

class SlackAppCleanupService
{
    public function __construct(
        private SlackApiService $slackApi,
    ) {}

    public function cleanup(Agent $agent): void
    {
        $connection = $agent->slackConnection;

        if (! $connection?->is_automated || ! $connection->slack_app_id) {
            return;
        }

        $configToken = $agent->team->slackConfigurationToken;

        if (! $configToken) {
            return;
        }

        try {
            $token = $this->slackApi->getValidConfigToken($configToken);
            $this->slackApi->deleteApp($token, $connection->slack_app_id);
        } catch (SlackApiException $e) {
            Log::warning('Failed to delete Slack app during cleanup', [
                'agent_id' => $agent->id,
                'slack_app_id' => $connection->slack_app_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
