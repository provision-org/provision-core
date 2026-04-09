/**
 * Parses the agent's response text to extract delegations, approval requests,
 * work products, and the main result summary.
 *
 * Parsing rules:
 * - Lines starting with "DELEGATE:" are extracted as delegations.
 *   Format: DELEGATE: {agent_name} | {title} | {description}
 * - Lines starting with "APPROVAL_REQUEST:" are extracted as approval requests.
 *   Format: APPROVAL_REQUEST: {type} | {title} | {description}
 * - Lines starting with "WORK_PRODUCT:" are extracted as work products.
 *   Format: WORK_PRODUCT: {title} | {file_path} | {summary}
 * - Everything else becomes the result summary.
 */
import type { ParsedResponse } from './types.js';
export declare function parseResponse(text: string): ParsedResponse;
//# sourceMappingURL=response-parser.d.ts.map