<?php

use App\Enums\ApprovalStatus;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Task;
use App\Models\User;

function approvalUser(): User
{
    return User::factory()->withPersonalTeam()->create();
}

test('user can list approvals for their team', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    Approval::factory()->count(3)->create(['team_id' => $team->id, 'requesting_agent_id' => $agent->id]);

    $response = $this->actingAs($user)->get(route('company.approvals.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('company/approvals/index'));
});

test('user can filter approvals by status', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);

    Approval::factory()->create(['team_id' => $team->id, 'requesting_agent_id' => $agent->id, 'status' => ApprovalStatus::Pending]);
    Approval::factory()->approved()->create(['team_id' => $team->id, 'requesting_agent_id' => $agent->id]);

    $response = $this->actingAs($user)->get(route('company.approvals.index', [
        'team' => $team,
        'status' => 'pending',
    ]));

    $response->assertSuccessful();
});

test('user can view an approval', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $approval = Approval::factory()->create(['team_id' => $team->id, 'requesting_agent_id' => $agent->id]);

    $response = $this->actingAs($user)->get(route('company.approvals.show', $approval));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('company/approvals/show'));
});

test('user can approve a pending approval', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $approval = Approval::factory()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'status' => ApprovalStatus::Pending,
    ]);

    $response = $this->actingAs($user)->post(route('company.approvals.approve', $approval), [
        'review_note' => 'Looks good.',
    ]);

    $response->assertRedirect();

    $approval->refresh();
    expect($approval->status)->toBe(ApprovalStatus::Approved);
    expect($approval->reviewed_by)->toBe($user->id);
    expect($approval->review_note)->toBe('Looks good.');
});

test('approving an approval unblocks the linked task', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $task = Task::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'status' => 'blocked']);
    $approval = Approval::factory()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'linked_task_id' => $task->id,
        'status' => ApprovalStatus::Pending,
    ]);

    $this->actingAs($user)->post(route('company.approvals.approve', $approval));

    $task->refresh();
    expect($task->status)->toBe('todo');
});

test('user can reject a pending approval', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $approval = Approval::factory()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'status' => ApprovalStatus::Pending,
    ]);

    $response = $this->actingAs($user)->post(route('company.approvals.reject', $approval), [
        'review_note' => 'Not aligned with goals.',
    ]);

    $response->assertRedirect();

    $approval->refresh();
    expect($approval->status)->toBe(ApprovalStatus::Rejected);
});

test('user can request revision on an approval', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $approval = Approval::factory()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'status' => ApprovalStatus::Pending,
    ]);

    $response = $this->actingAs($user)->post(route('company.approvals.requestRevision', $approval), [
        'review_note' => 'Needs more detail on budget.',
    ]);

    $response->assertRedirect();

    $approval->refresh();
    expect($approval->status)->toBe(ApprovalStatus::RevisionRequested);
    expect($approval->review_note)->toBe('Needs more detail on budget.');
});

test('cannot approve a non-pending approval', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $approval = Approval::factory()->approved()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'reviewed_by' => $user->id,
    ]);

    $response = $this->actingAs($user)->post(route('company.approvals.approve', $approval));

    $response->assertStatus(422);
});

test('approval actions log audit entries', function () {
    $user = approvalUser();
    $team = $user->currentTeam;
    $agent = Agent::factory()->create(['team_id' => $team->id]);
    $approval = Approval::factory()->create([
        'team_id' => $team->id,
        'requesting_agent_id' => $agent->id,
        'status' => ApprovalStatus::Pending,
    ]);

    $this->actingAs($user)->post(route('company.approvals.approve', $approval));

    $this->assertDatabaseHas('audit_log', [
        'team_id' => $team->id,
        'action' => 'approval.approved',
    ]);
});
