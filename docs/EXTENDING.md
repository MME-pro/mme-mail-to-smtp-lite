# Adding a screen to the admin app

This plugin's admin screens are one React app, mounted on a single WordPress
page. An add-on can add a screen to it: a tab in the row, and a route of its
own.

This is a stable contract. It is here so an add-on does not have to patch this
plugin at runtime, and it will not be changed without a version bump and a note
in the changelog.

## What you get

- a tab between **Connections** and **Settings**, with your label and icon
- a route under the app's hash router, so `#/your-path` is a real link
- the same React instance, so your elements render here without ceremony

## What you do

### 1. Build a script that registers the screen

Import React from `@wordpress/element`, the same as this plugin does. Webpack
leaves it as a reference to `wp.element`, so there is one React on the page.
Importing `react` directly gives you a second copy and hooks will break.

```js
import { __ } from '@wordpress/i18n';
import { ScrollText } from 'lucide-react';
import Logs from './screens/logs';

window.mmoa.registerScreen( {
	id:     'my-addon-logs',
	path:   '/logs',
	label:  __( 'Email Logs', 'my-addon' ),
	icon:   ScrollText,
	render: () => <Logs />,
} );
```

| Field | Required | Notes |
|---|---|---|
| `id` | yes | non-empty string, unique |
| `path` | yes | begins with `/`, not one of `/setup` `/dashboard` `/connections` `/settings` |
| `label` | yes | non-empty string, already translated |
| `icon` | no | a component; a puzzle piece is used if you leave it out |
| `render` | yes | a function returning an element |

An entry that does not satisfy that is dropped with a `console.warn`, and the
rest of the app carries on. A broken add-on must not be able to take these
screens down with it.

### 2. Enqueue it before the app

Register your script with `mmoa-registry` as a dependency, then add your handle
to `mmoa_admin_app_dependencies`. Being a dependency of the app is what makes
your script run first, which is what lets it register before anything renders.

```php
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'toplevel_page_modern-mailer' !== $hook ) {
			return;
		}

		$asset = require MY_ADDON_DIR . 'build/index.asset.php';

		wp_register_script(
			'my-addon-app',
			MY_ADDON_URL . 'build/index.js',
			array_merge( $asset['dependencies'], [ 'mmoa-registry' ] ),
			$asset['version'],
			true
		);
		wp_enqueue_script( 'my-addon-app' );
	}
);

add_filter(
	'mmoa_admin_app_dependencies',
	static function ( array $handles ): array {
		$handles[] = 'my-addon-app';

		return $handles;
	}
);
```

The resulting order is `mmoa-registry`, then your script, then `mmoa-app`.
WordPress guarantees it, because each is a declared dependency of the next.

## Why registration happens before mount, not after

The routes and the tab row are built once, from whatever is in
`window.mmoa.screens` when the app's bundle runs. Registering later does
nothing - there is no re-render to pick it up, and adding one would mean the
tab row and the routes could disagree about what exists.

## What this does not give you

- **REST routes.** Register your own with `register_rest_route`; nothing here
  is in your way.
- **Anything on the Dashboard, Connections or Settings screens.** Those are
  this plugin's, and it keeps them.
- **A say in how your screen looks.** You render it. This plugin gives it a
  place to be and does not style it.
