#!/usr/bin/env node
/**
 * provisiond — Provision Workforce Agent Daemon
 *
 * A lightweight Node.js process that runs on agent servers and orchestrates
 * workforce agent task execution by polling Provision for work, invoking
 * agents through the gateway API, and reporting results.
 */
import { loadConfig } from './config.js';
import { logger } from './logger.js';
import { startPolling, requestStop, getActiveRunCount } from './poller.js';
const VERSION = '0.1.0';
function printBanner() {
    console.log(`provisiond v${VERSION} — Provision Workforce Agent Daemon`);
    console.log('');
}
function parseArgs(argv) {
    const overrides = {};
    const args = argv.slice(2);
    for (let i = 0; i < args.length; i++) {
        const arg = args[i];
        const next = args[i + 1];
        switch (arg) {
            case '--config':
                overrides.config = next;
                i++;
                break;
            case '--api-url':
                overrides.apiUrl = next;
                i++;
                break;
            case '--token':
                overrides.token = next;
                i++;
                break;
            case '--server-id':
                overrides.serverId = next;
                i++;
                break;
            case '--poll-interval':
                overrides.pollInterval = parseInt(next, 10);
                i++;
                break;
            case '--help':
            case '-h':
                printUsage();
                process.exit(0);
                break;
            case '--version':
            case '-v':
                console.log(`provisiond v${VERSION}`);
                process.exit(0);
                break;
            default:
                if (arg.startsWith('--')) {
                    logger.warn(`Unknown argument: ${arg}`);
                }
        }
    }
    return overrides;
}
function printUsage() {
    console.log(`
Usage: provisiond [options]

Options:
  --config <path>         Path to config file (default: /etc/provisiond/config.json)
  --api-url <url>         Provision API URL
  --token <token>         Daemon authentication token
  --server-id <id>        Server ID
  --poll-interval <sec>   Poll interval in seconds (default: 30)
  -h, --help              Show this help message
  -v, --version           Show version

Environment variables:
  PROVISION_API_URL          API URL
  PROVISION_DAEMON_TOKEN     Daemon token
  PROVISION_SERVER_ID        Server ID
  PROVISION_POLL_INTERVAL    Poll interval (seconds)
  PROVISION_MAX_CONCURRENT   Max concurrent tasks (default: 2)
  PROVISION_TASK_TIMEOUT     Task timeout (seconds, default: 600)
  PROVISION_CHECKOUT_DURATION  Checkout duration (seconds, default: 3600)
  PROVISION_CONFIG_PATH      Config file path
  PROVISION_DEBUG            Set to "1" for debug logging
`);
}
function redactToken(token) {
    if (token.length <= 8) {
        return '****';
    }
    return `${token.slice(0, 4)}...${token.slice(-4)}`;
}
async function main() {
    printBanner();
    const overrides = parseArgs(process.argv);
    let config;
    try {
        config = loadConfig(overrides);
    }
    catch (err) {
        logger.error(err instanceof Error ? err.message : String(err));
        process.exit(1);
    }
    logger.info('Configuration loaded', {
        apiUrl: config.apiUrl,
        serverId: config.serverId,
        token: redactToken(config.daemonToken),
        pollInterval: config.pollInterval,
        maxConcurrent: config.maxConcurrent,
        taskTimeout: config.taskTimeout,
        checkoutDuration: config.checkoutDuration,
    });
    // Graceful shutdown
    const shutdown = () => {
        logger.info('Shutdown signal received, finishing active tasks...');
        requestStop();
        // Force exit if tasks don't finish within 30 seconds
        setTimeout(() => {
            const remaining = getActiveRunCount();
            if (remaining > 0) {
                logger.warn(`Force exiting with ${remaining} active task(s)`);
            }
            process.exit(0);
        }, 30_000);
    };
    process.on('SIGTERM', shutdown);
    process.on('SIGINT', shutdown);
    await startPolling(config);
    logger.info('Daemon stopped');
    process.exit(0);
}
main().catch((err) => {
    logger.error('Fatal error', {
        error: err instanceof Error ? err.message : String(err),
    });
    process.exit(1);
});
//# sourceMappingURL=index.js.map