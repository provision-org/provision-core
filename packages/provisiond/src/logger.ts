/**
 * Simple structured logger for provisiond.
 *
 * Outputs messages in the format: [provisiond] [LEVEL] message
 */

type LogLevel = 'INFO' | 'WARN' | 'ERROR' | 'DEBUG';

function timestamp(): string {
  return new Date().toISOString();
}

function log(level: LogLevel, message: string, data?: Record<string, unknown>): void {
  const prefix = `[provisiond] [${level}] ${timestamp()}`;
  if (data) {
    console.log(`${prefix} ${message}`, JSON.stringify(data));
  } else {
    console.log(`${prefix} ${message}`);
  }
}

export const logger = {
  info(message: string, data?: Record<string, unknown>): void {
    log('INFO', message, data);
  },

  warn(message: string, data?: Record<string, unknown>): void {
    log('WARN', message, data);
  },

  error(message: string, data?: Record<string, unknown>): void {
    log('ERROR', message, data);
  },

  debug(message: string, data?: Record<string, unknown>): void {
    if (process.env.PROVISION_DEBUG === '1') {
      log('DEBUG', message, data);
    }
  },
};
