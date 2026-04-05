import { describe, it, expect } from 'vitest';
import { buildRequestBody, parseGatewayOutput, type SendMessageOptions } from '../src/gateway-client.js';

function makeOptions(overrides: Partial<SendMessageOptions> = {}): SendMessageOptions {
  return {
    port: 8642,
    harnessType: 'hermes',
    harnessAgentId: '01kn-harness-1',
    taskId: '01kn-task-1',
    prompt: 'You are an agent. Do the task.',
    timeoutMs: 60000,
    ...overrides,
  };
}

describe('buildRequestBody', () => {
  it('builds correct request for OpenClaw', () => {
    const body = buildRequestBody(
      makeOptions({
        harnessType: 'openclaw',
        harnessAgentId: 'agent-xyz',
        taskId: 'task-123',
        prompt: 'Do the thing.',
      }),
    );

    expect(body.model).toBe('openclaw/agent-xyz');
    expect(body.input).toBe('Do the thing.');
    expect(body.user).toBe('task:task-123');
    expect(body).not.toHaveProperty('conversation');
  });

  it('builds correct request for Hermes', () => {
    const body = buildRequestBody(
      makeOptions({
        harnessType: 'hermes',
        taskId: 'task-456',
        prompt: 'Complete the task.',
      }),
    );

    expect(body.model).toBe('hermes-agent');
    expect(body.input).toBe('Complete the task.');
    expect(body.conversation).toBe('task:task-456');
    expect(body).not.toHaveProperty('user');
  });
});

describe('parseGatewayOutput', () => {
  it('parses Responses API output with output_text field', () => {
    const data = {
      output_text: 'Here is the result.',
      usage: {
        input_tokens: 1500,
        output_tokens: 300,
      },
      model: 'anthropic/claude-haiku-4-5',
    };

    const result = parseGatewayOutput(data);

    expect(result.outputText).toBe('Here is the result.');
    expect(result.inputTokens).toBe(1500);
    expect(result.outputTokens).toBe(300);
    expect(result.model).toBe('anthropic/claude-haiku-4-5');
  });

  it('parses Responses API output with output array format', () => {
    const data = {
      output: [
        {
          type: 'message',
          content: [
            { type: 'output_text', text: 'First part.' },
            { type: 'output_text', text: 'Second part.' },
          ],
        },
      ],
      usage: {
        input_tokens: 2000,
        output_tokens: 500,
      },
      model: 'anthropic/claude-sonnet-4',
    };

    const result = parseGatewayOutput(data);

    expect(result.outputText).toBe('First part.\nSecond part.');
    expect(result.inputTokens).toBe(2000);
    expect(result.outputTokens).toBe(500);
    expect(result.model).toBe('anthropic/claude-sonnet-4');
  });

  it('handles missing usage gracefully', () => {
    const data = {
      output_text: 'Some output.',
    };

    const result = parseGatewayOutput(data);

    expect(result.outputText).toBe('Some output.');
    expect(result.inputTokens).toBe(0);
    expect(result.outputTokens).toBe(0);
    expect(result.model).toBe('unknown');
  });

  it('handles empty output array', () => {
    const data = {
      output: [],
      usage: { input_tokens: 100, output_tokens: 50 },
      model: 'test-model',
    };

    const result = parseGatewayOutput(data);

    expect(result.outputText).toBe('');
    expect(result.inputTokens).toBe(100);
    expect(result.outputTokens).toBe(50);
  });

  it('filters non-message items from output array', () => {
    const data = {
      output: [
        { type: 'tool_call', content: [] },
        {
          type: 'message',
          content: [{ type: 'output_text', text: 'Actual output.' }],
        },
      ],
      usage: { input_tokens: 100, output_tokens: 50 },
      model: 'test-model',
    };

    const result = parseGatewayOutput(data);

    expect(result.outputText).toBe('Actual output.');
  });
});
