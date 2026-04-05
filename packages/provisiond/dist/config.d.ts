/**
 * Configuration loader for provisiond.
 *
 * Resolves config from (in priority order):
 * 1. CLI arguments (passed as overrides)
 * 2. Environment variables
 * 3. Config file at /etc/provisiond/config.json or --config path
 * 4. Defaults
 */
import type { Config } from './types.js';
export interface CliOverrides {
    config?: string;
    apiUrl?: string;
    token?: string;
    serverId?: string;
    pollInterval?: number;
}
export declare function loadConfig(overrides?: CliOverrides): Config;
//# sourceMappingURL=config.d.ts.map