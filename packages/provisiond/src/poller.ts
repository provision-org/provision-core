/**
 * Main poll loop for provisiond.
 *
 * Runs indefinitely, polling Provision for work at the configured interval.
 * Manages concurrent task execution up to maxConcurrent limit.
 */

import { executeTask } from './executor.js';
import { logger } from './logger.js';
import { ProvisionApiClient } from './provision-api.js';
import type { Config } from './types.js';

/**
 * Tracks active task runs so we can enforce max concurrency
 * and report them in heartbeats.
 */
const activeRuns = new Map<string, Promise<void>>();

/**
 * Signal to stop the poll loop gracefully.
 */
let stopping = false;

export function requestStop(): void {
  stopping = true;
}

export function isRunning(): boolean {
  return !stopping;
}

export function getActiveRunCount(): number {
  return activeRuns.size;
}

export async function startPolling(config: Config): Promise<void> {
  const api = new ProvisionApiClient(config);

  logger.info('Poll loop started', {
    interval: config.pollInterval,
    maxConcurrent: config.maxConcurrent,
  });

  while (!stopping) {
    try {
      await pollOnce(config, api);
    } catch (err) {
      // Never crash the loop
      logger.error('Poll cycle failed', {
        error: err instanceof Error ? err.message : String(err),
      });
    }

    if (stopping) {
      break;
    }

    await sleep(config.pollInterval * 1000);
  }

  // Wait for active tasks to finish before exiting
  if (activeRuns.size > 0) {
    logger.info(`Waiting for ${activeRuns.size} active task(s) to finish...`);
    await Promise.allSettled(activeRuns.values());
  }

  logger.info('Poll loop stopped');
}

async function pollOnce(config: Config, api: ProvisionApiClient): Promise<void> {
  // Clean up completed runs
  for (const [runId, promise] of activeRuns.entries()) {
    // Check if settled by racing with an already-resolved promise
    const settled = await Promise.race([
      promise.then(() => true, () => true),
      Promise.resolve(false),
    ]);
    if (settled) {
      activeRuns.delete(runId);
    }
  }

  const availableSlots = config.maxConcurrent - activeRuns.size;
  if (availableSlots <= 0) {
    logger.debug('All slots occupied, skipping work-queue fetch');
    await sendHeartbeat(api);
    return;
  }

  // Fetch work queue
  const tasks = await api.getWorkQueue();

  if (tasks.length > 0) {
    logger.info(`Work queue: ${tasks.length} task(s) available, ${availableSlots} slot(s) free`);
  }

  // Spawn tasks up to available slots
  const toExecute = tasks.slice(0, availableSlots);
  for (const task of toExecute) {
    const runId = `${task.id}-${Date.now()}`;
    const taskPromise = executeTask(task, config, api).catch((err) => {
      logger.error(`Unhandled error in task ${task.identifier}`, {
        error: err instanceof Error ? err.message : String(err),
      });
    });
    activeRuns.set(runId, taskPromise);
  }

  // Check for resolved approvals
  try {
    const approvals = await api.getResolvedApprovals();
    if (approvals.length > 0) {
      logger.info(`${approvals.length} resolved approval(s) found`, {
        ids: approvals.map((a) => a.id),
      });
    }
  } catch (err) {
    logger.warn('Failed to fetch resolved approvals', {
      error: err instanceof Error ? err.message : String(err),
    });
  }

  // Heartbeat
  await sendHeartbeat(api);
}

async function sendHeartbeat(api: ProvisionApiClient): Promise<void> {
  try {
    await api.sendHeartbeat([...activeRuns.keys()]);
  } catch (err) {
    logger.warn('Heartbeat failed', {
      error: err instanceof Error ? err.message : String(err),
    });
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
