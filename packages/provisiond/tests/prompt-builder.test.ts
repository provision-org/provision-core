import { describe, it, expect } from 'vitest';
import { buildPrompt } from '../src/prompt-builder.js';
import type { WorkQueueTask } from '../src/types.js';

function makeTask(overrides: Partial<WorkQueueTask> = {}): WorkQueueTask {
  return {
    id: '01kn-task-1',
    identifier: 'TSK-42',
    title: 'Write blog post about AI agents',
    description: 'Write a 1500-word blog post covering the basics of AI agents.',
    priority: 'high',
    status: 'todo',
    agent: {
      id: '01kn-agent-1',
      name: 'Mira',
      harness_agent_id: '01kn-harness-1',
      harness_type: 'hermes',
      api_server_port: 8642,
      org_title: 'Content Lead',
      manager_name: 'Alex',
    },
    goal: {
      id: '01kn-goal-1',
      title: 'Launch marketing site',
      parent_title: 'Reach $1M MRR',
      root_title: 'Build the #1 AI agent platform',
    },
    direct_reports: [
      {
        name: 'Jordan',
        org_title: 'Writer',
        capabilities: 'Blog posts, social media copy',
      },
    ],
    ...overrides,
  };
}

describe('buildPrompt', () => {
  it('builds correct prompt with all fields', () => {
    const prompt = buildPrompt(makeTask());

    expect(prompt).toContain('# Task Assignment');
    expect(prompt).toContain('You are Mira, Content Lead.');
    expect(prompt).toContain('You report to Alex.');
    expect(prompt).toContain('**TSK-42:** Write blog post about AI agents');
    expect(prompt).toContain('Priority: high');
    expect(prompt).toContain('Write a 1500-word blog post');
    expect(prompt).toContain('This task serves: Launch marketing site');
    expect(prompt).toContain('Which is part of: Reach $1M MRR');
    expect(prompt).toContain('Team mission: Build the #1 AI agent platform');
    expect(prompt).toContain('- Jordan (Jordan, Writer): Blog posts, social media copy');
    expect(prompt).toContain('DELEGATE:');
    expect(prompt).toContain('APPROVAL_REQUEST:');
  });

  it('handles missing goal (no goal context section)', () => {
    const prompt = buildPrompt(makeTask({ goal: null }));

    expect(prompt).not.toContain('## Goal Context');
    expect(prompt).not.toContain('This task serves:');
    expect(prompt).toContain('## Current Task');
    expect(prompt).toContain('## Instructions');
  });

  it('handles no direct reports (no team section)', () => {
    const prompt = buildPrompt(makeTask({ direct_reports: [] }));

    expect(prompt).not.toContain('## Your Team');
    expect(prompt).not.toContain('Jordan');
    // Should not include delegation instructions when no reports
    expect(prompt).not.toContain('To delegate sub-tasks');
    // But should still have approval instructions
    expect(prompt).toContain('APPROVAL_REQUEST:');
  });

  it('handles missing manager (reports to board)', () => {
    const task = makeTask();
    task.agent.manager_name = null;
    const prompt = buildPrompt(task);

    expect(prompt).toContain('You report directly to the board.');
    expect(prompt).not.toContain('You report to');
  });

  it('handles goal with no parent or root', () => {
    const prompt = buildPrompt(
      makeTask({
        goal: {
          id: '01kn-goal-1',
          title: 'Ship v1',
          parent_title: null,
          root_title: null,
        },
      }),
    );

    expect(prompt).toContain('This task serves: Ship v1');
    expect(prompt).not.toContain('Which is part of:');
    expect(prompt).not.toContain('Team mission:');
  });
});
