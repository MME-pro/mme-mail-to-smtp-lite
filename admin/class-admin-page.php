<?php
/**
 * The Google sign-in round trip, and the failure notice.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Admin;

use ModernMailer\Auth\Google_Consent;
use ModernMailer\Plugin;
use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What cannot live inside the admin app.
 *
 * The menu and every screen belong to App_Page. Two things are left over.
 *
 * The first is the Google sign-in handshake: it navigates the browser away to
 * Google and comes back as a top-level GET, which a fetch() cannot do, so it
 * needs real admin-post endpoints and a server-side redirect rather than a REST
 * route. The second is the admin notice raised while sending is broken, which
 * has to appear on whatever screen the admin happens to be on.
 */
class Admin_Page {

	private const CAPABILITY = 'manage_options';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_post_mmoa_connect_google', [ $this, 'handle_connect_google' ] );
		add_action( 'admin_post_mmoa_disconnect_google', [ $this, 'handle_disconnect_google' ] );

		// Google's redirect lands on admin-post.php rather than on one of our
		// screens. The redirect URI has to be registered by hand in the Google
		// Cloud console and matched character for character, so it must not
		// depend on where the menu happens to live - moving a page would
		// otherwise silently break every existing connection.
		add_action( 'admin_post_' . Google_Consent::CALLBACK_ACTION, [ $this, 'handle_google_callback' ] );

		add_action( 'admin_notices', [ $this, 'failure_notice' ] );
	}

	/**
	 * URL of the plugin's screen.
	 */
	public static function url( string $slug = App_Page::SLUG ): string {
		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Persistent banner while sending is broken.
	 */
	public function failure_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->plugin->health->is_failing() ) {
			return;
		}

		$state = $this->plugin->health->state();

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Email is not being delivered.', 'mme-mail-to-smtp' ),
			esc_html( (string) ( $state['last_error']['message'] ?? '' ) ),
			esc_url( self::url() ),
			esc_html__( 'Review mail settings', 'mme-mail-to-smtp' )
		);
	}

	/**
	 * Send the admin to Google's sign-in prompt.
	 */
	public function handle_connect_google(): void {
		$this->guard( 'mmoa_connect_google' );

		$slot = $this->posted_slot();
		$url  = $this->plugin->consent->authorization_url( $slot );

		if ( is_wp_error( $url ) ) {
			$this->redirect_to_app( 'error', $url->get_error_message() );
		}

		// Not wp_safe_redirect(): the destination is accounts.google.com, which
		// is deliberately off-host, so the local-host allowlist would refuse it.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public function handle_disconnect_google(): void {
		$this->guard( 'mmoa_disconnect_google' );

		$slot   = $this->posted_slot();
		$result = $this->plugin->consent->disconnect( $slot );

		// Flush tokens either way: the cached access token was minted from a
		// grant that no longer exists.
		$this->plugin->tokens->flush();

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_app( 'error', $result->get_error_message() );
		}

		$this->redirect_to_app( 'saved', __( 'Google account disconnected.', 'mme-mail-to-smtp' ) );
	}

	/**
	 * Complete the flow when Google redirects back.
	 */
	public function handle_google_callback(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'mme-mail-to-smtp' ) );
		}

		// No nonce here by necessity - this request comes from Google, not from
		// a form of ours. The `state` parameter is the CSRF defence, and
		// handle_callback() checks it against a transient we wrote before
		// leaving, then bins it so a replay cannot reuse it.
		$result = $this->plugin->consent->handle_callback( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->plugin->tokens->flush();
		$this->plugin->health->reset();

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_app( 'error', $result->get_error_message() );
		}

		$this->redirect_to_app(
			'saved',
			sprintf(
				/* translators: %s: connection name, e.g. Primary. */
				__( 'Google account connected to %s. Send a test email to confirm delivery.', 'mme-mail-to-smtp' ),
				$this->plugin->connections->name_for( $result )
			)
		);
	}

	/**
	 * $_REQUEST rather than $_POST: the admin app starts these flows from a
	 * nonce-signed link, because beginning an OAuth handshake means navigating
	 * the browser away to Google - which a fetch() cannot do.
	 */
	private function posted_slot(): string {
		$id = sanitize_text_field( (string) ( $_REQUEST['slot'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended -- callers verify a nonce first.

		// Resolved through Connections rather than trusted, so an id that does
		// not name a connection falls back to the primary instead of addressing
		// a slot that does not exist - which would silently create one on save.
		return $this->plugin->connections->slot_for( $id ) ?? Settings::SLOT_PRIMARY;
	}

	/**
	 * Nonce-signed URLs the admin app can link to for the Google flows.
	 *
	 * @return array{connect:string,disconnect:string}
	 */
	public static function google_urls( string $slot ): array {
		$out = [];

		foreach ( [ 'connect' => 'mmoa_connect_google', 'disconnect' => 'mmoa_disconnect_google' ] as $key => $action ) {
			$out[ $key ] = self::signed_url( $action, [ 'slot' => $slot ] );
		}

		return $out;
	}

	/**
	 * A nonce-signed admin-post URL, safe to hand to the admin app as data.
	 *
	 * Deliberately not wp_nonce_url(), which finishes with esc_html() and so
	 * returns `&amp;` between parameters. That is right for a URL printed into
	 * markup, where the browser decodes the entities on the way back out - and
	 * wrong for every URL here, because these are serialised into JSON and set
	 * as an href by React, which assigns the attribute directly and decodes
	 * nothing.
	 *
	 * The result was that the browser requested `…&amp;_wpnonce=…` verbatim, so
	 * PHP parsed the parameter as `amp;_wpnonce`, the real `_wpnonce` was never
	 * present, and check_admin_referer() answered "The link you followed has
	 * expired" - which points at a stale nonce and sends you looking in exactly
	 * the wrong place.
	 *
	 * @param array<string,string> $args Query parameters, values unencoded.
	 */
	private static function signed_url( string $action, array $args ): string {
		$args['action']   = $action;
		$args['_wpnonce'] = wp_create_nonce( $action );

		// add_query_arg() expects pre-encoded input and passes values through
		// untouched, so the encoding has to happen here.
		return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin-post.php' ) );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'mme-mail-to-smtp' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Return to the admin app, carrying a message for it to surface.
	 *
	 * The Google flows leave the browser entirely and come back as a fresh page
	 * load, so the app is remounted with no memory of what was happening. The
	 * outcome therefore has to survive in the URL.
	 *
	 * Somebody partway through the wizard goes back to the wizard. Connecting a
	 * mailbox is one step of five there, and returning them to the connections
	 * screen instead - which is where this flow is normally started from - drops
	 * them out of the sequence at the exact moment it was working.
	 */
	private function redirect_to_app( string $type, string $message, string $route = 'connections' ): void {
		if ( 'connections' === $route && $this->plugin->setup->is_in_progress() ) {
			$route = \ModernMailer\Setup::ROUTE;
		}

		$url = add_query_arg(
			[
				'page'        => App_Page::SLUG,
				'mmoa_status' => $type,
				'mmoa_msg'    => rawurlencode( $message ),
			],
			admin_url( 'admin.php' )
		) . '#/' . $route;

		wp_safe_redirect( $url );
		exit;
	}
}
