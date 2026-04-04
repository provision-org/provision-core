<?php

use App\Models\Task;
use App\Models\Team;
use App\Services\TaskCheckoutService;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->service = new TaskCheckoutService;
});

it('checks out an available task', function () {
    $task = Task::factory()->create(['team_id' => $this->team->id, 'status' => 'todo']);

    $result = $this->service->checkout($task, 'run_001');

    expect($result)->toBeTrue();

    $task->refresh();
    expect($task->checked_out_by_run)->toBe('run_001')
        ->and($task->status)->toBe('in_progress')
        ->and($task->started_at)->not->toBeNull()
        ->and($task->checked_out_at)->not->toBeNull()
        ->and($task->checkout_expires_at)->not->toBeNull();
});

it('prevents double checkout by different runs', function () {
    $task = Task::factory()->create(['team_id' => $this->team->id, 'status' => 'todo']);

    $this->service->checkout($task, 'run_001');
    $result = $this->service->checkout($task, 'run_002');

    expect($result)->toBeFalse();
});

it('allows checkout of expired tasks', function () {
    $task = Task::factory()->create([
        'team_id' => $this->team->id,
        'status' => 'in_progress',
        'checked_out_by_run' => 'run_old',
        'checked_out_at' => now()->subHours(2),
        'checkout_expires_at' => now()->subHour(),
    ]);

    $result = $this->service->checkout($task, 'run_new');

    expect($result)->toBeTrue();

    $task->refresh();
    expect($task->checked_out_by_run)->toBe('run_new');
});

it('releases a checkout by the owning run', function () {
    $task = Task::factory()->create(['team_id' => $this->team->id, 'status' => 'todo']);

    $this->service->checkout($task, 'run_001');
    $result = $this->service->release($task, 'run_001');

    expect($result)->toBeTrue();

    $task->refresh();
    expect($task->checked_out_by_run)->toBeNull()
        ->and($task->checked_out_at)->toBeNull()
        ->and($task->checkout_expires_at)->toBeNull();
});

it('rejects release from a different run', function () {
    $task = Task::factory()->create(['team_id' => $this->team->id, 'status' => 'todo']);

    $this->service->checkout($task, 'run_001');
    $result = $this->service->release($task, 'run_other');

    expect($result)->toBeFalse();
});

it('reports checked out status correctly', function () {
    $task = Task::factory()->create(['team_id' => $this->team->id, 'status' => 'todo']);

    expect($this->service->isCheckedOut($task))->toBeFalse();

    $this->service->checkout($task, 'run_001');

    expect($this->service->isCheckedOut($task))->toBeTrue();
});

it('reports expired checkout as not checked out', function () {
    $task = Task::factory()->create([
        'team_id' => $this->team->id,
        'status' => 'in_progress',
        'checked_out_by_run' => 'run_old',
        'checked_out_at' => now()->subHours(2),
        'checkout_expires_at' => now()->subHour(),
    ]);

    expect($this->service->isCheckedOut($task))->toBeFalse();
});

it('releases expired checkouts in bulk', function () {
    // Create 2 expired tasks and 1 active
    Task::factory()->create([
        'team_id' => $this->team->id,
        'checked_out_by_run' => 'run_1',
        'checkout_expires_at' => now()->subMinutes(5),
    ]);

    Task::factory()->create([
        'team_id' => $this->team->id,
        'checked_out_by_run' => 'run_2',
        'checkout_expires_at' => now()->subMinutes(10),
    ]);

    Task::factory()->create([
        'team_id' => $this->team->id,
        'checked_out_by_run' => 'run_3',
        'checkout_expires_at' => now()->addHour(),
    ]);

    $released = $this->service->releaseExpired();

    expect($released)->toBe(2);
});
