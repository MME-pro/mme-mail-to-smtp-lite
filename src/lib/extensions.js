/**
 * Screens contributed by an add-on.
 *
 * An add-on's bundle runs before this one - it is a dependency of the app
 * script, which is what makes the ordering certain - and calls:
 *
 *     window.mmoa.registerScreen( {
 *         id:     'logs',
 *         path:   '/logs',
 *         label:  __( 'Email Logs', 'my-addon' ),
 *         icon:   ScrollText,          // optional, a component
 *         render: () => <Logs />,      // returns an element
 *     } );
 *
 * Both bundles import React from `@wordpress/element`, which webpack leaves as
 * a reference to `wp.element`, so there is one React on the page and an
 * element built in the add-on renders here without ceremony.
 *
 * Everything below is defensive on purpose. A broken add-on must not be able
 * to take this plugin's admin screens down with it: a malformed entry is
 * dropped and the rest of the app carries on. The console warning is for
 * whoever is writing the add-on, since nothing else would tell them.
 */

const RESERVED = [ '/setup', '/dashboard', '/connections', '/settings' ];

const warn = ( message, screen ) => {
	// eslint-disable-next-line no-console
	console.warn( `[mme-mail-to-smtp] ignoring a registered screen: ${ message }`, screen );
};

const isValid = ( screen, seen ) => {
	if ( ! screen || typeof screen !== 'object' ) {
		warn( 'not an object', screen );
		return false;
	}

	const { id, path, label, render } = screen;

	if ( typeof id !== 'string' || ! id ) {
		warn( 'needs a non-empty string id', screen );
		return false;
	}

	if ( typeof path !== 'string' || ! path.startsWith( '/' ) ) {
		warn( `path must be a string beginning with "/" (${ id })`, screen );
		return false;
	}

	if ( RESERVED.includes( path ) ) {
		warn( `path "${ path }" belongs to this plugin (${ id })`, screen );
		return false;
	}

	if ( seen.has( path ) ) {
		warn( `path "${ path }" is already taken (${ id })`, screen );
		return false;
	}

	if ( typeof label !== 'string' || ! label ) {
		warn( `needs a non-empty label (${ id })`, screen );
		return false;
	}

	if ( typeof render !== 'function' ) {
		warn( `render must be a function returning an element (${ id })`, screen );
		return false;
	}

	return true;
};

/**
 * Every valid screen an add-on registered, in registration order.
 *
 * Read once at module scope rather than in a hook: registration happens before
 * this bundle runs, so the list cannot change afterwards, and reading it once
 * keeps the routes and the tab row from disagreeing about what exists.
 */
const registered = ( () => {
	const raw = typeof window !== 'undefined' && Array.isArray( window.mmoa?.screens )
		? window.mmoa.screens
		: [];

	const seen = new Set();

	return raw.filter( ( screen ) => {
		if ( ! isValid( screen, seen ) ) {
			return false;
		}

		seen.add( screen.path );

		return true;
	} );
} )();

export default registered;
