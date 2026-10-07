import { useEffect, useState, useCallback } from '@wordpress/element';

const KEY = 'mmoa-theme';

/**
 * Light or dark, remembered per browser.
 *
 * The class goes on #mmoa-app rather than <html>: this app is a guest inside
 * wp-admin, and darkening the whole admin because a plugin screen is dark would
 * be presumptuous - and would leave WordPress's own chrome in a state it never
 * designed for.
 *
 * Dark by default: it is how the product looks everywhere else - on the
 * website, in the account dashboard, in every screenshot - so a fresh install
 * opens looking like what was bought. Light is remembered once chosen.
 */
export const useTheme = () => {
	const [ theme, setTheme ] = useState( () => {
		try {
			return window.localStorage.getItem( KEY ) === 'light' ? 'light' : 'dark';
		} catch {
			// Private windows and locked-down browsers throw on access rather
			// than returning null.
			return 'dark';
		}
	} );

	useEffect( () => {
		const root = document.getElementById( 'mmoa-app' );

		if ( root ) {
			root.classList.toggle( 'dark', theme === 'dark' );
		}

		try {
			window.localStorage.setItem( KEY, theme );
		} catch {
			// Not being able to remember the choice is not a reason to refuse
			// to make it.
		}
	}, [ theme ] );

	const toggle = useCallback(
		() => setTheme( ( current ) => ( current === 'dark' ? 'light' : 'dark' ) ),
		[]
	);

	return { theme, toggle };
};
