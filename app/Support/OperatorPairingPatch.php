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
     *
     * Waits up to ~30s for the gateway to finish auto-pairing the local device
     * before running the patch. Without this wait, the script can race the
     * gateway: paired.json doesn't exist yet, the patch is a no-op, then the
     * gateway pairs the device with read-only scopes and the install dies on
     * the first scope-upgrade request.
     */
    public static function buildScript(): string
    {
        $scopesJson = json_encode(self::SCOPES);

        return implode(' ', [
            // Poll up to ~30s for the device to actually be in paired.json.
            // We check non-empty content (`jq -e ". != {}"`) so an empty `{}`
            // doesn't satisfy the wait — the gateway writes `{}` early then
            // populates it once auto-pairing completes.
            'for _ in $(seq 1 30); do',
            '  if [ -s /root/.openclaw/devices/paired.json ] && jq -e ". != {} and (length > 0)" /root/.openclaw/devices/paired.json >/dev/null 2>&1; then',
            '    break;',
            '  fi;',
            '  sleep 1;',
            'done;',
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
