/**
 * HTTP client for Provision's daemon API.
 *
 * All endpoints are prefixed with /api/daemon/{token}/.
 * Uses Node's built-in fetch API (Node 22+).
 */
import { logger } from './logger.js';
export class ProvisionApiClient {
    baseUrl;
    token;
    constructor(config) {
        this.baseUrl = `${config.apiUrl}/api/daemon/${config.daemonToken}`;
        this.token = config.daemonToken;
    }
    async getWorkQueue() {
        const res = await this.request('GET', '/work-queue');
        const data = (await res.json());
        return data.tasks;
    }
    async checkoutTask(taskId, runId) {
        const res = await this.request('POST', `/tasks/${taskId}/checkout`, {
            daemon_run_id: runId,
        });
        if (res.status === 409) {
            logger.debug(`Task ${taskId} already checked out`);
            return { ok: false };
        }
        if (!res.ok) {
            logger.error(`Checkout failed for task ${taskId}`, {
                status: res.status,
                statusText: res.statusText,
            });
            return { ok: false };
        }
        const data = (await res.json());
        return { ok: true, task: data.task };
    }
    async reportResult(taskId, result) {
        const res = await this.request('POST', `/tasks/${taskId}/result`, result);
        if (!res.ok) {
            throw new Error(`Failed to report result for task ${taskId}: ${res.status} ${res.statusText}`);
        }
    }
    async releaseTask(taskId, runId, reason) {
        const res = await this.request('POST', `/tasks/${taskId}/release`, {
            daemon_run_id: runId,
            reason,
        });
        if (!res.ok) {
            logger.error(`Failed to release task ${taskId}`, {
                status: res.status,
                statusText: res.statusText,
            });
        }
    }
    async getResolvedApprovals() {
        const res = await this.request('GET', '/resolved-approvals');
        const data = (await res.json());
        return data.approvals;
    }
    async reportUsage(event) {
        const res = await this.request('POST', '/usage-events', event);
        if (!res.ok) {
            logger.error('Failed to report usage event', {
                status: res.status,
                statusText: res.statusText,
            });
        }
    }
    async postNote(taskId, body) {
        const res = await this.request('POST', `/tasks/${taskId}/notes`, { body });
        if (!res.ok) {
            logger.error(`Failed to post note for task ${taskId}`, {
                status: res.status,
                statusText: res.statusText,
            });
        }
    }
    async sendHeartbeat(activeRuns) {
        const res = await this.request('POST', '/heartbeat', {
            timestamp: new Date().toISOString(),
            active_runs: activeRuns,
        });
        if (!res.ok) {
            logger.warn('Heartbeat failed', {
                status: res.status,
                statusText: res.statusText,
            });
        }
    }
    async request(method, path, body) {
        const url = `${this.baseUrl}${path}`;
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        };
        const init = { method, headers };
        if (body !== undefined) {
            init.body = JSON.stringify(body);
        }
        logger.debug(`${method} ${path}`);
        return fetch(url, init);
    }
}
//# sourceMappingURL=provision-api.js.map