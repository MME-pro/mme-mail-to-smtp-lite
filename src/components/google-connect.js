import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { CheckCircle2, TriangleAlert } from 'lucide-react';
import { Button, Separator, Alert, AlertDescription } from './ui';
import { GoogleButton } from './google-button';

/**
 * Google sign-in for the consumer Gmail connection.
 *
 * This is the only control in the app that is a plain link rather than a
 * fetch(). Starting an OAuth handshake means handing the browser to Google and
 * getting it back as a fresh page load, which XHR cannot do - so the link is a
 * nonce-signed admin-post URL the server turns into a redirect.
 *
 * The OAuth client is the site's own, so the consent prompt is strictly between
 * the admin and Google. Nothing is proxied through a shared application and no
 * third party ever sees the resulting tokens - which is worth stating on the
 * screen, because "sign in with Google" in a plugin usually means the opposite.
 */
const GoogleConnect = ( { oauth, dirty } ) => {
	if ( ! oauth ) {
		return null;
	}

	const {
		connected,
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
						{ __( 'Account', 'modern-mailer-oauth' ) }
					</h4>

					{ connected ? (
						<div className="flex flex-wrap items-center gap-3 rounded-lg border border-success/25 bg-success-subtle px-4 py-3">
							<CheckCircle2 className="size-4 text-success shrink-0" />
							<span className="text-sm flex-1 min-w-[200px]">
								{ __(
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
													'This revokes the grant at Google and forgets it here. Sending stops. Continue?',
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
											'Enter the client ID and secret above and save them before signing in.',
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

							<div>
								<GoogleButton
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

export default GoogleConnect;
