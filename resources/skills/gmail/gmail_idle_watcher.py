#!/usr/bin/env python3
"""Per-agent Gmail IMAP IDLE watcher.

Watches the agent's Gmail inbox over IMAP IDLE and, when new mail arrives, wakes
the agent by delivering a short message into a dedicated "gmail-inbox" session on
the LOCAL OpenClaw gateway (127.0.0.1) — the same Responses API the web chat uses.

No public endpoint, no Pub/Sub: the box pulls from Gmail and pushes to itself.
One watcher per Gmail agent (runs as a systemd user service). stdlib only.

Env:
  GMAIL_ADDRESS, GMAIL_APP_PASSWORD  — the account to watch (per-agent .env)
  AGENT_ID                            — openclaw agent id (harness_agent_id)
  GATEWAY_PORT                        — default 18789
  OPENCLAW_CONFIG                     — path to openclaw.json (for the gateway token)
"""
from __future__ import annotations

import email
import imaplib
import json
import os
import socket
import sys
import time
import urllib.error
import urllib.request
from email.header import decode_header, make_header

IMAP_HOST = "imap.gmail.com"
IDLE_TIMEOUT = 20 * 60  # re-IDLE before Gmail drops the connection (~29 min)
RECONNECT_BACKOFF = [5, 15, 30, 60]


def log(msg: str) -> None:
    print(f"[gmail-watcher] {msg}", flush=True)


def decode(value: str | None) -> str:
    if not value:
        return ""
    try:
        return str(make_header(decode_header(value)))
    except Exception:
        return value


def gateway_token(config_path: str) -> str:
    with open(config_path) as fh:
        cfg = json.load(fh)
    return cfg.get("gateway", {}).get("auth", {}).get("token", "")


def wake_agent(port: int, token: str, agent_id: str, text: str) -> None:
    """Deliver a message into the agent's persistent gmail-inbox session."""
    payload = json.dumps(
        {
            "model": f"openclaw/{agent_id}",
            "input": text,
            "stream": False,
            # Stable session key so the whole email thread stays in one place.
            "user": "gmail-inbox",
        }
    ).encode()
    req = urllib.request.Request(
        f"http://127.0.0.1:{port}/v1/responses",
        data=payload,
        headers={"Content-Type": "application/json", "Authorization": f"Bearer {token}"},
        method="POST",
    )
    try:
        # Fire-and-forget: a generous timeout so the agent has time to start,
        # but we don't care about the body — we just need it to begin working.
        urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.URLError as e:
        log(f"wake failed: {e}")


def new_summaries(imap: imaplib.IMAP4_SSL, since_uid: int) -> tuple[list[dict], int]:
    typ, data = imap.uid("search", None, f"UID {since_uid + 1}:*")
    if typ != "OK" or not data or not data[0]:
        return [], since_uid
    uids = [u for u in data[0].split() if int(u) > since_uid]
    summaries: list[dict] = []
    highest = since_uid
    for uid in uids:
        highest = max(highest, int(uid))
        typ, mdata = imap.uid("fetch", uid, "(BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])")
        if typ != "OK" or not mdata or not mdata[0]:
            continue
        msg = email.message_from_bytes(mdata[0][1])
        summaries.append(
            {
                "uid": uid.decode(),
                "from": decode(msg.get("From")),
                "subject": decode(msg.get("Subject")),
                "date": decode(msg.get("Date")),
            }
        )
    return summaries, highest


def do_idle(imap: imaplib.IMAP4_SSL, timeout: int) -> bool:
    """Block until new-mail activity or timeout. Returns True if activity seen."""
    tag = imap._new_tag()
    imap.send(b"%s IDLE\r\n" % tag)
    resp = imap.readline()
    if not resp.startswith(b"+"):
        raise imaplib.IMAP4.error(f"IDLE not accepted: {resp!r}")

    activity = False
    imap.sock.settimeout(timeout)
    try:
        while True:
            line = imap.readline()
            if not line:
                break  # server closed the connection
            if b"EXISTS" in line or b"RECENT" in line:
                activity = True
                break
    except (socket.timeout, TimeoutError):
        pass
    finally:
        imap.sock.settimeout(None)
        try:
            imap.send(b"DONE\r\n")
            while True:
                line = imap.readline()
                if not line or line.startswith(tag):
                    break
        except Exception:
            pass
    return activity


def run() -> None:
    addr = os.environ.get("GMAIL_ADDRESS", "").strip()
    pw = os.environ.get("GMAIL_APP_PASSWORD", "").strip()
    agent_id = os.environ.get("AGENT_ID", "").strip()
    port = int(os.environ.get("GATEWAY_PORT", "18789"))
    config_path = os.environ.get("OPENCLAW_CONFIG", "/root/.openclaw/openclaw.json")
    if not (addr and pw and agent_id):
        sys.exit("GMAIL_ADDRESS, GMAIL_APP_PASSWORD and AGENT_ID are required.")

    attempt = 0
    while True:
        try:
            token = gateway_token(config_path)
            imap = imaplib.IMAP4_SSL(IMAP_HOST)
            imap.login(addr, pw)
            imap.select("INBOX")
            # Baseline: only notify for mail that arrives AFTER we start, so a
            # restart doesn't replay the whole inbox at the agent.
            typ, data = imap.uid("search", None, "ALL")
            uids = data[0].split() if typ == "OK" and data and data[0] else []
            last_uid = int(uids[-1]) if uids else 0
            attempt = 0
            log(f"watching {addr} for agent {agent_id} (baseline uid={last_uid})")

            while True:
                activity = do_idle(imap, IDLE_TIMEOUT)
                if not activity:
                    imap.noop()  # keep-alive re-select cycle
                    continue
                summaries, last_uid = new_summaries(imap, last_uid)
                for s in summaries:
                    log(f"new mail uid={s['uid']} from={s['from']!r} subj={s['subject']!r}")
                    text = (
                        f"\U0001f4e7 New email in your Gmail inbox ({addr}).\n"
                        f"From: {s['from']}\nSubject: {s['subject']}\nDate: {s['date']}\n\n"
                        f"Read it with `python3 gmail_cli.py read {s['uid']}` and handle it per "
                        f"your instructions (reply with `gmail_cli.py send`)."
                    )
                    wake_agent(port, token, agent_id, text)
        except (imaplib.IMAP4.error, OSError) as e:
            delay = RECONNECT_BACKOFF[min(attempt, len(RECONNECT_BACKOFF) - 1)]
            attempt += 1
            log(f"connection error: {e} — reconnecting in {delay}s")
            time.sleep(delay)


if __name__ == "__main__":
    run()
