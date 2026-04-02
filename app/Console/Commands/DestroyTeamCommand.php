<?php

namespace App\Console\Commands;

use App\Jobs\DestroyTeamJob;
use App\Models\Team;
use Illuminate\Console\Command;

class DestroyTeamCommand extends Command
{
    protected $signature = 'team:destroy {team? : Team ID or name} {--force : Skip confirmation}';

    protected $description = 'Destroy a team and all its resources (agents, server, Slack apps, MailboxKit, OpenRouter keys)';

    public function handle(): int
    {
        $input = $this->argument('team');

        if (! $input) {
            $teams = Team::with('server')->get();

            if ($teams->isEmpty()) {
                $this->warn('No teams found.');

                return 0;
            }

            $this->table(
                ['ID', 'Name', 'Server', 'Agents', 'Created'],
                $teams->map(fn (Team $t) => [
                    $t->id,
                    $t->name,
                    $t->server?->ipv4_address ?? 'none',
                    $t->agents()->count(),
                    $t->created_at->diffForHumans(),
                ]),
            );

            $input = $this->ask('Enter team ID or name to destroy');
        }

        $team = Team::where('id', $input)->orWhere('name', $input)->first();

        if (! $team) {
            $this->error("Team '{$input}' not found.");

            return 1;
        }

        $this->info("Team: {$team->name} ({$team->id})");
        $this->info("  Server: {$team->server?->ipv4_address} ({$team->server?->cloud_provider?->value})");
        $this->info("  Agents: {$team->agents()->count()}");

        if (! $this->option('force') && ! $this->confirm('This will destroy the team, all agents, and the server. Continue?')) {
            $this->info('Cancelled.');

            return 0;
        }

        $this->info('Destroying team...');

        DestroyTeamJob::dispatchSync($team);

        $this->info("Team '{$team->name}' destroyed.");

        return 0;
    }
}
