<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentSlackConnection;

/**
 * Builds shell commands that mutate a server's openclaw.json.
 *
 * Add/update/channel mutations go through the gateway config RPC
 * (`config.get` for the snapshot hash, then `config.patch` with `baseHash`)
 * so writes are optimistically locked against OpenClaw's own writers and the
 * gateway stamps `meta.lastTouchedVersion` itself — raw file rewrites race
 * the gateway and can trip its clobber detector, which silently restores
 * openclaw.json.bak. A JSON merge patch merges `agents.list` entries by id
 * (objects merge, null deletes), so add and update are the same operation.
 *
 * If the gateway is unreachable after retries, the command falls back to a
 * direct `node -e` file edit of the same patch so provisioning flows still
 * converge on a box whose gateway is down.
 */
class ConfigPatchService
{
    public function buildAddAgentPatch(Agent $agent): string
    {
        return $this->buildRpcPatchCommand(['agents' => ['list' => [$this->agentEntry($agent)]]]);
    }

    public function buildUpdateAgentPatch(Agent $agent): string
    {
        // Merge-by-id: identical to add — an existing entry deep-merges.
        return $this->buildAddAgentPatch($agent);
    }

    /**
     * Removal from a merge-patched array requires replacing the whole list,
     * which would need the full desired state. The agent entry is instead
     * filtered out with a direct file edit — a rare, teardown-only operation
     * that runs right before a gateway restart. The edit preserves all other
     * keys (including meta), so the clobber detector's missing-meta check is
     * not triggered.
     */
    public function buildRemoveAgentPatch(string $agentId): string
    {
        $encodedId = json_encode($agentId);

        return 'node -e '.escapeshellarg(
            "const fs=require('fs');const f='/root/.openclaw/openclaw.json';"
            .'const c=JSON.parse(fs.readFileSync(f));'
            ."c.agents=c.agents||{};c.agents.list=(c.agents.list||[]).filter(a=>a.id!=={$encodedId});"
            .'fs.writeFileSync(f,JSON.stringify(c,null,2));'
        );
    }

    public function buildSetSlackTokensPatch(AgentSlackConnection $slack): string
    {
        return $this->buildRpcPatchCommand([
            'channels' => [
                'slack' => [
                    'enabled' => true,
                    'botToken' => $slack->bot_token,
                    'appToken' => $slack->app_token,
                    'groupPolicy' => $slack->group_policy ?? 'open',
                    'requireMention' => (bool) ($slack->require_mention ?? false),
                    'replyToMode' => $slack->reply_to_mode ?? 'off',
                    'dmPolicy' => $slack->dm_policy ?? 'open',
                    'allowFrom' => ['*'],
                    // null deletes in a JSON merge patch
                    'dm' => null,
                    'accounts' => null,
                ],
            ],
            'session' => [
                'dmScope' => $slack->dm_session_scope ?? 'main',
            ],
        ], replacePaths: ['channels.slack.allowFrom']);
    }

    public function buildRemoveSlackTokensPatch(): string
    {
        return $this->buildRpcPatchCommand([
            'channels' => [
                'slack' => [
                    'enabled' => false,
                    'botToken' => null,
                    'appToken' => null,
                ],
            ],
        ]);
    }

    /**
     * Arbitrary JSON merge patch against openclaw.json via the gateway RPC —
     * for callers outside this class (e.g. the skills module registering a
     * skills.entries entry, which also bumps the gateway's skills snapshot).
     *
     * @param  array<string, mixed>  $patch
     * @param  list<string>  $replacePaths
     */
    public function buildGenericPatch(array $patch, array $replacePaths = []): string
    {
        return $this->buildRpcPatchCommand($patch, $replacePaths);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentEntry(Agent $agent): array
    {
        $agentId = $agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";

        return [
            'id' => $agentId,
            'name' => $agent->name,
            'workspace' => $agentDir,
            'agentDir' => "{$agentDir}/agent",
            'model' => $agent->openclawModelConfig(),
        ];
    }

    /**
     * Emit a self-contained bash command: fetch the config hash, apply the
     * merge patch via config.patch with optimistic locking (3 attempts, the
     * gateway rejects on hash mismatch), then fall back to a direct file
     * merge if the gateway is unreachable.
     *
     * All dynamic data rides inside JSON produced by json_encode and is
     * shell-escaped exactly once — nothing user-controlled is interpolated
     * into code.
     *
     * @param  array<string, mixed>  $patch
     * @param  list<string>  $replacePaths
     */
    private function buildRpcPatchCommand(array $patch, array $replacePaths = []): string
    {
        $rawJson = json_encode($patch, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $replaceJson = json_encode($replacePaths, JSON_THROW_ON_ERROR);

        $fallback = 'node -e '.escapeshellarg(
            "const fs=require('fs');const f='/root/.openclaw/openclaw.json';"
            .'const merge=(base,patch)=>{for(const [k,v] of Object.entries(patch)){'
            .'if(v===null){delete base[k];}'
            .'else if(Array.isArray(v)&&v.every(x=>x&&typeof x==="object"&&typeof x.id==="string")&&Array.isArray(base[k])){'
            .'for(const item of v){const i=base[k].findIndex(e=>e&&e.id===item.id);'
            .'if(i>=0){merge(base[k][i],item);}else{base[k].push(item);}}}'
            .'else if(v&&typeof v==="object"&&!Array.isArray(v)){base[k]=base[k]&&typeof base[k]==="object"&&!Array.isArray(base[k])?base[k]:{};merge(base[k],v);}'
            .'else{base[k]=v;}}};'
            .'const c=JSON.parse(fs.readFileSync(f));merge(c,JSON.parse(process.argv[1]));'
            .'fs.writeFileSync(f,JSON.stringify(c,null,2));'
        ).' '.escapeshellarg($rawJson);

        return implode(' ', [
            'PATCH_RAW='.escapeshellarg($rawJson).';',
            'PATCH_OK=0;',
            'for PATCH_ATTEMPT in 1 2 3; do',
            'PATCH_HASH=$(openclaw gateway call config.get --json 2>/dev/null | jq -r \'.hash // .payload.hash // empty\' 2>/dev/null | head -1);',
            '[ -n "$PATCH_HASH" ] || break;',
            'PATCH_PARAMS=$(jq -cn --arg raw "$PATCH_RAW" --arg hash "$PATCH_HASH" --argjson rp '.escapeshellarg($replaceJson)
                .' \'{raw: $raw, baseHash: $hash} + (if ($rp | length) > 0 then {replacePaths: $rp} else {} end)\');',
            'if openclaw gateway call config.patch --json --params "$PATCH_PARAMS" 2>&1; then PATCH_OK=1; break; fi;',
            'sleep 2;',
            'done;',
            'if [ "$PATCH_OK" != "1" ]; then '.$fallback.'; fi',
        ]);
    }
}
