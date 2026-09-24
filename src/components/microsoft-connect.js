import { __, sprintf } from '@wordpress/i18n';
import { createInterpolateElement, useState } from '@wordpress/element';
import { CheckCircle2, TriangleAlert, Info } from 'lucide-react';
import { Button, Separator, Alert, AlertDescription } from './ui';
import { MicrosoftButton } from './microsoft-button';

/**
 * Microsoft sign-in for the delegated connection.
 *
 * The counterpart to GoogleConnect and deliberately the same shape, because it
 * is the same job: an OAuth handshake hands the browser to the identity
 * provider and gets it back as a fresh page load, which a fetch() cannot do -
 * so the control is a nonce-signed admin-post link the server turns into a
 * redirect, not a button that posts.
 *
 * Two things differ from the Google block, and both are Microsoft's doing.
 *
 * It shows which mailbox signed in. A delegated connection can send as that one
 * address and no other, so it is the most useful fact on the panel - where a
 * Gmail connection's account is implied by the client it belongs to.
 *
 * And disconnecting says less. Google revokes the grant when asked; Microsoft
 * publishes no endpoint that revokes a single one, so this can only forget the
 * token locally and point at the portal. Saying "revoked" here would be untrue,
 * and untrue in the direction that matters - somebody would believe access had
 * been withdrawn when it had not.
 */
const MicrosoftConnect = ( { oauth, dirty } ) => {
	if ( ! oauth ) {
		return null;
	}

	const {
		connected,
		account,
		has_credentials: hasCredentials,
		connect_url: connectUrl,
		disconnect_url: disconnectUrl,
	} = oauth;

	return (
		<div className="mt-6">
			<Separator />

			<div className="grid gap-5 pt-6">
				<div className="grid gap-3">
					<h4 className="text-sm font-medium m-0">
						{ __( 'Mailbox', 'modern-mailer-oauth' ) }
					</h4>

					{ connected ? (
						<div className="flex flex-wrap items-center gap-3 rounded-lg border border-success/25 bg-success-subtle px-4 py-3">
							<CheckCircle2 className="size-4 text-success shrink-0" />
							{ /* One interpolated string rather than a sentence with the
							     address bolted on the end. German puts the mailbox
							     before the verb, and a translator handed two
							     fragments has nowhere to put it. */ }
							<span className="text-sm flex-1 min-w-[200px]">
								{ account
									? createInterpolateElement(
											sprintf(
												/* translators: %s: the signed-in mailbox address. */
												__(
													'Signed in as <b>%s</b>. This connection can send as that mailbox.',
													'modern-mailer-oauth'
												),
												account
											),
											{ b: <strong className="font-medium" /> }
									  )
									: __(
											'Connected. A refresh token is stored for this connection.',
											'modern-mailer-oauth'
									  ) }
							</span>
							<Button
								asChild
								variant="outline"
								size="sm"
								className="border-danger/30 text-danger hover:bg-danger/10 hover:text-danger"
							>
								<a
									href={ disconnectUrl }
									onClick={ ( e ) => {
										// eslint-disable-next-line no-alert
										if (
											! window.confirm(
												__(
													'This forgets the sign-in and sending stops. Microsoft cannot revoke it remotely - remove the app in your Microsoft account too. Continue?',
													'modern-mailer-oauth'
												)
											)
										) {
											e.preventDefault();
										}
									} }
								>
									{ __( 'Disconnect', 'modern-mailer-oauth' ) }
								</a>
							</Button>
						</div>
					) : (
						<>
							{ ! hasCredentials && (
								<Alert variant="warning">
									<TriangleAlert />
									<AlertDescription>
										{ __(
											'Enter the application ID and client secret above and save them before signing in.',
											'modern-mailer-oauth'
										) }
									</AlertDescription>
								</Alert>
							) }

							{ dirty && hasCredentials && (
								<Alert variant="warning">
									<TriangleAlert />
									<AlertDescription>
										{ __(
											'You have unsaved changes. Signing in leaves this page and they will be lost.',
											'modern-mailer-oauth'
										) }
									</AlertDescription>
								</Alert>
							) }

							{ /* Both sentences come from watching a real setup fail.
							     The instinct is to sign in as the administrator -
							     it is the account that can consent - and an
							     administrator account frequently has no mailbox,
							     so the connection is made and then dies on the
							     first send. The consent tick is what makes the
							     second attempt work without a second prompt. */ }
							<Alert variant="info">
								<Info />
								<AlertDescription>
									<p className="m-0">
										{ __(
											'Sign in as the mailbox that will send, not your administrator account - an admin account with no mailbox fails on the first send.',
											'modern-mailer-oauth'
										) }
									</p>
									<p className="mt-2 mb-0">
										{ __(
											'If offered, tick "Consent on behalf of your organization" so the sending mailbox is not asked separately.',
											'modern-mailer-oauth'
										) }
									</p>
								</AlertDescription>
							</Alert>

							<div>
								<MicrosoftButton
									href={ connectUrl }
									disabled={ ! hasCredentials }
								/>
							</div>

						</>
					) }

				</div>
			</div>
		</div>
	);
};

export default MicrosoftConnect;
