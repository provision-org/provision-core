<?php

use App\Enums\AgentMode;
use App\Models\Agent;
use App\Models\Server;
use App\Models\Task;
use App\Models\TaskWorkProduct;
use App\Models\User;

function workProductSetup(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $server = Server::factory()->running()->create([
        'team_id' => $team->id,
        'daemon_token' => 'wp-test-token-123',
    ]);

    $agent = Agent::factory()->create([
        'team_id' => $team->id,
        'server_id' => $server->id,
        'agent_mode' => AgentMode::Workforce,
    ]);

    $task = Task::factory()->inProgress()->create([
        'team_id' => $team->id,
        'agent_id' => $agent->id,
        'checked_out_by_run' => 'run-wp-1',
    ]);

    return [$user, $team, $server, $agent, $task];
}

it('creates work products when daemon reports result with work_products', function () {
    [, , , , $task] = workProductSetup();

    $response = $this->postJson("/api/daemon/wp-test-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-wp-1',
        'status' => 'done',
        'result_summary' => 'Completed with deliverables.',
        'work_products' => [
            [
                'title' => 'Market Analysis Report',
                'file_path' => '/workspace/output/market-analysis.md',
                'summary' => 'Comprehensive market analysis for Q2.',
            ],
            [
                'title' => 'Competitor Spreadsheet',
                'file_path' => '/workspace/output/competitors.csv',
                'summary' => 'Competitor pricing comparison.',
            ],
        ],
    ]);

    $response->assertOk();

    expect(TaskWorkProduct::where('task_id', $task->id)->count())->toBe(2);
});

it('stores work products with correct attributes', function () {
    [, , , $agent, $task] = workProductSetup();

    $this->postJson("/api/daemon/wp-test-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-wp-1',
        'status' => 'done',
        'work_products' => [
            [
                'type' => 'report',
                'title' => 'Final Deliverable',
                'file_path' => '/workspace/output/final.pdf',
                'summary' => 'The final report.',
            ],
        ],
    ]);

    $wp = TaskWorkProduct::where('task_id', $task->id)->first();

    expect($wp)->not->toBeNull()
        ->and($wp->task_id)->toBe($task->id)
        ->and($wp->agent_id)->toBe($agent->id)
        ->and($wp->type)->toBe('report')
        ->and($wp->title)->toBe('Final Deliverable')
        ->and($wp->file_path)->toBe('/workspace/output/final.pdf')
        ->and($wp->summary)->toBe('The final report.');
});

it('loads work products in task show page', function () {
    $this->withoutVite();

    [$user, , , $agent, $task] = workProductSetup();

    TaskWorkProduct::factory()->count(2)->create([
        'task_id' => $task->id,
        'agent_id' => $agent->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('company.tasks.show', $task));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('company/tasks/show')
        ->has('task.work_products', 2)
    );
});

it('handles result without work products gracefully', function () {
    [, , , , $task] = workProductSetup();

    $response = $this->postJson("/api/daemon/wp-test-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-wp-1',
        'status' => 'done',
        'result_summary' => 'Done without deliverables.',
    ]);

    $response->assertOk();

    expect(TaskWorkProduct::where('task_id', $task->id)->count())->toBe(0);
});

it('validates work product fields', function () {
    [, , , , $task] = workProductSetup();

    $response = $this->postJson("/api/daemon/wp-test-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-wp-1',
        'status' => 'done',
        'work_products' => [
            [
                'title' => str_repeat('x', 256),
                'file_path' => '/workspace/output/too-long-title.md',
                'summary' => 'Title exceeds max length.',
            ],
        ],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['work_products.0.title']);
});

it('associates work products with the task agent', function () {
    [, , , $agent, $task] = workProductSetup();

    $this->postJson("/api/daemon/wp-test-token-123/tasks/{$task->id}/result", [
        'daemon_run_id' => 'run-wp-1',
        'status' => 'done',
        'work_products' => [
            [
                'title' => 'Agent Output',
                'file_path' => '/workspace/output/agent-output.md',
            ],
        ],
    ]);

    $wp = TaskWorkProduct::where('task_id', $task->id)->first();

    expect($wp->agent_id)->toBe($agent->id)
        ->and($wp->agent_id)->toBe($task->agent_id);
});
