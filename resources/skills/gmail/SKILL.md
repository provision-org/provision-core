---
name: gmail
description: Send and receive email from your own Gmail account. Use when asked to send an email, check your inbox, read a message, reply, search mail, or when you are notified that a new email arrived.
metadata: {"openclaw":{"requires":{"bins":["python3"],"env":["GMAIL_ADDRESS","GMAIL_APP_PASSWORD","GMAIL_CLI"]},"primaryEnv":"GMAIL_ADDRESS"}}
---

# Gmail

You have a real Gmail mailbox. Send and read email through `gmail_cli.py` using
`python3 "$GMAIL_CLI" <command>`. Credentials are already configured in your
environment (`GMAIL_ADDRESS`, `GMAIL_APP_PASSWORD`) — never print or ask for them.

## New mail arrives automatically

A watcher notifies you the moment a new email lands, with the sender, subject, and
the message `uid`. When you get such a notice, read the message and act on it — you
don't need to poll.

## Your address

```bash
echo "$GMAIL_ADDRESS"
```

## List recent inbox messages (newest first, JSON)

```bash
python3 "$GMAIL_CLI" list --limit 15
```

Each row has `uid`, `from`, `subject`, `date`. Use the `uid` to read one.

## Read a message

```bash
python3 "$GMAIL_CLI" read <uid>
```

Prints headers (including `Message-ID`) and the plain-text body.

## Search (full Gmail search syntax)

```bash
python3 "$GMAIL_CLI" search "from:customer@example.com is:unread" --limit 10
python3 "$GMAIL_CLI" search "subject:invoice newer_than:7d"
```

## Send an email

```bash
python3 "$GMAIL_CLI" send --to "person@example.com" --subject "Hello" --body "Message text."
```

- Multiple recipients: comma-separate `--to`. Optional `--cc "a@x.com,b@y.com"`.
- Long/multi-line body: pass `--body -` and pipe the text on stdin.

## Reply in the same thread

Read the original first to get its `Message-ID`, then thread the reply:

```bash
python3 "$GMAIL_CLI" send --to "person@example.com" --subject "Re: Hello" \
  --in-reply-to "<the-original-Message-ID>" --body "My reply."
```

## Notes

- This is your own account — be careful and professional; never send half-finished
  drafts. Confirm with your human before anything irreversible or high-stakes.
- On an auth error, the App Password may have been revoked or 2-Step Verification
  changed — tell your human; you cannot fix it yourself.
