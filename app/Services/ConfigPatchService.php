<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentSlackConnection;

class ConfigPatchService
{
    /**
     * Build a Node.js one-liner that adds an agent to openclaw.json.
     */
    public function buildAddAgentPatch(Agent $agent): string
    {
        $agentId = $agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";

        $agentData = json_encode([
            'id' => $agentId,
            'name' => $agent->name,
            'workspace' => $agentDir,
            'agentDir' => "{$agentDir}/agent",
            'model' => $agent->openclawModelConfig(),
        ], JSON_UNESCAPED_SLASHES);

        return "node -e \"const fs=require('fs');const f='/root/.openclaw/openclaw.json';const c=JSON.parse(fs.readFileSync(f));c.agents=c.agents||{};c.agents.list=c.agents.list||[];c.agents.list.push({$agentData});fs.writeFileSync(f,JSON.stringify(c,null,2));\"";
    }

    /**
     * Build a Node.js one-liner that updates an agent in openclaw.json.
     */
    public function buildUpdateAgentPatch(Agent $agent): string
    {
        $agentId = $agent->harness_agent_id;
        $agentDir = "/root/.openclaw/agents/{$agentId}";

        $agentData = json_encode([
            'name' => $agent->name,
            'workspace' => $agentDir,
            'agentDir' => "{$agentDir}/agent",
            'model' => $agent->openclawModelConfig(),
        ], JSON_UNESCAPED_SLASHES);

        return "node -e \"const fs=require('fs');const f='/root/.openclaw/openclaw.json';const c=JSON.parse(fs.readFileSync(f));const i=(c.agents?.list||[]).findIndex(a=>a.id==='{$agentId}');if(i>=0){Object.assign(c.agents.list[i],{$agentData});fs.writeFileSync(f,JSON.stringify(c,null,2));}\"";
    }

    /**
     * Build a Node.js one-liner that removes an agent from openclaw.json.
     */
    public function buildRemoveAgentPatch(string $agentId): string
    {
        return "node -e \"const fs=require('fs');const f='/root/.openclaw/openclaw.json';const c=JSON.parse(fs.readFileSync(f));c.agents.list=(c.agents?.list||[]).filter(a=>a.id!=='{$agentId}');fs.writeFileSync(f,JSON.stringify(c,null,2));\"";
    }

    /**
     * Build a Node.js one-liner that sets Slack tokens in openclaw.json.
     */
    public function buildSetSlackTokensPatch(AgentSlackConnection $slack): string
    {
        $dmPolicy = $slack->dm_policy ?? 'open';
        $groupPolicy = $slack->group_policy ?? 'open';
        $requireMention = ($slack->require_mention ?? false) ? 'true' : 'false';
        $replyToMode = $slack->reply_to_mode ?? 'off';
        $dmSessionScope = $slack->dm_session_scope ?? 'main';

        return "node -e \"const fs=require('fs');const f='/root/.openclaw/openclaw.json';const c=JSON.parse(fs.readFileSync(f));c.channels=c.channels||{};c.channels.slack=c.channels.slack||{};c.channels.slack.enabled=true;c.channels.slack.botToken='{$slack->bot_token}';c.channels.slack.appToken='{$slack->app_token}';c.channels.slack.groupPolicy='{$groupPolicy}';c.channels.slack.requireMention={$requireMention};c.channels.slack.replyToMode='{$replyToMode}';c.channels.slack.dmPolicy='{$dmPolicy}';c.channels.slack.allowFrom=['*'];c.session=c.session||{};c.session.dmScope='{$dmSessionScope}';delete c.channels.slack.dm;delete c.channels.slack.accounts;fs.writeFileSync(f,JSON.stringify(c,null,2));\"";
    }

    /**
     * Build a Node.js one-liner that removes Slack tokens from openclaw.json.
     */
    public function buildRemoveSlackTokensPatch(): string
    {
        return "node -e \"const fs=require('fs');const f='/root/.openclaw/openclaw.json';const c=JSON.parse(fs.readFileSync(f));if(c.channels?.slack){delete c.channels.slack.botToken;delete c.channels.slack.appToken;c.channels.slack.enabled=false;}fs.writeFileSync(f,JSON.stringify(c,null,2));\"";
    }
}
