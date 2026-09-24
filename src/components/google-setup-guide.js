import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Copy, Check, TriangleAlert, ExternalLink } from 'lucide-react';
import {
	Button,
	Dialog,
	DialogTrigger,
	DialogContent,
	DialogHeader,
	DialogBody,
	DialogFooter,
	DialogTitle,
	DialogDescription,
	DialogClose,
	Alert,
	AlertTitle,
	AlertDescription,
} from './ui';

/**
 * Google setup, written out here rather than linked away to.
 *
 * The Documentation link used to open developers.google.com, which is accurate,
 * enormous, and about the Gmail API rather than about this form. Somebody
 * standing in front of two empty credential boxes needs six steps in order, not
 * a reference manual - and the one fact that decides whether their connection
 * survives a fortnight is not in Google's setup guide at all, because it is not
 * a Gmail API question.
 *
 * That fact is the publishing status. An OAuth consent screen left in Testing
 * expires every refresh token after seven days, so the connection works,
 * verifies, sends, and then dies each week - and nothing in the failure says
 * why. It is far and away the most common Gmail support request there is, and
 * the remedy is one button in a console the admin has already been in. So it is
 * both a numbered step and, under the steps, an alert restating it - a footnote
 * is what it used to be, and a footnote is what gets skipped.
 *
 * The external link stays, at the bottom, for anybody who wants Google's own
 * words. It is no longer the first thing offered.
 */

const GOOGLE_CONSOLE_URL = 'https://console.cloud.google.com/apis/credentials';
const GOOGLE_CONSENT_URL = 'https://console.cloud.google.com/apis/credentials/consent';
const SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

const Copyable = ( { value, label } ) => {
	const [ copied, setCopied ] = useState( false );

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( value );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Refused over plain HTTP or by policy. The value is on screen and
			// selectable either way, so there is nothing useful to report.
		}
	};

	return (
		<div className="flex items-stretch gap-2">
			<code className="flex-1 min-w-0 rounded-md border bg-muted/60 px-3 py-2 font-mono text-xs leading-relaxed break-all">
				{ value }
			</code>
			<Button variant="outline" size="icon" onClick={ copy } aria-label={ label }>
				{ copied ? <Check className="text-success" /> : <Copy /> }
			</Button>
		</div>
	);
};

const Step = ( { n, title, children } ) => (
	<li className="grid grid-cols-[1.75rem_1fr] gap-x-3 gap-y-2">
		<span
			aria-hidden="true"
			className="mt-px flex size-7 items-center justify-center rounded-full bg-brand-subtle text-xs font-medium text-brand-deep"
		>
			{ n }
		</span>
		<div className="grid gap-2">
			<p className="m-0 text-sm font-medium text-foreground">{ title }</p>
			{ children }
		</div>
	</li>
);

const Note = ( { children } ) => (
	<p className="m-0 text-[13px] leading-relaxed text-muted-foreground">{ children }</p>
);

const GoogleSetupGuide = ( { docsUrl, redirectUri } ) => (
	<Dialog>
		<DialogTrigger asChild>
			<button
				type="button"
				className="cursor-pointer self-center border-0 bg-transparent p-0 text-[13px] text-brand-deep underline-offset-2 hover:underline"
			>
				{ __( 'Documentation', 'modern-mailer-oauth' ) }
			</button>
		</DialogTrigger>

		<DialogContent>
			<DialogHeader>
				<DialogTitle>{ __( 'Connecting Google', 'modern-mailer-oauth' ) }</DialogTitle>
				<DialogDescription>
					{ __(
						'Three ways in, depending on the mailbox and who can authorise it.',
						'modern-mailer-oauth'
					) }
				</DialogDescription>
			</DialogHeader>

			<DialogBody>
				<div className="grid gap-6">
					<div className="grid gap-2">
						<Note>
							<strong className="font-medium text-foreground">
								{ __( 'One-click', 'modern-mailer-oauth' ) }
							</strong>
							{ ' - ' }
							{ __(
								'nothing to set up. Sign in and send as that mailbox.',
								'modern-mailer-oauth'
							) }
						</Note>
						<Note>
							<strong className="font-medium text-foreground">
								{ __( 'My own OAuth client', 'modern-mailer-oauth' ) }
							</strong>
							{ ' - ' }
							{ __(
								'an OAuth client you create in your own Google Cloud project, so nothing passes through anybody else.',
								'modern-mailer-oauth'
							) }
						</Note>
						<Note>
							<strong className="font-medium text-foreground">
								{ __( 'Service account', 'modern-mailer-oauth' ) }
							</strong>
							{ ' - ' }
							{ __(
								'Workspace only, and the best where available: no sign-in and nothing expires. Needs a domain administrator.',
								'modern-mailer-oauth'
							) }
						</Note>
					</div>

					<div className="grid gap-3">
						<h4 className="m-0 text-sm font-semibold">
							{ __( 'My own OAuth client, step by step', 'modern-mailer-oauth' ) }
						</h4>

						<ol className="m-0 grid list-none gap-5 p-0">
							<Step n="1" title={ __( 'Create or choose a Google Cloud project', 'modern-mailer-oauth' ) }>
								<Note>
									{ __(
										'Any project will do. The Gmail API is free at this volume.',
										'modern-mailer-oauth'
									) }
								</Note>
							</Step>

							<Step n="2" title={ __( 'Enable the Gmail API', 'modern-mailer-oauth' ) }>
								<Note>
									{ __(
										'APIs & Services, then Library, then Gmail API, then Enable.',
										'modern-mailer-oauth'
									) }
								</Note>
							</Step>

							<Step n="3" title={ __( 'Configure the OAuth consent screen', 'modern-mailer-oauth' ) }>
								<Note>
									{ __(
										'On a Workspace domain choose Internal: no test users, no verification, no seven-day expiry. Consumer accounts can only choose External.',
										'modern-mailer-oauth'
									) }
								</Note>
								<Note>
									{ __(
										'Add one scope and no others:',
										'modern-mailer-oauth'
									) }
								</Note>
								<Copyable
									value={ SEND_SCOPE }
									label={ __( 'Copy the scope', 'modern-mailer-oauth' ) }
								/>
							</Step>

							<Step n="4" title={ __( 'Publish the app', 'modern-mailer-oauth' ) }>
								<Note>
									{ __(
										'Press Publish app so the status reads In production.',
										'modern-mailer-oauth'
									) }
								</Note>
								<Note>
									{ __(
										'Google shows an unverified-app notice until verification completes; choose Advanced, then continue. It is the ordinary review, not the paid assessment.',
										'modern-mailer-oauth'
									) }
								</Note>
							</Step>

							<Step n="5" title={ __( 'Create the OAuth client', 'modern-mailer-oauth' ) }>
								<Note>
									{ __(
										'Credentials, then Create credentials, then OAuth client ID. Choose Web application, not Desktop.',
										'modern-mailer-oauth'
									) }
								</Note>
								<Note>
									{ __(
										'Under Authorised redirect URIs, add this exact address:',
										'modern-mailer-oauth'
									) }
								</Note>
								<Copyable
									value={ redirectUri }
									label={ __( 'Copy redirect URI', 'modern-mailer-oauth' ) }
								/>
							</Step>

							<Step n="6" title={ __( 'Paste, save, sign in', 'modern-mailer-oauth' ) }>
								<Note>
									{ __(
										'Paste the client ID and secret into the fields on this screen and save. The sign-in button stays disabled until you do.',
										'modern-mailer-oauth'
									) }
								</Note>
							</Step>
						</ol>

						{ /* After the steps rather than before them. It restates step 4
						     because step 4 is the one people skip, and skipping it does not
						     fail at setup - it fails a week later, looking like something
						     else entirely. */ }
						<Alert variant="warning">
							<TriangleAlert />
							<AlertTitle>
								{ __( 'Publish the app, or it stops sending every seven days', 'modern-mailer-oauth' ) }
							</AlertTitle>
							<AlertDescription>
								<p className="m-0">
									{ __(
										'In Testing, Google expires every refresh token after seven days, and only listed Test users can sign in.',
										'modern-mailer-oauth'
									) }
								</p>
								<p className="mt-2 mb-0">
									{ __(
										'Publishing removes both. It is step 4 above, and it is the one people skip.',
										'modern-mailer-oauth'
									) }
								</p>
							</AlertDescription>
						</Alert>
					</div>

					<div className="grid gap-2 rounded-lg border bg-muted/40 px-4 py-3">
						<h4 className="m-0 text-sm font-semibold">
							{ __( 'It worked, then stopped a week later', 'modern-mailer-oauth' ) }
						</h4>
						<Note>
							{ __(
								'The consent screen is still in Testing. Publish it, then sign in again here.',
								'modern-mailer-oauth'
							) }
						</Note>
					</div>

					<div className="grid gap-2">
						<h4 className="m-0 text-sm font-semibold">
							{ __( 'Service account, in short', 'modern-mailer-oauth' ) }
						</h4>
						<Note>
							{ __(
								'Create a service account, download its JSON key, and paste it here with the mailbox to send as. A domain administrator authorises it once.',
								'modern-mailer-oauth'
							) }
						</Note>
						<Note>
							{ __(
								'No sign-in and no refresh token, so nothing expires. Not available for a consumer gmail.com address.',
								'modern-mailer-oauth'
							) }
						</Note>
					</div>
				</div>
			</DialogBody>

			<DialogFooter>
				<a
					href={ GOOGLE_CONSENT_URL }
					target="_blank"
					rel="noreferrer"
					className="mr-auto inline-flex items-center gap-1.5 text-[13px] text-brand-deep no-underline hover:underline"
				>
					<ExternalLink className="size-3.5" />
					{ __( 'Consent screen', 'modern-mailer-oauth' ) }
				</a>

				<a
					href={ GOOGLE_CONSOLE_URL }
					target="_blank"
					rel="noreferrer"
					className="inline-flex items-center gap-1.5 text-[13px] text-brand-deep no-underline hover:underline"
				>
					<ExternalLink className="size-3.5" />
					{ __( 'Credentials', 'modern-mailer-oauth' ) }
				</a>

				{ docsUrl && (
					<a
						href={ docsUrl }
						target="_blank"
						rel="noreferrer"
						className="inline-flex items-center gap-1.5 text-[13px] text-muted-foreground no-underline hover:underline"
					>
						<ExternalLink className="size-3.5" />
						{ __( "Google's own documentation", 'modern-mailer-oauth' ) }
					</a>
				) }

				<DialogClose asChild>
					<Button variant="outline" size="sm">
						{ __( 'Close', 'modern-mailer-oauth' ) }
					</Button>
				</DialogClose>
			</DialogFooter>
		</DialogContent>
	</Dialog>
);

export default GoogleSetupGuide;
