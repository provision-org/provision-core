<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActorType;
use App\Enums\AgentMode;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\UsageSource;
use App\Events\ApprovalRequestedEvent;
use App\Events\TaskStatusChangedEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Governance\ReportResultRequest;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Server;
use App\Models\Task;
use App\Models\TaskWorkProduct;
use App\Models\UsageEvent;
use App\Services\AuditService;
use App\Services\TaskCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DaemonController extends Controller
{
    public function __construct(
        private readonly TaskCheckoutService $checkoutService,
        private readonly AuditService $audit,
    ) {}

    /**
     * GET /api/daemon/{token}/work-queue
     *
     * Return tasks ready for pickup by agents on this server.
     */
    public function workQueue(Request $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');

        $workforceAgentIds = Agent::query()
            ->where('server_id', $server->id)
            ->where('agent_mode', AgentMode::Workforce)
            ->where('status', 'active')
            ->pluck('id');

        $tasks = Task::query()
            ->whereIn('agent_id', $workforceAgentIds)
            ->where('status', 'todo')
            ->where(function ($q) {
                $q->whereNull('checked_out_by_run')
                    ->orWhere('checkout_expires_at', '<', now());
            })
            ->with([
                'agent:id,name,handle,role,agent_mode,reports_to,org_title,capabilities,delegation_enabled,harness_type,harness_agent_id,api_server_port,api_server_key',
                'agent.directReports:id,name,handle,reports_to,org_title,capabilities',
                'goal:id,title,description,status,priority',
            ])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'identifier' => $task->identifier,
                'title' => $task->title,
                'description' => $task->description,
                'priority' => $task->priority,
                'tags' => $task->tags,
                'goal' => $task->goal ? [
                    'id' => $task->goal->id,
                    'title' => $task->goal->title,
                    'description' => $task->goal->description,
                ] : null,
                // Duplicated at task level for provisiond v0.1.0 compatibility
                'direct_reports' => $task->agent->directReports->map(fn (Agent $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'handle' => $r->handle,
                    'org_title' => $r->org_title,
                    'capabilities' => $r->capabilities,
                ]),
                'agent' => [
                    'id' => $task->agent->id,
                    'name' => $task->agent->name,
                    'handle' => $task->agent->handle,
                    'role' => $task->agent->role,
                    'org_title' => $task->agent->org_title,
                    'capabilities' => $task->agent->capabilities,
                    'delegation_enabled' => $task->agent->delegation_enabled,
                    'harness_type' => $task->agent->harness_type?->value ?? 'hermes',
                    'harness_agent_id' => $task->agent->harness_agent_id,
                    'api_server_port' => $task->agent->api_server_port,
                    'api_server_key' => $task->agent->api_server_key,
                    'direct_reports' => $task->agent->directReports->map(fn (Agent $r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                        'handle' => $r->handle,
                        'org_title' => $r->org_title,
                        'capabilities' => $r->capabilities,
                    ]),
                ],
            ]),
        ]);
    }

    /**
     * POST /api/daemon/{token}/tasks/{task}/checkout
     */
    public function checkoutTask(Request $request, string $token, Task $task): JsonResponse
    {
        $request->validate([
            'daemon_run_id' => ['required_without:run_id', 'nullable', 'string', 'max:255'],
            'run_id' => ['required_without:daemon_run_id', 'nullable', 'string', 'max:255'],
        ]);

        $runId = $request->input('daemon_run_id') ?? $request->input('run_id');

        /** @var Server $server */
        $server = $request->get('daemon_server');

        // Ensure the task's agent belongs to this server
        abort_unless(
            $task->agent && $task->agent->server_id === $server->id,
            403,
            'Task agent is not on this server.',
        );

        $success = $this->checkoutService->checkout($task, $runId);

        if (! $success) {
            return response()->json(['error' => 'Task is already checked out.'], 409);
        }

        $task->refresh()->load('agent', 'goal');

        return response()->json(['task' => $task]);
    }

    /**
     * POST /api/daemon/{token}/tasks/{task}/result
     */
    public function reportResult(ReportResultRequest $request, string $token, Task $task): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');

        abort_unless(
            $task->agent && $task->agent->server_id === $server->id,
            403,
            'Task agent is not on this server.',
        );

        $validated = $request->validated();
        $runId = $validated['daemon_run_id'] ?? $validated['run_id'] ?? null;
        $oldStatus = $task->status;

        // Update the task
        $taskUpdate = [
            'status' => $validated['status'],
            'result_summary' => $validated['result_summary'] ?? null,
            'tokens_input' => ($task->tokens_input ?? 0) + ($validated['tokens_input'] ?? 0),
            'tokens_output' => ($task->tokens_output ?? 0) + ($validated['tokens_output'] ?? 0),
        ];

        if ($validated['status'] === 'done') {
            $taskUpdate['completed_at'] = now();
        }

        $task->update($taskUpdate);

        // Create usage event
        if (($validated['tokens_input'] ?? 0) > 0 || ($validated['tokens_output'] ?? 0) > 0) {
            UsageEvent::query()->create([
                'team_id' => $task->team_id,
                'agent_id' => $task->agent_id,
                'task_id' => $task->id,
                'daemon_run_id' => $runId,
                'model' => $validated['model'] ?? 'unknown',
                'input_tokens' => $validated['tokens_input'] ?? 0,
                'output_tokens' => $validated['tokens_output'] ?? 0,
                'source' => UsageSource::Daemon,
            ]);
        }

        // Create work product records
        if (! empty($validated['work_products'])) {
            foreach ($validated['work_products'] as $wp) {
                TaskWorkProduct::query()->create([
                    'task_id' => $task->id,
                    'agent_id' => $task->agent_id,
                    'type' => $wp['type'] ?? 'file',
                    'title' => $wp['title'],
                    'file_path' => $wp['file_path'] ?? null,
                    'url' => $wp['url'] ?? null,
                    'summary' => $wp['summary'] ?? null,
                ]);
            }
        }

        // Process delegations — create sub-tasks for named direct reports
        if (! empty($validated['delegations']) && $task->agent?->delegation_enabled) {
            foreach ($validated['delegations'] as $delegation) {
                $agentRef = $delegation['agent_name'];
                $directReport = Agent::query()
                    ->where('reports_to', $task->agent_id)
                    ->where(fn ($q) => $q->where('handle', $agentRef)->orWhere('name', $agentRef))
                    ->first();

                if ($directReport) {
                    $subTask = Task::query()->create([
                        'team_id' => $task->team_id,
                        'agent_id' => $directReport->id,
                        'created_by_type' => 'agent',
                        'created_by_id' => $task->agent_id,
                        'title' => $delegation['title'],
                        'description' => $delegation['description'] ?? null,
                        'status' => 'todo',
                        'priority' => $delegation['priority'] ?? $task->priority,
                        'parent_task_id' => $task->id,
                        'goal_id' => $task->goal_id,
                        'delegated_by' => $task->agent_id,
                        'request_depth' => ($task->request_depth ?? 0) + 1,
                    ]);

                    $this->audit->log(
                        teamId: $task->team_id,
                        actorType: ActorType::Agent,
                        actorId: $task->agent_id,
                        action: 'task.delegated',
                        targetType: 'task',
                        targetId: $subTask->id,
                        payload: ['parent_task_id' => $task->id, 'delegate_agent' => $directReport->name],
                    );
                }
            }
        }

        // Process approval requests — create Approval records and block task
        if (! empty($validated['approval_requests'])) {
            foreach ($validated['approval_requests'] as $approvalReq) {
                $approval = Approval::query()->create([
                    'team_id' => $task->team_id,
                    'requesting_agent_id' => $task->agent_id,
                    'type' => ApprovalType::tryFrom($approvalReq['type']) ?? ApprovalType::ExternalAction,
                    'status' => ApprovalStatus::Pending,
                    'title' => $approvalReq['title'],
                    'payload' => ['description' => $approvalReq['description'] ?? '', 'raw' => $approvalReq],
                    'linked_task_id' => $task->id,
                    'expires_at' => now()->addHours(72),
                ]);

                event(new ApprovalRequestedEvent($approval->load('requestingAgent')));
            }

            // Block the task while awaiting approval
            if ($task->status !== 'done' && $task->status !== 'failed') {
                $task->update(['status' => 'blocked']);
            }
        }

        // Broadcast status change
        if ($oldStatus !== $task->status) {
            event(new TaskStatusChangedEvent($task->load('agent'), $oldStatus, $task->status));
        }

        $this->audit->log(
            teamId: $task->team_id,
            actorType: ActorType::Daemon,
            actorId: $server->id,
            action: 'task.result_reported',
            targetType: 'task',
            targetId: $task->id,
            payload: [
                'status' => $validated['status'],
                'daemon_run_id' => $runId,
            ],
        );

        return response()->json(['status' => 'ok', 'task' => $task->fresh()]);
    }

    /**
     * POST /api/daemon/{token}/tasks/{task}/release
     */
    public function releaseTask(Request $request, string $token, Task $task): JsonResponse
    {
        $request->validate([
            'daemon_run_id' => ['required_without:run_id', 'nullable', 'string', 'max:255'],
            'run_id' => ['required_without:daemon_run_id', 'nullable', 'string', 'max:255'],
        ]);

        $runId = $request->input('daemon_run_id') ?? $request->input('run_id');

        /** @var Server $server */
        $server = $request->get('daemon_server');

        abort_unless(
            $task->agent && $task->agent->server_id === $server->id,
            403,
            'Task agent is not on this server.',
        );

        $released = $this->checkoutService->release($task, $runId);

        if (! $released) {
            return response()->json(['error' => 'Task is not checked out by this run.'], 409);
        }

        return response()->json(['status' => 'released']);
    }

    /**
     * GET /api/daemon/{token}/resolved-approvals
     */
    public function resolvedApprovals(Request $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');

        $agentIds = Agent::query()
            ->where('server_id', $server->id)
            ->pluck('id');

        $approvals = Approval::query()
            ->whereIn('requesting_agent_id', $agentIds)
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Rejected])
            ->where('reviewed_at', '>=', now()->subDay())
            ->with(['requestingAgent:id,name', 'linkedTask:id,title,status'])
            ->orderByDesc('reviewed_at')
            ->get();

        return response()->json(['approvals' => $approvals]);
    }

    /**
     * POST /api/daemon/{token}/usage-events
     */
    public function reportUsage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'string', 'exists:agents,id'],
            'task_id' => ['nullable', 'string', 'exists:tasks,id'],
            'daemon_run_id' => ['nullable', 'string', 'max:255'],
            'run_id' => ['nullable', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'input_tokens' => ['required', 'integer', 'min:0'],
            'output_tokens' => ['required', 'integer', 'min:0'],
        ]);

        $runId = $validated['daemon_run_id'] ?? $validated['run_id'] ?? null;

        /** @var Server $server */
        $server = $request->get('daemon_server');

        $agent = Agent::query()->findOrFail($validated['agent_id']);
        abort_unless($agent->server_id === $server->id, 403, 'Agent is not on this server.');

        UsageEvent::query()->create([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'task_id' => $validated['task_id'] ?? null,
            'daemon_run_id' => $runId,
            'model' => $validated['model'],
            'input_tokens' => $validated['input_tokens'],
            'output_tokens' => $validated['output_tokens'],
            'source' => UsageSource::Daemon,
        ]);

        return response()->json(['status' => 'recorded']);
    }

    /**
     * POST /api/daemon/{token}/tasks/{task}/notes
     */
    public function postNote(Request $request, string $token, Task $task): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');

        abort_unless(
            $task->agent && $task->agent->server_id === $server->id,
            403,
            'Task agent is not on this server.',
        );

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $task->notes()->create([
            'author_type' => 'agent',
            'author_id' => $task->agent_id,
            'body' => $validated['body'],
        ]);

        return response()->json(['status' => 'ok', 'note' => $note]);
    }

    /**
     * POST /api/daemon/{token}/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->get('daemon_server');

        $server->update(['last_health_check' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
