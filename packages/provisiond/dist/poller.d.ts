/**
 * Main poll loop for provisiond.
 *
 * Runs indefinitely, polling Provision for work at the configured interval.
 * Manages concurrent task execution up to maxConcurrent limit.
 */
import type { Config } from './types.js';
export declare function requestStop(): void;
export declare function isRunning(): boolean;
export declare function getActiveRunCount(): number;
export declare function startPolling(config: Config): Promise<void>;
//# sourceMappingURL=poller.d.ts.map