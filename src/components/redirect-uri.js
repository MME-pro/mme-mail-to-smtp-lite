import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Copy, Check, TriangleAlert } from 'lucide-react';
import { Button, Alert, AlertDescription } from './ui';

/**
 * The address the provider must be told to return to.
 *
 * Above the credential fields, not below them, because that is the order the
 * work actually happens in: this value goes into the app registration, and the
 * client ID and secret only exist once that registration has been made. Sitting
 * under the form it was something to scroll back up past after the fact.
 *
 * Shared by the panels that need it, which each had their own copy of
 * this block and their own copy of the copy button.
 */
const RedirectUri = ( { value, warning } ) => {
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
		<div className="grid gap-2">
			<h4 className="text-sm font-medium m-0">
				{ __( 'Redirect URI', 'modern-mailer-oauth' ) }
			</h4>

			{ warning && (
				<Alert variant="warning">
					<TriangleAlert />
					<AlertDescription>{ warning }</AlertDescription>
				</Alert>
			) }

			<div className="flex items-stretch gap-2">
				<code className="flex-1 min-w-0 rounded-md border bg-muted/60 px-3 py-2 font-mono text-xs leading-relaxed break-all">
					{ value }
				</code>
				<Button
					variant="outline"
					size="icon"
					onClick={ copy }
					aria-label={ __( 'Copy redirect URI', 'modern-mailer-oauth' ) }
				>
					{ copied ? <Check className="text-success" /> : <Copy /> }
				</Button>
			</div>
		</div>
	);
};

export default RedirectUri;
