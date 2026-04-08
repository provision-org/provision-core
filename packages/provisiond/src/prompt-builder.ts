/**
 * Builds the markdown prompt that the daemon sends to an agent for a task.
 *
 * Uses the template defined in PRD section 5.4.
 */

import type { WorkQueueTask } from './types.js';

export function buildPrompt(task: WorkQueueTask): string {
  const lines: string[] = [];

  // --- Identity ---
  lines.push('# Task Assignment');
  lines.push('');
  lines.push('## Your Identity');
  lines.push(`You are ${task.agent.name}, ${task.agent.org_title}.`);

  if (task.agent.manager_name) {
    lines.push(`You report to ${task.agent.manager_name}.`);
  } else {
    lines.push('You report directly to the board.');
  }

  // --- Task ---
  lines.push('');
  lines.push('## Current Task');
  lines.push(`**${task.identifier}:** ${task.title}`);
  lines.push(`Priority: ${task.priority}`);
  lines.push('');
  lines.push(task.description);

  // --- Goal Context ---
  if (task.goal) {
    lines.push('');
    lines.push('## Goal Context');
    lines.push(`This task serves: ${task.goal.title}`);

    if (task.goal.parent_title) {
      lines.push(`Which is part of: ${task.goal.parent_title}`);
    }

    if (task.goal.root_title) {
      lines.push(`Team mission: ${task.goal.root_title}`);
    }
  }

  // --- Direct Reports ---
  const directReports = task.direct_reports ?? [];
  if (directReports.length > 0) {
    lines.push('');
    lines.push('## Your Team (Direct Reports)');
    for (const report of directReports) {
      const ref = report.handle ? `@${report.handle}` : report.name;
      lines.push(`- ${ref} (${report.name}, ${report.org_title}): ${report.capabilities}`);
    }
  }

  // --- Instructions ---
  lines.push('');
  lines.push('## Instructions');
  lines.push('Complete this task. You have access to your browser, terminal, and workspace.');
  lines.push('');
  lines.push('When done, provide a summary of what you accomplished.');

  if (directReports.length > 0) {
    lines.push('');
    lines.push('To delegate sub-tasks to your reports:');
    lines.push('DELEGATE: @{report_handle} | {sub-task title} | {sub-task description}');
  }

  lines.push('');
  lines.push('To request approval for a high-impact action:');
  lines.push('APPROVAL_REQUEST: {type} | {title} | {description}');

  return lines.join('\n');
}
