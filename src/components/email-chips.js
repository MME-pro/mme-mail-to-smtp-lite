import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { X } from 'lucide-react';
import { inputClass } from './ui';
import { cn } from '../lib/utils';

/**
 * A list of email addresses, entered one at a time.
 *
 * Type an address, press Enter, it becomes a chip underneath. A plain
 * comma-separated text field was the obvious alternative and is worse to use
 * for the thing this is actually for: adding a colleague to a list that
 * already has three people on it, without having to find the right comma or
 * risk mangling the others.
 *
 * The value crossing the boundary is still one comma-separated string, which
 * is how it is stored and what MMOA_REPORT_EMAIL holds, so nothing below this
 * component knows the chips exist.
 *
 * Committed on Enter, on comma, and on blur. Blur matters more than it looks:
 * typing an address and pressing Save without leaving the field is the obvious
 * thing to do, and losing it there would be the worst bug this could have.
 */
const EmailChips = ( { id, value, onChange, disabled = false, placeholder } ) => {
	const [ draft, setDraft ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const list = String( value || '' )
		.split( ',' )
		.map( ( item ) => item.trim() )
		.filter( Boolean );

	// Deliberately loose. The server sanitises with is_email() and is the
	// authority; this only exists to catch a typo while the person can still
	// see what they typed, so it must not reject an address the server would
	// have accepted.
	const looksLikeEmail = ( candidate ) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( candidate );

	const commit = ( raw ) => {
		const candidates = String( raw )
			.split( /[,;\s]+/ )
			.map( ( item ) => item.trim() )
			.filter( Boolean );

		if ( ! candidates.length ) {
			setDraft( '' );
			setError( '' );
			return true;
		}

		const added = [];
		const rejected = [];

		candidates.forEach( ( candidate ) => {
			if ( ! looksLikeEmail( candidate ) ) {
				rejected.push( candidate );
				return;
			}

			// Case-insensitive, because the same person added twice means the
			// report arrives twice.
			const seen = [ ...list, ...added ].some(
				( existing ) => existing.toLowerCase() === candidate.toLowerCase()
			);

			if ( ! seen ) {
				added.push( candidate );
			}
		} );

		if ( added.length ) {
			onChange( [ ...list, ...added ].join( ', ' ) );
		}

		// Whatever was not an address stays in the field so it can be fixed,
		// rather than vanishing and taking the typo with it.
		setDraft( rejected.join( ' ' ) );
		setError(
			rejected.length
				? sprintf(
						/* translators: %s: what the person typed that was not an email address. */
						__( 'Not an email address: %s', 'modern-mailer-oauth' ),
						rejected.join( ', ' )
				  )
				: ''
		);

		return ! rejected.length;
	};

	const remove = ( target ) => {
		onChange( list.filter( ( item ) => item !== target ).join( ', ' ) );
	};

	const onKeyDown = ( e ) => {
		if ( 'Enter' === e.key || ',' === e.key ) {
			// Enter would otherwise submit the settings form, saving a value
			// that does not include the address just typed.
			e.preventDefault();
			commit( draft );
			return;
		}

		// Backspace on an empty field takes back the last chip, which is what
		// every other chip input does.
		if ( 'Backspace' === e.key && '' === draft && list.length ) {
			e.preventDefault();
			remove( list[ list.length - 1 ] );
		}
	};

	return (
		<div className="grid gap-2">
			<input
				id={ id }
				type="text"
				inputMode="email"
				autoComplete="off"
				disabled={ disabled }
				className={ cn( inputClass, error && 'border-danger' ) }
				placeholder={ placeholder }
				value={ draft }
				aria-invalid={ error ? 'true' : undefined }
				aria-describedby={ error ? `${ id }-error` : undefined }
				onChange={ ( e ) => {
					setDraft( e.target.value );
					if ( error ) {
						setError( '' );
					}
				} }
				onKeyDown={ onKeyDown }
				onBlur={ () => commit( draft ) }
			/>

			{ error ? (
				<p id={ `${ id }-error` } className="m-0 text-xs text-danger" role="alert">
					{ error }
				</p>
			) : null }

			{ list.length ? (
				<ul className="flex flex-wrap gap-1.5 m-0 p-0 list-none">
					{ list.map( ( email ) => (
						<li key={ email }>
							<span className="inline-flex items-center gap-1 rounded-full border bg-secondary text-secondary-foreground pl-2.5 pr-1 py-0.5 text-xs">
								<span className="break-all">{ email }</span>
								{ ! disabled && (
									<button
										type="button"
										className="inline-flex items-center justify-center rounded-full p-0.5 hover:bg-background/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
										onClick={ () => remove( email ) }
										aria-label={ sprintf(
											/* translators: %s: an email address. */
											__( 'Remove %s', 'modern-mailer-oauth' ),
											email
										) }
									>
										<X className="size-3" aria-hidden="true" />
									</button>
								) }
							</span>
						</li>
					) ) }
				</ul>
			) : null }
		</div>
	);
};

export default EmailChips;
