/**
 * HTTP client for Provision's daemon API.
 *
 * All endpoints are prefixed with /api/daemon/{token}/.
 * Uses Node's built-in fetch API (Node 22+).
 */

import { logger } from './logger.js';
import type { Config, WorkQueueTask, TaskResult, ResolvedApproval, UsageEvent } from './types.js';

export class ProvisionApiClient {
  private readonly baseUrl: string;
  private readonly token: string;

  constructor(config: Config) {
    this.baseUrl = `${config.apiUrl}/api/daemon/${config.daemonToken}`;
    this.token = config.daemonToken;
  }

  async getWorkQueue(): Promise<WorkQueueTask[]> {
    const res = await this.request('GET', '/work-queue');
    const data = (await res.json()) as { tasks: WorkQueueTask[] };
    return data.tasks;
  }

  async checkoutTask(
    taskId: string,
    runId: string,
  ): Promise<{ ok: boolean; task?: WorkQueueTask }> {
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

    const data = (await res.json()) as { task: WorkQueueTask };
    return { ok: true, task: data.task };
  }

  async reportResult(taskId: string, result: TaskResult): Promise<void> {
    const res = await this.request('POST', `/tasks/${taskId}/result`, result);
    if (!res.ok) {
      throw new Error(
        `Failed to report result for task ${taskId}: ${res.status} ${res.statusText}`,
      );
    }
  }

  async releaseTask(taskId: string, runId: string, reason?: string): Promise<void> {
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

  async getResolvedApprovals(): Promise<ResolvedApproval[]> {
    const res = await this.request('GET', '/resolved-approvals');
    const data = (await res.json()) as { approvals: ResolvedApproval[] };
    return data.approvals;
  }

  async reportUsage(event: UsageEvent): Promise<void> {
    const res = await this.request('POST', '/usage-events', event);
    if (!res.ok) {
      logger.error('Failed to report usage event', {
        status: res.status,
        statusText: res.statusText,
      });
    }
  }

  async sendHeartbeat(activeRuns: string[]): Promise<void> {
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

  private async request(
    method: string,
    path: string,
    body?: unknown,
  ): Promise<Response> {
    const url = `${this.baseUrl}${path}`;
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    };

    const init: RequestInit = { method, headers };
    if (body !== undefined) {
      init.body = JSON.stringify(body);
    }

    logger.debug(`${method} ${path}`);

    return fetch(url, init);
  }
}
