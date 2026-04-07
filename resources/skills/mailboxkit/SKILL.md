---
name: mailboxkit
description: Send and receive emails via MailboxKit API. Use when the user asks to send an email, check inbox, read messages, reply to emails, search emails, or browse threads.
metadata: {"openclaw":{"requires":{"bins":["curl","jq"],"env":["MAILBOXKIT_API_KEY","MAILBOXKIT_INBOX_ID","MAILBOXKIT_EMAIL"]},"primaryEnv":"MAILBOXKIT_API_KEY"}}
---

# MailboxKit Email Skill

Manage emails using the MailboxKit REST API via curl and jq.

## Environment

- `MAILBOXKIT_API_KEY` — API key for authentication
- `MAILBOXKIT_INBOX_ID` — The agent inbox ID
- `MAILBOXKIT_EMAIL` — The agent email address (your email)

## Commands

### Get your email address
```bash
echo "$MAILBOXKIT_EMAIL"
```

### List inbox messages
```bash
curl -sS -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
  -H "Accept: application/json" \
  "https://mailboxkit.com/api/v1/inboxes/$MAILBOXKIT_INBOX_ID/messages?per_page=10" \
  | jq '.data[] | {id, from: .from_email, subject, date: .created_at}'
```

### Read a specific message
```bash
curl -sS -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
  -H "Accept: application/json" \
  "https://mailboxkit.com/api/v1/inboxes/$MAILBOXKIT_INBOX_ID/messages/$MESSAGE_ID" \
  | jq '.data | {id, from: .from_email, to: .to_emails, cc: .cc_emails, subject, text: .text_body, html: .html_body, attachments: [.attachments[]? | {id, filename, mime_type, size, url}], date: .created_at}'
```

### Send an email
```bash
curl -sS -X POST -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  "https://mailboxkit.com/api/v1/inboxes/$MAILBOXKIT_INBOX_ID/messages/send" \
  -d '{
    "to": ["recipient@example.com"],
    "subject": "Subject line",
    "text": "Plain text body",
    "html": "<p>HTML body</p>"
  }' | jq '.data'
```

Optional fields for send: `"cc": ["cc@example.com"]`, `"bcc": ["bcc@example.com"]`, `"attachments": [{"filename": "file.pdf", "content": "<base64>", "mime_type": "application/pdf"}]`

### Reply to a message
```bash
curl -sS -X POST -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  "https://mailboxkit.com/api/v1/inboxes/$MAILBOXKIT_INBOX_ID/messages/$MESSAGE_ID/reply" \
  -d '{
    "text": "Reply text",
    "html": "<p>Reply HTML</p>"
  }' | jq '.data'
```

Optional fields for reply: `"cc": [...]`, `"bcc": [...]`, `"attachments": [...]`

### Search messages
```bash
curl -sS -X POST -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  "https://mailboxkit.com/api/v1/messages/search" \
  -d '{
    "query": "search term",
    "inbox_id": '"$MAILBOXKIT_INBOX_ID"',
    "fields": ["subject", "body"]
  }' | jq '.data[] | {id, from: .from_email, subject, date: .created_at}'
```

Optional fields for search: `"date_from": "2026-01-01"`, `"date_to": "2026-02-28"`

### List threads
```bash
curl -sS -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
  -H "Accept: application/json" \
  "https://mailboxkit.com/api/v1/inboxes/$MAILBOXKIT_INBOX_ID/threads?per_page=10" \
  | jq '.data[] | {id, subject, message_count, last_message_at}'
```

### Get thread details
```bash
curl -sS -H "Authorization: Bearer $MAILBOXKIT_API_KEY" \
  -H "Accept: application/json" \
  "https://mailboxkit.com/api/v1/threads/$THREAD_ID" \
  | jq '.data | {id, subject, messages: [.messages[] | {id, from: .from_email, text: .text_body, date: .created_at}]}'
```

## Important Notes
- Always check your email address with `echo "$MAILBOXKIT_EMAIL"` before sharing it
- When listing messages, summarize them concisely
- For replies, include relevant context from the original message
- Use search to find specific emails by subject, sender, or content
- Supports CC/BCC on both send and reply
- Attachments must be base64-encoded when sending
- Use date_from/date_to in search for time-filtered results
- Use threads to view conversation history
