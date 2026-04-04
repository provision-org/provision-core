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
import type { Config, WorkQueueTask } from './types.js';
import { ProvisionApiClient } from './provision-api.js';
export declare function executeTask(task: WorkQueueTask, config: Config, api: ProvisionApiClient): Promise<void>;
//# sourceMappingURL=executor.d.ts.map