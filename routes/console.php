<?php

use App\Enums\ServerStatus;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\SyncAgentStatsJob;
use App\Models\Server;
use App\Models\SlackConfigurationToken;
use App\Services\SlackApiService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new CheckServerHealthJob)->everyFiveMinutes();

Schedule::call(function () {
    Server::query()
        ->where('status', ServerStatus::Provisioning)
        ->where('created_at', '<', now()->subMinutes(10))
        ->each(function (Server $server) {
            $server->update(['status' => ServerStatus::Error->value]);
            $server->events()->create([
                'event' => 'provisioning_timeout',
                'payload' => [],
            ]);
        });
})->everyFiveMinutes();

Schedule::call(function () {
    $slackApi = app(SlackApiService::class);

    SlackConfigurationToken::query()
        ->where('expires_at', '<', now()->addHours(2))
        ->each(function (SlackConfigurationToken $token) use ($slackApi) {
            try {
                $slackApi->getValidConfigToken($token);
            } catch (Throwable $e) {
                Log::warning('Failed to refresh Slack configuration token', [
                    'team_id' => $token->team_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
})->hourly();

Schedule::job(new SyncAgentStatsJob)->everyTwoMinutes();
