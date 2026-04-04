/**
 * Simple structured logger for provisiond.
 *
 * Outputs messages in the format: [provisiond] [LEVEL] message
 */
function timestamp() {
    return new Date().toISOString();
}
function log(level, message, data) {
    const prefix = `[provisiond] [${level}] ${timestamp()}`;
    if (data) {
        console.log(`${prefix} ${message}`, JSON.stringify(data));
    }
    else {
        console.log(`${prefix} ${message}`);
    }
}
export const logger = {
    info(message, data) {
        log('INFO', message, data);
    },
    warn(message, data) {
        log('WARN', message, data);
    },
    error(message, data) {
        log('ERROR', message, data);
    },
    debug(message, data) {
        if (process.env.PROVISION_DEBUG === '1') {
            log('DEBUG', message, data);
        }
    },
};
//# sourceMappingURL=logger.js.map