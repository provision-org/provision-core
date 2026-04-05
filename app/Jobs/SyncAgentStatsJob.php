<?php

namespace App\Jobs;

use App\Enums\AgentStatus;
use App\Enums\HarnessType;
use App\Enums\ServerStatus;
use App\Events\AgentActivityEvent;
use App\Events\AgentUpdatedEvent;
use App\Models\Agent;
use App\Models\AgentActivity;
use App\Models\AgentDailyStat;
use App\Models\Server;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAgentStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const STATS_SCRIPT = <<<'JS'
const fs = require('fs');
const readline = require('readline');

const agentId = process.argv[2];
if (!agentId) { console.error('Usage: node agent-stats.js <agentId>'); process.exit(1); }

const sessionsPath = `/mnt/openclaw-data/agents/${agentId}/sessions/sessions.json`;

async function main() {
    let sessions;
    try {
        sessions = JSON.parse(fs.readFileSync(sessionsPath, 'utf8'));
    } catch (e) {
        console.log(JSON.stringify({ totalSessions: 0, totalMessages: 0, tokensInput: 0, tokensOutput: 0, lastActiveAt: null }));
        return;
    }

    const allSessions = Object.values(sessions);
    let totalMessages = 0;
    let tokensInput = 0;
    let tokensOutput = 0;
    let lastActiveAt = null;

    for (const session of allSessions) {
        tokensInput += session.inputTokens || 0;
        tokensOutput += session.outputTokens || 0;

        if (session.updatedAt) {
            const ts = typeof session.updatedAt === 'number' ? session.updatedAt : Date.parse(session.updatedAt);
            if (!lastActiveAt || ts > lastActiveAt) lastActiveAt = ts;
        }

        if (!session.sessionFile) continue;
        if (!fs.existsSync(session.sessionFile)) continue;

        try {
            const rl = readline.createInterface({ input: fs.createReadStream(session.sessionFile), crlfDelay: Infinity });
            for await (const line of rl) {
                if (!line.trim()) continue;
                try {
                    const entry = JSON.parse(line);
                    if (entry.type === 'message') totalMessages++;
                } catch (e) { /* skip malformed lines */ }
            }
        } catch (e) { /* skip unreadable files */ }
    }

    console.log(JSON.stringify({
        totalSessions: allSessions.length,
        totalMessages,
        tokensInput,
        tokensOutput,
        lastActiveAt: lastActiveAt ? new Date(lastActiveAt).toISOString() : null,
    }));
}

main().catch(e => { console.error(e); process.exit(1); });
JS;

    public function handle(SshService $sshService): void
    {
        Server::query()
            ->where('status', ServerStatus::Running)
            ->whereHas('agents', fn ($q) => $q->where('status', AgentStatus::Active))
            ->with(['agents' => fn ($q) => $q->where('status', AgentStatus::Active)])
            ->each(function (Server $server) use ($sshService): void {
                $this->syncServer($server, $sshService);
            });
    }

    private function syncServer(Server $server, SshService $sshService): void
    {
        try {
            $sshService->connect($server);
            $sshService->writeFile('/tmp/agent-stats.js', self::STATS_SCRIPT);

            /** @var Agent $agent */
            foreach ($server->agents as $agent) {
                $this->syncAgent($agent, $sshService);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to sync stats for server', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $sshService->disconnect();
        }
    }

    private function syncAgent(Agent $agent, SshService $sshService): void
    {
        try {
            $output = match ($agent->harness_type) {
                HarnessType::Hermes => $this->fetchHermesStats($agent, $sshService),
                default => $sshService->exec("node /tmp/agent-stats.js {$agent->harness_agent_id}"),
            };
            $stats = json_decode(trim($output), true);

            if (! is_array($stats)) {
                Log::warning('Invalid stats output for agent', [
                    'agent_id' => $agent->id,
                    'output' => $output,
                ]);

                return;
            }

            $previousSessions = $agent->stats_total_sessions;
            $previousMessages = $agent->stats_total_messages;

            $agent->update([
                'stats_total_sessions' => $stats['totalSessions'] ?? 0,
                'stats_total_messages' => $stats['totalMessages'] ?? 0,
                'stats_tokens_input' => $stats['tokensInput'] ?? 0,
                'stats_tokens_output' => $stats['tokensOutput'] ?? 0,
                'stats_last_active_at' => $stats['lastActiveAt'] ?? null,
                'stats_synced_at' => now(),
            ]);
            broadcast(new AgentUpdatedEvent($agent));

            $this->createActivitiesForChanges($agent, $previousSessions, $previousMessages, $stats);

            AgentDailyStat::query()->updateOrCreate(
                ['agent_id' => $agent->id, 'date' => now()->toDateString()],
                [
                    'cumulative_tokens_input' => $stats['tokensInput'] ?? 0,
                    'cumulative_tokens_output' => $stats['tokensOutput'] ?? 0,
                    'cumulative_messages' => $stats['totalMessages'] ?? 0,
                    'cumulative_sessions' => $stats['totalSessions'] ?? 0,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to sync stats for agent', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function createActivitiesForChanges(Agent $agent, int $previousSessions, int $previousMessages, array $stats): void
    {
        $newSessions = ($stats['totalSessions'] ?? 0) - $previousSessions;
        $newMessages = ($stats['totalMessages'] ?? 0) - $previousMessages;

        if ($newSessions > 0) {
            $activity = AgentActivity::query()->create([
                'agent_id' => $agent->id,
                'type' => 'session_started',
                'summary' => "{$newSessions} new ".($newSessions === 1 ? 'session' : 'sessions').' detected',
                'metadata' => ['new_sessions' => $newSessions, 'total_sessions' => $stats['totalSessions']],
            ]);

            broadcast(new AgentActivityEvent($activity));
        }

        if ($newMessages > 0) {
            $activity = AgentActivity::query()->create([
                'agent_id' => $agent->id,
                'type' => 'message_received',
                'summary' => "{$newMessages} new ".($newMessages === 1 ? 'message' : 'messages').' processed',
                'metadata' => ['new_messages' => $newMessages, 'total_messages' => $stats['totalMessages']],
            ]);

            broadcast(new AgentActivityEvent($activity));
        }
    }

    /**
     * Fetch stats from Hermes sessions.json (different format/path from OpenClaw).
     */
    private function fetchHermesStats(Agent $agent, SshService $sshService): string
    {
        $hermesHome = "/root/.hermes-{$agent->harness_agent_id}";
        $sessionsFile = "{$hermesHome}/sessions/sessions.json";

        // Python one-liner to parse Hermes sessions.json and output stats in the same format as the Node.js script
        $script = <<<'PYTHON'
import json, sys, os, glob

sessions_file = sys.argv[1]
sessions_dir = os.path.dirname(sessions_file)

try:
    with open(sessions_file) as f:
        sessions = json.load(f)
except:
    print(json.dumps({"totalSessions": 0, "totalMessages": 0, "tokensInput": 0, "tokensOutput": 0, "lastActiveAt": None}))
    sys.exit(0)

all_sessions = list(sessions.values())
tokens_input = sum(s.get("input_tokens", 0) for s in all_sessions)
tokens_output = sum(s.get("output_tokens", 0) for s in all_sessions)

last_active = None
for s in all_sessions:
    ts = s.get("updated_at")
    if ts and (not last_active or ts > last_active):
        last_active = ts

# Count messages from JSONL session files
total_messages = 0
for f in glob.glob(os.path.join(sessions_dir, "*.jsonl")):
    try:
        with open(f) as fh:
            for line in fh:
                line = line.strip()
                if not line:
                    continue
                try:
                    entry = json.loads(line)
                    if entry.get("role") in ("user", "assistant"):
                        total_messages += 1
                except:
                    pass
    except:
        pass

print(json.dumps({
    "totalSessions": len(all_sessions),
    "totalMessages": total_messages,
    "tokensInput": tokens_input,
    "tokensOutput": tokens_output,
    "lastActiveAt": last_active,
}))
PYTHON;

        $sshService->writeFile('/tmp/hermes-stats.py', $script);

        return $sshService->exec("python3 /tmp/hermes-stats.py {$sessionsFile}");
    }
}
