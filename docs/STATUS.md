# Status

What is built, what works, what is not here, and what is left. Updated at 0.16.0,
after the free/Pro split.

**284 assertions, all passing**, plus a runtime smoke pass over activation,
upgrade, the provider registry, the REST payloads, Site Health and the privacy
exporters. Nothing here has been exercised against a live Google endpoint yet —
see [Built but not verified](#built-but-not-verified).

---

## Admin layout

One top-level **MME-Mail to SMTP** menu opening straight onto the React app,
which carries its own tabs:

| Tab | Holds |
|---|---|
| Dashboard | Whether sending works, the last error if it does not, and the retry queue counts |
| Connections | The connection: provider chooser, its fields, verify, disconnect, and the test-email tool |
| Settings | From address, force-sender, the failure threshold, retry-queue toggle and retention, privacy notes |

The setup wizard is a sequence rather than a destination, so it renders without
the tab row — five ways out of a five-step flow is an invitation to abandon it
halfway.

The **OAuth redirect URI deliberately does not live under any of these screens.**
It is `admin-post.php?action=mmoa_google_callback`. Google matches that string
character for character against what the admin registered by hand, so tying it to
a menu page would mean that reorganising the admin silently breaks every existing
connection — with an error naming the URI rather than the rename that caused it.

There is no second admin interface any more. The server-rendered settings
screens under `admin/views/` were deleted when the wordpress.org review flagged
the inline `<script>` in one of them; nothing reached them, because the only
registered menu page is the React app. What is left of `Admin_Page` is the
Google sign-in round trip and the delivery-failure notice.

---

## Working, and covered by tests

| Feature | Where | Notes |
|---|---|---|
| Google Workspace service account | `providers/class-gmail-service-account.php` | Signed assertion exchanged for a short-lived token. No refresh token exists, so nothing expires. |
| Gmail consumer OAuth | `providers/class-gmail-oauth.php` | Sign-in prompt, own OAuth client, revocable. Keeps a refresh token — the one path where that is unavoidable. |
| SendGrid, Mailgun, Brevo, Postmark, Resend, SMTP2GO | `providers/class-*.php` | API key, raw MIME, per-service error mapping |
| Other SMTP | `providers/class-smtp.php` | Works everywhere; the least diagnosable |
| Retry queue | `class-queue.php` | 5-minute schedule then backoff, its own table, deleted on delivery |
| Failure visibility | `class-health-monitor.php`, `admin/class-site-health.php` | Streak counting, admin notice, Site Health check, four actions |
| Mid-life 401 recovery | `providers/abstract-gmail.php` | A token rejected mid-life is discarded and re-minted once, silently |
| Stale-token grace | `providers/abstract-provider.php` | A refresh that cannot reach the network spends the token already held rather than losing the message |
| Credential encryption | `class-secrets.php` | libsodium, versioned ciphertext, per-write nonce |
| Constant precedence | `class-settings.php` | A `wp-config.php` constant beats the database, and the UI says the field is pinned |
| Privacy | `class-privacy.php` | Exporter and eraser over the queue, with a post-filter so a lookalike address is never touched |
| Conflict detection | `class-conflicts.php` | Names the other mailer plugin, and catches one that defined `wp_mail()` before WordPress could |

---

## Deliberately not here

These are in the paid add-on, which requires this plugin and does not replace it.
They are not disabled or locked in this build — the code is absent.

Microsoft 365 / Outlook · Amazon SES · Zoho · one-click Google sign-in (and the
OAuth broker behind it) · email logs (view, search, resend) ·
open/click tracking and reports · a backup connection · smart routing · failure
alerts to Slack, Teams, Discord, SMS or webhooks · rate limiting · the weekly
summary report · licensing.

Two of those do not exist anywhere yet, in either plugin: **Amazon SES** and
**open/click tracking**.

---

## Built but not verified

Nothing here has been run against a live Google or third-party endpoint. Every
test stubs `pre_http_request`, which proves the request shape and the error
handling but not that the far end accepts it.

What that leaves open, honestly: a request this plugin considers correct could
still be rejected by the real API for a reason no stub anticipated. The Google
paths are the ones to exercise first, because they are the most involved.

---

## Known gaps

| Gap | Detail |
|---|---|
| Large attachments | ~2 MB ceiling, enforced before sending. A message on this path is base64-encoded twice, so the usable payload is about half the API limit. Chunked upload not built. |
| Plugin Check | Zero errors against the wordpress.org review ruleset. Fourteen warnings remain, all of them an interpolated `$wpdb->prefix` table name in the queue and the privacy exporter — a table name cannot be a placeholder, so there is nothing to change. |
| wordpress.org submission | Submitted, reviewed, nine findings, all answered. Awaiting the second review. |
| Translations | None bundled. The German catalogue was removed at the reviewer's request: a plugin in the directory gets its translations from translate.wordpress.org, and core discovers those by itself. German is untranslated until it is uploaded there — the strings themselves are unchanged, so the existing work can be imported rather than redone. |
| Upgrade prompts | The free build carries no pointer to the paid add-on yet. Permitted by the directory guidelines, within bounds — contextual, on our own screens, dismissible. |
| `migrate_merged_providers` | Has no test coverage. The file that covered it was never wired into `run.sh` and was deleted with the Microsoft removal; the gap predates that. |
