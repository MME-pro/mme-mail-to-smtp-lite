import { __, sprintf } from '@wordpress/i18n';
import { Lock } from 'lucide-react';
import { Button } from './ui';
import {
	Dialog,
	DialogContent,
	DialogHeader,
	DialogBody,
	DialogFooter,
	DialogTitle,
	DialogDescription,
} from './ui/dialog';
import { proStatus } from '../lib/pro';

/**
 * What to say when a Pro tile is picked, depending on where Pro stands.
 *
 * Four answers, and only one of them is "buy it": somebody who already has Pro
 * installed, or running, needs to be told the next step for their site, not
 * sold the thing they have.
 */
const nextStep = ( status ) => {
	// Pro is not on sale yet, so there is nowhere to send anyone: the dialog
	// says so instead of linking to a page that cannot take an order.
	if ( ! status.installed ) {
		return {
			body: __(
				'This connection comes with MME-Mail to SMTP Pro, which is not available yet.',
				'mme-mail-to-smtp'
			),
			note: __( 'Coming soon', 'mme-mail-to-smtp' ),
		};
	}

	if ( ! status.active ) {
		return {
			body: __( 'MME-Mail to SMTP Pro is installed on this site but switched off. Activate it to use this connection.', 'mme-mail-to-smtp' ),
			action: __( 'Go to Plugins', 'mme-mail-to-smtp' ),
			href: status.activate_url,
		};
	}

	if ( ! status.licensed ) {
		return {
			body: __( 'MME-Mail to SMTP Pro is running, but this site has no licence yet. Enter your licence key and this connection unlocks straight away.', 'mme-mail-to-smtp' ),
			action: __( 'Enter licence key', 'mme-mail-to-smtp' ),
			href: '#/licence',
		};
	}

	// Licensed, and the tile is still here: the Pro on this site predates the
	// connection. Updating is the answer.
	return {
		body: __( 'Your version of MME-Mail to SMTP Pro does not include this connection yet. Update Pro from the Plugins screen to get it.', 'mme-mail-to-smtp' ),
		action: __( 'Go to Plugins', 'mme-mail-to-smtp' ),
		href: status.activate_url,
	};
};

const ProDialog = ( { tile, onClose } ) => {
	const step = nextStep( proStatus() );

	return (
		<Dialog open={ Boolean( tile ) } onOpenChange={ ( open ) => ! open && onClose() }>
			{ tile && (
				<DialogContent className="w-[min(30rem,calc(100vw-2rem))]">
					<DialogHeader>
						<DialogTitle className="flex items-center gap-2">
							<Lock size={ 15 } className="text-brand-deep" />
							{ sprintf(
								/* translators: %s: a connection, e.g. Microsoft 365. */
								__( '%s is a Pro connection', 'mme-mail-to-smtp' ),
								tile.label
							) }
						</DialogTitle>
						<DialogDescription>{ tile.summary }</DialogDescription>
					</DialogHeader>

					<DialogBody>
						<p className="m-0 text-[13px] text-foreground">{ step.body }</p>
					</DialogBody>

					<DialogFooter>
						{ step.note && (
							<span className="mr-auto inline-flex items-center rounded-full bg-brand-subtle px-3 py-1 text-[12px] font-semibold text-brand-deep">
								{ step.note }
							</span>
						) }
						<Button variant="ghost" onClick={ onClose }>
							{ step.href ? __( 'Not now', 'mme-mail-to-smtp' ) : __( 'Close', 'mme-mail-to-smtp' ) }
						</Button>
						{ step.href && (
							<a
								href={ step.href }
								target={ step.external ? '_blank' : undefined }
								rel={ step.external ? 'noreferrer' : undefined }
								onClick={ () => ! step.external && onClose() }
								className="inline-flex h-9 items-center rounded-md bg-foreground px-4 text-[13px] font-medium text-background no-underline hover:opacity-90"
							>
								{ step.action }
							</a>
						) }
					</DialogFooter>
				</DialogContent>
			) }
		</Dialog>
	);
};

export default ProDialog;
