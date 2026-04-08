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
    const res = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json"
      },
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
  if (task.direct_reports.length > 0) {
    lines.push("");
    lines.push("## Your Team (Direct Reports)");
    for (const report of task.direct_reports) {
      lines.push(`- ${report.name} (${report.org_title}): ${report.capabilities}`);
    }
  }
  lines.push("");
  lines.push("## Instructions");
  lines.push("Complete this task. You have access to your browser, terminal, and workspace.");
  lines.push("");
  lines.push("When done, provide a summary of what you accomplished.");
  if (task.direct_reports.length > 0) {
    lines.push("");
    lines.push("To delegate sub-tasks to your reports:");
    lines.push("DELEGATE: {report_name} | {sub-task title} | {sub-task description}");
  }
  lines.push("");
  lines.push("To request approval for a high-impact action:");
  lines.push("APPROVAL_REQUEST: {type} | {title} | {description}");
  return lines.join("\n");
}

// src/response-parser.ts
var DELEGATE_PREFIX = "DELEGATE:";
var APPROVAL_PREFIX = "APPROVAL_REQUEST:";
function parseResponse(text) {
  const lines = text.split("\n");
  const summaryLines = [];
  const delegations = [];
  const approvalRequests = [];
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
    summaryLines.push(line);
  }
  const resultSummary = summaryLines.join("\n").trim();
  return { resultSummary, delegations, approvalRequests };
}
function parseDelegation(raw) {
  const parts = raw.split("|").map((s) => s.trim());
  if (parts.length < 3 || !parts[0] || !parts[1] || !parts[2]) {
    return null;
  }
  return {
    assignToAgentName: parts[0],
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

// src/executor.ts
var OPENCLAW_DEFAULT_PORT = 18789;
async function executeTask(task, config, api) {
  const runId = randomUUID();
  const taskLabel = `${task.identifier} (${task.id})`;
  logger.info(`Starting task ${taskLabel}`, { runId, agent: task.agent.name });
  const checkout = await api.checkoutTask(task.id, runId);
  if (!checkout.ok) {
    logger.info(`Skipping task ${taskLabel} \u2014 checkout failed (likely already checked out)`);
    return;
  }
  try {
    const prompt = buildPrompt(task);
    const port = task.agent.harness_type === "hermes" ? task.agent.api_server_port : OPENCLAW_DEFAULT_PORT;
    const gatewayResponse = await sendMessage({
      port,
      harnessType: task.agent.harness_type,
      harnessAgentId: task.agent.harness_agent_id,
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
    this.baseUrl = `${config.apiUrl}/api/daemon/${config.daemonToken}`;
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
    const res = await this.request("POST", `/tasks/${taskId}/result`, result);
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
  async sendHeartbeat(activeRuns2) {
    const res = await this.request("POST", "/heartbeat", {
      timestamp: (/* @__PURE__ */ new Date()).toISOString(),
      active_runs: activeRuns2
    });
    if (!res.ok) {
      logger.warn("Heartbeat failed", {
        status: res.status,
        statusText: res.statusText
      });
    }
  }
  async request(method, path, body) {
    const url = `${this.baseUrl}${path}`;
    const headers = {
      Accept: "application/json",
      "Content-Type": "application/json"
    };
    const init = { method, headers };
    if (body !== void 0) {
      init.body = JSON.stringify(body);
    }
    logger.debug(`${method} ${path}`);
    return fetch(url, init);
  }
};

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
  for (const [runId, promise] of activeRuns.entries()) {
    const settled = await Promise.race([
      promise.then(() => true, () => true),
      Promise.resolve(false)
    ]);
    if (settled) {
      activeRuns.delete(runId);
    }
  }
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
    await api.sendHeartbeat([...activeRuns.keys()]);
  } catch (err) {
    logger.warn("Heartbeat failed", {
      error: err instanceof Error ? err.message : String(err)
    });
  }
}
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// src/index.ts
var VERSION = "0.1.0";
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
  const shutdown = () => {
    logger.info("Shutdown signal received, finishing active tasks...");
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
