import { __, sprintf } from '@wordpress/i18n';
import { CheckCircle2, TriangleAlert } from 'lucide-react';
import { Button, Separator, Alert, AlertDescription } from './ui';
import { GoogleButton } from './google-button';

/**
 * Connect a mailbox without anyone opening a cloud console.
 *
 * Like the own-client sign-in, this is a plain link rather than a fetch():
 * beginning an OAuth handshake means handing the browser to another origin and
 * getting it back as a fresh page load, which XHR cannot do. The link is a
 * nonce-signed admin-post URL that the server turns into a redirect.
 *
 * The setup service performs the OAuth exchange and hands back a real Google
 * credential. It never relays mail: every message afterwards goes straight
 * from this site to Gmail.
 */
const FAMILY = {
	google: {
		Button: GoogleButton,
		name: __( 'Google', 'mme-mail-to-smtp' ),
	},
};

const OneClickConnect = ( { family, oneClick, dirty, heading } ) => {
	const meta = FAMILY[ family ];

	if ( ! meta || ! oneClick ) {
		return null;
	}

	// A site that has filtered the broker away gets told why the button is not
	// here, rather than being left to wonder where it went.
	if ( ! oneClick.available ) {
		return (
			<div className="mt-6">
				<Separator />
				<Alert variant="info" className="mt-6">
					<TriangleAlert />
					<AlertDescription>
						{ __(
							'One-click setup is switched off on this site. Connect using your own OAuth client instead.',
							'mme-mail-to-smtp'
						) }
					</AlertDescription>
				</Alert>
			</div>
		);
	}

	const state = oneClick.families?.[ family ];

	if ( ! state ) {
		return null;
	}

	const { Button: SignInButton, name } = meta;

	return (
		<div className="mt-6">
			<Separator />

			<div className="grid gap-3 pt-6">
				<h4 className="text-sm font-medium m-0">
					{ heading || __( 'Account', 'mme-mail-to-smtp' ) }
				</h4>

				{ state.connected ? (
					<div className="flex flex-wrap items-center gap-3 rounded-lg border border-success/25 bg-success-subtle px-4 py-3">
						<CheckCircle2 className="size-4 text-success shrink-0" />
						<span className="text-sm flex-1 min-w-[200px]">
							{ state.account
								? sprintf(
										/* translators: %s: connected email address. */
										__( 'Connected as %s.', 'mme-mail-to-smtp' ),
										state.account
								  )
								: __( 'Connected.', 'mme-mail-to-smtp' ) }
						</span>
						<Button
							asChild
							variant="outline"
							size="sm"
							className="border-danger/30 text-danger hover:bg-danger/10 hover:text-danger"
						>
							<a
								href={ state.disconnect_url }
								onClick={ ( e ) => {
									// eslint-disable-next-line no-alert
									if (
										! window.confirm(
											__(
												'This revokes the grant and forgets it here. Sending stops. Continue?',
												'mme-mail-to-smtp'
											)
										)
									) {
										e.preventDefault();
									}
								} }
							>
								{ __( 'Disconnect', 'mme-mail-to-smtp' ) }
							</a>
						</Button>
					</div>
				) : (
					<>
						{ dirty && (
							<Alert variant="warning">
								<TriangleAlert />
								<AlertDescription>
									{ __(
										'You have unsaved changes. Signing in leaves this page and they will be lost.',
										'mme-mail-to-smtp'
									) }
								</AlertDescription>
							</Alert>
						) }

						<div>
							<SignInButton href={ state.connect_url } />
						</div>


						<p className="text-xs text-muted-foreground m-0 max-w-prose">
							{ sprintf(
								/* translators: %s: provider name, e.g. Google. */
								__(
									'Nothing to register with %s.',
									'mme-mail-to-smtp'
								),
								name
							) }
						</p>
					</>
				) }
			</div>
		</div>
	);
};

export default OneClickConnect;
