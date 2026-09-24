<?php
/**
 * Mount point for the admin app.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Admin;

use ModernMailer\Api\Rest_Controller;
use ModernMailer\Auth\Google_Consent;
use ModernMailer\Plugin;

use const ModernMailer\PLUGIN_DIR;
use const ModernMailer\PLUGIN_URL;
use const ModernMailer\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu and hands the rest to the React app.
 *
 * The whole interface is one page. The WordPress submenu entries exist because
 * people look for them there, but each is a link into the same app at a
 * different hash route rather than a separate screen - so navigating between
 * them costs nothing and the app never remounts.
 */
class App_Page {

	public const SLUG = 'modern-mailer';

	private const CAPABILITY = 'manage_options';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * One menu entry, no submenus.
	 *
	 * The app already carries its own tabs, and duplicating them in the
	 * WordPress sidebar meant two navigations for one set of destinations -
	 * which then had to be kept in step with each other, and which disagreed
	 * about where you were the moment you moved between tabs inside the app
	 * without the sidebar noticing.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'MME-Mail to SMTP', 'mme-mail-to-smtp' ),
			__( 'MME-Mail to SMTP', 'mme-mail-to-smtp' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-email-alt',
			// Just below Settings, so it sits with configuration rather than
			// among the content menus.
			80
		);
	}

	/*
	 * The "another plugin has taken over wp_mail()" notice used to live here.
	 * It has moved to ModernMailer\Conflicts, which asks the same question and
	 * two more - which known mailers are active, and which are installed but
	 * switched off - and answers all three in one notice. Two classes printing
	 * near-identical warnings about the same plugin is how a screen ends up
	 * saying the same thing twice.
	 */

	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$asset_file = PLUGIN_DIR . 'build/index.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'mmoa-app',
			PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// No webfont. The stylesheet asks for Inter first and falls back to the
		// system UI face, so a machine that has Inter installed gets it and
		// every other machine gets what the rest of wp-admin is set in. Loading
		// it from fonts.googleapis.com instead would make every admin page view
		// a request to Google carrying the viewer's IP, for a typeface - which
		// is not a thing to do to somebody without asking, and is not something
		// a mail plugin should have to disclose as a third-party service.
		wp_enqueue_style(
			'mmoa-app',
			PLUGIN_URL . 'build/index.css',
			[],
			$asset['version']
		);

		wp_set_script_translations( 'mmoa-app', 'mme-mail-to-smtp' );

		wp_localize_script(
			'mmoa-app',
			'mmoa',
			[
				'version'          => VERSION,
				'restNamespace'    => Rest_Controller::NAMESPACE,
				'currentUserEmail' => wp_get_current_user()->user_email,
				'redirectUri'      => Google_Consent::redirect_uri(),

				// Built here rather than in the browser. The app is served from
				// admin.php, so a relative link would happen to resolve, and
				// would stop resolving the moment the page moved.
				'privacy'          => [
					'export' => admin_url( 'export-personal-data.php' ),
					'erase'  => admin_url( 'erase-personal-data.php' ),
					'policy' => admin_url( 'options-privacy.php' ),
				],

				// Built here rather than in the browser. The app is served from
				// admin.php, so a relative link would happen to resolve, and
				// would stop resolving the moment the page moved.
				'privacy'          => [
					'export' => admin_url( 'export-personal-data.php' ),
					'erase'  => admin_url( 'erase-personal-data.php' ),
					'policy' => admin_url( 'options-privacy.php' ),
				],
			]
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// The app replaces the page entirely. The noscript fallback is not
		// decoration: an admin whose bundle failed to load should be told that,
		// not left looking at a blank screen.
		//
		// The screen-reader heading and the wp-header-end rule are load-bearing.
		// WordPress relocates every admin notice - its own and any other
		// plugin's - to just after the first h1 inside .wrap, or to .wp-header-end
		// if one exists. Without them, notices were being injected into the
		// middle of the app's own header band, landing on top of the wordmark.
		// This gives them somewhere to go above the app instead.
		?>
		<div class="wrap" style="margin:0;padding:0">
			<h1 class="screen-reader-text"><?php esc_html_e( 'MME-Mail to SMTP', 'mme-mail-to-smtp' ); ?></h1>
			<hr class="wp-header-end" style="display:none" />

			<div id="mmoa-app-root">
				<noscript>
					<p style="padding:2rem">
						<?php esc_html_e( 'MME-Mail to SMTP needs JavaScript to show its settings.', 'mme-mail-to-smtp' ); ?>
					</p>
				</noscript>
			</div>
		</div>
		<?php
	}
}
