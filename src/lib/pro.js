import { __ } from '@wordpress/i18n';

/**
 * The connections only MME-Mail to SMTP Pro provides, by name.
 *
 * This plugin contains none of them: no fields, no sign-in, nothing waiting to
 * be switched on. What it has is this list, so that someone who came looking
 * for Microsoft 365 finds a tile that says where it is, instead of concluding
 * it does not exist. With Pro running and licensed, Pro registers the real
 * providers and each entry here simply stops being shown.
 *
 * An entry with a `mode` is a way of connecting to a service this plugin does
 * have - Google one-click is a mode of the Google tile - and is shown until
 * that mode is on offer.
 */
const TILES = () => [
	{
		slug: 'microsoft',
		label: __( 'Microsoft 365', 'mme-mail-to-smtp' ),
		summary: __( 'Microsoft 365, Outlook.com and Exchange Online.', 'mme-mail-to-smtp' ),
	},
	{
		slug: 'google_one_click',
		provider: 'google',
		mode: 'one_click',
		label: __( 'Google one-click', 'mme-mail-to-smtp' ),
		summary: __( 'Sign in with Google, with no Cloud project of your own to set up.', 'mme-mail-to-smtp' ),
	},
	{
		slug: 'zoho',
		label: __( 'Zoho Mail', 'mme-mail-to-smtp' ),
		summary: __( 'Zoho Mail and Zoho Workplace.', 'mme-mail-to-smtp' ),
	},
];

/** Does this provider's mode selector offer that mode? */
export const offersMode = ( providers, slug, mode ) => {
	const provider = ( providers || [] ).find( ( p ) => p.slug === slug );
	const field = provider?.fields?.find( ( f ) => f.key === provider.mode_key );

	return Boolean( field?.options && Object.prototype.hasOwnProperty.call( field.options, mode ) );
};

/**
 * The Pro tiles to show locked, given what the registry actually offers.
 */
export const lockedTiles = ( providers ) =>
	TILES().filter( ( tile ) =>
		tile.mode
			? ! offersMode( providers, tile.provider, tile.mode )
			: ! ( providers || [] ).some( ( p ) => p.slug === tile.slug )
	);

/**
 * Where Pro stands on this site.
 *
 * The server says whether Pro is installed; Pro itself says whether it is
 * running and licensed. A Pro from before it could say so still prints its own
 * flag into the page, so that is honoured too - telling somebody who holds a
 * licence to go and enter one would be the worst answer available.
 */
export const proStatus = () => {
	const status = { installed: false, active: false, licensed: false, ...( window.mmoa?.pro || {} ) };

	if ( window.mmeMailPro ) {
		status.installed = true;
		status.active = true;
		status.licensed = status.licensed || true === window.mmeMailPro.licensed;
	}

	return status;
};
