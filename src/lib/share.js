import * as ui from '../components/ui';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { useToast } from '../components/toast';
import { GoogleButton } from '../components/google-button';
import RedirectUri from '../components/redirect-uri';
import { cn } from './utils';

/**
 * Hand the admin app's building blocks to whatever is extending it.
 *
 * A screen contributed by an add-on sits between this plugin's own screens.
 * Without this it would be built out of whatever that add-on happened to have,
 * and the page would read as two pieces of software stapled together - a panel
 * with different corners, a button with a different weight, a spinner that
 * spins at a different speed. Sharing the kit is what keeps one screen from
 * looking like a guest on the page.
 *
 * It also stops the obvious alternative, which is every add-on copying these
 * components into its own bundle. That works for exactly as long as nothing
 * here changes.
 *
 * **Read it at render time, not at import time.** An add-on's script is a
 * dependency of this one, so it runs *first* - by design, because it has to
 * register its screens before anything is rendered. At that moment this file
 * has not run and `window.mmoa.ui` does not exist yet. By the time a screen is
 * actually rendered it does. So this is right:
 *
 *     const Logs = () => {
 *         const { Panel, Button } = window.mmoa.ui;
 *         return <Panel>…</Panel>;
 *     };
 *
 * and this is not:
 *
 *     const { Panel } = window.mmoa.ui;   // undefined - too early
 *
 * **The data hooks are here for a reason that is easy to miss.** This app runs
 * inside a QueryClientProvider. React context is per-copy-of-the-library, so an
 * add-on that bundles its own `@tanstack/react-query` gets a second copy with
 * no provider above it, and `useQuery` throws "No QueryClient set" the moment
 * its screen renders. Sharing these hooks means an add-on uses *this* copy, and
 * lands inside the provider it is already rendering within. It also means one
 * cache rather than two.
 *
 * What is shared is presentation and the means to fetch: panels, buttons,
 * badges, form fields, the toast hook, the class-name helper and the query
 * hooks. No settings, no credentials, no sending. An add-on that wants data
 * asks the REST API for it, through routes of its own.
 */
export const share = () => {
	if ( typeof window === 'undefined' ) {
		return;
	}

	window.mmoa = window.mmoa || {};

	window.mmoa.ui = {
		...ui,
		// For connectors: a sign-in and the address it returns to look the
		// same whichever plugin draws them.
		GoogleButton,
		RedirectUri,
		useToast,
		cn,
		useQuery,
		useMutation,
		useQueryClient,
		keepPreviousData,
	};
};

export default share;
