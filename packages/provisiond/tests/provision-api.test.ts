import { afterEach, describe, expect, it, vi } from 'vitest';
import { ProvisionApiClient } from '../src/provision-api.js';
import type { Config, TaskResult, UsageEvent } from '../src/types.js';

const daemonToken = 'daemon-secret-that-must-not-appear-in-a-url';
const config: Config = {
    apiUrl: 'https://provision.test/',
    daemonToken,
    serverId: 'server-01',
    pollInterval: 30,
    maxConcurrent: 2,
    taskTimeout: 600,
    checkoutDuration: 3600,
};

function jsonResponse(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('ProvisionApiClient authentication and routing', () => {
    it('uses the server-scoped API path and sends the daemon token only as a Bearer header', async () => {
        const fetchMock = vi.fn(
            async (
                input: RequestInfo | URL,
                _init?: RequestInit,
            ): Promise<Response> => {
                const url = String(input);
                if (url.endsWith('/work-queue')) {
                    return jsonResponse({ tasks: [] });
                }
                if (url.endsWith('/checkout')) {
                    return jsonResponse({ task: { id: 'task-01' } });
                }
                if (url.endsWith('/resolved-approvals')) {
                    return jsonResponse({ approvals: [] });
                }

                return new Response(null, { status: 204 });
            },
        );
        vi.stubGlobal('fetch', fetchMock);
        const client = new ProvisionApiClient(config);
        const taskResult: TaskResult = {
            daemon_run_id: 'run-01',
            status: 'done',
            result_summary: 'Complete',
            tokens_input: 1,
            tokens_output: 2,
            model: 'test-model',
            delegations: [],
            approval_requests: [],
            work_products: [],
        };
        const usage: UsageEvent = {
            agent_id: 'agent-01',
            model: 'test-model',
            input_tokens: 1,
            output_tokens: 2,
            source: 'daemon',
        };

        await client.getWorkQueue();
        await client.checkoutTask('task-01', 'run-01');
        await client.reportResult('task-01', taskResult);
        await client.releaseTask('task-01', 'run-01', 'done');
        await client.getResolvedApprovals();
        await client.reportUsage(usage);
        await client.postNote('task-01', 'Progress update');
        await client.sendHeartbeat(['run-01'], '0.4.0', ['chat-relay-v1']);
        await client.reportChatEvents([
            {
                event: 'chat',
                agent_id: 'agent-01',
                session_key: 'agent:agent-01:main',
                run_id: 'run-01',
                state: 'final',
            },
        ]);
        await client.syncOpenClawSessions([
            {
                agentId: 'agent-01',
                key: 'agent:agent-01:main',
                kind: 'direct',
            },
        ]);

        const expectedRequests = [
            ['GET', '/work-queue'],
            ['POST', '/tasks/task-01/checkout'],
            ['POST', '/tasks/task-01/result'],
            ['POST', '/tasks/task-01/release'],
            ['GET', '/resolved-approvals'],
            ['POST', '/usage-events'],
            ['POST', '/tasks/task-01/notes'],
            ['POST', '/heartbeat'],
            ['POST', '/chat/events'],
            ['POST', '/chat/sessions/snapshot'],
        ] as const;
        expect(fetchMock).toHaveBeenCalledTimes(expectedRequests.length);

        for (const [index, [method, path]] of expectedRequests.entries()) {
            const [input, init] = fetchMock.mock.calls[index];
            const url = String(input);
            const headers = new Headers(init?.headers);

            expect(url).toBe(
                `https://provision.test/api/daemon/servers/server-01${path}`,
            );
            expect(url).not.toContain(daemonToken);
            expect(init?.method).toBe(method);
            expect(headers.get('Authorization')).toBe(`Bearer ${daemonToken}`);
        }
    });
});
