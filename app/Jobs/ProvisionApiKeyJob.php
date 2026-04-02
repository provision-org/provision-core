<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Team;
use App\Services\OpenRouterKeyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionApiKeyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Team $team) {}

    public function handle(OpenRouterKeyService $keyService): void
    {
        if ($this->team->managedApiKey()->exists()) {
            return;
        }

        if (! config('services.openrouter.provisioning_api_key')) {
            return;
        }

        $result = $keyService->createKey($this->team);

        $this->team->managedApiKey()->create([
            'openrouter_key_hash' => $result['hash'],
            'api_key' => $result['key'],
            'name' => "Provision-{$this->team->id}",
        ]);

        $server = $this->team->server;

        if ($server && $server->status === ServerStatus::Running) {
            UpdateEnvOnServerJob::dispatch($server);
        }
    }
}
