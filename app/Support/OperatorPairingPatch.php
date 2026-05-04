<?php

namespace App\Support;

class OperatorPairingPatch
{
    /**
     * Full operator scope set required for Provision install scripts to drive the
     * gateway end-to-end (config edits, browser profile registration, approvals).
     *
     * Why all six: 2026.5.3 hardcodes silent=false for any reason="scope-upgrade",
     * so the CLI can't escalate from read+pairing on its own — admin approval is
     * needed, but the only paired device IS the operator. Granting the full set
     * once at install avoids the deadlock entirely.
     */
    public const SCOPES = [
        'operator.pairing',
        'operator.read',
        'operator.write',
        'operator.admin',
        'operator.approvals',
        'operator.talk.secrets',
    ];

    /**
     * Bash one-liner that grants the full operator scope set to every paired
     * device on the current host and clears any pending scope-upgrade requests.
     *
     * Idempotent: re-running on a box that already has the scopes is a no-op.
     */
    public static function buildScript(): string
    {
        $scopesJson = json_encode(self::SCOPES);

        return implode(' ', [
            'if [ -f /root/.openclaw/devices/paired.json ]; then',
            '  cp /root/.openclaw/devices/paired.json /root/.openclaw/devices/paired.json.bak.$(date +%s);',
            '  jq \'map_values(.scopes |= (('.$scopesJson.') | unique)',
            '  | .approvedScopes |= (('.$scopesJson.') | unique)',
            '  | (.tokens // {}) |= map_values(.scopes |= (('.$scopesJson.') | unique)))\'',
            '  /root/.openclaw/devices/paired.json > /root/.openclaw/devices/paired.json.new',
            '  && mv /root/.openclaw/devices/paired.json.new /root/.openclaw/devices/paired.json;',
            '  if [ -f /root/.openclaw/identity/device-auth.json ]; then',
            '    jq \'(.tokens // {}) |= map_values(.scopes |= (('.$scopesJson.') | unique))\'',
            '    /root/.openclaw/identity/device-auth.json > /root/.openclaw/identity/device-auth.json.new',
            '    && mv /root/.openclaw/identity/device-auth.json.new /root/.openclaw/identity/device-auth.json;',
            '  fi;',
            '  echo "{}" > /root/.openclaw/devices/pending.json;',
            'fi',
        ]);
    }
}
