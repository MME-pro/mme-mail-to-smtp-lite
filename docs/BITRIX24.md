# Sending alerts to Bitrix24

Modern Mailer can tell you in Bitrix24 when your site stops being able to send
email. This page is the whole setup: making the webhook in Bitrix24, finding the
ID you need, and filling in the three fields on the Alerts screen.

You need to be an administrator of the Bitrix24 portal to create a webhook. If
you are not, send someone who is the [Create the webhook](#1-create-the-webhook)
section — it is the only part that happens in Bitrix24.

---

## What you will end up with

Three values:

| Field on the Alerts screen | Looks like |
|---|---|
| Inbound webhook URL | `https://your-portal.bitrix24.com/rest/1/xxxxxxxx/` |
| Send as | Notification, Chat message, or Task |
| User or chat ID | `1`, or `chat42` |

---

## 1. Create the webhook

In Bitrix24:

1. Open **Developer resources** from the left-hand menu. On some portals it sits
   under **Applications**, and older ones call the whole area **Webhooks** — the
   wording moves around between Bitrix24 versions, but it is always the section
   about REST and integrations.
2. Choose **Other** → **Inbound webhook**.
3. Give it a name you will recognise in a year. "Modern Mailer alerts" is fine.
4. Tick the scopes it needs — see the table below. Tick only those.
5. Save. Bitrix24 shows you a URL ending in a slash and a code. **Copy the whole
   URL.** Bitrix24 will not show you the code again, though you can always come
   back and generate a new webhook.

### Which scope to tick

Pick the scope for the destination you want. If you are not sure yet, tick `im`
— notifications are the safest default.

| You want the alert as | Scope | Bitrix24 method the plugin calls |
|---|---|---|
| A notification to one person | `im` | `im.notify.system.add` |
| A message in a group chat | `im` | `im.message.add` |
| A task with someone responsible | `tasks` | `tasks.task.add` |

A webhook granted only `im` cannot create tasks, and vice versa. If you later
switch **Send as** to something the webhook was not granted, Bitrix24 refuses
the alert and Modern Mailer shows you its reason — see
[When it does not work](#when-it-does-not-work).

Bitrix24's own reference for this is
[Inbound and Outbound Webhooks](https://apidocs.bitrix24.com/local-integrations/local-webhooks.html).

---

## 2. Find the ID to send to

What this needs depends on the destination.

### A user ID (for a notification or a task)

Open that person's profile in Bitrix24 and look at the address bar:

```
https://your-portal.bitrix24.com/company/personal/user/17/
                                                      ^^
```

The number is the user ID. Here, `17`.

This is the person who receives the notification, or who becomes responsible for
the task. Pick someone who can actually fix a mail outage — a task assigned to
somebody on holiday is the same as no alert at all.

### A chat ID (for a chat message)

Open the chat and read the address bar. Group chats appear as `chat` followed by
a number, for example `chat42`, and that whole string is what goes in the field.

Bitrix24 calls this the dialog ID. A direct message to one person uses their
plain user ID instead, but if that is what you want, **Notification** is the
better destination — it goes to their notification centre rather than arriving
as a chat from a robot.

---

## 3. Fill in the Alerts screen

In WordPress: **Modern Mailer → Alerts → Bitrix24**.

1. Paste the webhook URL. Keep the whole thing including the trailing slash;
   the plugin appends the method it needs.
2. Choose **Send as**.
3. Enter the user or chat ID from step 2.
4. Turn the channel on and save, then use **Send a test** to confirm it arrives.

The URL is stored encrypted and is never shown again after saving, because
anyone holding it can call every REST method the webhook was granted. Treat it
like a password: if it leaks, delete the webhook in Bitrix24 and make a new one.

### Which destination to choose

Not a technical decision — it depends on what your team does with an alert.

- **Notification** — one administrator is responsible for the site. It lands in
  their notification centre and nobody else is disturbed.
- **Chat message** — a team watches together and nobody is formally on call.
- **Task** — an alert is expected to be worked and closed rather than read. The
  person you name becomes responsible for it.

---

## What an alert looks like

Bitrix24 messages are BBCode, not Markdown and not HTML, so the plugin formats
them accordingly: a bold title, a bold label for each line, and a link back to
the send log on your site.

```
[B]Sending is failing[/B]
[B]Connection:[/B] Microsoft 365 (primary)
[B]Last error:[/B] The credential was rejected
[URL=https://example.com/wp-admin/...]Open the send log[/URL]
```

---

## When it does not work

Bitrix24 answers `200 OK` with an error object in the body for most
application-level problems rather than using an error status, so the plugin
reads the body rather than the status code. Anything it refuses shows up on the
Alerts screen as:

> Bitrix24 refused the alert: `<code>` — `<description>`

The usual causes:

| What you see | What it means |
|---|---|
| `ACCESS_DENIED`, or an error naming a method | The webhook was not granted the scope for the destination you chose. Edit the webhook in Bitrix24 and tick `im` or `tasks`. |
| An error about the user or dialog | The ID is wrong. A user ID is a bare number; a group chat ID looks like `chat42`. |
| `INVALID_CREDENTIALS`, or a 401 | The webhook was deleted or regenerated in Bitrix24. Make a new one and paste the new URL. |
| Nothing arrives, no error | The channel is switched off, or alerts have not been triggered. Use **Send a test**. |

Alerts are sent with a five-second timeout and are not retried. They run inside
somebody else's page load while the site is already having trouble, and a slow
alert service must not become a slow website. A missed alert is recoverable; the
send log still holds everything.

---

## Notes

- Alerts go out only when sending actually fails. A working site sends nothing
  to Bitrix24, which is why a test is worth doing at setup.
- The alert never contains message bodies. It names the connection, the error
  and the affected recipient, and links back to the log on your own site.
- Bitrix24 is one of several channels and they are not exclusive — you can have
  a task raised here and a message in Slack for the same failure.
