#!/usr/bin/env python3
"""Gmail send/read CLI for a Provision agent.

Uses IMAP (read) + SMTP (send) over the account's App Password. Credentials come
from the per-agent .env (GMAIL_ADDRESS, GMAIL_APP_PASSWORD) — never passed on the
command line, so they don't leak into shell history or process listings.

Subcommands: list | read | search | send. Output is JSON for `list`/`search`
(easy to parse) and human text for `read`. stdlib only (imaplib/smtplib) — no pip.
"""
from __future__ import annotations

import argparse
import email
import email.utils
import imaplib
import json
import os
import smtplib
import sys
from email.header import decode_header, make_header
from email.message import EmailMessage

IMAP_HOST = "imap.gmail.com"
SMTP_HOST = "smtp.gmail.com"
SMTP_PORT = 587


def _creds() -> tuple[str, str]:
    addr = os.environ.get("GMAIL_ADDRESS", "").strip()
    pw = os.environ.get("GMAIL_APP_PASSWORD", "").strip()
    if not addr or not pw:
        sys.exit("GMAIL_ADDRESS / GMAIL_APP_PASSWORD not set in the environment.")
    return addr, pw


def _decode(value: str | None) -> str:
    if not value:
        return ""
    try:
        return str(make_header(decode_header(value)))
    except Exception:
        return value


def _imap() -> imaplib.IMAP4_SSL:
    addr, pw = _creds()
    imap = imaplib.IMAP4_SSL(IMAP_HOST)
    imap.login(addr, pw)
    return imap


def _summaries(imap: imaplib.IMAP4_SSL, uids: list[bytes]) -> list[dict]:
    out: list[dict] = []
    for uid in uids:
        typ, data = imap.uid("fetch", uid, "(BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])")
        if typ != "OK" or not data or not data[0]:
            continue
        msg = email.message_from_bytes(data[0][1])
        out.append(
            {
                "uid": uid.decode(),
                "from": _decode(msg.get("From")),
                "subject": _decode(msg.get("Subject")),
                "date": _decode(msg.get("Date")),
            }
        )
    return out


def cmd_list(args: argparse.Namespace) -> None:
    imap = _imap()
    try:
        imap.select(args.folder, readonly=True)
        typ, data = imap.uid("search", None, "ALL")
        uids = data[0].split() if typ == "OK" and data and data[0] else []
        uids = uids[-args.limit :][::-1]  # newest first
        print(json.dumps(_summaries(imap, uids), indent=2))
    finally:
        imap.logout()


def cmd_search(args: argparse.Namespace) -> None:
    imap = _imap()
    try:
        imap.select(args.folder, readonly=True)
        # Gmail exposes X-GM-RAW for full Gmail search syntax.
        typ, data = imap.uid("search", None, "X-GM-RAW", f'"{args.query}"')
        uids = data[0].split() if typ == "OK" and data and data[0] else []
        uids = uids[-args.limit :][::-1]
        print(json.dumps(_summaries(imap, uids), indent=2))
    finally:
        imap.logout()


def _body_text(msg: email.message.Message) -> str:
    if msg.is_multipart():
        for part in msg.walk():
            if part.get_content_type() == "text/plain" and "attachment" not in str(
                part.get("Content-Disposition", "")
            ):
                payload = part.get_payload(decode=True) or b""
                return payload.decode(part.get_content_charset() or "utf-8", "replace")
        # fall back to any text/html
        for part in msg.walk():
            if part.get_content_type() == "text/html":
                payload = part.get_payload(decode=True) or b""
                return payload.decode(part.get_content_charset() or "utf-8", "replace")
        return ""
    payload = msg.get_payload(decode=True) or b""
    return payload.decode(msg.get_content_charset() or "utf-8", "replace")


def cmd_read(args: argparse.Namespace) -> None:
    imap = _imap()
    try:
        imap.select(args.folder, readonly=True)
        typ, data = imap.uid("fetch", args.uid, "(RFC822)")
        if typ != "OK" or not data or not data[0]:
            sys.exit(f"Message uid {args.uid} not found in {args.folder}.")
        msg = email.message_from_bytes(data[0][1])
        print(f"From:    {_decode(msg.get('From'))}")
        print(f"To:      {_decode(msg.get('To'))}")
        print(f"Date:    {_decode(msg.get('Date'))}")
        print(f"Subject: {_decode(msg.get('Subject'))}")
        print(f"Message-ID: {msg.get('Message-ID', '')}")
        print("-" * 60)
        print(_body_text(msg).strip())
    finally:
        imap.logout()


def cmd_send(args: argparse.Namespace) -> None:
    addr, pw = _creds()
    msg = EmailMessage()
    msg["From"] = addr
    msg["To"] = args.to
    if args.cc:
        msg["Cc"] = args.cc
    msg["Subject"] = args.subject
    # Threaded reply headers so replies land in the same Gmail thread.
    if args.in_reply_to:
        msg["In-Reply-To"] = args.in_reply_to
        msg["References"] = args.in_reply_to
    body = args.body
    if body == "-":
        body = sys.stdin.read()
    msg.set_content(body)

    recipients = [r.strip() for r in (args.to.split(",") + (args.cc.split(",") if args.cc else [])) if r.strip()]
    with smtplib.SMTP(SMTP_HOST, SMTP_PORT) as smtp:
        smtp.starttls()
        smtp.login(addr, pw)
        smtp.send_message(msg, from_addr=addr, to_addrs=recipients)
    print(json.dumps({"sent": True, "to": recipients, "subject": args.subject}))


def main() -> None:
    p = argparse.ArgumentParser(prog="gmail_cli.py", description="Gmail send/read for a Provision agent")
    sub = p.add_subparsers(dest="cmd", required=True)

    lp = sub.add_parser("list", help="List recent inbox messages (newest first)")
    lp.add_argument("--limit", type=int, default=15)
    lp.add_argument("--folder", default="INBOX")
    lp.set_defaults(func=cmd_list)

    sp = sub.add_parser("search", help="Gmail search (X-GM-RAW syntax, e.g. 'from:foo is:unread')")
    sp.add_argument("query")
    sp.add_argument("--limit", type=int, default=15)
    sp.add_argument("--folder", default="INBOX")
    sp.set_defaults(func=cmd_search)

    rp = sub.add_parser("read", help="Read one message by uid")
    rp.add_argument("uid")
    rp.add_argument("--folder", default="INBOX")
    rp.set_defaults(func=cmd_read)

    ep = sub.add_parser("send", help="Send an email")
    ep.add_argument("--to", required=True, help="comma-separated recipients")
    ep.add_argument("--cc", default="")
    ep.add_argument("--subject", required=True)
    ep.add_argument("--body", required=True, help="body text, or - to read from stdin")
    ep.add_argument("--in-reply-to", default="", help="Message-ID to thread the reply under")
    ep.set_defaults(func=cmd_send)

    args = p.parse_args()
    try:
        args.func(args)
    except (imaplib.IMAP4.error, smtplib.SMTPException) as e:
        sys.exit(f"Gmail error: {e}")


if __name__ == "__main__":
    main()
