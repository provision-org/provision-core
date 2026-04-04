/**
 * HTTP client for the local OpenClaw/Hermes gateway API.
 *
 * Sends messages via the Responses API at http://127.0.0.1:{port}/v1/responses.
 * Supports both OpenClaw (multi-agent) and Hermes (single-agent) modes.
 */

import type { GatewayResponse } from './types.js';
import { logger } from './logger.js';

export interface SendMessageOptions {
  port: number;
  harnessType: 'openclaw' | 'hermes';
  harnessAgentId: string;
  taskId: string;
  prompt: string;
  timeoutMs: number;
}

/**
 * Build the request body for the Responses API based on harness type.
 */
export function buildRequestBody(options: SendMessageOptions): Record<string, unknown> {
  const { harnessType, harnessAgentId, taskId, prompt } = options;

  if (harnessType === 'openclaw') {
    return {
      model: `openclaw/${harnessAgentId}`,
      input: prompt,
      user: `task:${taskId}`,
    };
  }

  // Hermes
  return {
    model: 'hermes-agent',
    input: prompt,
    conversation: `task:${taskId}`,
  };
}

/**
 * Parse the Responses API output into a structured GatewayResponse.
 */
export function parseGatewayOutput(data: Record<string, unknown>): GatewayResponse {
  // The Responses API returns output as an array of content items.
  // We extract the text from output_text or by concatenating output items.
  let outputText = '';

  if (typeof data.output_text === 'string') {
    outputText = data.output_text;
  } else if (Array.isArray(data.output)) {
    outputText = (data.output as Array<Record<string, unknown>>)
      .filter((item) => item.type === 'message')
      .flatMap((item) => {
        const content = item.content as Array<Record<string, unknown>> | undefined;
        if (!Array.isArray(content)) {
          return [];
        }
        return content
          .filter((c) => c.type === 'output_text')
          .map((c) => String(c.text ?? ''));
      })
      .join('\n');
  }

  const usage = data.usage as Record<string, number> | undefined;
  const inputTokens = usage?.input_tokens ?? 0;
  const outputTokens = usage?.output_tokens ?? 0;
  const model = typeof data.model === 'string' ? data.model : 'unknown';

  return { outputText, inputTokens, outputTokens, model };
}

/**
 * Send a message to the local gateway and return the parsed response.
 */
export async function sendMessage(options: SendMessageOptions): Promise<GatewayResponse> {
  const { port, timeoutMs } = options;
  const url = `http://127.0.0.1:${port}/v1/responses`;
  const body = buildRequestBody(options);

  logger.debug(`Gateway request to :${port}`, {
    model: body.model as string,
  });

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
      signal: controller.signal,
    });

    if (!res.ok) {
      const errorText = await res.text().catch(() => '');
      throw new Error(
        `Gateway returned ${res.status} ${res.statusText}: ${errorText}`,
      );
    }

    const data = (await res.json()) as Record<string, unknown>;
    return parseGatewayOutput(data);
  } finally {
    clearTimeout(timer);
  }
}
