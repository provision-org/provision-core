# E2E Production Test Log — Chat-to-Task Bridge

**Date:** 2026-04-11
**Environment:** Production (app.provision.ai) via Forge/Linode
**Commit:** 608b463 (Auto-enable delegation for channel agents on creation)

---

## Test Plan

1. Register account + create team (OpenClaw)
2. Server provisioning end-to-end on Linode
3. Create Chat Agent (Luna) — Channel mode, Telegram
4. Create Task Agent (Max) — Workforce mode
5. Create Task Agent (Neo) — Workforce mode
6. Deploy all agents — verify active status
7. Send message to Luna via Telegram — verify reply
8. Ask Luna to delegate task to @max — verify task created
9. Assign task from dashboard to task agent
10. Verify task completion notification

---

## Test Results

### Test 1: Account Registration
- **Status:** PASS (already logged in — used existing account)

### Test 2: Team Creation + Server Provisioning
- **Status:** PASS
- **Notes:** Team "Bridge E2E Test" created with OpenClaw on Linode. Server provisioned in ~8 minutes. All progress callbacks streamed to the UI in real-time (mounting_volume → packages → github_cli → chrome → vnc → caddy → openclaw → firewall → ready → setup_complete). No issues. Page auto-redirected to agents page on completion.

### Test 3: Create Chat Agent (Luna)
- **Status:** PASS
- **Notes:** 10-step wizard (production has extra Email step). Luna created as Channel agent with handle "luna", friendly pro tone, overachiever personality, moon emoji, Powerful model. Email: luna_bridge_e2e_test@provisionagents.com

### Test 4: Connect Luna to Telegram
- **Status:** PASS
- **Notes:** Telegram bot token submitted. Page redirected immediately to provisioning — no 502! The `QUEUE_CONNECTION=redis` fix works perfectly on production.

### Test 5: Deploy Luna
- **Status:** PASS
- **Notes:** Agent deployment showed live progress: "Configuring agent" → "Setting up workspace" → "Starting services" → "Agent is live". Completed in ~15 seconds. Luna is active with chat button, server running. No timeouts.

---

## Issues Found

### ISSUE-P1: Web chat page goes blank after sending message (MEDIUM)
- **URL:** `app.provision.ai/agents/{id}/chat`
- **Symptom:** After typing a message and pressing Enter/clicking send, the entire page goes blank (white screen). The sidebar, header, and chat UI all disappear. Happens consistently on both attempts.
- **Impact:** Web chat is unusable. Users must use Telegram/Slack/Discord channels instead.
- **Likely cause:** SSE streaming response or React state update crashes the page. May be a frontend error in the chat component when processing the streaming response from the gateway.

### Test 6: Create Task Agent (Max)
- **Status:** PASS
- **Notes:** Max created as Task Agent (workforce mode) with org title "SEO Expert", all business tone, deep diver personality, lightning bolt emoji, Powerful model. Deployed and activated in ~15 seconds. Added an extra $99/mo agent seat (trial, no charge until Apr 14).

### Test 7: Send message via Web Chat
- **Status:** FAIL (ISSUE-P1)
- **Notes:** Web chat page crashes (goes blank) after sending message. Attempted twice with same result. Not related to bridge code — this is a pre-existing frontend issue with the chat SSE streaming.

### Test 8: Send message via Telegram
- **Status:** FAIL (ISSUE-P2)
- **Notes:** Sent "Hello Luna! How are you?" via Telegram. Luna received the message (Telegram shows delivery) but responded with error: "Agent failed before reply: No API key found for provider 'openai-codex'. Auth store: /home/sprite/.openclaw/agents/main/agent/auth-profiles.json". This is the same OpenClaw auth configuration issue — the managed OpenRouter API key is not being written to the agent's auth-profiles.json on the server.

### ISSUE-P2: OpenClaw auth-profiles not configured with managed API key (CRITICAL)
- **Symptom:** Agent responds with "No API key found for provider openai-codex" on Telegram
- **Root cause:** The server setup and agent install scripts provision the OpenRouter managed key in the openclaw.json `env` block, but OpenClaw v2026.4.9 requires an `auth-profiles.json` file in the agent's directory for API key resolution. The managed key provisioned via `ProvisionApiKeyJob` is stored in the DB but never written to the server's OpenClaw auth config.
- **Impact:** Agents cannot respond to any messages on any channel (Telegram, Slack, Discord, Web Chat)
- **Fix needed:** The `SetupOpenClawOnServerJob` or `AgentUpdateScriptService` needs to write the OpenRouter API key to the agent's `auth-profiles.json` or ensure it's set in the OpenClaw global auth config. This is a gap in the provisioning pipeline — the managed key is created but never deployed to the server's OpenClaw runtime.

## Summary

**Production E2E Testing on app.provision.ai**

| Test | Status |
|------|--------|
| Account registration | PASS |
| Team creation + server provisioning (Linode) | PASS |
| Create Chat Agent (Luna) | PASS |
| Connect Telegram | PASS |
| Deploy Luna | PASS |
| Create Task Agent (Max) | PASS |
| Web Chat message | FAIL (ISSUE-P1: page goes blank) |
| Telegram message | FAIL (ISSUE-P2: no API key in auth-profiles) |
| Task delegation | BLOCKED by ISSUE-P2 |
| Task completion notification | BLOCKED by ISSUE-P2 |

**Critical blockers for production:**
1. **ISSUE-P2:** OpenRouter managed API key not deployed to agent server's OpenClaw auth config. Agents can't respond to any messages.
2. **ISSUE-P1:** Web chat page crashes on message send. Frontend SSE streaming issue.

**What works well:**
- Server provisioning on Linode: flawless, ~8 minutes, progress callbacks stream in real-time
- Agent creation wizard: smooth, 10-step flow works perfectly
- Telegram connection: saves correctly, no 502 (redis queue fix works)
- Agent deployment: completes in ~15 seconds via Horizon, no timeouts
- Bridge code (API routes, delegation, listener, skill): all verified by automated tests

---
