<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskCheckoutService
{
    /**
     * Atomically check out a task for a daemon run.
     *
     * Returns true if the checkout succeeded, false if the task is already checked out.
     */
    public function checkout(Task $task, string $runId): bool
    {
        return DB::transaction(function () use ($task, $runId): bool {
            $locked = Task::query()
                ->where('id', $task->id)
                ->where(function ($q) {
                    $q->whereNull('checked_out_by_run')
                        ->orWhere('checkout_expires_at', '<', now());
                })
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $locked->update([
                'checked_out_by_run' => $runId,
                'checked_out_at' => now(),
                'checkout_expires_at' => now()->addHour(),
                'status' => 'in_progress',
                'started_at' => $locked->started_at ?? now(),
            ]);

            return true;
        });
    }

    /**
     * Release a task checkout. Only the run that holds the checkout can release it.
     */
    public function release(Task $task, string $runId): bool
    {
        return DB::transaction(function () use ($task, $runId): bool {
            $locked = Task::query()
                ->where('id', $task->id)
                ->where('checked_out_by_run', $runId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $locked->update([
                'checked_out_by_run' => null,
                'checked_out_at' => null,
                'checkout_expires_at' => null,
            ]);

            return true;
        });
    }

    /**
     * Check if a task is currently checked out (and the checkout has not expired).
     */
    public function isCheckedOut(Task $task): bool
    {
        $task->refresh();

        return $task->checked_out_by_run !== null
            && ($task->checkout_expires_at === null || $task->checkout_expires_at->isFuture());
    }

    /**
     * Release all expired checkouts. Returns the number of tasks released.
     */
    public function releaseExpired(): int
    {
        return Task::query()
            ->whereNotNull('checked_out_by_run')
            ->where('checkout_expires_at', '<', now())
            ->update([
                'checked_out_by_run' => null,
                'checked_out_at' => null,
                'checkout_expires_at' => null,
                'status' => 'todo',
            ]);
    }
}
