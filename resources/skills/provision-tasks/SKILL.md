---
name: provision-tasks
description: Track your work on the team's task board. Create tasks, delegate to teammates, add progress notes, and mark complete when done.
metadata: {"openclaw":{"requires":{"bins":["node"],"env":["PROVISION_API_URL","PROVISION_AGENT_TOKEN"]},"primaryEnv":"PROVISION_AGENT_TOKEN"}}
---

# Provision Tasks Skill

Use the provision_tasks_tool.js script in {baseDir} to log your work on the team's task board.

## Environment

The following environment variables are available:

- PROVISION_API_URL: API base URL for the Provision app
- PROVISION_AGENT_TOKEN: Your API authentication token

## When to Use

**Create a task** whenever you start working on something substantive — a research request, a writing task, a multi-step project, debugging, etc. Quick one-line answers don't need a task.

**Skip task creation** for trivial interactions: greetings, simple factual questions, clarifying questions, or when you're just chatting.

## Workflow

1. **Create a task** when you begin substantive work: `tasks_create "Research competitor pricing"`
2. **Add progress notes** as you work through steps: `tasks_add_note <id> "Found 5 competitors, analyzing pricing tiers"`
3. **Complete with a summary** when finished: `tasks_complete <id> "Compiled pricing comparison for 5 competitors"`
4. **Block if stuck**: `tasks_block <id> "Need access to their enterprise pricing page"` — this flags it for the team
5. **Check existing tasks** if someone asks about status: `tasks_list --assigned mine`

## Commands

All commands use Node.js. Run from {baseDir}:

### Create a task

```bash
node {baseDir}/provision_tasks_tool.js create "Task title" ["Description"] [--priority medium] [--tags tag1,tag2] [--assign agent-name-or-handle]
```

Use `--assign` to delegate a task to another agent on your team. You can use their name or @handle. The assigned agent will be notified immediately.

### Add a progress note

```bash
node {baseDir}/provision_tasks_tool.js note <task_id> "Progress note"
```

### Complete a task

```bash
node {baseDir}/provision_tasks_tool.js complete <task_id> ["Completion summary"]
```

### Block a task

```bash
node {baseDir}/provision_tasks_tool.js block <task_id> "Reason for blocking"
```

### List tasks

```bash
node {baseDir}/provision_tasks_tool.js list [--status in_progress] [--assigned mine]
```

### Show task details

```bash
node {baseDir}/provision_tasks_tool.js show <task_id>
```

### Update a task

```bash
node {baseDir}/provision_tasks_tool.js update <task_id> [--title "New title"] [--priority high] [--status in_review]
```

### Claim a pre-existing task

```bash
node {baseDir}/provision_tasks_tool.js claim <task_id>
```

### Release a task

```bash
node {baseDir}/provision_tasks_tool.js unclaim <task_id>
```

### List team agents

```bash
node {baseDir}/provision_tasks_tool.js team-agents
```

Shows all active agents on your team. Use their names with `--assign` when creating tasks.

## Delegating to Workforce Agents

When you use `--assign` to delegate to a workforce agent (one that runs autonomously), the task enters a **todo** queue rather than the standard board. The workflow is asynchronous:

1. Create the task with `--assign max` (or whatever the agent's name or @handle is)
2. The assigned agent will pick it up automatically in their next work cycle
3. You will receive a notification when the task is **done**, **failed**, or **blocked**
4. You do NOT need to poll `tasks_list` — the system will message you automatically

Use `tasks_show <task_id>` if you need to check status before the notification arrives.

Use `tasks_list --status todo` to see tasks that are queued but not yet started.

## Guidelines

- Create tasks for substantive work so your team has visibility into what you're doing
- Use clear, descriptive titles — the board is a dashboard your team reads at a glance
- Add notes at meaningful milestones, not every micro-step
- Complete with a brief summary of the outcome, not just "done"
- If someone created a task for you (it appears in `tasks_list --assigned mine`), claim it and work on it
- Use tags to categorize work: `research`, `writing`, `code`, `analysis`, etc.
- Set priority to `high` for urgent requests
- To delegate work to a teammate, use `team-agents` to see who's available, then `create "title" --assign "Agent Name"`
- The assigned agent will be notified immediately and can claim the task
