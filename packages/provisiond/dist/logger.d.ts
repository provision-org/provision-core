/**
 * Simple structured logger for provisiond.
 *
 * Outputs messages in the format: [provisiond] [LEVEL] message
 */
export declare const logger: {
    info(message: string, data?: Record<string, unknown>): void;
    warn(message: string, data?: Record<string, unknown>): void;
    error(message: string, data?: Record<string, unknown>): void;
    debug(message: string, data?: Record<string, unknown>): void;
};
//# sourceMappingURL=logger.d.ts.map