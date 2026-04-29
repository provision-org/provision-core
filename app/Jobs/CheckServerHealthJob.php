<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckServerHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function handle(SshService $sshService): void
    {
        Server::query()
            ->where('status', ServerStatus::Running)
            ->each(function (Server $server) use ($sshService): void {
                $this->checkServer($server, $sshService);
            });
    }

    private function checkServer(Server $server, SshService $sshService): void
    {
        try {
            $sshService->connect($server);
            $healthOutput = $sshService->execWithRetry('openclaw health');
            $statusOutput = $sshService->execWithRetry('openclaw status --all');

            $server->update(['last_health_check' => now()]);

            $server->events()->create([
                'event' => 'health_check_passed',
                'payload' => [
                    'health' => $healthOutput,
                    'status' => $statusOutput,
                ],
            ]);
        } catch (\Throwable $e) {
            $server->update(['last_health_check' => now()]);

            $server->events()->create([
                'event' => 'health_check_failed',
                'payload' => [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ],
            ]);

            $this->checkConsecutiveFailures($server);
        } finally {
            $sshService->disconnect();
        }
    }

    private function checkConsecutiveFailures(Server $server): void
    {
        $recentFailures = $server->events()
            ->where('event', 'health_check_failed')
            ->latest()
            ->take(3)
            ->count();

        if ($recentFailures >= 3) {
            $lastPass = $server->events()
                ->where('event', 'health_check_passed')
                ->latest()
                ->first();

            $thirdFailure = $server->events()
                ->where('event', 'health_check_failed')
                ->latest()
                ->skip(2)
                ->first();

            if (! $lastPass || ($thirdFailure && $lastPass->created_at->lt($thirdFailure->created_at))) {
                $server->events()->create([
                    'event' => 'health_check_alert',
                    'payload' => ['consecutive_failures' => 3],
                ]);
            }
        }
    }
}
