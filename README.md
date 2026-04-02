<p align="center">
  <img src="public/logo.svg" alt="Provision" width="60" />
</p>

<h1 align="center">Provision</h1>

<p align="center">
  Open-source platform for deploying AI agents that join your Slack, Telegram, and Discord.
</p>

<p align="center">
  <a href="#quick-start">Quick Start</a> &middot;
  <a href="#configuration">Configuration</a> &middot;
  <a href="#features">Features</a> &middot;
  <a href="https://provision.ai">Website</a> &middot;
  <a href="https://docs.provision.ai">Docs</a> &middot;
  <a href="https://discord.gg/W8rnGcvRCu">Discord</a>
</p>

<p align="center">
  <a href="https://github.com/provision-org/provision-core/stargazers"><img src="https://img.shields.io/github/stars/provision-org/provision-core?style=social" alt="GitHub Stars" /></a>
  &nbsp;
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License" /></a>
  &nbsp;
  <a href="https://discord.gg/W8rnGcvRCu"><img src="https://img.shields.io/discord/000000000?label=Discord&logo=discord&logoColor=white" alt="Discord" /></a>
  &nbsp;
  <a href="https://github.com/provision-org/provision-core/actions"><img src="https://img.shields.io/github/actions/workflow/status/provision-org/provision-core/tests.yml?label=tests" alt="Tests" /></a>
</p>

---

Give your AI agents a name, a personality, and a Slack account. They show up in your workspace like any other team member. Provision handles the infrastructure — you focus on what they should do.

**No vendor lock-in.** Run everything on your own machine with Docker, deploy to your own cloud, or use [Provision Cloud](https://provision.ai) for managed hosting.

## Quick Start

**Prerequisites:** [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.

```bash
git clone https://github.com/provision-org/provision-core.git && cd provision-core
cp .env.example .env
```

Add your OpenRouter key to `.env` (see [Configuration](#configuration) below), then:

```bash
docker compose up -d         # ~2 min on first run
```

Open **http://localhost:8000** &rarr; create an account &rarr; create a team &rarr; deploy your first agent.

<details>
<summary><strong>Run without Docker</strong> (PHP 8.3+, Node 22+, Redis)</summary>

```bash
composer install && npm install
cp .env.example .env
php artisan key:generate && php artisan migrate
composer run dev
```

You'll need Redis running locally for queues, cache, and sessions.

</details>

## Configuration

### Required: OpenRouter API Key

Provision uses [OpenRouter](https://openrouter.ai) to give each team its own LLM access. You provide one management key, and Provision automatically creates isolated sub-keys per team.

1. Go to [openrouter.ai/keys](https://openrouter.ai/keys) and create a key
2. Add it to your `.env`:

```bash
OPENROUTER_PROVISIONING_API_KEY=sk-or-v1-your-key-here
```

**Why OpenRouter?** One key gives your agents access to Claude, GPT-4, Gemini, Llama, and 200+ other models. Provision creates a separate sub-key for each team so usage is isolated. You only pay for what your agents use — there are no Provision fees for self-hosted.

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `OPENROUTER_PROVISIONING_API_KEY` | **Yes** | — | Your OpenRouter management key. Provision creates per-team sub-keys from this. |
| `APP_KEY` | Auto | — | Generated automatically on first `docker compose up`. |
| `CLOUD_PROVIDER` | No | `docker` | Where agent servers run. `docker` for local, or `digitalocean`/`hetzner`/`linode` for cloud. |
| `ENABLE_CLOUD_PROVIDER_SELECTION` | No | `false` | Set to `true` to let users choose their cloud provider per team. |
| `DIGITALOCEAN_API_TOKEN` | No | — | Required if using DigitalOcean for agent servers. |
| `HETZNER_API_TOKEN` | No | — | Required if using Hetzner for agent servers. |
| `LINODE_API_KEY` | No | — | Required if using Linode for agent servers. |
| `MAILBOXKIT_API_KEY` | No | — | Enables email identities for agents (premium module). |
| `MAIL_MAILER` | No | `log` | Set to `smtp`/`ses`/`postmark` for real email delivery. |

### What Happens on First Run

When you run `docker compose up -d`, the app container:

1. Installs PHP and Node dependencies
2. Generates an encryption key (if not set)
3. Creates the SQLite database and runs migrations
4. Builds the frontend assets (React + Vite)
5. Starts the web server, queue worker (Horizon), and scheduler

The agent-runtime container starts separately with Chrome, OpenClaw, Hermes, and a VNC server.

**First run takes ~2 minutes.** Subsequent starts are faster since dependencies and assets are cached.

## Features

**Deploy agents to your team's channels**
Connect to Slack, Telegram, or Discord. Agents show up as real bot users — they receive messages, respond in threads, and can be mentioned just like a colleague.

**Choose your agent framework**
Each team picks their agent framework during setup:
- **[OpenClaw](https://openclaw.ai)** — Browser-first agents. Best for web research, form filling, and tool access.
- **[Hermes](https://github.com/NousResearch/hermes-agent)** — Reasoning-first agents. Best for analysis, writing, and conversation.

**Browser automation built in**
Each agent gets its own Chrome instance with a virtual display. Watch them work in real time through the built-in VNC viewer at `localhost:6080`.

**Run anywhere**
Docker for local development. Hetzner, DigitalOcean, or Linode for production. Bring your own cloud provider API keys — Provision handles server provisioning, agent deployment, and health monitoring.

**Plugin architecture**
Extend with Composer packages. The core ships with everything you need. Premium modules add capabilities like email identities, residential proxies, and usage analytics.

## How It Works

```
┌────────────────────────────────────────────────────────┐
│                   docker compose up                    │
├────────────────┬──────────────┬────────────────────────┤
│      app       │    redis     │    agent-runtime       │
│                │              │                        │
│  Laravel 12    │  Queues      │  Ubuntu 24.04          │
│  React 19      │  Cache       │  OpenClaw / Hermes     │
│  Inertia v2    │  Sessions    │  Chrome + VNC          │
│  SQLite        │              │  Agent workspaces      │
│                │              │                        │
│  :8000         │  :6379       │  :6080 (browser)       │
└────────────────┴──────────────┴────────────────────────┘
```

The **app** container runs the web UI and queue workers. The **agent-runtime** container runs the actual AI agents with Chrome for browser automation. They communicate via `docker exec` — the same interface used for remote servers over SSH.

### Agent Setup Flow

1. **Create a team** — pick a name, choose OpenClaw or Hermes
2. **Server provisioned** — Provision configures the agent runtime automatically
3. **Create an agent** — name, personality, model selection (8-step wizard)
4. **Connect a channel** — paste a Telegram bot token, Slack app, or Discord bot
5. **Agent goes live** — responds to messages, uses tools, browses the web

## Deploy to Production

When you're ready for 24/7 agents, connect a cloud provider:

```bash
# In your .env
CLOUD_PROVIDER=hetzner          # or digitalocean, linode
HETZNER_API_TOKEN=your-key      # Provision handles the rest
```

Or set `ENABLE_CLOUD_PROVIDER_SELECTION=true` to let each team choose their own provider in the UI.

Create an agent in the UI and Provision will automatically provision a server, install the agent framework, configure channels, and deploy your agent. One click.

## Self-Hosted vs Provision Cloud

|  | Self-Hosted | Provision Cloud |
|--|-------------|-----------------|
| **Price** | Free, forever | From $49/mo |
| **Agents** | Unlimited | Tiered |
| **Infrastructure** | Docker or BYO cloud | Fully managed |
| **Updates** | `git pull && docker compose up` | Automatic |
| **Premium modules** | Add via Composer | Included |

Self-hosted Provision is the full product, not a limited demo. It's the same code that powers Provision Cloud.

<p align="center">
  <a href="https://provision.ai"><strong>Try Provision Cloud &rarr;</strong></a>
</p>

## Extend with Modules

The core handles agent deployment, channels, and infrastructure. Premium modules add more:

| Module | Adds |
|--------|------|
| **MailboxKit** | Email identities for agents — send and receive with custom domains |
| **Browser Pro** | Residential proxy for web research (Decodo) |
| **Analytics** | Performance dashboards and token usage tracking |

```bash
composer require provision/module-mailboxkit
php artisan migrate
# Done — your agents can now send and receive email
```

Building your own module? See [`app/Contracts/Modules/`](app/Contracts/Modules/) for the interfaces and [`CONTRIBUTING.md`](CONTRIBUTING.md) for guidelines.

## Tech Stack

- **Backend:** PHP 8.3, Laravel 12, Inertia.js v2
- **Frontend:** React 19, TypeScript, Tailwind CSS v4
- **Agent Frameworks:** OpenClaw, Hermes
- **Infrastructure:** Docker, Hetzner, DigitalOcean, Linode
- **Database:** SQLite (dev) / MySQL (production)

## Troubleshooting

**`docker compose up` is slow on first run**
First run installs dependencies and builds frontend assets (~2 min). Subsequent starts reuse cached assets and start in seconds.

**Agent deployment fails**
Check Horizon logs: `docker compose logs app | grep horizon`. Common causes:
- Missing `OPENROUTER_PROVISIONING_API_KEY` in `.env`
- Agent-runtime container not running: `docker compose ps`

**VNC viewer shows blank screen**
The display starts empty. Chrome launches when an agent uses browser automation. You can also open `http://localhost:6080` directly.

**APP_KEY errors after restart**
If you see "MAC is invalid" errors, your `APP_KEY` changed between runs. Set a permanent key in `.env`: `php artisan key:generate`

## Contributing

We welcome contributions. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for setup instructions.

Areas we'd love help with:
- New cloud provider integrations (AWS, GCP, Azure)
- New channel integrations (WhatsApp, Microsoft Teams)
- New agent framework drivers
- Documentation and examples

## Community

- [Discord](https://discord.gg/W8rnGcvRCu) — Ask questions, share what you're building
- [GitHub Issues](https://github.com/provision-org/provision-core/issues) — Bug reports and feature requests
- [Twitter/X](https://x.com/tryprovision) — Updates and announcements

## License

MIT License. See [LICENSE](LICENSE) for details.

---

<p align="center">
  If Provision helps you, consider giving it a star. It helps others discover the project.
</p>

<p align="center">
  <a href="https://github.com/provision-org/provision-core/stargazers"><img src="https://img.shields.io/github/stars/provision-org/provision-core?style=social&label=Star" alt="GitHub Stars" /></a>
</p>
