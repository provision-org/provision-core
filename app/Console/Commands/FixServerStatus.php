<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Jobs\SetupOpenClawOnServerJob;
use App\Models\Server;
use App\Services\ServerProvisioningDispatcher;
use Illuminate\Console\Command;

class FixServerStatus extends Command
{
    protected $signature = 'app:fix-server-status {--ip=} {--status=setup_complete} {--reprovision} {--setup}';

    protected $description = 'Fix server status or re-provision a broken server';

    public function handle(): int
    {
        $servers = Server::all();

        if ($servers->isEmpty()) {
            $this->error('No servers found.');

            return self::FAILURE;
        }

        foreach ($servers as $server) {
            $this->info("Server {$server->id} | team: {$server->team_id} | status: {$server->status->value} | IP: {$server->ipv4_address} | provider: {$server->cloud_provider?->value} | provider_server_id: {$server->provider_server_id}");
        }

        if ($this->option('reprovision')) {
            $server = Server::where('status', '!=', ServerStatus::Running)->first();

            if (! $server) {
                $this->error('No non-running server found to re-provision.');

                return self::FAILURE;
            }

            $this->info("Deleting broken server {$server->id} and re-provisioning...");
            $team = $server->team;
            $server->delete();

            $newServer = $team->server()->create([
                'name' => "provision-{$team->id}",
                'cloud_provider' => $team->cloudProvider(),
            ]);

            ServerProvisioningDispatcher::dispatch($newServer);
            $this->info("Dispatched provisioning for new server {$newServer->id} (size: {$team->serverType()})");

            return self::SUCCESS;
        }

        if ($this->option('setup')) {
            $server = Server::where('status', ServerStatus::SetupComplete)->first();

            if (! $server) {
                $this->error('No server with setup_complete status found.');

                return self::FAILURE;
            }

            SetupOpenClawOnServerJob::dispatch($server);
            $this->info("Dispatched SetupOpenClawOnServerJob for server {$server->id}");

            return self::SUCCESS;
        }

        $server = Server::where('status', '!=', ServerStatus::Running)->first();

        if (! $server) {
            $this->info('All servers are running. Nothing to fix.');

            return self::SUCCESS;
        }

        $newStatus = $this->option('status');
        $newIp = $this->option('ip');

        if ($newIp) {
            $server->ipv4_address = $newIp;
        }

        $server->status = ServerStatus::from($newStatus);
        $server->save();

        $this->info("Updated server {$server->id} to status: {$server->status->value}, IP: {$server->ipv4_address}");

        return self::SUCCESS;
    }
}
