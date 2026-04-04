# provisiond

Lightweight Node.js daemon that runs on agent servers and orchestrates workforce agent task execution. It polls Provision for assigned tasks, invokes agents through the local gateway API (OpenClaw or Hermes), and reports results back.

## Requirements

- Node.js 22+
- An agent server with OpenClaw or Hermes gateway running

## Installation

```bash
npm install -g @provision/daemon
```

## Configuration

provisiond reads configuration from three sources (in priority order):

1. **CLI arguments** (`--api-url`, `--token`, `--server-id`, `--poll-interval`)
2. **Environment variables** (`PROVISION_API_URL`, `PROVISION_DAEMON_TOKEN`, `PROVISION_SERVER_ID`)
3. **Config file** at `/etc/provisiond/config.json` (or `--config <path>`)

### Config file format

```json
{
  "api_url": "https://provision.ai",
  "api_token": "srv_xxxxxxxxxxxx",
  "server_id": "01kn...",
  "poll_interval_seconds": 30,
  "max_concurrent_tasks": 2,
  "task_timeout_seconds": 600,
  "checkout_duration_seconds": 3600
}
```

### Environment variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `PROVISION_API_URL` | Yes | — | Provision API base URL |
| `PROVISION_DAEMON_TOKEN` | Yes | — | Server daemon auth token |
| `PROVISION_SERVER_ID` | Yes | — | Server ID |
| `PROVISION_POLL_INTERVAL` | No | 30 | Poll interval in seconds |
| `PROVISION_MAX_CONCURRENT` | No | 2 | Max concurrent task executions |
| `PROVISION_TASK_TIMEOUT` | No | 600 | Task timeout in seconds |
| `PROVISION_CHECKOUT_DURATION` | No | 3600 | Checkout lease duration in seconds |
| `PROVISION_DEBUG` | No | — | Set to "1" for debug logging |

## Running

```bash
# Via CLI args
provisiond --api-url https://provision.ai --token srv_xxx --server-id 01kn...

# Via environment variables
export PROVISION_API_URL=https://provision.ai
export PROVISION_DAEMON_TOKEN=srv_xxx
export PROVISION_SERVER_ID=01kn...
provisiond

# Via config file
provisiond --config /path/to/config.json
```

### systemd service

The install command sets up a systemd service:

```bash
provisiond install --api-url="https://provision.ai" --token="{token}"
```

## Development

```bash
npm install
npm run dev       # Run with tsx (hot reload)
npm run build     # Compile TypeScript
npm test          # Run tests
```

## How It Works

1. **Poll**: Every 30 seconds, fetches the work queue from Provision
2. **Checkout**: Claims a task with an atomic checkout (prevents double-execution)
3. **Prompt**: Builds a structured prompt with task details, goal context, and org hierarchy
4. **Execute**: Sends the prompt to the local gateway (OpenClaw or Hermes) via the Responses API
5. **Parse**: Extracts the result summary, delegation requests, and approval requests from the response
6. **Report**: Sends the result and token usage back to Provision

The daemon handles errors gracefully — a single task failure never crashes the process.
