/**
 * Task executor — handles the full lifecycle of a single task execution.
 *
 * Flow:
 * 1. Generate run ID
 * 2. Checkout task (handle 409 gracefully)
 * 3. Build prompt
 * 4. Call gateway via sendMessage()
 * 5. Parse response
 * 6. Report result back to Provision
 * 7. On error: release checkout, log error
 */
import { randomUUID } from 'node:crypto';
import { sendMessage } from './gateway-client.js';
import { logger } from './logger.js';
import { buildPrompt } from './prompt-builder.js';
import { parseResponse } from './response-parser.js';
const OPENCLAW_DEFAULT_PORT = 18789;
export async function executeTask(task, config, api) {
    const runId = randomUUID();
    const taskLabel = `${task.identifier} (${task.id})`;
    logger.info(`Starting task ${taskLabel}`, { runId, agent: task.agent.name });
    // Step 1: Checkout
    const checkout = await api.checkoutTask(task.id, runId);
    if (!checkout.ok) {
        logger.info(`Skipping task ${taskLabel} — checkout failed (likely already checked out)`);
        return;
    }
    try {
        // Step 2: Build prompt
        const prompt = buildPrompt(task);
        // Step 3: Determine gateway details
        const port = task.agent.harness_type === 'hermes'
            ? task.agent.api_server_port
            : OPENCLAW_DEFAULT_PORT;
        // Step 4: Call gateway
        const gatewayResponse = await sendMessage({
            port,
            harnessType: task.agent.harness_type,
            harnessAgentId: task.agent.harness_agent_id,
            taskId: task.id,
            prompt,
            timeoutMs: config.taskTimeout * 1000,
        });
        // Step 5: Parse response
        const parsed = parseResponse(gatewayResponse.outputText);
        // Step 6: Determine result status
        let status = 'done';
        if (parsed.approvalRequests.length > 0) {
            status = 'blocked';
        }
        else if (parsed.delegations.length > 0) {
            status = 'in_progress';
        }
        // Step 7: Report result
        const result = {
            daemon_run_id: runId,
            status,
            result_summary: parsed.resultSummary,
            tokens_input: gatewayResponse.inputTokens,
            tokens_output: gatewayResponse.outputTokens,
            model: gatewayResponse.model,
            delegations: parsed.delegations.map((d) => ({
                assign_to_agent_name: d.assignToAgentName,
                title: d.title,
                description: d.description,
            })),
            approval_requests: parsed.approvalRequests.map((a) => ({
                type: a.type,
                title: a.title,
                description: a.description,
            })),
        };
        await api.reportResult(task.id, result);
        // Step 8: Report usage
        await api.reportUsage({
            agent_id: task.agent.id,
            task_id: task.id,
            model: gatewayResponse.model,
            input_tokens: gatewayResponse.inputTokens,
            output_tokens: gatewayResponse.outputTokens,
            source: 'daemon',
        });
        logger.info(`Completed task ${taskLabel}`, {
            status,
            inputTokens: gatewayResponse.inputTokens,
            outputTokens: gatewayResponse.outputTokens,
            delegations: parsed.delegations.length,
            approvalRequests: parsed.approvalRequests.length,
        });
    }
    catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        logger.error(`Task ${taskLabel} failed: ${message}`, { runId });
        // Release checkout so the task can be retried
        try {
            await api.releaseTask(task.id, runId, message);
        }
        catch (releaseErr) {
            logger.error(`Failed to release task ${taskLabel}`, {
                error: releaseErr instanceof Error ? releaseErr.message : String(releaseErr),
            });
        }
    }
}
//# sourceMappingURL=executor.js.map