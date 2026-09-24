=== MME-Mail to SMTP ===
Contributors: builtwithmtw
Tags: smtp, wp_mail, microsoft 365, gmail, oauth
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.15.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress email through the Microsoft Graph and Gmail APIs using OAuth 2.0, with no password and nothing that expires and needs reauthorizing.

== Description ==

Microsoft is retiring Basic authentication for SMTP AUTH in Exchange Online, which ends the long-standing practice of pointing WordPress at smtp.office365.com with a mailbox password. The usual replacement is OAuth with a delegated sign-in, but that swaps one failure for another: it stores a refresh token, and refresh tokens die. They expire after about 90 days idle, and are revoked outright by a password change, an MFA enrolment, or a Conditional Access policy change.

When that happens, sending stops. Silently - because almost no WordPress code checks what `wp_mail()` returned. Sites routinely go weeks before somebody notices the receipts stopped arriving.

This plugin removes the refresh token from the design entirely.

**Microsoft 365** uses app-only authentication (the OAuth 2.0 client credentials grant). The site holds a client credential and mints a short-lived access token whenever it needs one. There is no sign-in prompt, no consent screen, and nothing left in the system to expire.

**Google Workspace** uses a service account with domain-wide delegation, which has the same property for the same reason.

**Gmail** consumer accounts use standard OAuth, because a service account cannot impersonate an @gmail.com address. This is the one path where a refresh token is unavoidable, and the plugin is explicit about that rather than pretending otherwise.

= Also =

* One MIME pipeline. Both APIs accept a complete RFC 822 message, so the message PHPMailer already built is what gets sent. Attachments, inline images, Reply-To, Cc, Bcc and custom headers are handled by WordPress core code rather than reassembled by hand.
* Failures are loud. `wp_mail()` returns an accurate result, `wp_mail_failed` fires with a real error, repeated failures raise an admin notice and a Site Health warning, and the `mmoa_send_failing` action lets you route alerts anywhere.
* Error messages name the actual misconfiguration - which credential is wrong, which Exchange policy is missing - instead of echoing a raw API response.
* Client secret expiry warnings, because an Entra secret lasts at most 24 months and its expiry is a scheduled outage.
* A backup connection and a persistent retry queue, so a transient fault at your host delays an email instead of losing it. Failures that no retry can help - an oversized attachment, a wrong secret - are reported immediately instead.
* Credentials belong in wp-config.php. Anything stored in the database instead is encrypted with libsodium.

== External services ==

This plugin sends your outgoing email through third-party APIs. Nothing is transmitted until you configure a provider.

**Microsoft (Entra ID and Microsoft Graph)** - used when the Microsoft 365 provider is selected.

* `https://login.microsoftonline.com` - receives your tenant ID, application ID and client secret, in exchange for an access token.
* `https://graph.microsoft.com` - receives the complete outgoing email: sender, recipients, subject, body and attachments.
* Terms: https://www.microsoft.com/licensing/terms/ - Privacy: https://privacy.microsoft.com/privacystatement

**Google (OAuth 2.0 and the Gmail API)** - used when either Google provider is selected.

* `https://oauth2.googleapis.com` - receives a signed assertion or your refresh token, in exchange for an access token.
* `https://gmail.googleapis.com` - receives the complete outgoing email: sender, recipients, subject, body and attachments.
* Terms: https://policies.google.com/terms - Privacy: https://policies.google.com/privacy

**Alert channels** - used only if you switch one on under Alerts, and only when a message fails to send.

* Whichever services you configure: Slack (`hooks.slack.com`), Discord (`discord.com`), Microsoft Teams (your Workflows endpoint), Bitrix24 (your portal), Twilio (`api.twilio.com`) or any address you enter as a custom webhook.
* Each alert contains the failed message's recipient address and subject line, the time, the error code and reason, the connection it was sent through, and your site's name and address. **It never contains the message body, any attachment, or any provider credential.**
* Nothing is sent to any of these until you switch a channel on, and nothing is sent at all while sending is working.
* Each service has its own terms and privacy policy. You are choosing to send a recipient address and a subject line to them, so read the policy of whichever you pick.

**MME-pro licensing service** - used by every installation, whether or not a provider is configured.

* `https://portal.techyza.com` - receives, once when the plugin is activated and once a day afterwards: your site's domain and address, a random identifier this site generates for itself, the versions of WordPress, PHP, MySQL and this plugin, your locale and timezone, whether the site is a multisite, which provider slugs are configured (for example "microsoft" or "smtp"), and two counts - how many messages this site sent in the current calendar month and how many of those went through a connection this plugin authenticates rather than an SMTP host of your own. When you enter a licence key, it receives that key too.
* It never receives a recipient, a subject, a message body, an attachment, a provider credential or the administrator's email address.
* This service is not on the path your email takes. If it is unreachable, or you switch it off, sending is unaffected. To switch it off entirely, add `add_filter( 'mmoa_portal_url', '__return_empty_string' );` to your theme or a small plugin - registration, the daily check-in and licensing all stop.
* Terms and privacy: https://mme-pro.de/

== Support ==

Written and maintained by MME-pro (https://mme-pro.de/).

For help with a connection that will not authenticate, mail that is not arriving, or anything else about this plugin, write to support@mme-pro.de. Include the version shown in the footer of the plugin screen and, if a message failed, the entry from Email Logs - the provider response in it is usually the whole answer.

== Frequently Asked Questions ==

= Can I use a personal outlook.com or hotmail.com address? =

Yes, on the Legacy connection. Choose Microsoft, then Legacy, and sign in with the account - it needs an app registration of your own but no tenant ID and no administrator.

Not on Graph API. App-only authentication needs a Microsoft 365 work or school tenant, and consumer accounts do not have one. That is the whole reason the Legacy option exists, and also why it is second choice: it keeps a refresh token, which a password or multi-factor change will invalidate.

= Why does it want an Exchange access policy? =

The `Mail.Send` application permission lets the app send as any mailbox in the tenant. Scoping it with `New-ApplicationAccessPolicy`, or with RBAC for Applications, restricts it to the mailboxes you choose. Do not skip this.

= My Gmail connection stops working every week. =

Your Google Cloud consent screen is still in Testing status, which expires refresh tokens every seven days. Publish it to In production and reconnect.

= How large can attachments be? =

About 2 MB in this version. Both APIs cap a single request at 4-5 MB, and a message on this path is base64-encoded twice, so the usable payload is roughly half the nominal limit. Oversized messages are rejected before sending with a message saying so. Chunked upload for larger attachments is planned.

== Changelog ==

= 0.15.0 =
* **The monthly send limit is gone.** Version 0.13.0 introduced a free tier of twenty messages a calendar month through Microsoft, Google, Brevo and the other authenticated services, after which the plugin refused to send. Nothing is capped now. Every provider is available to every installation, however much it sends, whether or not a licence key is entered.
* The Licence screen no longer shows a meter counting down to a limit, and no longer warns that mail is about to be refused - neither can happen. It still shows how many messages the site sent this month, which is worth knowing on its own.
* Sites that had been refused mail this month start sending again immediately on update. Nothing needs to be re-entered and no setting changed.

= 0.14.2 =
* The weekly report can go to more than one person. Type an address, press Enter, and it is added to the list; remove one with the x beside it. Everyone listed gets the same single message, so they can see who else received it. An existing single address keeps working exactly as it did, and so does MMOA_REPORT_EMAIL - which now accepts several addresses separated by commas.
* Fixed the Bitrix24 help link, which led to "Course not found or access denied". It pointed at a numbered training course, and those numbers are reassigned when Bitrix24 reorganises their courses. It now goes to their developer documentation on inbound webhooks.
* Added a setup guide for Bitrix24 alerts: making the webhook, which scope to grant, where to find the user or chat ID, and what to do about each error it can refuse an alert with.

= 0.14.1 =
* **Fixed: this plugin broke other plugins that update themselves from GitHub.** Version 0.13.0 added a credential to the download of its own update, and attached it far too broadly - to every request to GitHub's API made anywhere on the site. GitHub refuses those with "415 Unsupported 'Accept' header", which appeared in the admin as **Update check failed: GitHub answered 415** on somebody else's plugin. It also stopped this plugin's own updates from being found. Nothing else was affected - no mail, no settings, no data - but on a site with more than one GitHub-hosted plugin, none of them could see an update. The credential is now attached to exactly one address: this plugin's own release file.
* **If you are on 0.13.0 or 0.14.0, update by hand once.** The fault stops the site finding this update too, so the screen will keep saying you are up to date. Download the zip from the release page and install it over the top - your settings and connections are untouched - and every plugin's updates start working again immediately.

= 0.14.0 =
* **A new Alerts screen, and somewhere for an alert to actually go.** Seven channels: email, Slack, Discord, Microsoft Teams, Bitrix24 (as a notification, a chat message or a task), WhatsApp and SMS through Twilio, and a plain JSON webhook for anything else. Switch on as many as you like.
* **Every channel has a Send test alert button.** This matters more than it sounds. The alert address has always been sent by the web server rather than by this plugin - deliberately, because an alert about email being broken must not travel down the broken path - and on a great many hosts that means it is accepted and never delivered. There was previously no way to find that out except during an outage.
* **An alert now says what failed.** The recipient, the subject, the time, the error code and reason, and which connection was used. Previously the only thing available to an alert was the error, so it could tell you something had gone wrong but not what.
* The Settings screen now explains, in as many words, what triggers what: a failed send is logged immediately and every time, an alert waits for the number of consecutive failures you set, and the daily licensing check-in is unrelated and emails nobody. Choose "on every failed message" under Alerts if you would rather hear about each one; a quiet period stops one broken credential becoming a thousand notifications.
* **A weekly summary report**, off unless you switch it on. Delivered, failed, success rate, the busiest day, a breakdown per connection and the five errors that came up most. It is sent through your configured connection on purpose, so a report arriving is also a weekly proof that sending works end to end. A week with no mail at all sends nothing rather than a report saying zero.
* **Email Logs are paginated properly.** Ten, twenty-five, fifty or a hundred a page, with Previous and Next, a jump box, and a real total - "Showing 1-25 of 3,140". The search and the Sent/Failed filter now run in the database rather than over the rows already on screen, which is the only way either can be trusted once there is more than one page.
* **Fixed: a Gmail API error could produce a PHP warning and a useless message.** Google uses the same field name for two different things - a string from the sign-in endpoints, an object from the Gmail API - and the plugin treated both as a string. On most Gmail API errors that wrote an "Array to string conversion" warning to the error log and left the plugin unable to recognise the error it had just received.
* The backup and retry path has been tested against the cases that matter and did not have tests before: a timed-out connection, a rate-limited one, both connections down at once, a permanently rejected recipient, a retry landing on the connection it started on, and a routed message still falling back. Every one of them now asserts the same thing - the message was delivered, queued or reported, and never quietly dropped.

= 0.13.0 =
* **A setup wizard now runs when the plugin is activated.** It asks for the one thing that has to be decided - which service sends your email - and gets a test message out before it lets go of you. Previously activation left you on a settings screen with no indication of what to do first. You can leave it at any point; the plugin reminds you once and then stops asking.
* The plugin now notices when another mailer plugin is installed alongside it. Two plugins both replacing wp_mail() means one of them silently loses, and which one wins depends on load order - so if you have configured this plugin and your email is still going somewhere else, this is almost always why. The notice names the other plugin and can be dismissed.
* A new Licence screen, and with it a free tier. **Twenty messages a calendar month across Microsoft, Google, Brevo and the other built-in services.** Beyond that the plugin refuses to send, says so in the log, and points at the upgrade - it does not fail quietly.
* **Other SMTP is exempt, and always will be.** Configure a plain SMTP host and the plugin is free with no cap at all, on any number of messages. What a licence buys is the convenience of the built-in services, not the ability to send email.
* **This version reports its installation to the vendor.** Once on activation and once a day afterwards it sends the domain, the site address, the plugin, WordPress, PHP and database versions, which providers are configured, and how many messages have been sent this month. It does not send recipients, subjects, message bodies, provider credentials, or your administrator address. Settings, Privacy says the same thing on screen, and readme's External services section lists every field. The plugin's documentation previously said it sent nothing at all; that is no longer true and has been rewritten rather than left standing.
* A licence is tied to one domain. Activating the same key on another site releases the first one automatically, which is what moving a site or rebuilding it usually means - the previous site then says on screen that its licence moved, rather than quietly dropping to the free tier.
* **Nothing about licensing can stop your email.** If the vendor is unreachable a licensed site keeps sending for fourteen days on its cached entitlement, and an unlicensed site is unaffected. An outage at our end must never become an outage at yours.
* The provider list is now one component shared by the wizard and the connection screen, so the two cannot drift apart. The services that are not ready yet are marked in the corner instead of across the tile, where the label used to overlap the name.

= 0.12.0 =
* Answers privacy requests. Tools, Export Personal Data and Tools, Erase Personal Data now return what this plugin holds about an email address. Erasing deletes any message still waiting to be sent to that person, and anonymises the delivery log entries - keeping the date and outcome, removing the address, subject and diagnostics. A new Privacy section on the Settings screen links to both, and suggested wording for your privacy policy is offered under Settings, Privacy.
* **Disconnecting a connection now clears everything on it**, the From address and name included, not just the provider and its credentials. Previously the next person to use that connection saw a form pre-filled from a mailbox that was no longer connected.
* Messages waiting in the retry queue are now discarded after a set number of days, which you can change on the Settings screen. A queued message is stored complete, body and attachments, and until now a queue that stopped draining held them indefinitely.
* Disconnecting a Microsoft connection now clears its sign-in as well. It was clearing Google's and leaving Microsoft's in place.
* The Microsoft connection choices are now One-click and Own Auth App. The Graph API option is no longer offered for new connections; **any connection already using it carries on sending exactly as before**, and nothing needs reconfiguring.
* The Documentation link on the Google connection now opens a setup guide in place rather than sending you to Google. It covers all three ways in, and leads with the step people skip - publishing the OAuth consent screen, without which Google expires the sign-in every seven days.
* The redirect address now appears above the credential fields rather than below them, which is the order the work happens in: it goes into the app registration, and the client ID and secret only come back out of it.
* A great deal of explanatory text has been removed from the forms. What remains is the handful of lines that prevent a specific mistake.
* Informational notices are blue again. They had turned green when the house colours were applied, which made them read as success messages.
* Credits and a support address on every screen: support@mme-pro.de.

= 0.11.1 =
* Fixed the redirect address for the Legacy Microsoft connection, which Microsoft would not accept. It contained a question mark, and Entra refuses any address with one on an app registration that allows personal Outlook.com accounts - so the address shown on screen could not be saved into the very app registration people were told to create. It now reads like /mmoa-microsoft-callback/, which every kind of registration accepts. **If you already set up a Legacy connection, copy the new address from the connection screen into your Entra app, otherwise signing in will report AADSTS50011.**
* Sites using Plain permalinks cannot serve that address, so they keep the old one and the screen now says which account types their app registration must be limited to. Previously it handed over an address that Microsoft would simply refuse, with nothing to explain why.
* The connection screen now says to sign in with the mailbox that will send the email, rather than with an administrator account. An administrator account without a mailbox of its own connects successfully and then fails on the first message, which reads as a broken connection rather than the wrong account.
* Closed a fault on the new address that let any visitor trigger a PHP error by crafting the URL.

= 0.11.0 =
* Added a third way to connect Microsoft, for people who cannot register an application in Azure. It needs only an application ID and a client secret - no tenant ID and no administrator - and sends as the mailbox that signs in. It is also the only Microsoft option that works with a personal Outlook or Hotmail account.
* The Microsoft connection choices are now named One-click, Graph API and Legacy. Graph API is unchanged and is still the one to prefer: it has nothing that expires but the secret. Legacy is the sign-in option above, and it is named that because it keeps a token that a password change, a new multi-factor setup or ninety days of silence will invalidate - the very problem this plugin was written to remove. Choose it only where Graph API cannot be used.
* The Microsoft 365 form no longer asks for the sending mailbox twice. It was always optional in practice - left empty it uses the From address, which is what nearly every site wants - so it is now marked optional and explains the one case that needs it: sending through a shared mailbox that has Send As permission for your From address.
* Existing Microsoft connections are unaffected. Only the labels changed; nothing needs reconfiguring.

= 0.10.1 =
* Fixed the screen going blank when you press Save with a browser translation switched on. Chrome's translation rewrites the page in a way the interface could not follow, and the moment anything on screen changed it gave up and left nothing behind. Translated pages now save normally.
* If a screen does ever fail, it now says so and offers to reload, instead of showing an empty page. It also says whether your change was saved - it almost always was, because the failure happens after the save has been sent and answered.

= 0.10.0 =
* The plugin is now called MME-Mail to SMTP. Only the name changes - your connections, credentials and logs are untouched, and the entry in the Plugins list simply reads differently after the update.
* Added Zoho Mail as a connection. Choose the region your Zoho account belongs to, enter the address and an app password, and the server and port are set for you. The region matters: Zoho runs a separate mail system per data centre, and an account created on zoho.eu cannot sign in to the American server - which fails looking exactly like a wrong password.
* New look, taken from the MME-pro house colours. Same screens in the same places; the header band, buttons, tabs and charts now carry the brand rather than a generic blue.
* The screens now credit MME-pro in a footer, with the version and licence beside it - which is also the quickest thing to screenshot when reporting a problem.

= 0.9.1 =
* HTML email now arrives as HTML. A message whose body is HTML but that never said so was delivered with its tags showing - which is most theme functions, form plugins and hand-built templates, because WordPress sends plain text unless a Content-Type header is added. Such a message is now sent as HTML, with a readable plain-text version alongside it for the clients that want one.
* Fixed HTML email going out as plain text through SendGrid, Resend, Brevo, Postmark and SMTP2GO. This hit ordinary, correctly formed HTML mail - anything WooCommerce, WordPress itself or a form plugin sends - and the recipient saw the raw markup. The Microsoft, Google and SMTP connections were never affected.

= 0.9.0 =
* German translation, complete. The plugin follows your WordPress language, including each user's own profile setting, and reads as part of the admin rather than something bolted onto it.
* The From address and From name now belong to each connection instead of the whole site. Two connections normally authenticate as different mailboxes, and every provider here refuses or rewrites a From address the authenticated identity may not use - so one shared value meant a backup could only work by coincidence. Your existing address is copied to every connection on update; nothing to redo.
* Saved credentials are no longer shown in plain text. There is a copy button instead, so a key can be checked against the provider's own console without appearing on a screen that might be shared.
* Every connection now has a Disconnect button. It clears the provider and its credentials, and withdraws any account the connection is signed in to rather than merely forgetting it.

* Upgrade note: if you set MMOA_FROM_EMAIL or MMOA_FROM_NAME in wp-config.php, those constants now pin the primary connection. Additional connections take their own address from the settings screen.

= 0.8.6 =
* Click any row in the send log to see the full story of that attempt: WordPress and PHP versions, the connection settings that were in force, the server environment, the error, and - for SMTP - the entire conversation with the mail server. There is a button to copy the lot as text.
* Credentials never appear in a report. A stored password is listed as set, never shown, and the SMTP transcript is captured at a level where PHPMailer masks the login exchange itself.
* Reports are kept for failures only, so the log does not grow larger than the mail it describes. Entries recorded before this release have none.
* Sending a test message no longer falls back to the backup connection, and is no longer queued. A test exists to say whether the primary works, and both of those could report success while it was broken.
* SendGrid, Resend, Brevo and Other SMTP are available again. Postmark, Mailgun and SMTP2GO remain marked as coming soon.
* API keys are shown in the connection form, masked, with a button to reveal them - so a key that was pasted with a character missing can be spotted without sending a message to find out.
* Fixed the band of empty space that appeared under every provider tile.

= 0.8.5 =
* The connection chooser is now in a set order: Microsoft, Google, SendGrid, Resend, Brevo, Postmark, Mailgun, SMTP2GO, Other SMTP.

= 0.8.4 =
* The connection chooser now offers Microsoft and Google. The rest are shown as coming soon while they are finished.
* A connection already sending through one of those is untouched: it keeps working, keeps its settings, and stays editable. Nothing that was delivering mail stops delivering it.
* Fixed a misleading error on Outlook. Sending with a From address that is not the mailbox you signed in with was reported as "an administrator may have restricted it", which is the wrong place to look. It now names both addresses and tells you which one to change.
* Verify catches that mismatch too, so it surfaces before the first message fails rather than after.

= 0.8.3 =
* One-click setup now works. The setup service it needs is live, so Sign in with Google connects a mailbox without anyone opening the Google Cloud console. Nothing to configure: the address ships with the plugin.
* Your mail still goes directly from your site to Gmail. The setup service performs the sign-in and never sees a message.
* Prefer to depend on nothing of ours? Choose My own OAuth client, or a service account, exactly as before. Both are untouched by this.
* One-click for Microsoft is not available yet and says so; Microsoft 365 with your own Azure application is unaffected.

= 0.8.2 =
* Every sign-in and disconnect button works again. All of them failed with "The link you followed has expired", however fresh the page was.
* The links are built for the admin app, which receives them as data and sets them as a link directly. They were being escaped for HTML instead, so the browser sent the escaped text verbatim and the security nonce arrived under the wrong name - which WordPress reports as an expired link, blaming the one thing that was not wrong.

= 0.8.1 =
* One-click setup is visible again. 0.8.0 shipped it hidden, because no setup service exists yet to answer it - but hiding a finished feature to avoid a bad error message was the wrong trade. Sign in with Google and Sign in with Microsoft are both on the connection screen.
* Pressing either without a setup service now says so plainly, and says it before leaving the site rather than after a browser error page on a host that cannot exist. Define MMOA_BROKER_URL with the address of your service to switch it on.

= 0.8.0 =
* Microsoft 365 and Outlook are now one Microsoft provider, and Google Workspace and Gmail one Google provider. The chooser listed authentication methods, which asked you to decide how to authenticate before deciding where to send. Each tile now asks how to connect once you have picked the service. Existing connections are migrated and keep sending exactly as before.
* Added one-click setup for Google and Microsoft, which obtains the credential without anyone opening a cloud console. It needs a hosted setup service, which does not exist yet, so it is switched off and hidden until MMOA_BROKER_URL points at one. Nothing else is affected.
* Added Outlook as a delegated Microsoft transport - sends as the signed-in mailbox, needs nothing registered in Azure, and works for personal accounts with no tenant. Reached through the Microsoft provider.
* Signing in from a connection other than the primary or backup used to store the credential against the primary, overwriting a working one and leaving the intended connection reporting itself disconnected however many times you tried.
* Credentials belonging to a setup method you are not using are now hidden rather than greyed out. Two dead boxes under a one-click choice read as something still to fill in.

= 0.7.4 =
* Verifying a Microsoft 365 connection now names the permission that is missing, instead of reporting whichever error Graph happened to return first. An app registration with no admin consent, or with everything granted except Mail.Send, is told exactly what to add and where to add it.
* Verify no longer reports a clean pass when it could only check part of the connection. Where the sending mailbox itself could not be read - that needs a permission sending does not require, and this plugin does not ask for - it now says so instead of leaving it unmentioned.
* Connecting or verifying now points out required fields that are still empty, before anything is sent to the provider. A half-filled connection used to come back as an error from the provider rather than a note on the field that was missing.

= 0.7.3 =
* Updates now appear promptly. The check remembered its answer for six hours, so a release could sit unnoticed for most of a working day; it is now fifteen minutes, and a failed check is retried after five rather than thirty.
* Check Again on the Updates screen now really does check again. It was rebuilding WordPress own list while still reading the plugin cached answer, so clicking it changed nothing until the cache expired.
* Added an update entry to Tools, Site Health. A check that cannot reach GitHub used to look exactly like a check that found nothing new - the site simply never offered an update and nothing said why. It now reports whether the check is getting through, and names the usual causes when it is not.

= 0.7.2 =
* The plugin screens now use the full width of the admin area. They were capped at 1240px, which left a growing empty margin on wide monitors while the email log and the provider grid were the things that wanted the room.

= 0.7.1 =
* Clicking a tab no longer leaves a pale outline behind it. The ring was firing on plain focus, which a mouse click triggers as well as the Tab key; it now appears only for keyboard focus, where it is needed.

= 0.7.0 =
* Reworked the interface palette and typography. Cool neutrals with a single indigo accent, and Plus Jakarta Sans with Inter in place of the previous warm paper-and-serif treatment, which read as somebody else's brand rather than as a dashboard.
* Added Brevo, SMTP2GO and Resend, bringing the provider list to ten.
* Every provider now shows its own logo. The marks are drawn inline rather than loaded from the provider, so the screen makes no third-party request and nothing breaks if one is unreachable.
* The provider chooser is now a single wrapping row instead of three grouped sections - with ten providers, one field of marks is quicker to search than three headings and three grids.

= 0.6.2 =
* Fixed the admin styling actually reaching the page. WordPress ships plain element rules that are unlayered, and an unlayered rule beats a layered one whatever its specificity - so every heading size, heading colour and link colour in the app was silently losing to WordPress. The tabs were rendering in WordPress blue and the wordmark near-black on the dark band.
* WordPress admin notices no longer land in the middle of the plugin header. They were being relocated to just after the first heading inside the page, which was the wordmark.
* Confirmations now appear at the top right, clear of the admin bar.
* The activity chart has a proper empty state instead of a blank panel.

= 0.6.1 =
* Redesigned the dark theme. It was the light palette inverted rather than designed - cards barely separated from the canvas, the header band was darker than the page and disappeared, and oxblood lightened into pink. It is now built as the same room at night: elevation from lightness rather than shadow, chrome lighter than the canvas, and the semantic colours held to garnet and jade instead of drifting to pink and mint.
* Dark can now actually be chosen. There is a toggle in the header, remembered per browser; before this the dark styles existed but nothing ever applied them.
* Rebuilt the tabs around a single brass rule that travels between them rather than five that take turns appearing, measured from the labels so it stays correct in any translation.

= 0.6.0 =
* Gave the admin a visual identity. Paper canvas, ink chrome and a single brass accent, with Fraunces for display type and Inter Tight for everything else - a private bank's own collateral rather than a default admin template.
* The dashboard leads with one figure at display size rather than four equal cards, so the screen says what it is about before you read a word.
* Added an engine-turned guilloche - the rosette engraved on banknotes and share certificates - behind the header band and, faintly, behind the chart. It appears exactly twice.
* Colour now means one thing each: brass is the only decorative accent, and oxblood and malachite appear on failure and delivery and nowhere else.

= 0.5.2 =
* The MME-Mail to SMTP menu no longer has submenus. It opens straight onto the app, which carries its own tabs - Dashboard, Connections, Routing, Email Logs and Settings - so there is one navigation to follow rather than two that had to agree with each other.

= 0.5.1 =
* Fixed the on/off switches. The thumb was almost the same colour as the track when a switch was off, so the control read as a blank pill that gave no sign of its state - or that it was a control at all. The off track now has its own colour, the thumb carries a shadow, and the switch is larger and easier to hit.
* Clicking the text beside a switch now toggles it. It previously did nothing: the label pointed at a button, which browsers do not reliably activate that way.

= 0.5.0 =
* Added additional connections. Beyond Primary and Backup you can now configure up to ten more, each with its own provider and its own credentials.
* Added smart routing. Rules send matching email through a chosen connection - receipts through a transactional sender, a newsletter through somewhere else. Conditions can test the subject, the To, Cc or Bcc addresses, the recipient domain or the From address; conditions in a group are combined with And, groups with Or, and the first matching rule wins.
* Routing chooses the path and nothing more: a routed message that fails still falls back to the backup connection and then to the retry queue, exactly as an unrouted one does.
* A rule pointing at a connection that has been deleted, or one left without a condition, is ignored rather than capturing mail. Deleting a connection removes its stored credentials and any rule that referenced it.
* The queue records which connection a message was routed to, so a retry cannot silently move it to a different sender after the rules change.

= 0.4.2 =
* The plugin now checks GitHub for its own updates. New versions appear on the Plugins screen and under Dashboard - Updates like any other plugin, and update with one click or automatically if the site has auto-updates on.

= 0.4.1 =
* Reworked the Other SMTP form: server, username and password on one row; encryption as TLS / SSL / None radio buttons; the port set automatically from the encryption and still editable; authentication as an explicit on/off choice, on by default.
* Switching authentication off now disables the username and password rather than leaving them looking editable, and a username missing while authentication is on is reported before anything is dialled.

= 0.4.0 =
* Added Other SMTP - any server that speaks SMTP, including the SMTP endpoint of a service not listed here. Host, port, STARTTLS or implicit TLS, and an optional username and password.
* Verifying an SMTP connection opens a real session and authenticates, so a wrong port, the wrong encryption or a bad password is reported before the first message is sent rather than after.
* SMTP replies are classified correctly for retrying: a 4xx (greylisting, over quota) is held and tried again, a 5xx rejection is reported immediately and not retried.

= 0.3.0 =
* Rebuilt the admin as a single-page app: Dashboard, Connections, Email Logs and Settings under one menu, with a sending-status indicator on every screen.
* Added a Sign in with Google button for the consumer Gmail connection, alongside the redirect URI it needs and a plain explanation of what the consent prompt grants.
* Added a REST API the admin app runs on. Provider settings forms are generated from each provider's declared fields, so a provider added by another plugin gets a working form without any UI changes.
* Added SendGrid, Postmark and Mailgun.
* Added a notice when another plugin has taken over wp_mail(), which previously left this plugin configured but silently not sending.

= 0.2.0 =
* Moved to a top-level MME-Mail to SMTP menu with three screens: Settings, Backup and Logs. The Logs entry carries a count when the retry queue is holding anything.
* The Google OAuth redirect URI is now `admin-post.php?action=mmoa_google_callback`, which does not change if the admin menu is ever reorganised.
* Added a backup connection. When the primary fails, the message is retried immediately against a second, independently configured provider.
* Added a retry queue. A send that fails for a transient reason - a network or DNS fault at the host, a throttle, a 5xx - is held and retried across later requests instead of being lost. Attempts back off from five minutes and stop after about two days.
* A failed token refresh now falls back to the cached token while it is still genuinely valid, so a brief outage at the identity endpoint no longer costs an email.
* Added the Gmail sign-in flow. Enter your own OAuth client ID and secret, connect the account through Google's consent prompt, and revoke it again from the same screen.
* Queued and abandoned mail is surfaced in Site Health and on the settings screen, so mail that was never delivered cannot pass unnoticed.

= 0.1.0 =
* Initial release: Microsoft Graph app-only, Google service account, and Gmail OAuth providers.
