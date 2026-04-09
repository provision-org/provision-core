<?php

namespace App\Http\Controllers;

use App\Events\TaskStatusChangedEvent;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskNoteController extends Controller
{
    /**
     * Store a new comment on a task.
     *
     * If the task is done, reopen it for revision.
     */
    public function store(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->team_id === $request->user()->current_team_id, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $task->notes()->create([
            'author_type' => 'user',
            'author_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        if ($task->status === 'done') {
            $oldStatus = $task->status;

            $task->update([
                'status' => 'todo',
                'checked_out_by_run' => null,
                'checkout_expires_at' => null,
            ]);

            event(new TaskStatusChangedEvent($task->load('agent'), $oldStatus, 'todo'));
        }

        return redirect()->back();
    }
}
