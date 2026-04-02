<?php

namespace App\Console\Commands;

use App\Enums\AgentStatus;
use App\Models\Agent;
use Illuminate\Console\Command;

class FixAgentStatus extends Command
{
    protected $signature = 'app:fix-agent-status {--id=} {--status=active}';

    protected $description = 'List agents and fix stuck statuses';

    public function handle(): int
    {
        $agents = Agent::all();

        foreach ($agents as $agent) {
            $this->info("Agent {$agent->id} | {$agent->name} | status: {$agent->status->value} | is_syncing: {$agent->is_syncing}");
        }

        if ($id = $this->option('id')) {
            $agent = Agent::find($id);

            if (! $agent) {
                $this->error("Agent {$id} not found.");

                return self::FAILURE;
            }

            $newStatus = AgentStatus::from($this->option('status'));
            $agent->update(['status' => $newStatus, 'is_syncing' => false]);
            $this->info("Updated agent {$agent->id} to status: {$newStatus->value}");
        }

        return self::SUCCESS;
    }
}
