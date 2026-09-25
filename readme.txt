=== MME-Mail to SMTP ===
Contributors: mmeprowp
Tags: smtp, wp_mail, gmail, sendgrid, mailgun
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.16.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress email through Gmail, SendGrid, Mailgun, Brevo, Postmark, Resend, SMTP2GO or any SMTP server - and tells you when a message fails.

== Description ==

WordPress sends its email with `wp_mail()`, which hands the message to the web server's own mail function. On most hosts that means it lands in spam, or does not arrive at all - and nothing on the site says so, because almost no WordPress code checks what `wp_mail()` returned. Sites routinely go weeks before somebody notices the receipts stopped arriving.

This plugin does two things about that. It routes your mail through a real mail service, and it makes failure visible.

= Why this one and not one of the others =

There are a great many SMTP plugins. Four things here are deliberate choices the others mostly do not make.

* **No email log, on purpose.** A table of every message this site has sent - recipients, subjects, often the body - is a standing liability sitting in your database waiting for the next breach, and it is the first thing most mailer plugins turn on. This one keeps no copy of what it sent. It tells you instead whether sending is working right now, what the last error was, and what is queued.
* **A retry queue, in the free plugin.** A message lost to a five-minute network fault is lost for good in most free mailers, because a failed send is simply a failed send. Here it is persisted and retried over the following hours, and a failure no retry can fix - an oversized attachment, a rejected recipient - is reported straight away rather than queued behind hope.
* **Errors that name the misconfiguration.** "The service account is not authorized to impersonate this mailbox, so authorize its client ID in Google Workspace Admin" - not "HTTP 401". Every provider's responses are mapped to the thing you actually have to go and change.
* **Nothing is sent to the plugin's author.** No registration, no activation check-in, no usage statistics, no telemetry of any kind, no account to create. The only servers contacted are the mail service you configured. The list is in **External services** below, and it is the whole list.

Google Workspace is also handled with a service account and domain-wide delegation, which means no consent screen, no refresh token, and nothing that quietly expires in seven days.

= Connections =

* **Google Workspace**, with a service account and domain-wide delegation. No consent screen, no refresh token, nothing that expires: the site signs a short-lived assertion whenever it needs a token.
* **Gmail**, with a standard OAuth sign-in, for an account that is not on a Workspace domain.
* **SendGrid, Mailgun, Brevo, Postmark, Resend and SMTP2GO**, with an API key.
* **Any SMTP server**, including services not named above.

One connection is configured at a time, and every message goes out over it.

= What it does about failure =

* `wp_mail()` returns an accurate result, and `wp_mail_failed` fires with a real error rather than a bare `false`.
* Once sends fail repeatedly - you choose how many in a row count as an outage - an admin notice appears and the **Email delivery** check under Tools, Site Health turns red and names the last error. One success clears both.
* Error messages name the actual misconfiguration. "The service account is not authorized to impersonate this mailbox", not "HTTP 401".
* A persistent retry queue holds a message that a transient fault would otherwise have lost, and retries it over the following hours. Failures no retry can help, such as an oversized attachment, are reported immediately instead of being queued behind hope.
* Hooks for your own monitoring: `mmoa_send_attempted` on every attempt, `mmoa_send_failed` on each failure, `mmoa_send_failing` once an outage starts, and `mmoa_send_recovered` when it ends.

= Also =

* One MIME pipeline. Every connection here accepts a complete RFC 822 message, so what PHPMailer already built is what gets sent. Attachments, inline images, Reply-To, Cc, Bcc and custom headers are handled by WordPress core code rather than reassembled by hand.
* Credentials belong in `wp-config.php`, and the plugin marks a field as pinned when a constant is set. Anything stored in the database instead is encrypted with libsodium.
* A setup wizard that connects a mailbox, checks the credentials against the provider, and sends one real message - in that order, so you find out it works before you rely on it.
* Answers privacy requests through WordPress's own Tools, Export and Erase Personal Data.
* **The plugin sends nothing to its author.** No registration, no check-in, no usage figures, no telemetry of any kind.

== External services ==

This plugin sends your outgoing email through whichever third-party service you configure. Nothing is transmitted anywhere until you configure one, and the plugin contacts nothing else on its own.

**Google (OAuth 2.0 and the Gmail API)** - used when either Google connection is selected.

* `https://oauth2.googleapis.com` - receives a signed assertion or your refresh token, in exchange for a short-lived access token.
* `https://gmail.googleapis.com` - receives the complete outgoing email: sender, recipients, subject, body and any attachments.
* Terms: https://policies.google.com/terms - Privacy: https://policies.google.com/privacy

**SendGrid** - `https://api.sendgrid.com`. Terms: https://www.twilio.com/en-us/legal/tos - Privacy: https://www.twilio.com/en-us/legal/privacy

**Mailgun** - `https://api.mailgun.net` or `https://api.eu.mailgun.net`, depending on the region you choose. Terms: https://www.mailgun.com/legal/terms/ - Privacy: https://www.mailgun.com/legal/privacy-policy/

**Brevo** - `https://api.brevo.com`. Terms: https://www.brevo.com/legal/termsofuse/ - Privacy: https://www.brevo.com/legal/privacypolicy/

**Postmark** - `https://api.postmarkapp.com`. Terms: https://postmarkapp.com/terms-of-service - Privacy: https://postmarkapp.com/privacy-policy

**Resend** - `https://api.resend.com`. Terms: https://resend.com/legal/terms-of-service - Privacy: https://resend.com/legal/privacy-policy

**SMTP2GO** - `https://api.smtp2go.com`. Terms: https://www.smtp2go.com/terms/ - Privacy: https://www.smtp2go.com/privacy/

Each of those receives your API key and the complete outgoing email: sender, recipients, subject, body and any attachments. Each has its own terms and privacy policy, and you are choosing to send your mail through them, so read the policy of whichever you pick.

**Your own SMTP server** - used when Other SMTP is selected. The message goes to the host and port you enter, and nowhere else.

**Nothing else.** The plugin contacts no service of its author's: no registration, no check-in, no usage figures, no update server. The list above is the whole of it.

== Source code ==

Everything is here. The full, unminified source lives in a public repository: https://github.com/MME-pro/mme-mail-to-smtp-lite

All PHP in this plugin is plain, readable source. The one built asset is the admin interface, `build/index.js` and `build/index.css`, compiled by the standard WordPress toolchain from the React sources in `src/`, which ship inside this plugin as well as in the repository. No minifier, obfuscator or bundler other than `@wordpress/scripts` is involved, and there is no step that is not in the repository.

To build it yourself, from the plugin directory:

`npm ci`
`npm run build`

That reads `src/` and writes `build/`. `npm start` does the same in watch mode for development. The build requires Node 18 or newer; `package.json` and `package-lock.json` pin every dependency.

== Installation ==

1. Install and activate the plugin.
2. Go to **MME-Mail to SMTP** in the admin menu. The setup wizard opens on first run.
3. Choose a mail service and enter its credentials, or sign in to Google.
4. Send the test message the wizard offers. If it arrives, you are done.

Credentials can also be set in `wp-config.php` instead of the database - for example `define( 'MMOA_GOOGLE_SA_PRIVATE_KEY', '...' );`. The plugin shows a field as pinned when a constant is set for it.

== Screenshots ==

1. Eight mail services, every one of them available. Choose one and that is the connection every message goes out over.
2. The dashboard answers the only question that matters: is mail being delivered right now, what was the last error, and what is waiting in the retry queue.
3. A configured Google Workspace connection. The signing key is stored encrypted and never shown back; verify and send a test without leaving the screen.
4. The setup wizard. Choose a service, enter its credentials, verify them against the provider, and send one real message - in that order, so you find out it works before you rely on it.
5. Settings. How many failures in a row count as an outage, how long the retry queue holds a message, and the privacy tools WordPress already gives you.

== Support ==

Written and maintained by MME-pro (https://mme-pro.de/).

For help with a connection that will not authenticate, mail that is not arriving, or anything else about this plugin, write to support@mme-pro.de. Include the version shown in the footer of the plugin screen, and the error text from Tools, Site Health if sending is failing - the provider's own response is usually the whole answer.

== Frequently Asked Questions ==

= Do I need a Google Workspace account? =

For the service account connection, yes - domain-wide delegation is a Workspace feature. A plain @gmail.com account uses the Gmail OAuth connection instead, which works but keeps a refresh token.

= My Gmail connection stops working every week. =

Your Google Cloud consent screen is still in Testing status, which expires refresh tokens every seven days. Publish it to In production and reconnect.

= Why does the service account connection say it cannot impersonate the mailbox? =

Its client ID has not been authorized in Google Workspace Admin under Security, API Controls, Domain-wide Delegation, with the `https://www.googleapis.com/auth/gmail.send` scope. The error message names this too.

= How large can attachments be? =

About 2 MB. The API caps a single request at 4-5 MB, and a message on this path is base64-encoded twice, so the usable payload is roughly half the nominal limit. Oversized messages are rejected before sending, with a message saying so, rather than failing on the wire.

= Where did my mail go? Is there a log? =

Not in this plugin. It records no copy of what it sent - a mail log holding recipients and subjects is a standing liability, and this one deliberately does not keep one. What it does tell you is whether sending is currently working, what the last error was, and what is waiting in the retry queue.

= Can another mailer plugin be active at the same time? =

No. WordPress lets exactly one plugin take over sending, so one of the two would be configured and doing nothing - and which one wins depends on load order rather than on anything you chose. The plugin detects the common ones and says so on screen.

== Changelog ==

= 0.16.0 =
* First public release.
