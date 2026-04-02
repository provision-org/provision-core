<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncEnvFromServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server) {}

    public function handle(SshService $sshService): void
    {
        $sshService->connect($this->server);

        try {
            $envContent = $sshService->readFile('/root/.openclaw/.env');

            $team = $this->server->team;
            $lines = array_filter(explode("\n", $envContent), fn (string $line) => str_contains($line, '='));

            foreach ($lines as $line) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if ($key === '') {
                    continue;
                }

                $team->envVars()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'is_secret' => true],
                );
            }
        } finally {
            $sshService->disconnect();
        }
    }
}
