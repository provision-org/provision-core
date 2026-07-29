#!/usr/bin/env python3
"""Two-factor (TOTP) authenticator for an agent.

Stores TOTP secrets in a local, 0600 store and generates the current 6-digit
code on demand. Pure Python stdlib — no pip, no Google Authenticator needed:
TOTP (RFC 6238) is an open algorithm, so any code that holds the shared secret
can produce the same codes the authenticator app would.

Security posture: the *secret* is written once (at enrollment) and thereafter
only the throwaway 6-digit `code` is meant to leave this tool. Never print the
store, never reveal a secret — a leaked secret is a permanent 2FA bypass, while
a leaked code expires in ~30 seconds.

Commands:
    add <label> --secret <base32>        store a secret (manual-entry key)
    add <label> --uri "otpauth://..."    store from an otpauth:// QR URI
    code <label>                          print the current code (just digits)
    list                                  list stored labels (never secrets)
    remove <label>                        delete a stored secret
    backup <label> --codes A B C ...      save one-time backup/recovery codes

The store path comes from $TOTP_STORE (falls back to ./secrets/totp.json).
"""

import argparse
import base64
import hashlib
import hmac
import json
import os
import struct
import sys
import time
from urllib.parse import parse_qs, unquote, urlsplit


def store_path() -> str:
    return os.environ.get("TOTP_STORE") or os.path.join(os.getcwd(), "secrets", "totp.json")


def load_store() -> dict:
    path = store_path()
    if not os.path.exists(path):
        return {"version": 1, "entries": {}}
    with open(path, "r", encoding="utf-8") as fh:
        data = json.load(fh)
    data.setdefault("version", 1)
    data.setdefault("entries", {})
    return data


def save_store(data: dict) -> None:
    path = store_path()
    parent = os.path.dirname(path) or "."
    os.makedirs(parent, exist_ok=True)
    # Lock the parent down if we own it; the file's own 0600 is the real guard,
    # so don't fail when the store lives in a dir we can't chmod (e.g. a shared tmp).
    try:
        os.chmod(parent, 0o700)
    except OSError:
        pass
    # Write via a temp file, then move, so the store is never half-written.
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(data, fh, indent=2)
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)
    os.chmod(path, 0o600)


def normalize_secret(secret: str) -> str:
    """Google shows secrets in lowercase groups with spaces; normalize to the
    padded uppercase base32 the decoder expects."""
    cleaned = secret.replace(" ", "").replace("-", "").strip().upper()
    pad = (-len(cleaned)) % 8
    return cleaned + ("=" * pad)


def parse_otpauth(uri: str) -> dict:
    parts = urlsplit(uri)
    if parts.scheme != "otpauth":
        raise ValueError("not an otpauth:// URI")
    if parts.netloc.lower() != "totp":
        raise ValueError("only otpauth://totp/ (time-based) URIs are supported")
    query = parse_qs(parts.query)

    def one(key, default=None):
        vals = query.get(key)
        return vals[0] if vals else default

    secret = one("secret")
    if not secret:
        raise ValueError("otpauth URI has no secret")

    # Label is "Issuer:account"; issuer= param wins when present.
    label = unquote(parts.path.lstrip("/"))
    issuer = one("issuer")
    account = label
    if ":" in label:
        label_issuer, account = label.split(":", 1)
        issuer = issuer or label_issuer.strip()
        account = account.strip()

    return {
        "secret": normalize_secret(secret),
        "digits": int(one("digits", "6")),
        "period": int(one("period", "30")),
        "algorithm": (one("algorithm", "SHA1") or "SHA1").upper(),
        "issuer": issuer,
        "account": account,
    }


def generate_code(entry: dict, at: float | None = None) -> str:
    digits = int(entry.get("digits", 6))
    period = int(entry.get("period", 30))
    algo = (entry.get("algorithm", "SHA1") or "SHA1").upper()
    hasher = {"SHA1": hashlib.sha1, "SHA256": hashlib.sha256, "SHA512": hashlib.sha512}.get(algo)
    if hasher is None:
        raise ValueError(f"unsupported algorithm: {algo}")

    key = base64.b32decode(normalize_secret(entry["secret"]))
    counter = int((at if at is not None else time.time()) // period)
    msg = struct.pack(">Q", counter)
    digest = hmac.new(key, msg, hasher).digest()
    offset = digest[-1] & 0x0F
    binary = struct.unpack(">I", digest[offset:offset + 4])[0] & 0x7FFFFFFF
    return str(binary % (10 ** digits)).zfill(digits)


def seconds_remaining(entry: dict) -> int:
    period = int(entry.get("period", 30))
    return period - int(time.time()) % period


def cmd_add(args) -> int:
    if bool(args.uri) == bool(args.secret):
        print("provide exactly one of --uri or --secret", file=sys.stderr)
        return 2
    if args.uri:
        entry = parse_otpauth(args.uri)
    else:
        entry = {
            "secret": normalize_secret(args.secret),
            "digits": args.digits,
            "period": args.period,
            "algorithm": args.algorithm.upper(),
            "issuer": args.issuer,
            "account": args.account,
        }
    # Validate the secret decodes and produces a code before we persist it.
    try:
        generate_code(entry)
    except Exception as exc:  # noqa: BLE001
        print(f"invalid secret: {exc}", file=sys.stderr)
        return 1

    data = load_store()
    existing = data["entries"].get(args.label, {})
    entry.setdefault("issuer", existing.get("issuer"))
    entry.setdefault("account", existing.get("account"))
    if "backup_codes" in existing:
        entry["backup_codes"] = existing["backup_codes"]
    data["entries"][args.label] = entry
    save_store(data)
    print(f"stored 2FA secret for '{args.label}'"
          + (f" ({entry.get('issuer')})" if entry.get("issuer") else ""))
    return 0


def cmd_code(args) -> int:
    data = load_store()
    entry = data["entries"].get(args.label)
    if not entry:
        print(f"no 2FA secret stored for '{args.label}' — add it first", file=sys.stderr)
        return 1
    print(generate_code(entry))
    # Timing hint goes to stderr so the code on stdout stays clean for typing.
    print(f"(valid {seconds_remaining(entry)}s)", file=sys.stderr)
    return 0


def cmd_list(args) -> int:
    data = load_store()
    rows = []
    for label, entry in data["entries"].items():
        rows.append({
            "label": label,
            "issuer": entry.get("issuer"),
            "account": entry.get("account"),
            "digits": entry.get("digits", 6),
            "backup_codes": len(entry.get("backup_codes", [])),
        })
    print(json.dumps(rows, indent=2))
    return 0


def cmd_remove(args) -> int:
    data = load_store()
    if args.label not in data["entries"]:
        print(f"no 2FA secret stored for '{args.label}'", file=sys.stderr)
        return 1
    del data["entries"][args.label]
    save_store(data)
    print(f"removed '{args.label}'")
    return 0


def cmd_backup(args) -> int:
    data = load_store()
    entry = data["entries"].get(args.label)
    if not entry:
        print(f"no 2FA secret stored for '{args.label}' — add it first", file=sys.stderr)
        return 1
    entry["backup_codes"] = [c.replace(" ", "").strip() for c in args.codes if c.strip()]
    save_store(data)
    print(f"stored {len(entry['backup_codes'])} backup code(s) for '{args.label}'")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="TOTP two-factor authenticator")
    sub = parser.add_subparsers(dest="command", required=True)

    p_add = sub.add_parser("add", help="store a TOTP secret")
    p_add.add_argument("label")
    p_add.add_argument("--secret", help="base32 manual-entry key")
    p_add.add_argument("--uri", help="otpauth://totp/... URI")
    p_add.add_argument("--digits", type=int, default=6)
    p_add.add_argument("--period", type=int, default=30)
    p_add.add_argument("--algorithm", default="SHA1")
    p_add.add_argument("--issuer", default=None)
    p_add.add_argument("--account", default=None)
    p_add.set_defaults(func=cmd_add)

    p_code = sub.add_parser("code", help="print the current code")
    p_code.add_argument("label")
    p_code.set_defaults(func=cmd_code)

    p_list = sub.add_parser("list", help="list stored labels (never secrets)")
    p_list.set_defaults(func=cmd_list)

    p_remove = sub.add_parser("remove", help="delete a stored secret")
    p_remove.add_argument("label")
    p_remove.set_defaults(func=cmd_remove)

    p_backup = sub.add_parser("backup", help="save one-time backup codes")
    p_backup.add_argument("label")
    p_backup.add_argument("--codes", nargs="+", required=True)
    p_backup.set_defaults(func=cmd_backup)

    args = parser.parse_args()
    try:
        return args.func(args)
    except Exception as exc:  # noqa: BLE001
        print(f"error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
