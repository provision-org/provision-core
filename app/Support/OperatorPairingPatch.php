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
     * Bash one-liner that grants the full operator scope set to the host's local
     * device and clears only that device's pending scope-upgrade requests.
     *
     * Idempotent: re-running on a box that already has the scopes is a no-op.
     *
     * Waits up to ~30s for the gateway to finish auto-pairing the local device
     * before running the patch. Without this wait, the script can race the
     * gateway: paired.json doesn't exist yet, the patch is a no-op, then the
     * gateway pairs the device with read-only scopes and the install dies on
     * the first scope-upgrade request.
     */
    public static function buildScript(string $openClawHome = '/root/.openclaw'): string
    {
        $scopesJson = json_encode(self::SCOPES, JSON_THROW_ON_ERROR);
        $escapedHome = escapeshellarg(rtrim($openClawHome, '/'));
        $escapedScopes = escapeshellarg($scopesJson);

        return implode(' ', [
            '(',
            'umask 077;',
            'PROVISION_OPENCLAW_HOME='.$escapedHome.';',
            'PROVISION_IDENTITY_FILE="$PROVISION_OPENCLAW_HOME/identity/device.json";',
            'PROVISION_DEVICE_AUTH_FILE="$PROVISION_OPENCLAW_HOME/identity/device-auth.json";',
            'PROVISION_PAIRED_FILE="$PROVISION_OPENCLAW_HOME/devices/paired.json";',
            'PROVISION_PENDING_FILE="$PROVISION_OPENCLAW_HOME/devices/pending.json";',
            'PROVISION_PAIRING_LOCK_FILE="$PROVISION_OPENCLAW_HOME/.provision-operator-pairing.lock";',
            'PROVISION_PAIRING_LOCK_DIR="";',
            'PROVISION_PAIRING_LOCK_ACQUIRED=0;',
            'PROVISION_PAIRED_TEMP="";',
            'PROVISION_DEVICE_AUTH_TEMP="";',
            'PROVISION_PENDING_TEMP="";',
            'provision_pairing_cleanup() {',
            '  [ -z "$PROVISION_PAIRED_TEMP" ] || rm -f "$PROVISION_PAIRED_TEMP";',
            '  [ -z "$PROVISION_DEVICE_AUTH_TEMP" ] || rm -f "$PROVISION_DEVICE_AUTH_TEMP";',
            '  [ -z "$PROVISION_PENDING_TEMP" ] || rm -f "$PROVISION_PENDING_TEMP";',
            '  [ "$PROVISION_PAIRING_LOCK_ACQUIRED" -ne 1 ] || rmdir "$PROVISION_PAIRING_LOCK_DIR" 2>/dev/null || true;',
            '};',
            'provision_prepare_json_temp() {',
            '  local provision_source_file="$1";',
            '  local provision_temp_file;',
            '  provision_temp_file=$(mktemp "${provision_source_file}.provision.XXXXXX") || return 1;',
            '  cp -p "$provision_source_file" "$provision_temp_file" || { rm -f "$provision_temp_file"; return 1; };',
            '  chmod 0600 "$provision_temp_file" || { rm -f "$provision_temp_file"; return 1; };',
            '  printf "%s\\n" "$provision_temp_file";',
            '};',
            'provision_install_json() {',
            '  local provision_source_file="$1";',
            '  local provision_temp_file="$2";',
            '  local provision_backup_file;',
            '  chmod 0600 "$provision_temp_file" || return 1;',
            '  if cmp -s "$provision_source_file" "$provision_temp_file"; then',
            '    rm -f "$provision_temp_file";',
            '    return 0;',
            '  fi;',
            '  provision_backup_file=$(mktemp "${provision_source_file}.bak.XXXXXX") || return 1;',
            '  cp -p "$provision_source_file" "$provision_backup_file" || { rm -f "$provision_backup_file"; return 1; };',
            '  chmod 0600 "$provision_backup_file" || { rm -f "$provision_backup_file"; return 1; };',
            '  mv "$provision_temp_file" "$provision_source_file" || return 1;',
            '  chmod 0600 "$provision_source_file";',
            '};',
            'trap provision_pairing_cleanup EXIT;',
            'trap \'exit 1\' HUP INT TERM;',
            'PROVISION_LOCAL_DEVICE_ID=$(jq -r \'.deviceId // empty\' "$PROVISION_IDENTITY_FILE" 2>/dev/null || true);',
            'if [ -n "$PROVISION_LOCAL_DEVICE_ID" ]; then',
            '  if command -v flock >/dev/null 2>&1; then',
            '    exec 9>"$PROVISION_PAIRING_LOCK_FILE" || exit 1;',
            '    chmod 0600 "$PROVISION_PAIRING_LOCK_FILE" || exit 1;',
            '    flock -x 9 || exit 1;',
            '  else',
            // macOS does not provide flock. Production Linux hosts take the
            // flock branch; this atomic-directory fallback keeps local tests
            // and repair scripts serialized as well.
            '    PROVISION_PAIRING_LOCK_DIR="$PROVISION_PAIRING_LOCK_FILE.d";',
            '    for provision_lock_attempt in $(seq 1 60); do',
            '      if mkdir "$PROVISION_PAIRING_LOCK_DIR" 2>/dev/null; then',
            '        PROVISION_PAIRING_LOCK_ACQUIRED=1;',
            '        break;',
            '      fi;',
            '      sleep 1;',
            '    done;',
            '    [ "$PROVISION_PAIRING_LOCK_ACQUIRED" -eq 1 ] || exit 1;',
            '  fi;',
            // Poll up to ~30s for this specific local device to appear. Other
            // paired mobile devices must not satisfy the install-time wait.
            '  for provision_attempt in $(seq 1 30); do',
            '    if [ -s "$PROVISION_PAIRED_FILE" ] && jq -e --arg device_id "$PROVISION_LOCAL_DEVICE_ID" \'has($device_id)\' "$PROVISION_PAIRED_FILE" >/dev/null 2>&1; then',
            '      break;',
            '    fi;',
            '    sleep 1;',
            '  done;',
            '  if [ -f "$PROVISION_PAIRED_FILE" ] && jq -e --arg device_id "$PROVISION_LOCAL_DEVICE_ID" \'has($device_id)\' "$PROVISION_PAIRED_FILE" >/dev/null 2>&1; then',
            '    chmod 0600 "$PROVISION_PAIRED_FILE" || exit 1;',
            '    PROVISION_PAIRED_TEMP=$(provision_prepare_json_temp "$PROVISION_PAIRED_FILE") || exit 1;',
            '    if ! jq --arg device_id "$PROVISION_LOCAL_DEVICE_ID" --argjson required_scopes '.$escapedScopes.' \'',
            '      .[$device_id] |= (',
            '        .scopes = (((.scopes // []) + $required_scopes) | unique)',
            '        | .approvedScopes = (((.approvedScopes // []) + $required_scopes) | unique)',
            '        | .tokens = ((.tokens // {}) | with_entries(',
            '            .value.scopes = (((.value.scopes // []) + $required_scopes) | unique)',
            '          ))',
            '      )',
            '    \' "$PROVISION_PAIRED_FILE" > "$PROVISION_PAIRED_TEMP"; then',
            '      exit 1;',
            '    fi;',
            '    provision_install_json "$PROVISION_PAIRED_FILE" "$PROVISION_PAIRED_TEMP" || exit 1;',
            '    PROVISION_PAIRED_TEMP="";',
            '    if [ -f "$PROVISION_DEVICE_AUTH_FILE" ]; then',
            '      chmod 0600 "$PROVISION_DEVICE_AUTH_FILE" || exit 1;',
            '      PROVISION_DEVICE_AUTH_TEMP=$(provision_prepare_json_temp "$PROVISION_DEVICE_AUTH_FILE") || exit 1;',
            '      if ! jq --argjson required_scopes '.$escapedScopes.' \'',
            '        .tokens = ((.tokens // {}) | with_entries(',
            '          .value.scopes = (((.value.scopes // []) + $required_scopes) | unique)',
            '        ))',
            '      \' "$PROVISION_DEVICE_AUTH_FILE" > "$PROVISION_DEVICE_AUTH_TEMP"; then',
            '        exit 1;',
            '      fi;',
            '      provision_install_json "$PROVISION_DEVICE_AUTH_FILE" "$PROVISION_DEVICE_AUTH_TEMP" || exit 1;',
            '      PROVISION_DEVICE_AUTH_TEMP="";',
            '    fi;',
            '    if [ -f "$PROVISION_PENDING_FILE" ]; then',
            '      chmod 0600 "$PROVISION_PENDING_FILE" || exit 1;',
            '      PROVISION_PENDING_TEMP=$(provision_prepare_json_temp "$PROVISION_PENDING_FILE") || exit 1;',
            '      if ! jq --arg device_id "$PROVISION_LOCAL_DEVICE_ID" \'',
            '        del(.[$device_id])',
            '        | with_entries(select(.value.deviceId != $device_id))',
            '      \' "$PROVISION_PENDING_FILE" > "$PROVISION_PENDING_TEMP"; then',
            '        exit 1;',
            '      fi;',
            '      provision_install_json "$PROVISION_PENDING_FILE" "$PROVISION_PENDING_TEMP" || exit 1;',
            '      PROVISION_PENDING_TEMP="";',
            '    fi;',
            '  fi;',
            'fi;',
            ')',
        ]);
    }
}
