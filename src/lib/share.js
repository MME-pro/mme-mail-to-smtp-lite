import * as ui from '../components/ui';
import { useToast } from '../components/toast';
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
 * What is shared is presentation and nothing else: panels, buttons, badges,
 * form fields, the toast hook and the class-name helper. No settings, no
 * credentials, no sending. An add-on that wants data asks the REST API for it,
 * with routes of its own.
 */
export const share = () => {
	if ( typeof window === 'undefined' ) {
		return;
	}

	window.mmoa = window.mmoa || {};

	window.mmoa.ui = {
		...ui,
		useToast,
		cn,
	};
};

export default share;
