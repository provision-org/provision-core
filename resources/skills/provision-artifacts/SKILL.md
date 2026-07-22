---
name: provision-artifacts
description: Publish web artifacts (static sites or running apps) from your server to a public {your-slug}.provisionagents.com subdomain so people can open them in a browser.
metadata:
    {
        'openclaw':
            {
                'requires':
                    {
                        'bins': ['node'],
                        'env': ['PROVISION_API_URL', 'PROVISION_AGENT_TOKEN'],
                    },
                'primaryEnv': 'PROVISION_AGENT_TOKEN',
            },
    }
---

# Provision Artifacts Skill

Use the provision_artifacts_tool.js script in {baseDir} to publish web artifacts
you've built to a public URL. Anything you publish becomes reachable at
`https://{your-slug}.provisionagents.com/{path}/` — a real link you can share.

## Environment

The following environment variables are available:

- PROVISION_API_URL: API base URL for the Provision app
- PROVISION_AGENT_TOKEN: Your API authentication token

## When to Use

Publish an artifact when you've produced something a human should **open in a
browser**: a report, a dashboard, a small web app, an interactive visualization.
Two kinds:

- **static** — pre-built files (HTML/CSS/JS). Put them in a directory under this
  agent's `public/` folder, then publish that directory. Best for reports and
  dashboards.
- **app** — a long-running web server (Next.js, a Node/Python API, a Streamlit
  app). Put the app under this agent's `public/` folder and provide that
  directory plus a start command. The command must listen on the `PORT`
  environment variable that Provision passes in. Best for anything dynamic.

`--dir` is always relative to this agent's actual `public/` root. For example,
if a project is in `public/leads/`, use `--dir leads` — never `~/public/leads`,
`~/apps/leads`, or another absolute path. Provision starts app commands with
that directory as their working directory.

## Visibility

- **public** (default) — anyone with the link can open it.
- **gated** — the returned URL includes a secret `?token=` that's required to
  view it. Use for anything not meant for the whole internet. Revoke by
  unpublishing and re-publishing (which mints a new token).

## Workflow

### Publish a static report

1. Build your files into `public/q3-report/` in the current agent workspace (an
   `index.html` plus assets).
2. Publish it:

```bash
node {baseDir}/provision_artifacts_tool.js publish \
  --name "Q3 Report" \
  --path report \
  --type static \
  --dir q3-report \
  --visibility public
```

It returns `public_url`, e.g. `https://acme-bot.provisionagents.com/report/`.

### Publish a running app

1. Put the app in `public/leads/` in the current agent workspace. Make sure it
   reads the port from `process.env.PORT` (Node) / `os.environ["PORT"]` (Python)
   and binds `0.0.0.0`.
2. Publish it with the start command:

```bash
node {baseDir}/provision_artifacts_tool.js publish \
  --name "Lead Explorer" \
  --path leads \
  --type app \
  --dir leads \
  --command "npm run start" \
  --visibility gated
```

`--dir` is required for apps. Provision allocates a port, runs the command from
`public/leads/` as a managed service (restarts on crash/reboot), and
reverse-proxies the subdomain path to it.

### List what you've published

```bash
node {baseDir}/provision_artifacts_tool.js list
```

### Take something offline

```bash
node {baseDir}/provision_artifacts_tool.js unpublish --id <artifact_id>
```

## Notes

- Re-publishing the same `--path` updates the existing artifact in place.
- Publishing is idempotent: run it again after rebuilding your static files to
  atomically stage and ship a new immutable copy.
- Artifacts are served below `/{path}/`, and Provision strips that prefix before
  serving the directory or forwarding to an app. Use relative asset, API, and
  navigation URLs such as `./assets/app.js` and `./api/items`. Root-absolute
  URLs such as `/assets/app.js` escape the artifact route and will fail. When a
  framework has a base or asset-path option, configure it to emit relative URLs
  (for example, Vite's `base: './'`) or otherwise preserve the published path.
- The first request to a brand-new subdomain may take a few seconds while a TLS
  certificate is issued.
