/**
 * Provision Publish Skill for OpenClaw Agents
 *
 * Publishes a skill the agent has authored to its team's Skills library via
 * the Provision REST API. Mirrors the provision-tasks skill.
 */

import { readFileSync } from 'fs';
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

    if (!res.ok) {
        const text = await res.text();
        throw new Error(`API error ${res.status}: ${text}`);
    }

    return res.json();
}

// --- Tool function ---

export async function skill_publish({
    name,
    skill_content,
    content_file,
    description,
    readme,
    tags,
    tools,
    requires_env,
}) {
    try {
        let content = skill_content;
        if (!content && content_file) {
            content = readFileSync(content_file, 'utf8');
        }
        if (!name || !content) {
            return {
                success: false,
                error: 'name and skill_content (or content_file) are required',
            };
        }

        const body = { name, skill_content: content };
        if (description) body.description = description;
        if (readme) body.readme = readme;
        if (tags) body.tags = tags;
        if (tools) body.tools = tools;
        if (requires_env) body.requires_env = requires_env;

        const result = await api('POST', '/skills', body);
        return {
            success: true,
            skill: result,
            message: `Published "${result.name}" v${result.version} — visible in your team's Skills section.`,
        };
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
        case 'publish':
            return skill_publish({
                name: flags.name,
                skill_content: flags.content,
                content_file: flags.file,
                description: flags.description,
                readme: flags.readme,
                tags: flags.tags ? flags.tags.split(',') : undefined,
                tools: flags.tools ? flags.tools.split(',') : undefined,
                requires_env: flags.env ? flags.env.split(',') : undefined,
            });

        default:
            console.log(
                'Commands: publish --name "Skill Name" (--content "..." | --file path/to/SKILL.md) [--description "..."] [--tags a,b] [--tools browser,exec] [--env KEY1,KEY2]',
            );
            process.exit(0);
    }
}

if (command) {
    main().then((result) => console.log(JSON.stringify(result, null, 2)));
}
