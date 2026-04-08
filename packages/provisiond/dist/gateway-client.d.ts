/**
 * HTTP client for the local OpenClaw/Hermes gateway API.
 *
 * Sends messages via the Responses API at http://127.0.0.1:{port}/v1/responses.
 * Supports both OpenClaw (multi-agent) and Hermes (single-agent) modes.
 */
import type { GatewayResponse } from './types.js';
export interface SendMessageOptions {
    port: number;
    harnessType: 'openclaw' | 'hermes';
    harnessAgentId: string;
    apiServerKey: string | null;
    taskId: string;
    prompt: string;
    timeoutMs: number;
}
/**
 * Build the request body for the Responses API based on harness type.
 */
export declare function buildRequestBody(options: SendMessageOptions): Record<string, unknown>;
/**
 * Parse the Responses API output into a structured GatewayResponse.
 */
export declare function parseGatewayOutput(data: Record<string, unknown>): GatewayResponse;
/**
 * Send a message to the local gateway and return the parsed response.
 */
export declare function sendMessage(options: SendMessageOptions): Promise<GatewayResponse>;
//# sourceMappingURL=gateway-client.d.ts.map