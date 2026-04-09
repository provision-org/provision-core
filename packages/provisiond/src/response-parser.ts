/**
 * Parses the agent's response text to extract delegations, approval requests,
 * and the main result summary.
 *
 * Parsing rules:
 * - Lines starting with "DELEGATE:" are extracted as delegations.
 *   Format: DELEGATE: {agent_name} | {title} | {description}
 * - Lines starting with "APPROVAL_REQUEST:" are extracted as approval requests.
 *   Format: APPROVAL_REQUEST: {type} | {title} | {description}
 * - Everything else becomes the result summary.
 */

import { logger } from './logger.js';
import type { ParsedResponse } from './types.js';

const DELEGATE_PREFIX = 'DELEGATE:';
const APPROVAL_PREFIX = 'APPROVAL_REQUEST:';

export function parseResponse(text: string): ParsedResponse {
  const lines = text.split('\n');
  const summaryLines: string[] = [];
  const delegations: ParsedResponse['delegations'] = [];
  const approvalRequests: ParsedResponse['approvalRequests'] = [];

  for (const line of lines) {
    const trimmed = line.trim();

    if (trimmed.startsWith(DELEGATE_PREFIX)) {
      const delegation = parseDelegation(trimmed.slice(DELEGATE_PREFIX.length).trim());
      if (delegation) {
        delegations.push(delegation);
      } else {
        logger.warn('Malformed DELEGATE line, including in summary', { line: trimmed });
        summaryLines.push(line);
      }
      continue;
    }

    if (trimmed.startsWith(APPROVAL_PREFIX)) {
      const approval = parseApproval(trimmed.slice(APPROVAL_PREFIX.length).trim());
      if (approval) {
        approvalRequests.push(approval);
      } else {
        logger.warn('Malformed APPROVAL_REQUEST line, including in summary', { line: trimmed });
        summaryLines.push(line);
      }
      continue;
    }

    summaryLines.push(line);
  }

  const resultSummary = summaryLines.join('\n').trim();

  return { resultSummary, delegations, approvalRequests };
}

function parseDelegation(
  raw: string,
): ParsedResponse['delegations'][number] | null {
  const parts = raw.split('|').map((s) => s.trim());
  if (parts.length < 3 || !parts[0] || !parts[1] || !parts[2]) {
    return null;
  }
  return {
    assignToAgentName: parts[0].replace(/^@/, ''),
    title: parts[1],
    description: parts.slice(2).join(' | '),
  };
}

function parseApproval(
  raw: string,
): ParsedResponse['approvalRequests'][number] | null {
  const parts = raw.split('|').map((s) => s.trim());
  if (parts.length < 3 || !parts[0] || !parts[1] || !parts[2]) {
    return null;
  }
  return {
    type: parts[0],
    title: parts[1],
    description: parts.slice(2).join(' | '),
  };
}
