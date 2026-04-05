# Changelog

All notable changes to Provision are documented here.

## [0.2.0] - 2026-04-05

### Added

**Web Chat & Streaming**
- Web chat interface with SSE token streaming for real-time agent responses
- Chat button on agent detail page — agents usable without Slack/Telegram/Discord
- Web Chat shown as first-class channel option ("Always on, no setup needed")
- Message deduplication between SSE stream and Reverb broadcast

**Memory Browser**
- New Memory tab on agent detail page
- Split-panel UI: file list + markdown viewer/editor
- Supports both OpenClaw and Hermes memory paths
- View and edit agent memory files (MEMORY.md, USER.md) from the dashboard

**Governance Layer**
- Agent modes: Chat Agent (channels) vs Task Agent (autonomous)
- Org chart with reporting hierarchy, org titles, capabilities
- Goal hierarchy with cascading objectives and progress tracking
- Task board (kanban) with atomic checkout, delegation, sub-tasks
- Approval gates with governance modes (none/standard/strict)
- Usage tracking with per-agent token counts
- Immutable audit trail for all governance actions
- Real-time WebSocket updates for task board and approvals

**provisiond Daemon**
- Lightweight Node.js daemon for autonomous task execution
- Polls Provision API, checks out tasks, invokes agents via gateway
- Parses DELEGATE and APPROVAL_REQUEST from agent output
- Published via GitHub Releases (`provisiond-v*` tags)
- Auto-installed in Docker agent-runtime containers

**Gateway Integration**
- GatewayClient rewritten to use HTTP Responses API (`/v1/responses`)
- Supports both OpenClaw (port 18789) and Hermes (per-agent ports)
- Per-agent API server for Hermes (unique port per agent)
- Proper agent identity routing via model field and session scoping

**Infrastructure**
- Reverb (WebSocket) broadcasting enabled in Docker
- APP_KEY persistence fix (no longer regenerates on container restart)
- Comprehensive Hermes agent config from docs review
- Per-agent API server ports stored in database

**Skills Marketplace**
- Enhanced search with filters (tag, visibility, sort)
- Tags endpoint with counts
- Improved detail page with tabbed content (README/SKILL.md)

### Changed
- Agent creation flow: mode picker step (Chat/Task) after naming
- Workforce agents skip channels page, deploy directly
- Sidebar: Task Board and Company section always visible
- Breadcrumbs: "Company" prefix instead of "Governance"
- Task board: 4 columns (removed Backlog), clickable cards
- Org chart: org titles shown prominently, capabilities, reporting lines

### Fixed
- Docker MAC invalid errors (APP_KEY regeneration)
- Memory browser path for Hermes agents (`/memories/` not `/memory/`)
- HarnessType enum comparison (was comparing with string)
- Chat message deduplication (Reverb + SSE)
- OpenClaw agent identity routing (user field for session scoping)

## [0.1.0] - 2026-04-02

### Added
- Initial release
- Agent deployment on Docker, Hetzner, DigitalOcean, Linode
- Slack, Telegram, Discord channel integrations
- OpenClaw and Hermes agent framework support
- Browser automation with Chrome + VNC
- Agent workspace and file management
- Email identities via MailboxKit module
- Residential proxy via Browser Pro module
- Team management with invitations
- 8-step agent creation wizard
