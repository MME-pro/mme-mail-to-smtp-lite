<?php
/**
 * State for the setup wizard.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Admin\App_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the wizard has been through, and where it had got to.
 *
 * The wizard itself is a screen in the admin app - this is only the small
 * amount of state that has to outlive a page load, and there are two reasons it
 * has to.
 *
 * The first is activation. A plugin that takes over wp_mail() does nothing at
 * all until a provider is configured, so the moment of activation is the one
 * moment an admin is certain to be paying attention, and sending them straight
 * into the wizard is worth more than a notice they will scroll past. WordPress
 * gives no hook that fires on the request *after* activation, so the intent is
 * written down here and acted on at the next admin screen.
 *
 * The second is the OAuth round trip. Connecting a mailbox hands the browser to
 * Google and gets it back as a fresh page load with no memory of
 * what was happening, which would otherwise drop somebody out of step three of
 * six. The step is recorded before they leave, and `is_in_progress()` is what
 * tells the callback handlers to return to the wizard rather than to the
 * connections screen.
 *
 * Nothing here is authoritative about whether mail works. That question is
 * answered by Settings::is_active() and the health monitor, as it always was -
 * finishing the wizard is a statement about a person, not about a connection.
 */
class Setup {

	/** Where the wizard's own state lives. */
	public const OPTION = 'mmoa_setup';

	/** Set at activation, read and deleted on the next admin request. */
	public const REDIRECT_OPTION = 'mmoa_setup_redirect';

	/** The step a fresh wizard opens on, and the route it lives at. */
	public const FIRST_STEP = 'welcome';
	public const ROUTE      = 'setup';

	private const CAPABILITY = 'manage_options';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_redirect' ], 1 );
		add_action( 'admin_notices', [ $this, 'unfinished_notice' ] );
	}

	/**
	 * Ask for the wizard on the next admin screen.
	 *
	 * Called from the activation hook, which runs during the activation request
	 * itself - too early to redirect from, because WordPress is still in the
	 * middle of telling the plugins screen what happened.
	 *
	 * A site that has already been through this does not get sent back: a
	 * reactivation after an update is not a fresh install, and interrupting it
	 * with a wizard somebody has already finished is the kind of thing that
	 * teaches people to dismiss things without reading them.
	 */
	public static function on_activate(): void {
		$state = self::state_option();

		if ( ! empty( $state['completed'] ) || ! empty( $state['skipped'] ) ) {
			return;
		}

		update_option( self::REDIRECT_OPTION, time(), false );
	}

	/**
	 * Send an admin into the wizard once, just after activation.
	 *
	 * The flag is deleted before anything else can go wrong, so a redirect that
	 * is refused - by a capability check, by another plugin, by an exit in a
	 * hook - costs one lost redirect rather than trapping the admin in a loop.
	 *
	 * Bulk activation is deliberately exempt. Somebody activating fifteen
	 * plugins at once is not asking to be taken into any one of them, and
	 * WordPress says so through `activate-multi`.
	 */
	public function maybe_redirect(): void {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return;
		}

		delete_option( self::REDIRECT_OPTION );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen we are on, not acting on input.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || is_network_admin() ) {
			return;
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * A quiet way back in for anyone the redirect missed.
	 *
	 * Shown only while there is genuinely nothing sending: a site with a
	 * working connection has no use for an unfinished wizard, whatever the
	 * option says. It is not shown on the app's own screen either, where the
	 * wizard is one click away and a banner above it would just be noise.
	 */
	public function unfinished_notice(): void {
		$screen = get_current_screen();

		if ( ! current_user_can( self::CAPABILITY ) || $this->settings->is_active() ) {
			return;
		}

		if ( $screen && 'toplevel_page_' . App_Page::SLUG === $screen->id ) {
			return;
		}

		$state = self::state_option();

		if ( ! empty( $state['completed'] ) || ! empty( $state['skipped'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'MME-Mail to SMTP is installed but not sending yet.', 'mme-mail-to-smtp' ),
			esc_html__( 'WordPress is still using the server mail function. The setup wizard connects a mailbox in a few steps.', 'mme-mail-to-smtp' ),
			esc_url( self::url() ),
			esc_html__( 'Run the setup wizard', 'mme-mail-to-smtp' )
		);
	}

	/**
	 * The wizard's own address, hash route included.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . App_Page::SLUG ) . '#/' . self::ROUTE;
	}

	/**
	 * Is somebody partway through the wizard right now?
	 *
	 * This is what redirects an OAuth callback back into the wizard instead of
	 * onto the connections screen. It goes false the moment the wizard is
	 * finished or abandoned, so an ordinary sign-in from the connections screen
	 * later on returns where it came from.
	 */
	public function is_in_progress(): bool {
		$state = self::state_option();

		return ! empty( $state['started'] ) && empty( $state['completed'] ) && empty( $state['skipped'] );
	}

	/**
	 * Everything the admin app needs to decide what to show.
	 *
	 * `recommended` is the question the dashboard actually asks - not "has this
	 * been run" but "should this be offered" - and it is deliberately answered
	 * here rather than reconstructed from three fields in the browser.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		$state  = self::state_option();
		$active = $this->settings->is_active();

		return [
			'completed'   => (int) ( $state['completed'] ?? 0 ),
			'skipped'     => (int) ( $state['skipped'] ?? 0 ),
			'started'     => (int) ( $state['started'] ?? 0 ),
			'step'        => (string) ( $state['step'] ?? self::FIRST_STEP ),
			'in_progress' => $this->is_in_progress(),
			'configured'  => $active,
			'recommended' => ! $active && empty( $state['completed'] ),
		];
	}

	/**
	 * Record which step the wizard is on, and that it is running.
	 *
	 * Written on each step rather than only before an OAuth hand-off, because
	 * leaving the page is not always a decision: a browser is closed, a session
	 * expires, somebody opens the connections screen to check something. Coming
	 * back to the step you were on costs one option write.
	 */
	public function start( string $step ): array {
		$state = self::state_option();

		$state['started'] = $state['started'] ?? 0;

		if ( empty( $state['started'] ) ) {
			$state['started'] = time();
		}

		$state['step'] = sanitize_key( $step ) ?: self::FIRST_STEP;

		// Re-entering clears both endings. Somebody who ran the wizard last
		// month and has come back to change providers is in progress again,
		// and the OAuth callbacks need to know that.
		unset( $state['completed'], $state['skipped'] );

		return $this->save( $state );
	}

	/**
	 * The wizard was seen through to the end.
	 */
	public function complete(): array {
		$state = self::state_option();

		$state['completed'] = time();
		$state['step']      = 'done';

		unset( $state['skipped'] );

		return $this->save( $state );
	}

	/**
	 * The wizard was closed without finishing.
	 *
	 * Distinct from completing it. Nothing behaves differently, but the two
	 * facts are not the same one and flattening them would lose the only
	 * signal that somebody tried and stopped.
	 */
	public function skip(): array {
		$state = self::state_option();

		$state['skipped'] = time();

		unset( $state['completed'] );

		return $this->save( $state );
	}

	/**
	 * @param array<string,mixed> $state State to persist.
	 * @return array<string,mixed>
	 */
	private function save( array $state ): array {
		// Autoloaded: is_in_progress() is consulted on admin requests that have
		// no other reason to touch this option, and a second query for a row
		// this small is the more expensive half of the trade.
		update_option( self::OPTION, $state, true );

		return $this->state();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function state_option(): array {
		$state = get_option( self::OPTION, [] );

		return is_array( $state ) ? $state : [];
	}
}
