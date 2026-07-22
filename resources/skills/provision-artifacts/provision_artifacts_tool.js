/**
 * Provision Artifacts Skill for OpenClaw Agents
 *
 * Publishes web artifacts (static sites or running apps) from the agent's
 * server to a public {agent-slug}.provisionagents.com subdomain via the
 * Provision REST API. Mirrors the provision-tasks skill.
 */

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
    const res = await fetch(`${apiUrl}/api${path}`, {
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

// --- Tool functions ---

export async function artifact_publish({
    name,
    path_slug,
    type,
    source_dir,
    start_command,
    visibility,
}) {
    try {
        if (!name) {
            return { success: false, error: 'name is required' };
        }
        if (type === 'app' && !start_command) {
            return {
                success: false,
                error: 'start_command is required when type is "app"',
            };
        }
        if (type === 'app' && !source_dir) {
            return {
                success: false,
                error: 'source_dir (--dir) is required when type is "app"',
            };
        }

        const body = { name };
        if (path_slug) body.path_slug = path_slug;
        if (type) body.type = type;
        if (source_dir) body.source_dir = source_dir;
        if (start_command) body.start_command = start_command;
        if (visibility) body.visibility = visibility;

        const result = await api('POST', '/artifacts', body);
        return {
            success: true,
            artifact: result,
            message: `Published "${result.name}" — live at ${result.public_url}`,
        };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function artifact_list() {
    try {
        const result = await api('GET', '/artifacts');
        return { success: true, artifacts: result };
    } catch (error) {
        return { success: false, error: error.message };
    }
}

export async function artifact_unpublish({ artifact_id }) {
    try {
        if (!artifact_id) {
            return { success: false, error: 'artifact_id is required' };
        }
        const result = await api('DELETE', `/artifacts/${artifact_id}`);
        return {
            success: true,
            message: result.message || 'Artifact unpublished.',
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
            return artifact_publish({
                name: flags.name,
                path_slug: flags.path,
                type: flags.type,
                source_dir: flags.dir,
                start_command: flags.command,
                visibility: flags.visibility,
            });

        case 'list':
            return artifact_list();

        case 'unpublish':
            return artifact_unpublish({ artifact_id: flags.id });

        default:
            console.log(
                'Commands:\n' +
                    '  publish --name "My Dashboard" [--path dashboard] [--type static --dir dashboard] [--visibility public|gated]\n' +
                    '  publish --name "My App" [--path app] --type app --dir app --command "npm start" [--visibility public|gated]\n' +
                    '  list\n' +
                    '  unpublish --id <artifact_id>',
            );
            process.exit(0);
    }
}

if (command) {
    main().then((result) => console.log(JSON.stringify(result, null, 2)));
}
