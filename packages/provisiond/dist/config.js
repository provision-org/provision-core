/**
 * Configuration loader for provisiond.
 *
 * Resolves config from (in priority order):
 * 1. CLI arguments (passed as overrides)
 * 2. Environment variables
 * 3. Config file at /etc/provisiond/config.json or --config path
 * 4. Defaults
 */
import { readFileSync, existsSync } from 'node:fs';
import { logger } from './logger.js';
const DEFAULTS = {
    pollInterval: 30,
    maxConcurrent: 2,
    taskTimeout: 600,
    checkoutDuration: 3600,
};
const DEFAULT_CONFIG_PATH = '/etc/provisiond/config.json';
function loadConfigFile(path) {
    if (!existsSync(path)) {
        return {};
    }
    try {
        const raw = readFileSync(path, 'utf-8');
        return JSON.parse(raw);
    }
    catch (err) {
        logger.warn(`Failed to parse config file at ${path}`, {
            error: err instanceof Error ? err.message : String(err),
        });
        return {};
    }
}
export function loadConfig(overrides = {}) {
    const configPath = overrides.config ?? process.env.PROVISION_CONFIG_PATH ?? DEFAULT_CONFIG_PATH;
    const file = loadConfigFile(configPath);
    const apiUrl = overrides.apiUrl ??
        process.env.PROVISION_API_URL ??
        file.api_url;
    const daemonToken = overrides.token ??
        process.env.PROVISION_DAEMON_TOKEN ??
        file.api_token;
    const serverId = overrides.serverId ??
        process.env.PROVISION_SERVER_ID ??
        file.server_id;
    if (!apiUrl) {
        throw new Error('Missing required config: PROVISION_API_URL (env) or api_url (config file) or --api-url');
    }
    if (!daemonToken) {
        throw new Error('Missing required config: PROVISION_DAEMON_TOKEN (env) or api_token (config file) or --token');
    }
    if (!serverId) {
        throw new Error('Missing required config: PROVISION_SERVER_ID (env) or server_id (config file) or --server-id');
    }
    const pollInterval = overrides.pollInterval ??
        parseIntEnv('PROVISION_POLL_INTERVAL') ??
        file.poll_interval_seconds ??
        DEFAULTS.pollInterval;
    const maxConcurrent = parseIntEnv('PROVISION_MAX_CONCURRENT') ??
        file.max_concurrent_tasks ??
        DEFAULTS.maxConcurrent;
    const taskTimeout = parseIntEnv('PROVISION_TASK_TIMEOUT') ??
        file.task_timeout_seconds ??
        DEFAULTS.taskTimeout;
    const checkoutDuration = parseIntEnv('PROVISION_CHECKOUT_DURATION') ??
        file.checkout_duration_seconds ??
        DEFAULTS.checkoutDuration;
    return {
        apiUrl: apiUrl.replace(/\/+$/, ''),
        daemonToken,
        serverId,
        pollInterval,
        maxConcurrent,
        taskTimeout,
        checkoutDuration,
    };
}
function parseIntEnv(name) {
    const val = process.env[name];
    if (val === undefined) {
        return undefined;
    }
    const parsed = parseInt(val, 10);
    return isNaN(parsed) ? undefined : parsed;
}
//# sourceMappingURL=config.js.map