import { describe, it, expect } from 'vitest';
import { parseResponse } from '../src/response-parser.js';

describe('parseResponse', () => {
  it('extracts result summary from plain text', () => {
    const text = 'I completed the blog post. It covers AI agents and their applications.';
    const result = parseResponse(text);

    expect(result.resultSummary).toBe(text);
    expect(result.delegations).toHaveLength(0);
    expect(result.approvalRequests).toHaveLength(0);
  });

  it('parses DELEGATE: lines correctly', () => {
    const text = [
      'I drafted the outline.',
      'DELEGATE: Jordan | Write first draft | Write a 1500-word blog post based on the outline.',
    ].join('\n');

    const result = parseResponse(text);

    expect(result.resultSummary).toBe('I drafted the outline.');
    expect(result.delegations).toHaveLength(1);
    expect(result.delegations[0]).toEqual({
      assignToAgentName: 'Jordan',
      title: 'Write first draft',
      description: 'Write a 1500-word blog post based on the outline.',
    });
  });

  it('parses APPROVAL_REQUEST: lines correctly', () => {
    const text = [
      'I want to publish the post.',
      'APPROVAL_REQUEST: external_action | Publish blog post | Publishing the post to the company blog.',
    ].join('\n');

    const result = parseResponse(text);

    expect(result.resultSummary).toBe('I want to publish the post.');
    expect(result.approvalRequests).toHaveLength(1);
    expect(result.approvalRequests[0]).toEqual({
      type: 'external_action',
      title: 'Publish blog post',
      description: 'Publishing the post to the company blog.',
    });
  });

  it('handles mixed content (summary + delegates + approvals)', () => {
    const text = [
      'Completed the analysis.',
      '',
      'Key findings:',
      '- Revenue is up 20%',
      '- Churn is down',
      '',
      'DELEGATE: Kim | Build dashboard | Create a dashboard showing the key metrics from the analysis.',
      'DELEGATE: Jordan | Write report | Write an executive summary of the analysis findings.',
      'APPROVAL_REQUEST: strategy_proposal | New pricing model | Proposing a new pricing structure based on analysis.',
    ].join('\n');

    const result = parseResponse(text);

    expect(result.resultSummary).toContain('Completed the analysis.');
    expect(result.resultSummary).toContain('- Revenue is up 20%');
    expect(result.resultSummary).toContain('- Churn is down');
    expect(result.resultSummary).not.toContain('DELEGATE:');
    expect(result.resultSummary).not.toContain('APPROVAL_REQUEST:');

    expect(result.delegations).toHaveLength(2);
    expect(result.delegations[0].assignToAgentName).toBe('Kim');
    expect(result.delegations[1].assignToAgentName).toBe('Jordan');

    expect(result.approvalRequests).toHaveLength(1);
    expect(result.approvalRequests[0].type).toBe('strategy_proposal');
  });

  it('handles no special lines (pure summary)', () => {
    const text = [
      'Here is a multi-line summary.',
      '',
      'Paragraph two with details.',
      '',
      'Final paragraph.',
    ].join('\n');

    const result = parseResponse(text);

    expect(result.resultSummary).toBe(text.trim());
    expect(result.delegations).toHaveLength(0);
    expect(result.approvalRequests).toHaveLength(0);
  });

  it('handles malformed DELEGATE lines gracefully', () => {
    const text = [
      'Summary text.',
      'DELEGATE: only-one-part',
      'DELEGATE: two | parts',
      'DELEGATE: valid | title | description',
    ].join('\n');

    const result = parseResponse(text);

    // Malformed lines are kept in the summary
    expect(result.resultSummary).toContain('DELEGATE: only-one-part');
    expect(result.resultSummary).toContain('DELEGATE: two | parts');
    expect(result.resultSummary).not.toContain('DELEGATE: valid | title | description');

    // Only the valid one is parsed
    expect(result.delegations).toHaveLength(1);
    expect(result.delegations[0].assignToAgentName).toBe('valid');
  });

  it('handles malformed APPROVAL_REQUEST lines gracefully', () => {
    const text = [
      'Summary.',
      'APPROVAL_REQUEST: just-type',
      'APPROVAL_REQUEST: type | title | description',
    ].join('\n');

    const result = parseResponse(text);

    expect(result.resultSummary).toContain('APPROVAL_REQUEST: just-type');
    expect(result.approvalRequests).toHaveLength(1);
    expect(result.approvalRequests[0].type).toBe('type');
  });

  it('handles empty text', () => {
    const result = parseResponse('');

    expect(result.resultSummary).toBe('');
    expect(result.delegations).toHaveLength(0);
    expect(result.approvalRequests).toHaveLength(0);
  });

  it('preserves description with pipe characters', () => {
    const text =
      'DELEGATE: Jordan | Complex task | Do A | then B | then C';

    const result = parseResponse(text);

    expect(result.delegations).toHaveLength(1);
    expect(result.delegations[0].description).toBe('Do A | then B | then C');
  });
});
