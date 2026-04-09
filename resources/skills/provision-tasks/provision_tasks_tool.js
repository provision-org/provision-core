/**
 * Provision Tasks Skill for OpenClaw Agents
 *
 * Provides task management via the Provision REST API.
 */

import { homedir } from 'os';
import { join } from 'path';
import dotenv from 'dotenv';

// Load environment variables — workspace .env first, then global fallback
dotenv.config({ path: join(process.cwd(), '.env') });
dotenv.config({ path: join(homedir(), '.openclaw', '.env') });
dotenv.config({ path: join(homedir(), '.env') });

const apiUrl = process.env.PROVISION_API_URL;
const token = process.env.PROVISION_AGENT_TOKEN;

if (!apiUrl) {
    console.error('PROVISION_API_URL not configured');
    process.exit(1);
}

if (!token) {
    console.error('PROVISION_AGENT_TOKEN not configured');
    process.exit(1);
}

async function api(method, path, body) {
    const res = await fetch(`${apiUrl}${path}`, {
        method,
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (res.status === 204) {
        return { success: true, message: 'No content' };
    }

    if (!res.ok) {
        const text = await res.text();
        throw new Error(`API error ${res.status}: ${text}`);
    }

    return res.json();
}

// --- Tool functions ---

export async function tasks_list({ status, assigned, priority, limit } = {}) {
    try {
        const params = new URLSearchParams();
        if (status) params.set('status', status);
        if (assigned) params.set('assigned', assigned);
        if (priority) params.set('priority', priority);
        const qs = params.toString();
        const result = await api('GET', `/tasks${qs ? '?' + qs : ''}`);
        const tasks = Array.isArray(result) ? result : result.data || [];
        return {
            success: true,
            count: tasks.length,
            tasks: tasks.slice(0, limit || 20).map((t) => ({
                id: t.id,
                title: t.title,
                status: t.status,
                priority: t.priority,
                blocked: t.blocked,
                assigned_agent: t.assigned_agent?.name || null,
                tags: t.tags,
            })),
        };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_create({
    title,
    description,
    priority,
    tags,
    assign_to,
}) {
    try {
        const body = { title };
        if (description) body.description = description;
        if (priority) body.priority = priority;
        if (tags) body.tags = tags;
        if (assign_to) body.assign_to = assign_to;
        const result = await api('POST', '/tasks', body);
        return { success: true, task: result };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_team_agents() {
    try {
        const result = await api('GET', '/tasks/team-agents');
        const agents = Array.isArray(result) ? result : result.data || [];
        return {
            success: true,
            count: agents.length,
            agents: agents.map((a) => ({
                id: a.id,
                name: a.name,
                role: a.role,
            })),
        };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_next() {
    try {
        const result = await api('GET', '/tasks/next');
        if (result.message === 'No content') {
            return { success: true, message: 'No tasks available', task: null };
        }
        return { success: true, task: result };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_show({ task_id }) {
    try {
        const result = await api('GET', `/tasks/${task_id}`);
        return { success: true, task: result };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_claim({ task_id }) {
    try {
        const result = await api('PATCH', `/tasks/${task_id}/claim`);
        return {
            success: true,
            task: result,
            message: 'Task claimed and moved to in_progress',
        };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_unclaim({ task_id }) {
    try {
        const result = await api('PATCH', `/tasks/${task_id}/unclaim`);
        return {
            success: true,
            task: result,
            message: 'Task released and moved to up_next',
        };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_complete({ task_id, note }) {
    try {
        const body = {};
        if (note) body.note = note;
        const result = await api(
            'PATCH',
            `/tasks/${task_id}/complete`,
            Object.keys(body).length ? body : undefined,
        );
        return { success: true, task: result, message: 'Task completed' };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_block({ task_id, reason }) {
    try {
        const result = await api('PATCH', `/tasks/${task_id}/block`, {
            reason,
        });
        return { success: true, task: result, message: 'Task blocked' };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_add_note({ task_id, note }) {
    try {
        const result = await api('POST', `/tasks/${task_id}/notes`, {
            body: note,
        });
        return { success: true, activity: result, message: 'Note added' };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function tasks_update({
    task_id,
    title,
    description,
    priority,
    status,
    tags,
}) {
    try {
        const body = {};
        if (title !== undefined) body.title = title;
        if (description !== undefined) body.description = description;
        if (priority !== undefined) body.priority = priority;
        if (status !== undefined) body.status = status;
        if (tags !== undefined) body.tags = tags;
        const result = await api('PATCH', `/tasks/${task_id}`, body);
        return { success: true, task: result };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

// --- CLI interface ---

function parseArgs(args) {
    const result = {};
    for (let i = 0; i < args.length; i++) {
        if (args[i].startsWith('--')) {
            const key = args[i].slice(2);
            result[key] = args[i + 1] || true;
            i++;
        }
    }
    return result;
}

const command = process.argv[2];

async function main() {
    const args = process.argv.slice(3);
    const flags = parseArgs(args);

    switch (command) {
        case 'list':
            return tasks_list({
                status: flags.status,
                assigned: flags.assigned,
                priority: flags.priority,
                limit: flags.limit ? parseInt(flags.limit) : undefined,
            });

        case 'next':
            return tasks_next();

        case 'show':
            if (!args[0]) {
                console.error('Usage: show <task_id>');
                process.exit(1);
            }
            return tasks_show({ task_id: args[0] });

        case 'create':
            if (!args[0]) {
                console.error(
                    'Usage: create "title" ["description"] [--priority medium] [--tags tag1,tag2] [--assign agent-name]',
                );
                process.exit(1);
            }
            return tasks_create({
                title: args[0],
                description:
                    args[1] && !args[1].startsWith('--') ? args[1] : undefined,
                priority: flags.priority,
                tags: flags.tags ? flags.tags.split(',') : undefined,
                assign_to: flags.assign,
            });

        case 'team-agents':
            return tasks_team_agents();

        case 'claim':
            if (!args[0]) {
                console.error('Usage: claim <task_id>');
                process.exit(1);
            }
            return tasks_claim({ task_id: args[0] });

        case 'unclaim':
            if (!args[0]) {
                console.error('Usage: unclaim <task_id>');
                process.exit(1);
            }
            return tasks_unclaim({ task_id: args[0] });

        case 'complete':
            if (!args[0]) {
                console.error('Usage: complete <task_id> ["note"]');
                process.exit(1);
            }
            return tasks_complete({
                task_id: args[0],
                note:
                    args[1] && !args[1].startsWith('--') ? args[1] : undefined,
            });

        case 'block':
            if (!args[0] || !args[1]) {
                console.error('Usage: block <task_id> "reason"');
                process.exit(1);
            }
            return tasks_block({ task_id: args[0], reason: args[1] });

        case 'note':
            if (!args[0] || !args[1]) {
                console.error('Usage: note <task_id> "note text"');
                process.exit(1);
            }
            return tasks_add_note({ task_id: args[0], note: args[1] });

        case 'update':
            if (!args[0]) {
                console.error(
                    'Usage: update <task_id> [--title "..."] [--priority ...] [--status ...]',
                );
                process.exit(1);
            }
            return tasks_update({
                task_id: args[0],
                title: flags.title,
                description: flags.description,
                priority: flags.priority,
                status: flags.status,
                tags: flags.tags ? flags.tags.split(',') : undefined,
            });

        default:
            console.log(
                'Commands: list, next, show, create, claim, unclaim, complete, block, note, update, team-agents',
            );
            process.exit(0);
    }
}

if (command) {
    main().then((result) => console.log(JSON.stringify(result, null, 2)));
}
