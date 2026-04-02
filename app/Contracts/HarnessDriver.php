<?php

namespace App\Contracts;

use App\Models\Agent;
use App\Models\Server;

interface HarnessDriver
{
    /** One-time setup of harness software on a server (called on first agent deploy of this type). */
    public function setupOnServer(Server $server, CommandExecutor $executor): void;

    /** Deploy a new agent to the server. */
    public function createAgent(Agent $agent, CommandExecutor $executor): void;

    /** Update an agent's configuration files on the server. */
    public function updateAgent(Agent $agent, CommandExecutor $executor): void;

    /** Remove an agent from the server. */
    public function removeAgent(Agent $agent, CommandExecutor $executor): void;

    /** Restart the gateway/process for this agent's harness. */
    public function restartGateway(Server $server, CommandExecutor $executor): void;

    /** Health check for the harness on this server. */
    public function checkHealth(Agent $agent, CommandExecutor $executor): bool;

    /** Get the agent's working directory path on the server. */
    public function agentDir(Agent $agent): string;

    /** Format model config for this harness. */
    public function formatModelConfig(Agent $agent): string|array;
}
