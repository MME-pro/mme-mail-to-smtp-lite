import { useEffect, useState, useCallback } from '@wordpress/element';

/*
 * A new key, on purpose. Versions before 1.0 wrote the theme on every load,
 * so nearly every browser that ever opened this screen holds 'light' under
 * the old key without anyone having chosen it - reading it would keep those
 * sites light for good.
 */
const KEY = 'mmoa-theme-choice';

/**
 * Light or dark, remembered per browser - but only once somebody chooses.
 *
 * The class goes on #mmoa-app rather than <html>: this app is a guest inside
 * wp-admin, and darkening the whole admin because a plugin screen is dark would
 * be presumptuous - and would leave WordPress's own chrome in a state it never
 * designed for. The container is rendered dark already (admin/class-app-page.php),
 * so the first paint is not a flash of light.
 *
 * Dark by default: it is how the product looks everywhere else - on the
 * website, in the account dashboard, in every screenshot - so a fresh install
 * opens looking like what was bought.
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
	}, [ theme ] );

	const toggle = useCallback( () => {
		setTheme( ( current ) => {
			const next = current === 'dark' ? 'light' : 'dark';

			try {
				window.localStorage.setItem( KEY, next );
			} catch {
				// Not being able to remember the choice is not a reason to
				// refuse to make it.
			}

			return next;
		} );
	}, [] );

	return { theme, toggle };
};
