<?php

namespace App\Jobs;

use App\Models\Routine;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessRoutinesJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $routines = Routine::query()
            ->where('status', 'active')
            ->where('next_run_at', '<=', now())
            ->with('team')
            ->get();

        foreach ($routines as $routine) {
            $this->processRoutine($routine);
        }
    }

    private function processRoutine(Routine $routine): void
    {
        // Skip if a pending task from this routine already exists
        $hasPendingTask = Task::query()
            ->where('routine_id', $routine->id)
            ->whereIn('status', ['todo', 'in_progress', 'blocked'])
            ->exists();

        if ($hasPendingTask) {
            // Still update next_run_at so we check again later
            $routine->update([
                'next_run_at' => $routine->computeNextRun(),
            ]);

            return;
        }

        // Enforce per-team limit of 50 active routines
        $activeCount = Routine::query()
            ->where('team_id', $routine->team_id)
            ->where('status', 'active')
            ->count();

        if ($activeCount > 50) {
            Log::warning('Team has exceeded 50 active routines, skipping routine processing', [
                'team_id' => $routine->team_id,
                'routine_id' => $routine->id,
            ]);

            return;
        }

        $identifier = $this->generateIdentifier($routine->team);

        Task::query()->create([
            'team_id' => $routine->team_id,
            'agent_id' => $routine->agent_id,
            'routine_id' => $routine->id,
            'created_by_type' => 'routine',
            'created_by_id' => $routine->id,
            'title' => $routine->title,
            'description' => $routine->description,
            'status' => 'todo',
            'priority' => 'medium',
            'identifier' => $identifier,
        ]);

        $routine->update([
            'last_run_at' => now(),
            'next_run_at' => $routine->computeNextRun(),
        ]);
    }

    /**
     * Generate a sequential task identifier like TSK-1, TSK-2, etc.
     */
    private function generateIdentifier(Team $team): string
    {
        $count = $team->tasks()->count();

        return 'TSK-'.($count + 1);
    }
}
