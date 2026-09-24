<?php
/**
 * Settings screen.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Admin;

use ModernMailer\Auth\Google_Consent;
use ModernMailer\Plugin;
use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Form handlers, the failure notice, and the no-JavaScript screens.
 *
 * The menu belongs to App_Page, which mounts the admin app. What stays here is
 * everything that cannot live inside it: the Google sign-in round trip, which
 * leaves the browser and comes back as a top-level GET rather than a REST call,
 * and the admin-post handlers behind the server-rendered views.
 *
 * Those views are no longer reachable from a menu. They are kept because they
 * are a working fallback that needs no JavaScript, and because the test suite
 * renders them to assert the same data the app shows.
 */
class Admin_Page {

	/** Settings, and the parent menu slug. */
	private const SLUG        = 'mme-mail-to-smtp';

	private const CAPABILITY = 'manage_options';
	private const NOTICE     = 'mmoa_notice';

	/**
	 * Credential fields: form name => Secrets key.
	 */
	private const SECRET_FIELDS = [
		'google_sa_key'     => 'google_sa_key',
		'google_client_sec' => 'google_client_sec',
	];

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Form handlers only - App_Page owns the menu now.
	 *
	 * The React app covers everything except the Google sign-in round trip,
	 * which cannot be a REST call: it navigates the browser away to Google and
	 * comes back as a top-level GET. That needs real admin-post endpoints and a
	 * server-side redirect, so those handlers stay here rather than being
	 * awkwardly reimplemented in the app.
	 *
	 * The render_*() methods are also kept. They are no longer reachable from a
	 * menu, but they are a working no-JavaScript view of the same data and the
	 * test suite asserts against them.
	 */
	public function register(): void {
		add_action( 'admin_post_mmoa_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_mmoa_verify', [ $this, 'handle_verify' ] );
		add_action( 'admin_post_mmoa_test_email', [ $this, 'handle_test_email' ] );
		add_action( 'admin_post_mmoa_queue', [ $this, 'handle_queue' ] );
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
	 * URL of one of our screens.
	 */
	public static function url( string $slug = self::SLUG ): string {
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
	 * Connection-scoped setting keys, by sanitizer shape.
	 */
	private const CONNECTION_TEXT_FIELDS = [ 'provider', 'google_sa_email', 'google_sender', 'google_client_id' ];

	public function handle_save(): void {
		$this->guard( 'mmoa_save' );

		$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.

		// Site-wide settings first; these exist once regardless of slot.
		$global = [];

		foreach ( [ 'from_email', 'from_name', 'alert_threshold' ] as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$global[ $key ] = $posted[ $key ];
			}
		}

		$global['force_from']    = ! empty( $posted['force_from'] );
		$global['queue_enabled'] = ! empty( $posted['queue_enabled'] );

		$this->plugin->settings->update( $global );

		$this->save_connection( Settings::SLOT_PRIMARY, $posted );

		// Credentials may have changed underneath a cached token, and any
		// provider built earlier in this request captured the old ones.
		$this->plugin->tokens->flush();
		$this->plugin->health->reset();
		$this->plugin->dispatcher->reset_providers();

		$this->redirect( 'saved', __( 'Settings saved.', 'mme-mail-to-smtp' ) );
	}

	/**
	 * Persist one connection's settings and credentials.
	 *
	 * @param array<string,mixed> $posted Unslashed request payload.
	 */
	private function save_connection( string $slot, array $posted ): void {
		$settings = $this->plugin->settings->for_slot( $slot );
		$secrets  = $this->plugin->secrets->for_slot( $slot );
		$values   = [];

		foreach ( self::CONNECTION_TEXT_FIELDS as $key ) {
			$name = $this->name( $key, $slot );

			if ( isset( $posted[ $name ] ) ) {
				$values[ $key ] = $posted[ $name ];
			}
		}

		$settings->update( $values );

		// Credentials are only written when the field was actually filled in.
		// The form renders a masked placeholder rather than the stored value,
		// so an empty field means "leave it alone", never "clear it".
		foreach ( self::SECRET_FIELDS as $field => $key ) {
			$name = $this->name( $field, $slot );

			if ( isset( $posted[ $name ] ) && '' !== trim( (string) $posted[ $name ] ) ) {
				$secrets->set( $key, trim( (string) $posted[ $name ] ) );
			}
		}
	}

	public function handle_verify(): void {
		$this->guard( 'mmoa_verify' );

		$slot = Settings::SLOT_PRIMARY;

		$provider = $this->plugin->dispatcher->provider( $slot );

		if ( null === $provider ) {
			$this->redirect( 'error', __( 'Choose a provider first.', 'mme-mail-to-smtp' ) );
		}

		$result = $provider->verify_connection();

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		// A string result is a pass with a caveat, and the caveat is the only
		// part worth reading - it says what could not be checked.
		if ( is_string( $result ) ) {
			$this->redirect( 'saved', $result );
		}

		$this->redirect(
			'saved',
			__( 'Connection verified. Credentials are valid and the mailbox is reachable.', 'mme-mail-to-smtp' )
		);
	}

	/**
	 * Run the queue actions offered on the settings screen.
	 */
	public function handle_queue(): void {
		$this->guard( 'mmoa_queue' );

		$action = sanitize_key( wp_unslash( $_POST['queue_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		switch ( $action ) {
			case 'drain':
				$this->plugin->queue->reschedule_all();
				$stats = $this->plugin->queue->drain( $this->plugin->dispatcher );

				$this->redirect(
					$stats['sent'] > 0 || 0 === $stats['attempted'] ? 'saved' : 'error',
					sprintf(
						/* translators: 1: attempted count, 2: delivered count, 3: still-queued count, 4: abandoned count. */
						__( 'Attempted %1$d queued message(s): %2$d delivered, %3$d still queued, %4$d abandoned.', 'mme-mail-to-smtp' ),
						$stats['attempted'],
						$stats['sent'],
						$stats['failed'],
						$stats['exhausted']
					)
				);
				break;

			case 'requeue':
				$count = $this->plugin->queue->requeue_failed();

				$this->redirect(
					'saved',
					sprintf(
						/* translators: %d: number of messages returned to the queue. */
						_n( '%d abandoned message returned to the queue.', '%d abandoned messages returned to the queue.', $count, 'mme-mail-to-smtp' ),
						$count
					)
				);
				break;

			case 'purge':
				$this->plugin->queue->purge();
				$this->redirect( 'saved', __( 'Queue emptied. Anything it held is gone.', 'mme-mail-to-smtp' ) );
				break;
		}

		$this->redirect( 'error', __( 'Unknown queue action.', 'mme-mail-to-smtp' ) );
	}

	public function handle_test_email(): void {
		$this->guard( 'mmoa_test_email' );

		$to = sanitize_email( wp_unslash( $_POST['test_to'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_email( $to ) ) {
			$this->redirect( 'error', __( 'Enter a valid recipient address.', 'mme-mail-to-smtp' ) );
		}

		$captured = null;

		$capture = static function ( $error ) use ( &$captured ): void {
			$captured = $error;
		};

		add_action( 'wp_mail_failed', $capture );

		// Same rules as the app's Send test: no routing, no backup, no queue,
		// so the answer is the primary connection's own.
		$sent = $this->plugin->dispatcher->without_fallbacks(
			fn() => wp_mail(
				$to,
				sprintf(
					/* translators: %s: site name. */
					__( 'MME-Mail to SMTP test from %s', 'mme-mail-to-smtp' ),
					get_bloginfo( 'name' )
				),
				__( "This is a test message.\n\nIf you are reading it, the API connection is working.", 'mme-mail-to-smtp' )
			)
		);

		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			$this->redirect( 'saved', __( 'Test message accepted by the provider.', 'mme-mail-to-smtp' ) );
		}

		$this->redirect(
			'error',
			$captured instanceof \WP_Error
				? $captured->get_error_message()
				: __( 'The test message could not be sent.', 'mme-mail-to-smtp' )
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

		// Named, rather than a message that only distinguished primary from
		// backup. With any number of connections available, "connected" without
		// saying which one leaves the admin to go and check.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- callers verify a nonce first.
		$id = sanitize_text_field( (string) ( $_REQUEST['slot'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

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

	/**
	 * Render the Connect / Disconnect control for a Gmail OAuth connection.
	 */
	public function google_connect_control( string $slot ): void {
		$connected = $this->plugin->consent->is_connected( $slot );
		$action    = $connected ? 'mmoa_disconnect_google' : 'mmoa_connect_google';

		echo '<tr><th scope="row">' . esc_html__( 'Account', 'mme-mail-to-smtp' ) . '</th><td>';

		if ( $connected ) {
			printf(
				'<p><span style="color:#008a20">&#10003; %s</span></p>',
				esc_html__( 'Connected. A refresh token is stored for this connection.', 'mme-mail-to-smtp' )
			);
		} else {
			printf(
				'<p>%s</p>',
				esc_html__( 'Not connected. Save the client ID and secret first, then sign in to grant access.', 'mme-mail-to-smtp' )
			);
		}

		// A separate form, outside the settings form, because it navigates away
		// to Google rather than saving. Anything typed into the settings fields
		// and not yet saved would be lost, which is why the copy above insists
		// on saving first.
		printf(
			'<form method="post" action="%s"><input type="hidden" name="action" value="%s" />' .
			'<input type="hidden" name="slot" value="%s" />',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $action ),
			esc_attr( $slot )
		);

		// Printed by wp_nonce_field() itself rather than returned and passed
		// through printf(). The markup is identical either way, but returning it
		// makes it a value being output, which static analysis cannot tell apart
		// from an unescaped one - and silencing that warning would train the eye
		// to skip exactly the warning worth reading.
		wp_nonce_field( $action );

		submit_button(
			$connected
				? __( 'Disconnect Google account', 'mme-mail-to-smtp' )
				: __( 'Sign in with Google', 'mme-mail-to-smtp' ),
			$connected ? 'delete' : 'primary',
			'submit',
			false
		);

		echo '</form></td></tr>';
	}

	public function render_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = $this->plugin->settings;
		$page     = self::SLUG;

		require __DIR__ . '/views/settings.php';
	}

	/**
	 * The hidden field that sends a form handler back to the current screen.
	 */
	public function return_field( string $page ): void {
		printf( '<input type="hidden" name="return_page" value="%s" />', esc_attr( $page ) );
	}

	/**
	 * Render the one-shot notice left by a form handler, if any.
	 */
	public function render_notice(): void {
		$notice = $this->take_notice();

		if ( null === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice[0] ? 'error' : 'success',
			esc_html( $notice[1] )
		);
	}

	/**
	 * Form input name for a setting in a connection slot.
	 *
	 * Mirrors the storage key Settings uses, so the POST payload and the option
	 * array agree without a translation table in between.
	 */
	private function name( string $key, string $slot ): string {
		return Settings::SLOT_PRIMARY === $slot ? $key : $slot . '_' . $key;
	}

	/**
	 * Render one text field, disabled when a constant pins it.
	 */
	public function field( string $key, string $label, string $help = '', string $type = 'text', string $slot = Settings::SLOT_PRIMARY ): void {
		$settings = $this->plugin->settings->for_slot( $slot );
		$locked   = $settings->is_constant( $key );
		$name     = $this->name( $key, $slot );

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>' .
			'<input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="regular-text" %5$s />',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) $settings->get( $key ) ),
			$locked ? 'disabled="disabled"' : ''
		);

		if ( $locked ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Set in wp-config.php, so it cannot be edited here.', 'mme-mail-to-smtp' )
			);
		} elseif ( '' !== $help ) {
			printf( '<p class="description">%s</p>', esc_html( $help ) );
		}

		echo '</td></tr>';
	}

	/**
	 * Render one credential field. Never echoes the stored value back.
	 */
	public function secret_field( string $key, string $label, string $help = '', bool $textarea = false, string $slot = Settings::SLOT_PRIMARY ): void {
		$secrets = $this->plugin->secrets->for_slot( $slot );
		$locked  = $secrets->is_constant( $key );
		$has     = '' !== $secrets->get( $key );
		$name    = $this->name( $key, $slot );

		printf( '<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr( $name ), esc_html( $label ) );

		if ( $locked ) {
			printf(
				'<p><code>%s</code></p><p class="description">%s</p></td></tr>',
				esc_html__( 'defined in wp-config.php', 'mme-mail-to-smtp' ),
				esc_html__( 'This is the recommended place to keep it.', 'mme-mail-to-smtp' )
			);

			return;
		}

		$placeholder = $has
			? __( 'Stored. Leave blank to keep it.', 'mme-mail-to-smtp' )
			: __( 'Not set', 'mme-mail-to-smtp' );

		if ( $textarea ) {
			printf(
				'<textarea id="%1$s" name="%1$s" rows="5" class="large-text code" placeholder="%2$s" autocomplete="off"></textarea>',
				esc_attr( $name ),
				esc_attr( $placeholder )
			);
		} else {
			printf(
				'<input type="password" id="%1$s" name="%1$s" value="" class="regular-text" placeholder="%2$s" autocomplete="new-password" />',
				esc_attr( $name ),
				esc_attr( $placeholder )
			);
		}

		if ( '' !== $help ) {
			printf( '<p class="description">%s</p>', esc_html( $help ) );
		}

		if ( ! $secrets->is_encryption_available() ) {
			printf(
				'<p class="description"><strong>%s</strong></p>',
				esc_html__( 'Libsodium is unavailable, so this will be stored unencrypted. Put it in wp-config.php instead.', 'mme-mail-to-smtp' )
			);
		}

		echo '</td></tr>';
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'mme-mail-to-smtp' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Where a form handler should send the admin back to.
	 *
	 * Forms carry a hidden return_page so an action taken on the Backup
	 * screen returns there rather than dumping the admin on Settings. Validated
	 * against the known slugs, because it ends up in a redirect.
	 */
	private function return_slug(): string {
		$posted = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify a nonce first.

		return in_array( $posted, [ self::SLUG ], true )
			? $posted
			: self::SLUG;
	}

	private function redirect( string $type, string $message, ?string $slug = null ): void {
		set_transient( self::NOTICE . '_' . get_current_user_id(), [ $type, $message ], 60 );

		wp_safe_redirect( self::url( $slug ?? $this->return_slug() ) );
		exit;
	}

	/**
	 * Return to the admin app, carrying a message for it to surface.
	 *
	 * The Google flows leave the browser entirely and come back as a fresh page
	 * load, so the app is remounted with no memory of what was happening. The
	 * outcome therefore has to survive in the URL - a transient would be read
	 * by a PHP screen that no longer renders anything.
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

	/**
	 * Pull and clear the one-shot notice set by a form handler.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public function take_notice(): ?array {
		$key    = self::NOTICE . '_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return null;
		}

		delete_transient( $key );

		return $notice;
	}
}
