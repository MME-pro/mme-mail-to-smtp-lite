<?php
/**
 * REST API backing the admin app.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Api;

use ModernMailer\Failure;
use ModernMailer\Plugin;
use ModernMailer\Provider_Registry;
use ModernMailer\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the admin screens read and write.
 *
 * The admin app is a single page that talks to these routes, which means this
 * class is the whole contract between the two halves. Two consequences worth
 * stating, because they are why several things below look the way they do.
 *
 * Nothing here trusts the client about which fields exist. Every write is
 * filtered through the provider's declared schema, so a request naming a key no
 * provider asked for is dropped rather than stored - the front end cannot widen
 * what is persisted by posting extra keys.
 *
 * Credentials are sent to the browser, which they were not at first, and the
 * change is worth explaining. Withholding them left an administrator unable to
 * check what had been saved: a key pasted with a truncated tail looks exactly
 * like a correct one, and the only way to find out was to send a message and
 * read the error. The field now shows the stored value masked, with an eye to
 * reveal it - which requires the value to be here.
 *
 * The cost is that anything able to read this screen can read the credential.
 * These routes already require manage_options, and that capability can install
 * a plugin and take the value regardless, so the exposure is narrower than it
 * first appears - but it is real, and it is the reason the screen is the only
 * place this happens.
 */
class Rest_Controller {

	public const NAMESPACE = 'modern-mailer/v1';

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$auth = [ $this, 'can_manage' ];

		register_rest_route(
			self::NAMESPACE,
			'/bootstrap',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_bootstrap' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => $auth,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<slot>[A-Za-z0-9_-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_connection' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_connection' ],
					'permission_callback' => $auth,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<slot>[A-Za-z0-9_-]+)/disconnect',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'disconnect_connection' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<slot>[A-Za-z0-9_-]+)/verify',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'verify_connection' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/test-email',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'send_test' ],
				'permission_callback' => $auth,
				'args'                => [
					'to' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/(?P<action>drain|requeue|purge)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'queue_action' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_dashboard' ],
				'permission_callback' => $auth,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/setup',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_setup' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_setup' ],
					'permission_callback' => $auth,
				],
			]
		);
	}

	/**
	 * These routes expose credentials-adjacent configuration and can send mail,
	 * so they are held to the same bar as the settings screen itself.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * One request the app can start from, so the first paint needs no waterfall.
	 */
	public function get_bootstrap(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'settings'    => $this->settings_payload(),
				'connections' => [
					'primary' => $this->connection_payload( Settings::SLOT_PRIMARY ),
				],
				'health'      => $this->health_payload(),
				'queue'       => $this->plugin->queue->stats(),
				'categories'  => Provider_Registry::CATEGORIES,
				'catalogue'   => $this->connections_payload(),

				// Carried on the first request rather than fetched by the
				// wizard itself, because it decides something before the
				// wizard exists: whether an admin arriving at the app should
				// be shown the dashboard or taken into setup.
				'setup'       => $this->plugin->setup->state(),
			]
		);
	}

	public function get_setup(): WP_REST_Response {
		return new WP_REST_Response( $this->plugin->setup->state() );
	}

	/**
	 * Move the wizard on, or record how it ended.
	 *
	 * One route rather than three, because the client only ever wants the state
	 * back and the actions are mutually exclusive. An unrecognised action is
	 * answered with the current state instead of an error: this is bookkeeping
	 * for a screen, and failing a request over it would be the only way for the
	 * wizard to break.
	 */
	public function update_setup( WP_REST_Request $request ): WP_REST_Response {
		$body   = (array) $request->get_json_params();
		$action = isset( $body['action'] ) ? sanitize_key( (string) $body['action'] ) : '';
		$setup  = $this->plugin->setup;

		switch ( $action ) {
			case 'step':
				return new WP_REST_Response( $setup->start( (string) ( $body['step'] ?? \ModernMailer\Setup::FIRST_STEP ) ) );

			case 'complete':
				return new WP_REST_Response( $setup->complete() );

			case 'skip':
				return new WP_REST_Response( $setup->skip() );
		}

		return new WP_REST_Response( $setup->state() );
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings_payload() );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$body   = (array) $request->get_json_params();
		$allow  = [ 'alert_threshold', 'queue_enabled', 'queue_retention' ];
		$values = array_intersect_key( $body, array_flip( $allow ) );

		$this->plugin->settings->update( $values );
		$this->plugin->dispatcher->reset_providers();

		return new WP_REST_Response( $this->settings_payload() );
	}

	public function get_connection( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->connection_payload( $this->slot( $request ) ) );
	}

	public function update_connection( WP_REST_Request $request ): WP_REST_Response {
		$slot     = $this->slot( $request );
		$body     = (array) $request->get_json_params();
		$settings = $this->plugin->settings->for_slot( $slot );
		$secrets  = $settings->secrets();

		// The provider being saved decides which fields are legitimate. Anything
		// else in the request is ignored rather than stored.
		$provider = isset( $body['provider'] ) ? (string) $body['provider'] : (string) $settings->get( 'provider' );
		$provider = Provider_Registry::exists( $provider ) ? $provider : '';

		$values = [ 'provider' => $provider ];
		$class  = Provider_Registry::class_for( $provider );

		if ( null !== $class ) {
			foreach ( Provider_Registry::fields_for( $provider ) as $field ) {
				if ( ! array_key_exists( $field->key, $body ) ) {
					continue;
				}

				$value = $body[ $field->key ];

				if ( ! $field->secret ) {
					$values[ $field->key ] = $value;
					continue;
				}

				// An empty secret means "leave it alone". The form never
				// receives the stored value, so a blank field is the absence of
				// an edit, never an instruction to clear it - there is an
				// explicit clear action for that.
				if ( '' !== trim( (string) $value ) ) {
					$secrets->set( $field->key, trim( (string) $value ) );
				}
			}
		}

		$settings->update( $values );

		// Credentials may have moved underneath a cached token, and any provider
		// built earlier in this request captured the old ones.
		$this->plugin->tokens->flush();
		$this->plugin->dispatcher->reset_providers();

		return new WP_REST_Response( $this->connection_payload( $slot ) );
	}

	/**
	 * Reset the connection to unconfigured.
	 *
	 * Clears the provider, every credential in the slot, and the OAuth grant it
	 * holds. Deliberately thorough: the reason to press this is usually that the
	 * credentials are wrong or the account is being changed, and leaving half of
	 * them behind is how a connection ends up in a state nobody can explain.
	 *
	 * The connection itself survives - only what it was configured with goes.
	 */
	public function disconnect_connection( WP_REST_Request $request ): WP_REST_Response {
		$slot   = $this->slot( $request );
		$scoped = $this->plugin->settings->for_slot( $slot );

		if ( $this->plugin->consent->is_connected( $slot ) ) {
			$this->plugin->consent->disconnect( $slot );
		}

		// Then everything the connection ever stored - every provider field,
		// every credential, the setup mode, and the From address and name.
		// Disconnect used to clear the credentials and the provider only,
		// which left the next person to use this slot looking at a form
		// pre-filled from a mailbox that is no longer connected.
		$scoped->reset_connection();

		Settings::flush_cache();
		$this->plugin->tokens->flush();
		$this->plugin->dispatcher->reset_providers();

		return new WP_REST_Response(
			[
				'ok'      => true,
				'message' => __( 'Disconnected. Every setting and credential for this connection has been deleted, the From address included.', 'mme-mail-to-smtp' ),
			]
		);
	}

	public function verify_connection( WP_REST_Request $request ): WP_REST_Response {
		$slot     = $this->slot( $request );
		$provider = $this->plugin->dispatcher->provider( $slot );

		if ( null === $provider ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'message' => __( 'Choose a provider for this connection first.', 'mme-mail-to-smtp' ),
				]
			);
		}

		$result = $provider->verify_connection();

		// A string is a pass with a caveat - verified, but some part of the
		// check needed a permission the transport does not need to send, so it
		// was skipped rather than failed. The caveat is the message.
		return new WP_REST_Response(
			[
				'ok'      => true === $result || is_string( $result ),
				'message' => is_wp_error( $result )
					? $result->get_error_message()
					: ( is_string( $result )
						? $result
						: __( 'Verified. The credentials are valid and the mailbox is reachable.', 'mme-mail-to-smtp' ) ),
				'code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
			]
		);
	}

	public function send_test( WP_REST_Request $request ): WP_REST_Response {
		$to = (string) $request->get_param( 'to' );

		if ( ! is_email( $to ) ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'message' => __( 'Enter a valid recipient address.', 'mme-mail-to-smtp' ),
				]
			);
		}

		$captured = null;
		$capture  = static function ( $error ) use ( &$captured ): void {
			$captured = $error;
		};

		add_action( 'wp_mail_failed', $capture );

		// Sent with the safety nets off: no routing, no backup, no queue. A
		// test exists to say whether the primary connection works, and every
		// one of those would let it answer yes when the primary had failed -
		// which is the very situation somebody presses this button to find out
		// about.
		$sent = $this->plugin->dispatcher->without_fallbacks(
			fn() => wp_mail(
				$to,
				sprintf(
					/* translators: %s: site name. */
					__( 'MME-Mail to SMTP test from %s', 'mme-mail-to-smtp' ),
					get_bloginfo( 'name' )
				),
				__( "This is a test message.\n\nIf you are reading it, the connection is working.", 'mme-mail-to-smtp' )
			)
		);

		remove_action( 'wp_mail_failed', $capture );

		return new WP_REST_Response(
			[
				'ok'      => (bool) $sent,
				'message' => $sent
					? __( 'Accepted for delivery. If it does not arrive, check the log for what the provider said.', 'mme-mail-to-smtp' )
					: ( $captured instanceof WP_Error ? $captured->get_error_message() : __( 'The test message could not be sent.', 'mme-mail-to-smtp' ) ),
			]
		);
	}

	public function get_queue(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'stats'   => $this->plugin->queue->stats(),
				'enabled' => (bool) $this->plugin->settings->get( 'queue_enabled' ),
				'entries' => array_map(
					static fn( object $row ): array => [
						'id'         => (int) $row->id,
						'created_at' => (string) $row->created_at,
						'next'       => (string) $row->next_attempt_at,
						'attempts'   => (int) $row->attempts,
						'status'     => (string) $row->status,
						'recipients' => (string) $row->recipients,
						'subject'    => (string) $row->subject,
						'bytes'      => (int) $row->bytes,
						'error'      => (string) $row->error_message,
					],
					$this->plugin->queue->recent( 50 )
				),
			]
		);
	}

	public function queue_action( WP_REST_Request $request ): WP_REST_Response {
		$action = (string) $request->get_param( 'action' );
		$queue  = $this->plugin->queue;

		switch ( $action ) {
			case 'drain':
				$queue->reschedule_all();
				$stats = $queue->drain( $this->plugin->dispatcher );

				return new WP_REST_Response(
					[
						'ok'      => true,
						'stats'   => $stats,
						'message' => sprintf(
							/* translators: 1: attempted, 2: delivered, 3: still queued, 4: abandoned. */
							__( 'Attempted %1$d: %2$d delivered, %3$d still queued, %4$d abandoned.', 'mme-mail-to-smtp' ),
							$stats['attempted'],
							$stats['sent'],
							$stats['failed'],
							$stats['exhausted']
						),
					]
				);

			case 'requeue':
				$count = $queue->requeue_failed();

				return new WP_REST_Response(
					[
						'ok'      => true,
						'message' => sprintf(
							/* translators: %d: number of messages returned to the queue. */
							_n( '%d abandoned message returned to the queue.', '%d abandoned messages returned to the queue.', $count, 'mme-mail-to-smtp' ),
							$count
						),
					]
				);

			default:
				$queue->purge();

				return new WP_REST_Response(
					[
						'ok'      => true,
						'message' => __( 'Queue emptied. Anything it held is gone.', 'mme-mail-to-smtp' ),
					]
				);
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function connections_payload(): array {
		return [
			'connections' => $this->plugin->connections->all(),
			'labels'      => Provider_Registry::labels(),
		];
	}

	/**
	 * What the dashboard shows: whether sending works, and what is waiting.
	 *
	 * Deliberately not a send history. The free plugin keeps no record of
	 * individual messages, so there is nothing to count - what an administrator
	 * can act on here is the current state and the retry queue.
	 */
	public function get_dashboard(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'health' => $this->health_payload(),
				'queue'  => $this->plugin->queue->stats(),
			]
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings_payload(): array {
		$settings = $this->plugin->settings;
		$keys     = [ 'alert_threshold', 'queue_enabled', 'queue_retention' ];

		$out    = [];
		$locked = [];

		foreach ( $keys as $key ) {
			$out[ $key ]    = $settings->get( $key );
			$locked[ $key ] = $settings->is_constant( $key );
		}

		return [
			'values' => $out,
			'locked' => $locked,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function connection_payload( string $slot ): array {
		$scoped = $this->plugin->settings->for_slot( $slot );

		return [
			'slot'      => '' === $slot ? 'primary' : $slot,
			'provider'  => (string) $scoped->get( 'provider' ),
			'providers' => Provider_Registry::to_array( $scoped ),
			'oauth'     => $this->oauth_payload( $slot ),
		];
	}

	/**
	 * The Google sign-in state and the links that drive it.
	 *
	 * These are nonce-signed admin-post URLs rather than REST routes because
	 * starting a consent flow means navigating the browser to Google - a fetch()
	 * cannot do that, and the redirect has to be a real top-level navigation for
	 * Google to redirect back into the site afterwards.
	 *
	 * `has_credentials` exists so the app can explain why the button is
	 * unavailable rather than hiding it: the client ID and secret must be saved
	 * before there is anything to sign in with.
	 *
	 * Returned for every connection, not only one already set to Gmail. This
	 * block is what tells an admin the redirect URI to register and why they
	 * cannot sign in yet - which they need while setting Gmail up, i.e. before
	 * the provider has ever been saved. Gating it on the stored provider made
	 * the whole section invisible at exactly the moment it was needed.
	 *
	 * @return array<string,mixed>
	 */
	private function oauth_payload( string $slot ): array {
		$scoped = $this->plugin->settings->for_slot( $slot );
		$urls   = \ModernMailer\Admin\Admin_Page::google_urls( $slot );

		return [
			'connected'       => $this->plugin->consent->is_connected( $slot ),
			'has_credentials' => '' !== trim( (string) $scoped->get( 'google_client_id' ) )
				&& '' !== $scoped->secrets()->get( 'google_client_sec' ),
			'connect_url'     => $urls['connect'],
			'disconnect_url'  => $urls['disconnect'],
			'redirect_uri'    => \ModernMailer\Auth\Google_Consent::redirect_uri(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function health_payload(): array {
		$state = $this->plugin->health->state();

		return [
			'failing'      => $this->plugin->health->is_failing(),
			'streak'       => (int) $state['streak'],
			'last_error'   => (string) ( $state['last_error']['message'] ?? '' ),
			'last_success' => (int) $state['last_success'],
			'active'       => $this->plugin->settings->is_active(),
		];
	}

	/**
	 * Resolve the connection named in the route.
	 *
	 * Resolved through Connections rather than matched against a pattern, so an
	 * id for a connection that has been deleted falls back to the primary
	 * instead of addressing a slot that no longer exists - which would silently
	 * create one on save.
	 */
	private function slot( WP_REST_Request $request ): string {
		$slot = $this->plugin->connections->slot_for( (string) $request->get_param( 'slot' ) );

		return $slot ?? Settings::SLOT_PRIMARY;
	}
}
