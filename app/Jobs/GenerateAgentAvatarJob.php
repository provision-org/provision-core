<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\ReplicateService;
use App\Services\SlackApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateAgentAvatarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public Agent $agent) {}

    public function handle(ReplicateService $replicate, SlackApiService $slackApi): void
    {
        if (! config('replicate.api_token')) {
            return;
        }

        $role = $this->agent->role;

        if (! $role) {
            return;
        }

        $imageUrl = $replicate->generateAvatar($role->avatarPrompt());

        $imageContents = Http::timeout(30)->get($imageUrl)->body();

        $path = "avatars/{$this->agent->id}.jpg";

        Storage::disk('public')->put($path, $imageContents);

        $this->agent->update(['avatar_path' => $path]);

        $this->syncToSlack($slackApi, $imageContents);
    }

    private function syncToSlack(SlackApiService $slackApi, string $imageContents): void
    {
        $slackConnection = $this->agent->slackConnection;

        if (! $slackConnection?->bot_token) {
            return;
        }

        try {
            $slackApi->setBotPhoto($slackConnection->bot_token, $imageContents);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync avatar to Slack', [
                'agent_id' => $this->agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to generate agent avatar', [
            'agent_id' => $this->agent->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
