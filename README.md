# MME-Mail to SMTP

A WordPress plugin that routes `wp_mail()` through a real mail service — Gmail,
Google Workspace, SendGrid, Mailgun, Brevo, Postmark, Resend, SMTP2GO or any SMTP
server — and then makes failure visible.

> **This is the free plugin**, the one published on wordpress.org. The paid
> add-on lives in a separate repository and adds Microsoft 365 / Outlook, Amazon
> SES and Zoho, one-click Google sign-in, email logs, open/click tracking, a
> backup connection, smart routing, failure alerts and rate limiting. It requires
> this plugin to be installed and active; it does not replace it.

## Why

WordPress hands every message to the web server's own mail function. On most
hosts that means it lands in spam, or does not arrive at all — and nothing on the
site says so, because almost no WordPress code checks what `wp_mail()` returned.
Sites routinely go weeks before somebody notices the receipts stopped arriving.

So the plugin does two things: it puts the mail on a service that will actually
deliver it, and it refuses to let a failure pass silently.

## How it works

`wp_mail()` is taken over by replacing `$GLOBALS['phpmailer']` with a subclass
that intercepts `preSend()`. That matters: PHPMailer has already assembled the
complete RFC 822 message by then — attachments, inline images, Reply-To, Cc, Bcc
and custom headers included — so the plugin never reassembles a message by hand.
Every provider here accepts raw MIME, which is why a provider implementation is
three methods rather than a MIME library.

```
wp_mail()  ->  PHPMailer  ->  Mail_Catcher::preSend()  ->  Dispatcher
                                                            |
                                              provider for the connection
                                                            |
                                        success            failure
                                                            |
                                          retryable? -> retry queue (5 min, then backoff)
                                          permanent?  -> reported immediately
```

Two things stand between a transient fault and a lost email, in the order they
are tried: `Http` retries the request a few times inside the page load, and if
the connection still fails and the failure looks transient, the message goes on
the queue and is retried over the following hours. `Failure` decides which is
which — an oversized attachment is not retried anywhere, because no amount of
patience will make it fit.

### Connections

| Connection | Auth | Notes |
|---|---|---|
| Google Workspace | Service account, domain-wide delegation | No consent screen, no refresh token, nothing that expires |
| Gmail | OAuth sign-in | For accounts not on a Workspace domain; keeps a refresh token |
| SendGrid, Mailgun, Brevo, Postmark, Resend, SMTP2GO | API key | Mailgun has a US/EU region switch |
| Other SMTP | Host, port, credentials | Works everywhere; the hardest to diagnose |

One connection is configured at a time. Providers register through the
`mmoa_providers` filter and declare their own fields, so the chooser, the forms,
the REST payload and validation are all derived from that one list — adding a
provider means implementing `Provider_Interface`, not editing five files.

### What happens when sending breaks

- `wp_mail()` returns an accurate result and `wp_mail_failed` fires with a real
  `WP_Error`.
- `Health_Monitor` counts consecutive failures. Once the count crosses the
  configured threshold the site is recorded as failing: an admin notice appears
  and the **Email delivery** check under Tools, Site Health turns red and names
  the last error. One success clears both.
- Four actions, for monitoring or for an add-on to hang behaviour on:

  | Action | Fires |
  |---|---|
  | `mmoa_send_attempted` | every attempt, with provider, mailer, byte count, result and slot |
  | `mmoa_send_failed` | each individual failure |
  | `mmoa_send_failing` | once, when the streak crosses the threshold |
  | `mmoa_send_recovered` | when sending works again after an outage |

## Requirements

- WordPress 6.5+, PHP 8.0+
- libsodium, for encrypting credentials stored in the database
- Google Workspace domain for the service-account connection; a plain
  `@gmail.com` account uses the OAuth connection instead

## Setup

Everything lives under a top-level **MME-Mail to SMTP** menu. The setup wizard
opens on first run: it connects a mailbox, verifies the credentials against the
provider, and sends one real message, in that order.

Credentials can live in `wp-config.php` rather than the database — for example
`define( 'MMOA_GOOGLE_SA_PRIVATE_KEY', '...' );` — and the plugin marks a field
as pinned when a constant is set for it. Anything stored in the database instead
is encrypted with libsodium.

### Connecting a consumer Gmail account

Register an OAuth client in the Google Cloud console, paste the client ID and
secret in, save, then press **Sign in with Google**. Two traps worth knowing:

1. The consent screen must be **In production**. Left in Testing, Google expires
   every refresh token after seven days.
2. The redirect URI has to match character for character. The plugin shows the
   exact value to paste; it points at `admin-post.php` deliberately, so that
   reorganising the admin menu cannot break an existing connection.

Both Google paths use credentials you registered yourself — an OAuth client or a
service account key — so nothing passes through a third party.

## Privacy and GDPR

The plugin holds one thing that is personal data: the retry queue keeps the
complete message — body, headers and attachments — for as long as a retry might
still be wanted. It keeps no log of what it sent.

It registers a WordPress exporter and eraser, so Tools → Export and Erase
Personal Data answer for it. Erase deletes: a queued message addressed to the
requester is removed outright rather than anonymised, because it has not been
sent yet and delivering it afterwards would be the opposite of honouring the
request. The exporter deliberately omits the stored message body — that is the
site's outgoing mail, it can name other people in Cc, and an export is a file
somebody downloads.

`add_policy_content()` contributes suggested text to the site's privacy policy.

**The plugin sends nothing to its author.** No registration, no check-in, no
usage figures, no update server. The only servers it contacts are the mail
service you configure - nothing else, ever.

## Tests

Integration tests against a real WordPress install. Every outbound call is
stubbed through `pre_http_request`, so the suite needs no credentials and sends
nothing.

```bash
cd tests && ./run.sh
```

`run.sh` generates a throwaway 2048-bit service-account key before the run and
deletes it afterwards. **Run the suite through `run.sh`, not by invoking the
files directly** — without that key every service-account send fails and reads
like a product bug rather than a missing fixture.

| File | Covers |
|---|---|
| `test-failures.php` | Mid-life 401 recovery, error-message quality, the failure hooks |
| `test-gmail.php` | Both Google paths, JWT signing, base64url correctness |
| `test-google-consent.php` | Sign-in: authorization URL, callback, state replay, unresolvable slot, disconnect |
| `test-resilience.php` | Stale-token grace and the retry queue |
| `test-smtp.php` | SMTP conversation, error classification, credential isolation |
| `test-privacy.php` | Exporter and eraser, including the lookalike-address guard |
| `test-disconnect.php` | Disconnect clears every field and credential, and only that connection |
| `test-html-body.php` | HTML and plain-text part construction |
| `test-regression-wpms.php` | The WP Mail SMTP "no valid URL" failure, and that throttled mail is kept |
| `test-final.php` | End-to-end `wp_mail()` behaviour, credential encryption, constant precedence |

The plugin must sit in `wp-content/plugins/` of a working WordPress install. For
LocalWP or MAMP, point it at the right binary and socket:

```bash
PHP="/path/to/php" MYSQL_SOCK="/path/to/mysqld.sock" MMOA_TEST_HOST="mysite.local" ./run.sh
```

On **Windows** LocalWP there is no socket, and the CLI binary loads no extensions
by default, so pass the port and the extensions explicitly. Find the port in
`%APPDATA%\Local\sites.json` under `services.mysql.ports.MYSQL`:

```bash
PHPDIR="$APPDATA/Local/lightning-services/php-8.2.29+0/bin/win64"
MMOA_TEST_HOST=mysite.local "$PHPDIR/php.exe" \
  -d extension_dir="$PHPDIR/ext" \
  -d extension=php_mysqli.dll -d extension=php_openssl.dll -d extension=php_sodium.dll \
  -d mysqli.default_port=10013 \
  test-resilience.php
```

Only one mailer plugin can be active at a time — WordPress lets exactly one thing
take over sending. If another copy of this plugin is also active, the tests may
run against its classes rather than these.

## Status

Full detail, including known gaps and what is deliberately out of scope, is in
[docs/STATUS.md](docs/STATUS.md).

| | |
|---|---|
| Google Workspace service account | works, tested |
| Gmail consumer OAuth | works, tested — sign-in prompt, own OAuth client, revocable |
| SendGrid, Mailgun, Brevo, Postmark, Resend, SMTP2GO | works, tested |
| Other SMTP | works, tested |
| Retry queue | works, tested |
| Large attachments | ~2 MB ceiling, enforced before sending. Messages are base64-encoded twice on this path, so the usable payload is about half the API limit. Chunked upload not built |
| Plugin Check | zero errors against the wordpress.org review ruleset. The remaining warnings are interpolated table names, which cannot be placeholders |
| wordpress.org submission | reviewed once, every finding answered, resubmitted |

## Releasing

The free build is distributed through the WordPress.org plugin directory, so
sites are offered updates by WordPress itself. There is no update checker inside
the plugin and no credential shipped with it — core does that check centrally,
which also means a deactivated copy is still offered updates.

A release is therefore a version bump, a changelog entry in `readme.txt`, and a
publish to the directory. The exact commands are in
[docs/RELEASING.md](docs/RELEASING.md).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
