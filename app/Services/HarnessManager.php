<?php

namespace App\Services;

use App\Contracts\CommandExecutor;
use App\Contracts\HarnessDriver;
use App\Enums\HarnessType;
use App\Models\Agent;
use App\Models\Server;
use App\Services\Harness\HermesDriver;
use App\Services\Harness\OpenClawDriver;

class HarnessManager
{
    public function driver(HarnessType $type): HarnessDriver
    {
        return match ($type) {
            HarnessType::OpenClaw => app(OpenClawDriver::class),
            HarnessType::Hermes => app(HermesDriver::class),
        };
    }

    public function forAgent(Agent $agent): HarnessDriver
    {
        return $this->driver($agent->harness_type);
    }

    public function resolveExecutor(Server $server): CommandExecutor
    {
        if ($server->isDocker()) {
            return app(DockerExecutor::class);
        }

        return app(SshService::class)->connect($server);
    }
}
