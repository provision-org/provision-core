#!/usr/bin/env node

// src/config.ts
import { readFileSync, existsSync } from "node:fs";

// src/logger.ts
function timestamp() {
  return (/* @__PURE__ */ new Date()).toISOString();
}
function log(level, message, data) {
  const prefix = `[provisiond] [${level}] ${timestamp()}`;
  if (data) {
    console.log(`${prefix} ${message}`, JSON.stringify(data));
  } else {
    console.log(`${prefix} ${message}`);
  }
}
var logger = {
  info(message, data) {
    log("INFO", message, data);
  },
  warn(message, data) {
    log("WARN", message, data);
  },
  error(message, data) {
    log("ERROR", message, data);
  },
  debug(message, data) {
    if (process.env.PROVISION_DEBUG === "1") {
      log("DEBUG", message, data);
    }
  }
};

// src/config.ts
var DEFAULTS = {
  pollInterval: 30,
  maxConcurrent: 2,
  taskTimeout: 600,
  checkoutDuration: 3600
};
var DEFAULT_CONFIG_PATH = "/etc/provisiond/config.json";
function loadConfigFile(path) {
  if (!existsSync(path)) {
    return {};
  }
  try {
    const raw = readFileSync(path, "utf-8");
    return JSON.parse(raw);
  } catch (err) {
    logger.warn(`Failed to parse config file at ${path}`, {
      error: err instanceof Error ? err.message : String(err)
    });
    return {};
  }
}
function loadConfig(overrides = {}) {
  const configPath = overrides.config ?? process.env.PROVISION_CONFIG_PATH ?? DEFAULT_CONFIG_PATH;
  const file = loadConfigFile(configPath);
  const apiUrl = overrides.apiUrl ?? process.env.PROVISION_API_URL ?? file.api_url;
  const daemonToken = overrides.token ?? process.env.PROVISION_DAEMON_TOKEN ?? file.api_token;
  const serverId = overrides.serverId ?? process.env.PROVISION_SERVER_ID ?? file.server_id;
  if (!apiUrl) {
    throw new Error("Missing required config: PROVISION_API_URL (env) or api_url (config file) or --api-url");
  }
  if (!daemonToken) {
    throw new Error("Missing required config: PROVISION_DAEMON_TOKEN (env) or api_token (config file) or --token");
  }
  if (!serverId) {
    throw new Error("Missing required config: PROVISION_SERVER_ID (env) or server_id (config file) or --server-id");
  }
  const pollInterval = overrides.pollInterval ?? parseIntEnv("PROVISION_POLL_INTERVAL") ?? file.poll_interval_seconds ?? DEFAULTS.pollInterval;
  const maxConcurrent = parseIntEnv("PROVISION_MAX_CONCURRENT") ?? file.max_concurrent_tasks ?? DEFAULTS.maxConcurrent;
  const taskTimeout = parseIntEnv("PROVISION_TASK_TIMEOUT") ?? file.task_timeout_seconds ?? DEFAULTS.taskTimeout;
  const checkoutDuration = parseIntEnv("PROVISION_CHECKOUT_DURATION") ?? file.checkout_duration_seconds ?? DEFAULTS.checkoutDuration;
  return {
    apiUrl: apiUrl.replace(/\/+$/, ""),
    daemonToken,
    serverId,
    pollInterval,
    maxConcurrent,
    taskTimeout,
    checkoutDuration
  };
}
function parseIntEnv(name) {
  const val = process.env[name];
  if (val === void 0) {
    return void 0;
  }
  const parsed = parseInt(val, 10);
  return isNaN(parsed) ? void 0 : parsed;
}

// src/executor.ts
import { randomUUID } from "node:crypto";

// src/gateway-client.ts
function buildRequestBody(options) {
  const { harnessType, harnessAgentId, taskId, prompt } = options;
  if (harnessType === "openclaw") {
    return {
      model: `openclaw/${harnessAgentId}`,
      input: prompt,
      user: `task:${taskId}`
    };
  }
  return {
    model: "hermes-agent",
    input: prompt,
    conversation: `task:${taskId}`
  };
}
function parseGatewayOutput(data) {
  let outputText = "";
  if (typeof data.output_text === "string") {
    outputText = data.output_text;
  } else if (Array.isArray(data.output)) {
    outputText = data.output.filter((item) => item.type === "message").flatMap((item) => {
      const content = item.content;
      if (!Array.isArray(content)) {
        return [];
      }
      return content.filter((c) => c.type === "output_text").map((c) => String(c.text ?? ""));
    }).join("\n");
  }
  const usage = data.usage;
  const inputTokens = usage?.input_tokens ?? 0;
  const outputTokens = usage?.output_tokens ?? 0;
  const model = typeof data.model === "string" ? data.model : "unknown";
  return { outputText, inputTokens, outputTokens, model };
}
async function sendMessage(options) {
  const { port, timeoutMs } = options;
  const url = `http://127.0.0.1:${port}/v1/responses`;
  const body = buildRequestBody(options);
  logger.debug(`Gateway request to :${port}`, {
    model: body.model
  });
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const headers = {
      "Content-Type": "application/json",
      Accept: "application/json"
    };
    if (options.apiServerKey) {
      headers["Authorization"] = `Bearer ${options.apiServerKey}`;
    }
    const res = await fetch(url, {
      method: "POST",
      headers,
      body: JSON.stringify(body),
      signal: controller.signal
    });
    if (!res.ok) {
      const errorText = await res.text().catch(() => "");
      throw new Error(
        `Gateway returned ${res.status} ${res.statusText}: ${errorText}`
      );
    }
    const data = await res.json();
    return parseGatewayOutput(data);
  } finally {
    clearTimeout(timer);
  }
}

// src/prompt-builder.ts
function buildPrompt(task) {
  const lines = [];
  lines.push("# Task Assignment");
  lines.push("");
  lines.push("## Your Identity");
  lines.push(`You are ${task.agent.name}, ${task.agent.org_title}.`);
  if (task.agent.manager_name) {
    lines.push(`You report to ${task.agent.manager_name}.`);
  } else {
    lines.push("You report directly to the board.");
  }
  lines.push("");
  lines.push("## Current Task");
  lines.push(`**${task.identifier}:** ${task.title}`);
  lines.push(`Priority: ${task.priority}`);
  lines.push("");
  lines.push(task.description);
  if (task.goal) {
    lines.push("");
    lines.push("## Goal Context");
    lines.push(`This task serves: ${task.goal.title}`);
    if (task.goal.parent_title) {
      lines.push(`Which is part of: ${task.goal.parent_title}`);
    }
    if (task.goal.root_title) {
      lines.push(`Team mission: ${task.goal.root_title}`);
    }
  }
  const directReports = task.direct_reports ?? [];
  if (directReports.length > 0) {
    lines.push("");
    lines.push("## Your Team (Direct Reports)");
    for (const report of directReports) {
      const ref = report.handle ? `@${report.handle}` : report.name;
      lines.push(`- ${ref} (${report.name}, ${report.org_title}): ${report.capabilities}`);
    }
  }
  lines.push("");
  lines.push("## Instructions");
  lines.push("Complete this task. You have access to your browser, terminal, and workspace.");
  lines.push("Save files others need to ./shared/. Keep work-in-progress in your private workspace.");
  lines.push("");
  lines.push("When done, provide a summary of what you accomplished.");
  if (directReports.length > 0) {
    lines.push("");
    lines.push("To delegate sub-tasks to your reports:");
    lines.push("DELEGATE: @{report_handle} | {sub-task title} | {sub-task description}");
  }
  lines.push("");
  lines.push("To request approval for a high-impact action:");
  lines.push("APPROVAL_REQUEST: {type} | {title} | {description}");
  lines.push("");
  lines.push("To declare a file or deliverable you produced:");
  lines.push("WORK_PRODUCT: {title} | {file_path} | {summary}");
  return lines.join("\n");
}

// src/response-parser.ts
var DELEGATE_PREFIX = "DELEGATE:";
var APPROVAL_PREFIX = "APPROVAL_REQUEST:";
var WORK_PRODUCT_PREFIX = "WORK_PRODUCT:";
function parseResponse(text) {
  const lines = text.split("\n");
  const summaryLines = [];
  const delegations = [];
  const approvalRequests = [];
  const workProducts = [];
  for (const line of lines) {
    const trimmed = line.trim();
    if (trimmed.startsWith(DELEGATE_PREFIX)) {
      const delegation = parseDelegation(trimmed.slice(DELEGATE_PREFIX.length).trim());
      if (delegation) {
        delegations.push(delegation);
      } else {
        logger.warn("Malformed DELEGATE line, including in summary", { line: trimmed });
        summaryLines.push(line);
      }
      continue;
    }
    if (trimmed.startsWith(APPROVAL_PREFIX)) {
      const approval = parseApproval(trimmed.slice(APPROVAL_PREFIX.length).trim());
      if (approval) {
        approvalRequests.push(approval);
      } else {
        logger.warn("Malformed APPROVAL_REQUEST line, including in summary", { line: trimmed });
        summaryLines.push(line);
      }
      continue;
    }
    if (trimmed.startsWith(WORK_PRODUCT_PREFIX)) {
      const workProduct = parseWorkProduct(trimmed.slice(WORK_PRODUCT_PREFIX.length).trim());
      if (workProduct) {
        workProducts.push(workProduct);
      } else {
        logger.warn("Malformed WORK_PRODUCT line, including in summary", { line: trimmed });
        summaryLines.push(line);
      }
      continue;
    }
    summaryLines.push(line);
  }
  const resultSummary = summaryLines.join("\n").trim();
  return { resultSummary, delegations, approvalRequests, workProducts };
}
function parseDelegation(raw) {
  const parts = raw.split("|").map((s) => s.trim());
  if (parts.length < 3 || !parts[0] || !parts[1] || !parts[2]) {
    return null;
  }
  return {
    assignToAgentName: parts[0].replace(/^@/, ""),
    title: parts[1],
    description: parts.slice(2).join(" | ")
  };
}
function parseApproval(raw) {
  const parts = raw.split("|").map((s) => s.trim());
  if (parts.length < 3 || !parts[0] || !parts[1] || !parts[2]) {
    return null;
  }
  return {
    type: parts[0],
    title: parts[1],
    description: parts.slice(2).join(" | ")
  };
}
function parseWorkProduct(raw) {
  const parts = raw.split("|").map((s) => s.trim());
  if (parts.length < 1 || !parts[0]) {
    return null;
  }
  return {
    title: parts[0],
    filePath: parts[1] || void 0,
    summary: parts.length > 2 ? parts.slice(2).join(" | ") : void 0
  };
}

// src/executor.ts
var OPENCLAW_DEFAULT_PORT = 18789;
async function executeTask(task, config, api) {
  const watchdogMs = (config.taskTimeout + 60) * 1e3;
  let watchdogTimer;
  const watchdog = new Promise((_, reject) => {
    watchdogTimer = setTimeout(
      () => reject(new Error(`Watchdog timeout after ${watchdogMs}ms`)),
      watchdogMs
    );
  });
  try {
    await Promise.race([runTask(task, config, api), watchdog]);
  } finally {
    if (watchdogTimer) {
      clearTimeout(watchdogTimer);
    }
  }
}
async function runTask(task, config, api) {
  const runId = randomUUID();
  const taskLabel = `${task.identifier} (${task.id})`;
  logger.info(`Starting task ${taskLabel}`, { runId, agent: task.agent.name });
  const checkout = await api.checkoutTask(task.id, runId);
  if (!checkout.ok) {
    logger.info(`Skipping task ${taskLabel} \u2014 checkout failed (likely already checked out)`);
    return;
  }
  await api.postNote(task.id, "Starting task...");
  try {
    const prompt = buildPrompt(task);
    const port = task.agent.harness_type === "hermes" ? task.agent.api_server_port : OPENCLAW_DEFAULT_PORT;
    const gatewayResponse = await sendMessage({
      port,
      harnessType: task.agent.harness_type,
      harnessAgentId: task.agent.harness_agent_id,
      apiServerKey: task.agent.api_server_key,
      taskId: task.id,
      prompt,
      timeoutMs: config.taskTimeout * 1e3
    });
    const parsed = parseResponse(gatewayResponse.outputText);
    let status = "done";
    if (parsed.approvalRequests.length > 0) {
      status = "blocked";
    } else if (parsed.delegations.length > 0) {
      status = "in_progress";
    }
    const result = {
      daemon_run_id: runId,
      status,
      result_summary: parsed.resultSummary,
      tokens_input: gatewayResponse.inputTokens,
      tokens_output: gatewayResponse.outputTokens,
      model: gatewayResponse.model,
      delegations: parsed.delegations.map((d) => ({
        assign_to_agent_name: d.assignToAgentName,
        title: d.title,
        description: d.description
      })),
      approval_requests: parsed.approvalRequests.map((a) => ({
        type: a.type,
        title: a.title,
        description: a.description
      })),
      work_products: parsed.workProducts.map((wp) => ({
        title: wp.title,
        file_path: wp.filePath,
        type: "file",
        summary: wp.summary
      }))
    };
    await api.reportResult(task.id, result);
    await api.reportUsage({
      agent_id: task.agent.id,
      task_id: task.id,
      model: gatewayResponse.model,
      input_tokens: gatewayResponse.inputTokens,
      output_tokens: gatewayResponse.outputTokens,
      source: "daemon"
    });
    logger.info(`Completed task ${taskLabel}`, {
      status,
      inputTokens: gatewayResponse.inputTokens,
      outputTokens: gatewayResponse.outputTokens,
      delegations: parsed.delegations.length,
      approvalRequests: parsed.approvalRequests.length
    });
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    logger.error(`Task ${taskLabel} failed: ${message}`, { runId });
    try {
      await api.releaseTask(task.id, runId, message);
    } catch (releaseErr) {
      logger.error(`Failed to release task ${taskLabel}`, {
        error: releaseErr instanceof Error ? releaseErr.message : String(releaseErr)
      });
    }
  }
}

// src/provision-api.ts
var ProvisionApiClient = class {
  baseUrl;
  token;
  constructor(config) {
    const apiUrl = config.apiUrl.replace(/\/+$/, "");
    this.baseUrl = `${apiUrl}/api/daemon/servers/${encodeURIComponent(config.serverId)}`;
    this.token = config.daemonToken;
  }
  async getWorkQueue() {
    const res = await this.request("GET", "/work-queue");
    const data = await res.json();
    return data.tasks;
  }
  async checkoutTask(taskId, runId) {
    const res = await this.request("POST", `/tasks/${taskId}/checkout`, {
      daemon_run_id: runId
    });
    if (res.status === 409) {
      logger.debug(`Task ${taskId} already checked out`);
      return { ok: false };
    }
    if (!res.ok) {
      logger.error(`Checkout failed for task ${taskId}`, {
        status: res.status,
        statusText: res.statusText
      });
      return { ok: false };
    }
    const data = await res.json();
    return { ok: true, task: data.task };
  }
  async reportResult(taskId, result) {
    const res = await this.request(
      "POST",
      `/tasks/${taskId}/result`,
      result
    );
    if (!res.ok) {
      throw new Error(
        `Failed to report result for task ${taskId}: ${res.status} ${res.statusText}`
      );
    }
  }
  async releaseTask(taskId, runId, reason) {
    const res = await this.request("POST", `/tasks/${taskId}/release`, {
      daemon_run_id: runId,
      reason
    });
    if (!res.ok) {
      logger.error(`Failed to release task ${taskId}`, {
        status: res.status,
        statusText: res.statusText
      });
    }
  }
  async getResolvedApprovals() {
    const res = await this.request("GET", "/resolved-approvals");
    const data = await res.json();
    return data.approvals;
  }
  async reportUsage(event) {
    const res = await this.request("POST", "/usage-events", event);
    if (!res.ok) {
      logger.error("Failed to report usage event", {
        status: res.status,
        statusText: res.statusText
      });
    }
  }
  async postNote(taskId, body) {
    const res = await this.request("POST", `/tasks/${taskId}/notes`, {
      body
    });
    if (!res.ok) {
      logger.error(`Failed to post note for task ${taskId}`, {
        status: res.status,
        statusText: res.statusText
      });
    }
  }
  async sendHeartbeat(activeRuns2, version, capabilities) {
    const res = await this.request("POST", "/heartbeat", {
      timestamp: (/* @__PURE__ */ new Date()).toISOString(),
      active_runs: activeRuns2,
      version,
      capabilities
    });
    if (!res.ok) {
      logger.warn("Heartbeat failed", {
        status: res.status,
        statusText: res.statusText
      });
    }
  }
  async reportChatEvents(events) {
    const res = await this.request("POST", "/chat/events", { events });
    if (!res.ok) {
      throw new Error(
        `Chat event relay failed: ${res.status} ${res.statusText}`
      );
    }
  }
  async syncOpenClawSessions(sessions) {
    const res = await this.request("POST", "/chat/sessions/snapshot", {
      sessions
    });
    if (!res.ok) {
      throw new Error(
        `Session snapshot failed: ${res.status} ${res.statusText}`
      );
    }
  }
  async request(method, path, body, timeoutMs = 3e4) {
    const url = `${this.baseUrl}${path}`;
    const headers = {
      Accept: "application/json",
      Authorization: `Bearer ${this.token}`,
      "Content-Type": "application/json"
    };
    const init = { method, headers };
    if (body !== void 0) {
      init.body = JSON.stringify(body);
    }
    logger.debug(`${method} ${path}`);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      return await fetch(url, { ...init, signal: controller.signal });
    } finally {
      clearTimeout(timer);
    }
  }
};

// src/version.ts
var VERSION = "0.4.1";
var CAPABILITIES = ["chat-relay-v1", "session-discovery-v1"];

// src/poller.ts
var activeRuns = /* @__PURE__ */ new Map();
var stopping = false;
function requestStop() {
  stopping = true;
}
function getActiveRunCount() {
  return activeRuns.size;
}
async function startPolling(config) {
  const api = new ProvisionApiClient(config);
  logger.info("Poll loop started", {
    interval: config.pollInterval,
    maxConcurrent: config.maxConcurrent
  });
  while (!stopping) {
    try {
      await pollOnce(config, api);
    } catch (err) {
      logger.error("Poll cycle failed", {
        error: err instanceof Error ? err.message : String(err)
      });
    }
    if (stopping) {
      break;
    }
    await sleep(config.pollInterval * 1e3);
  }
  if (activeRuns.size > 0) {
    logger.info(`Waiting for ${activeRuns.size} active task(s) to finish...`);
    await Promise.allSettled(activeRuns.values());
  }
  logger.info("Poll loop stopped");
}
async function pollOnce(config, api) {
  const availableSlots = config.maxConcurrent - activeRuns.size;
  if (availableSlots <= 0) {
    logger.debug("All slots occupied, skipping work-queue fetch");
    await sendHeartbeat(api);
    return;
  }
  const tasks = await api.getWorkQueue();
  if (tasks.length > 0) {
    logger.info(`Work queue: ${tasks.length} task(s) available, ${availableSlots} slot(s) free`);
  }
  const toExecute = tasks.slice(0, availableSlots);
  for (const task of toExecute) {
    const runId = `${task.id}-${Date.now()}`;
    const taskPromise = executeTask(task, config, api).catch((err) => {
      logger.error(`Unhandled error in task ${task.identifier}`, {
        error: err instanceof Error ? err.message : String(err)
      });
    }).finally(() => {
      activeRuns.delete(runId);
    });
    activeRuns.set(runId, taskPromise);
  }
  try {
    const approvals = await api.getResolvedApprovals();
    if (approvals.length > 0) {
      logger.info(`${approvals.length} resolved approval(s) found`, {
        ids: approvals.map((a) => a.id)
      });
    }
  } catch (err) {
    logger.warn("Failed to fetch resolved approvals", {
      error: err instanceof Error ? err.message : String(err)
    });
  }
  await sendHeartbeat(api);
}
async function sendHeartbeat(api) {
  try {
    await api.sendHeartbeat([...activeRuns.keys()], VERSION, [...CAPABILITIES]);
  } catch (err) {
    logger.warn("Heartbeat failed", {
      error: err instanceof Error ? err.message : String(err)
    });
  }
}
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// src/openclaw-gateway-relay.ts
import { randomUUID as randomUUID2 } from "node:crypto";
import { existsSync as existsSync2, readFileSync as readFileSync2 } from "node:fs";
var OPENCLAW_CONFIG_PATH = "/root/.openclaw/openclaw.json";
var DEFAULT_GATEWAY_PORT = 18789;
var MAX_INBOUND_FRAME_CHARS = 1e6;
var MAX_EVENT_QUEUE = 500;
var MAX_CRITICAL_EVENT_QUEUE = 1e3;
var MAX_EVENT_BATCH_BYTES = 1e6;
var EVENT_FLUSH_DELAY_MS = 300;
var SESSION_SYNC_INTERVAL_MS = 5 * 6e4;
var SESSION_SYNC_RETRY_MS = 3e4;
var SOCKET_STALE_AFTER_MS = 6e4;
var MAX_SESSION_KEY_LENGTH = 255;
var OpenClawGatewayRelay = class {
  constructor(config, api) {
    this.config = config;
    this.api = api;
  }
  config;
  api;
  socket = null;
  stopped = false;
  runner = null;
  pending = /* @__PURE__ */ new Map();
  eventQueue = [];
  flushTimer = null;
  flushInProgress = false;
  sessionSyncTimer = null;
  watchdogTimer = null;
  lastFrameAt = 0;
  lastOuterSequence = null;
  reconnectAttempt = 0;
  challengeNonce = null;
  challengeRejecter = null;
  challengeTimer = null;
  start() {
    if (this.runner) {
      return;
    }
    this.stopped = false;
    this.runner = this.run().finally(() => {
      this.runner = null;
    });
  }
  stop() {
    this.stopped = true;
    this.clearTimers();
    this.cancelChallengeWait(new Error("Gateway relay stopped"));
    this.rejectPending(new Error("Gateway relay stopped"));
    this.socket?.close(1e3, "provisiond stopping");
    this.socket = null;
  }
  async run() {
    while (!this.stopped) {
      try {
        const credentials = this.loadCredentials();
        if (!credentials) {
          await this.sleep(3e4);
          continue;
        }
        await this.connectAndRun(credentials);
        this.reconnectAttempt = 0;
      } catch (error) {
        if (this.stopped) {
          return;
        }
        logger.warn("OpenClaw chat relay disconnected", {
          error: error instanceof Error ? error.message : String(error)
        });
        const baseDelay = Math.min(
          3e4,
          1e3 * 2 ** this.reconnectAttempt
        );
        this.reconnectAttempt = Math.min(this.reconnectAttempt + 1, 5);
        const jitter = Math.floor(
          Math.random() * Math.max(250, baseDelay / 4)
        );
        await this.sleep(baseDelay + jitter);
      }
    }
  }
  async connectAndRun(credentials) {
    this.lastOuterSequence = null;
    this.lastFrameAt = Date.now();
    this.challengeNonce = null;
    const socket = new WebSocket(`ws://127.0.0.1:${credentials.port}`);
    this.socket = socket;
    const closed = new Promise((resolve) => {
      socket.addEventListener(
        "close",
        () => {
          if (this.socket === socket) {
            this.cancelChallengeWait(
              new Error("Gateway WebSocket closed")
            );
            this.rejectPending(
              new Error("Gateway WebSocket closed")
            );
          }
          resolve();
        },
        { once: true }
      );
      socket.addEventListener(
        "error",
        () => {
          try {
            socket.close();
          } catch {
          }
        },
        { once: true }
      );
    });
    socket.addEventListener(
      "message",
      (event) => this.handleMessage(event, socket)
    );
    try {
      await Promise.race([
        this.waitForOpen(socket),
        closed.then(() => {
          throw new Error("Gateway WebSocket closed before opening");
        })
      ]);
      await Promise.race([
        this.waitForChallenge(),
        closed.then(() => {
          throw new Error(
            "Gateway WebSocket closed before challenge"
          );
        })
      ]);
      const hello = await this.request("connect", {
        minProtocol: 4,
        maxProtocol: 4,
        client: {
          id: "gateway-client",
          version: VERSION,
          platform: process.platform,
          mode: "backend",
          instanceId: `provisiond:${this.config.serverId}`
        },
        role: "operator",
        scopes: ["operator.read", "operator.write"],
        caps: [],
        commands: [],
        permissions: {},
        auth: { token: credentials.token }
        // The reserved direct-loopback backend path intentionally omits device.
      });
      if (hello.type !== "hello-ok" || hello.protocol !== 4 || !this.hasRequiredOperatorScopes(hello)) {
        throw new Error(
          "Gateway returned an incompatible or under-scoped handshake"
        );
      }
      await this.request("sessions.subscribe", {});
      this.reconnectAttempt = 0;
      logger.info("OpenClaw chat relay connected", {
        gatewayVersion: this.stringValue(
          this.recordValue(hello.server)?.version,
          32
        )
      });
      this.startWatchdog();
      let nextSessionSyncDelay = SESSION_SYNC_INTERVAL_MS;
      try {
        await this.syncSessions();
      } catch (error) {
        nextSessionSyncDelay = SESSION_SYNC_RETRY_MS;
        this.logSessionSyncFailure(error);
      }
      this.scheduleSessionSync(nextSessionSyncDelay);
      await closed;
      if (!this.stopped) {
        throw new Error("Gateway WebSocket closed");
      }
    } finally {
      this.cancelChallengeWait(
        new Error("Gateway connection attempt ended")
      );
      if (socket.readyState === WebSocket.CONNECTING || socket.readyState === WebSocket.OPEN) {
        try {
          socket.close(1e3, "Gateway connection attempt ended");
        } catch {
        }
      }
      this.clearConnectionState(socket);
    }
  }
  handleMessage(event, sourceSocket) {
    if (sourceSocket && sourceSocket !== this.socket) {
      return;
    }
    if (typeof event.data !== "string" || event.data.length > MAX_INBOUND_FRAME_CHARS) {
      this.socket?.close(4009, "Gateway frame too large");
      return;
    }
    let frame;
    try {
      frame = JSON.parse(event.data);
    } catch {
      return;
    }
    this.lastFrameAt = Date.now();
    if (frame.type === "res" && typeof frame.id === "string") {
      const pending = this.pending.get(frame.id);
      if (!pending) {
        return;
      }
      this.pending.delete(frame.id);
      clearTimeout(pending.timer);
      if (frame.ok === true && this.isRecord(frame.payload)) {
        pending.resolve(frame.payload);
      } else {
        pending.reject(new Error(this.gatewayError(frame.error)));
      }
      return;
    }
    if (frame.type !== "event" || typeof frame.event !== "string") {
      return;
    }
    if (frame.event === "connect.challenge") {
      const payload = this.recordValue(frame.payload);
      const nonce = this.stringValue(payload?.nonce, 512);
      if (nonce) {
        if (this.challengeResolver) {
          this.challengeResolver(nonce);
          this.challengeResolver = null;
        } else {
          this.challengeNonce = nonce;
        }
      }
      return;
    }
    const outerSequence = this.integerValue(frame.seq);
    if (outerSequence !== void 0 && this.lastOuterSequence !== null && outerSequence <= this.lastOuterSequence) {
      logger.warn(
        "OpenClaw relay received an out-of-order frame; scheduling reconciliation",
        {
          previous: this.lastOuterSequence,
          received: outerSequence
        }
      );
      this.scheduleSessionSync(0);
      return;
    }
    if (outerSequence !== void 0 && this.lastOuterSequence !== null && outerSequence !== this.lastOuterSequence + 1) {
      logger.warn(
        "OpenClaw relay sequence gap; scheduling reconciliation",
        {
          expected: this.lastOuterSequence + 1,
          received: outerSequence
        }
      );
      this.scheduleSessionSync(0);
    }
    if (outerSequence !== void 0) {
      this.lastOuterSequence = outerSequence;
    }
    const normalized = this.normalizeEvent(
      frame.event,
      this.recordValue(frame.payload)
    );
    if (normalized) {
      this.enqueueEvent(normalized);
    }
    if (frame.event === "sessions.changed") {
      this.scheduleSessionSync(1e3);
    } else if (frame.event === "shutdown") {
      this.socket?.close(4012, "Gateway restarting");
    }
  }
  challengeResolver = null;
  waitForChallenge() {
    if (this.challengeNonce) {
      const nonce = this.challengeNonce;
      this.challengeNonce = null;
      return Promise.resolve(nonce);
    }
    this.cancelChallengeWait(new Error("Gateway challenge superseded"));
    return new Promise((resolve, reject) => {
      this.challengeTimer = setTimeout(() => {
        this.challengeTimer = null;
        this.challengeResolver = null;
        this.challengeRejecter = null;
        reject(new Error("Gateway challenge timed out"));
      }, 15e3);
      this.challengeResolver = (nonce) => {
        if (this.challengeTimer) {
          clearTimeout(this.challengeTimer);
          this.challengeTimer = null;
        }
        this.challengeResolver = null;
        this.challengeRejecter = null;
        resolve(nonce);
      };
      this.challengeRejecter = (error) => {
        if (this.challengeTimer) {
          clearTimeout(this.challengeTimer);
          this.challengeTimer = null;
        }
        this.challengeResolver = null;
        this.challengeRejecter = null;
        reject(error);
      };
    });
  }
  cancelChallengeWait(error) {
    const rejecter = this.challengeRejecter;
    if (this.challengeTimer) {
      clearTimeout(this.challengeTimer);
      this.challengeTimer = null;
    }
    this.challengeResolver = null;
    this.challengeRejecter = null;
    rejecter?.(error);
  }
  request(method, params) {
    const socket = this.socket;
    if (!socket || socket.readyState !== WebSocket.OPEN) {
      return Promise.reject(
        new Error("Gateway WebSocket is not connected")
      );
    }
    const id = randomUUID2();
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(`Gateway ${method} request timed out`));
      }, 2e4);
      this.pending.set(id, { resolve, reject, timer });
      try {
        socket.send(
          JSON.stringify({ type: "req", id, method, params })
        );
      } catch (error) {
        clearTimeout(timer);
        this.pending.delete(id);
        reject(
          error instanceof Error ? error : new Error(String(error))
        );
      }
    });
  }
  normalizeEvent(event, payload) {
    if (!payload) {
      return null;
    }
    const sessionKey = this.identifierValue(
      payload.sessionKey,
      MAX_SESSION_KEY_LENGTH
    );
    const sessionAgentId = sessionKey ? this.agentIdFromSessionKey(sessionKey) : void 0;
    const explicitAgentId = this.identifierValue(payload.agentId, 255);
    if (explicitAgentId && sessionAgentId && explicitAgentId !== sessionAgentId) {
      return null;
    }
    const agentId = explicitAgentId ?? sessionAgentId;
    if (!sessionKey || !agentId) {
      return null;
    }
    if (event === "chat") {
      const state = payload.state;
      const runId = this.identifierValue(payload.runId, 255);
      if (!runId || !["delta", "final", "aborted", "error"].includes(String(state))) {
        return null;
      }
      const message = this.recordValue(payload.message);
      const cumulative = this.messageText(message);
      const errorKind = [
        "refusal",
        "timeout",
        "rate_limit",
        "context_length",
        "unknown"
      ].includes(String(payload.errorKind)) ? payload.errorKind : void 0;
      return {
        event: "chat",
        agent_id: agentId,
        session_key: sessionKey,
        run_id: runId,
        sequence: this.integerValue(payload.seq),
        state,
        delta: this.textValue(payload.deltaText, 5e4),
        cumulative: cumulative ? cumulative.slice(0, 2e5) : void 0,
        replace: payload.replace === true ? true : void 0,
        error_kind: errorKind
      };
    }
    if (event === "session.message") {
      const message = this.recordValue(payload.message);
      const metadata = this.recordValue(message?.__openclaw);
      const session = this.recordValue(payload.session);
      const role = message?.role;
      if (role !== "user" && role !== "assistant") {
        return null;
      }
      return {
        event: "session.message",
        agent_id: agentId,
        session_key: sessionKey,
        role,
        idempotency_key: this.identifierValue(message?.idempotencyKey, 255) ?? this.identifierValue(metadata?.idempotencyKey, 255),
        message_id: this.identifierValue(payload.messageId, 255) ?? this.identifierValue(metadata?.id, 255),
        message_sequence: this.integerValue(payload.messageSeq) ?? this.integerValue(metadata?.seq),
        has_active_run: this.booleanValue(payload.hasActiveRun) ?? this.booleanValue(session?.hasActiveRun)
      };
    }
    if (event === "session.tool" || event === "agent" && payload.stream === "tool") {
      const data = this.recordValue(payload.data);
      const runId = this.identifierValue(payload.runId, 255);
      const tool = this.stringValue(data?.name, 128);
      const phase = this.stringValue(data?.phase, 64);
      if (!runId) {
        return null;
      }
      return {
        event: "session.tool",
        agent_id: agentId,
        session_key: sessionKey,
        run_id: runId,
        sequence: this.integerValue(payload.seq),
        tool,
        phase,
        label: tool ? this.toolLabel(tool, phase) : "working"
      };
    }
    if (event === "sessions.changed") {
      const session = this.recordValue(payload.session);
      return {
        event: "sessions.changed",
        agent_id: agentId,
        session_key: sessionKey,
        run_id: this.identifierValue(payload.clientRunId, 255) ?? this.identifierValue(payload.runId, 255),
        has_active_run: this.booleanValue(payload.hasActiveRun) ?? this.booleanValue(session?.hasActiveRun)
      };
    }
    return null;
  }
  enqueueEvent(event) {
    if (event.event === "chat" && event.state === "delta" && this.coalesceChatDelta(event)) {
      return;
    }
    if (this.eventQueue.length >= MAX_EVENT_QUEUE) {
      const deltaIndex = this.eventQueue.findIndex(
        (candidate) => candidate.event === "chat" && candidate.state === "delta"
      );
      if (deltaIndex >= 0) {
        this.eventQueue.splice(deltaIndex, 1);
      } else if (event.event === "chat" && event.state === "delta") {
        this.scheduleSessionSync(0);
        return;
      }
    }
    this.eventQueue.push(event);
    if (this.eventQueue.length > MAX_CRITICAL_EVENT_QUEUE) {
      this.socket?.close(4013, "Gateway event relay overloaded");
    }
    this.scheduleEventFlush(
      this.eventQueue.length >= MAX_EVENT_QUEUE ? 0 : EVENT_FLUSH_DELAY_MS
    );
  }
  coalesceChatDelta(event) {
    for (let index = this.eventQueue.length - 1; index >= 0; index--) {
      const candidate = this.eventQueue[index];
      if (candidate.agent_id !== event.agent_id || candidate.session_key !== event.session_key) {
        continue;
      }
      if (candidate.event !== "chat" || candidate.state !== "delta" || candidate.run_id !== event.run_id) {
        return false;
      }
      if (candidate.sequence !== void 0 && event.sequence !== void 0 && event.sequence < candidate.sequence) {
        return true;
      }
      const baseText = candidate.cumulative ?? candidate.delta ?? "";
      const cumulative = event.cumulative ?? (event.replace === true ? event.delta : `${baseText}${event.delta ?? ""}`);
      this.eventQueue[index] = {
        ...candidate,
        ...event,
        cumulative: cumulative ? cumulative.slice(0, 2e5) : void 0,
        sequence: candidate.sequence === void 0 ? event.sequence : event.sequence === void 0 ? candidate.sequence : Math.max(candidate.sequence, event.sequence)
      };
      return true;
    }
    return false;
  }
  scheduleEventFlush(delay) {
    if (this.flushTimer || this.flushInProgress) {
      return;
    }
    this.flushTimer = setTimeout(() => {
      this.flushTimer = null;
      void this.flushEvents();
    }, delay);
  }
  async flushEvents() {
    if (this.eventQueue.length === 0) {
      return;
    }
    this.flushInProgress = true;
    const batch = this.takeEventBatch();
    let retryDelay = 0;
    try {
      await this.api.reportChatEvents(batch);
    } catch (error) {
      this.eventQueue.unshift(...batch);
      retryDelay = 1e3;
      logger.warn("Could not forward OpenClaw chat events", {
        error: error instanceof Error ? error.message : String(error)
      });
    } finally {
      this.flushInProgress = false;
    }
    if (this.eventQueue.length > 0) {
      this.scheduleEventFlush(retryDelay);
    }
  }
  takeEventBatch() {
    const batch = [];
    let bytes = 0;
    while (batch.length < 100 && this.eventQueue.length > 0) {
      const event = this.eventQueue[0];
      const eventBytes = Buffer.byteLength(JSON.stringify(event), "utf8") + 1;
      if (batch.length > 0 && bytes + eventBytes > MAX_EVENT_BATCH_BYTES) {
        break;
      }
      batch.push(event);
      this.eventQueue.shift();
      bytes += eventBytes;
    }
    return batch;
  }
  async syncSessions() {
    const snapshots = [];
    let offset = 0;
    for (let page = 0; page < 10; page++) {
      const result = await this.request("sessions.list", {
        configuredAgentsOnly: true,
        includeDerivedTitles: true,
        includeLastMessage: true,
        includeGlobal: false,
        includeUnknown: true,
        limit: 100,
        offset
      });
      const rows = Array.isArray(result.sessions) ? result.sessions : [];
      for (const value of rows) {
        const row = this.recordValue(value);
        const snapshot = row ? this.sessionSnapshot(row) : null;
        if (snapshot) {
          snapshots.push(snapshot);
        }
      }
      const nextOffset = this.integerValue(result.nextOffset);
      if (nextOffset === void 0 || result.hasMore !== true || nextOffset <= offset) {
        break;
      }
      offset = nextOffset;
    }
    for (let index = 0; index < snapshots.length; index += 100) {
      await this.api.syncOpenClawSessions(
        snapshots.slice(index, index + 100)
      );
    }
  }
  sessionSnapshot(row) {
    const key = this.identifierValue(row.key, MAX_SESSION_KEY_LENGTH);
    const agentId = key ? this.agentIdFromSessionKey(key) : void 0;
    const kind = row.kind;
    if (!key || !agentId || !["direct", "group", "global", "unknown"].includes(String(kind))) {
      return null;
    }
    return {
      agentId,
      key,
      kind,
      channel: this.stringValue(row.channel, 64),
      chatType: this.stringValue(row.chatType, 64),
      label: this.stringValue(row.label, 255),
      displayName: this.stringValue(row.displayName, 255),
      derivedTitle: this.stringValue(row.derivedTitle, 255),
      subject: this.stringValue(row.subject, 255),
      lastMessagePreview: this.stringValue(row.lastMessagePreview, 500),
      updatedAt: this.integerValue(row.updatedAt),
      hasActiveRun: this.booleanValue(row.hasActiveRun),
      activeRunIds: Array.isArray(row.activeRunIds) ? row.activeRunIds.map((value) => this.identifierValue(value, 255)).filter((value) => value !== void 0).slice(0, 20) : void 0,
      spawnedBy: this.stringValue(row.spawnedBy, 255),
      subagentRole: this.stringValue(row.subagentRole, 64)
    };
  }
  scheduleSessionSync(delay = SESSION_SYNC_INTERVAL_MS) {
    if (this.stopped || !this.socket) {
      return;
    }
    if (this.sessionSyncTimer) {
      clearTimeout(this.sessionSyncTimer);
    }
    const socket = this.socket;
    this.sessionSyncTimer = setTimeout(() => {
      this.sessionSyncTimer = null;
      let nextDelay = SESSION_SYNC_INTERVAL_MS;
      void this.syncSessions().catch((error) => {
        nextDelay = SESSION_SYNC_RETRY_MS;
        this.logSessionSyncFailure(error);
      }).finally(() => {
        if (!this.stopped && this.socket === socket) {
          this.scheduleSessionSync(nextDelay);
        }
      });
    }, delay);
  }
  logSessionSyncFailure(error) {
    logger.warn("OpenClaw session reconciliation failed", {
      error: error instanceof Error ? error.message : String(error)
    });
  }
  startWatchdog() {
    if (this.watchdogTimer) {
      clearInterval(this.watchdogTimer);
    }
    this.watchdogTimer = setInterval(() => {
      if (Date.now() - this.lastFrameAt > SOCKET_STALE_AFTER_MS) {
        this.socket?.close(4e3, "Gateway heartbeat timed out");
      }
    }, 15e3);
  }
  clearConnectionState(socket) {
    if (this.socket !== socket) {
      return;
    }
    this.rejectPending(new Error("Gateway WebSocket closed"));
    if (this.watchdogTimer) {
      clearInterval(this.watchdogTimer);
      this.watchdogTimer = null;
    }
    if (this.sessionSyncTimer) {
      clearTimeout(this.sessionSyncTimer);
      this.sessionSyncTimer = null;
    }
    this.socket = null;
  }
  clearTimers() {
    if (this.flushTimer) {
      clearTimeout(this.flushTimer);
      this.flushTimer = null;
    }
    if (this.sessionSyncTimer) {
      clearTimeout(this.sessionSyncTimer);
      this.sessionSyncTimer = null;
    }
    if (this.watchdogTimer) {
      clearInterval(this.watchdogTimer);
      this.watchdogTimer = null;
    }
  }
  rejectPending(error) {
    for (const pending of this.pending.values()) {
      clearTimeout(pending.timer);
      pending.reject(error);
    }
    this.pending.clear();
  }
  loadCredentials() {
    if (!existsSync2(OPENCLAW_CONFIG_PATH)) {
      return null;
    }
    try {
      const config = JSON.parse(
        readFileSync2(OPENCLAW_CONFIG_PATH, "utf8")
      );
      const gateway = this.recordValue(config.gateway);
      const auth = this.recordValue(gateway?.auth);
      const token = this.stringValue(auth?.token, 4096);
      const port = this.integerValue(gateway?.port) ?? DEFAULT_GATEWAY_PORT;
      if (!token || port < 1 || port > 65535) {
        return null;
      }
      return { token, port };
    } catch (error) {
      logger.warn("Could not read OpenClaw Gateway credentials", {
        error: error instanceof Error ? error.message : String(error)
      });
      return null;
    }
  }
  waitForOpen(socket) {
    return new Promise((resolve, reject) => {
      const cleanup = () => {
        clearTimeout(timer);
        socket.removeEventListener("open", onOpen);
        socket.removeEventListener("error", onError);
        socket.removeEventListener("close", onClose);
      };
      const onOpen = () => {
        cleanup();
        resolve();
      };
      const onError = () => {
        cleanup();
        reject(new Error("Gateway WebSocket could not open"));
      };
      const onClose = () => {
        cleanup();
        reject(new Error("Gateway WebSocket closed before opening"));
      };
      const timer = setTimeout(() => {
        cleanup();
        reject(new Error("Gateway WebSocket open timed out"));
      }, 15e3);
      socket.addEventListener("open", onOpen, { once: true });
      socket.addEventListener("error", onError, { once: true });
      socket.addEventListener("close", onClose, { once: true });
    });
  }
  hasRequiredOperatorScopes(hello) {
    const auth = this.recordValue(hello.auth);
    const scopes = Array.isArray(auth?.scopes) ? auth.scopes.filter(
      (scope) => typeof scope === "string"
    ) : [];
    return scopes.includes("operator.read") && scopes.includes("operator.write");
  }
  agentIdFromSessionKey(sessionKey) {
    const match = /^agent:([^:]+):/.exec(sessionKey);
    return match?.[1] && match[1].length <= 255 ? match[1] : void 0;
  }
  messageText(message) {
    if (!message) {
      return void 0;
    }
    if (typeof message.content === "string") {
      return message.content;
    }
    if (!Array.isArray(message.content)) {
      return void 0;
    }
    const text = message.content.map((block) => this.recordValue(block)).filter((block) => Boolean(block)).filter(
      (block) => block.type === "text" && typeof block.text === "string"
    ).map((block) => String(block.text)).join("\n");
    return text || void 0;
  }
  toolLabel(tool, phase) {
    const name = tool.replace(/[_-]+/g, " ").replace(/([a-z])([A-Z])/g, "$1 $2").trim().toLowerCase();
    return phase === "result" ? `finished ${name}` : `using ${name}`;
  }
  gatewayError(value) {
    const error = this.recordValue(value);
    return this.stringValue(error?.message, 500) ?? "Gateway request failed";
  }
  recordValue(value) {
    return this.isRecord(value) ? value : void 0;
  }
  isRecord(value) {
    return typeof value === "object" && value !== null && !Array.isArray(value);
  }
  stringValue(value, maxLength) {
    if (typeof value !== "string") {
      return void 0;
    }
    const trimmed = value.trim();
    return trimmed ? trimmed.slice(0, maxLength) : void 0;
  }
  identifierValue(value, maxLength) {
    if (typeof value !== "string") {
      return void 0;
    }
    const trimmed = value.trim();
    return trimmed && trimmed.length <= maxLength ? trimmed : void 0;
  }
  textValue(value, maxLength) {
    return typeof value === "string" && value.length > 0 ? value.slice(0, maxLength) : void 0;
  }
  integerValue(value) {
    return typeof value === "number" && Number.isSafeInteger(value) && value >= 0 ? value : void 0;
  }
  booleanValue(value) {
    return typeof value === "boolean" ? value : void 0;
  }
  sleep(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
  }
};

// src/index.ts
function printBanner() {
  console.log(`provisiond v${VERSION} \u2014 Provision Workforce Agent Daemon`);
  console.log("");
}
function parseArgs(argv) {
  const overrides = {};
  const args = argv.slice(2);
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    const next = args[i + 1];
    switch (arg) {
      case "--config":
        overrides.config = next;
        i++;
        break;
      case "--api-url":
        overrides.apiUrl = next;
        i++;
        break;
      case "--token":
        overrides.token = next;
        i++;
        break;
      case "--server-id":
        overrides.serverId = next;
        i++;
        break;
      case "--poll-interval":
        overrides.pollInterval = parseInt(next, 10);
        i++;
        break;
      case "--help":
      case "-h":
        printUsage();
        process.exit(0);
        break;
      case "--version":
      case "-v":
        console.log(`provisiond v${VERSION}`);
        process.exit(0);
        break;
      default:
        if (arg.startsWith("--")) {
          logger.warn(`Unknown argument: ${arg}`);
        }
    }
  }
  return overrides;
}
function printUsage() {
  console.log(`
Usage: provisiond [options]

Options:
  --config <path>         Path to config file (default: /etc/provisiond/config.json)
  --api-url <url>         Provision API URL
  --token <token>         Daemon authentication token
  --server-id <id>        Server ID
  --poll-interval <sec>   Poll interval in seconds (default: 30)
  -h, --help              Show this help message
  -v, --version           Show version

Environment variables:
  PROVISION_API_URL          API URL
  PROVISION_DAEMON_TOKEN     Daemon token
  PROVISION_SERVER_ID        Server ID
  PROVISION_POLL_INTERVAL    Poll interval (seconds)
  PROVISION_MAX_CONCURRENT   Max concurrent tasks (default: 2)
  PROVISION_TASK_TIMEOUT     Task timeout (seconds, default: 600)
  PROVISION_CHECKOUT_DURATION  Checkout duration (seconds, default: 3600)
  PROVISION_CONFIG_PATH      Config file path
  PROVISION_DEBUG            Set to "1" for debug logging
`);
}
function redactToken(token) {
  if (token.length <= 8) {
    return "****";
  }
  return `${token.slice(0, 4)}...${token.slice(-4)}`;
}
async function main() {
  printBanner();
  const overrides = parseArgs(process.argv);
  let config;
  try {
    config = loadConfig(overrides);
  } catch (err) {
    logger.error(err instanceof Error ? err.message : String(err));
    process.exit(1);
  }
  logger.info("Configuration loaded", {
    apiUrl: config.apiUrl,
    serverId: config.serverId,
    token: redactToken(config.daemonToken),
    pollInterval: config.pollInterval,
    maxConcurrent: config.maxConcurrent,
    taskTimeout: config.taskTimeout,
    checkoutDuration: config.checkoutDuration
  });
  const api = new ProvisionApiClient(config);
  const relay = new OpenClawGatewayRelay(config, api);
  relay.start();
  const shutdown = () => {
    logger.info("Shutdown signal received, finishing active tasks...");
    relay.stop();
    requestStop();
    setTimeout(() => {
      const remaining = getActiveRunCount();
      if (remaining > 0) {
        logger.warn(`Force exiting with ${remaining} active task(s)`);
      }
      process.exit(0);
    }, 3e4);
  };
  process.on("SIGTERM", shutdown);
  process.on("SIGINT", shutdown);
  await startPolling(config);
  logger.info("Daemon stopped");
  process.exit(0);
}
main().catch((err) => {
  logger.error("Fatal error", {
    error: err instanceof Error ? err.message : String(err)
  });
  process.exit(1);
});
