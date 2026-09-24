<?php
/**
 * HTTP client for the licensing portal.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The only thing here that talks to the portal.
 *
 * The portal knows which domains run this plugin and which of them have paid.
 * It is a different service from the OAuth broker and deliberately so: the
 * broker holds nothing that outlives the hour, while this one is a system of
 * record with customer data in it.
 *
 * What it is never sent is worth stating, because it is the whole basis on
 * which an administrator should be willing to let this run: no recipient, no
 * subject, no message body, and no provider credential. Sites report counts.
 *
 * Every request except registration is signed with a key this site was issued
 * when it registered. There is no shared secret, and there could not be: this
 * plugin is GPL, so anything shipped inside it can be read by anyone who
 * installs it.
 */
class Portal {

	/**
	 * Where the portal lives.
	 *
	 * A site can point somewhere else without touching the plugin, which is
	 * what a staging environment and the test suite both do:
	 *
	 *     define( 'MMOA_PORTAL_URL', 'https://portal.example.com/' );
	 *
	 * Filtering it to '' switches the whole thing off: no registration, no
	 * check-in, no licence. The plugin carries on sending exactly as before -
	 * this service is not on the path a message takes.
	 */
	private const DEFAULT_URL = 'https://portal.techyza.com/';

	/** Where the per-install signing key is kept, encrypted. */
	private const TOKEN_KEY = 'portal_token';

	/** Everything else the portal exchange needs to remember. */
	public const OPTION = 'mmoa_portal';

	public function __construct(
		private Http $http,
		private Site_Identity $identity,
		private Settings $settings,
		private Connections $connections
	) {}

	/**
	 * The portal base URL, always with a trailing slash.
	 */
	public static function base_url(): string {
		$url = defined( 'MMOA_PORTAL_URL' ) ? (string) MMOA_PORTAL_URL : self::DEFAULT_URL;

		/**
		 * Filter the licensing portal's base URL.
		 *
		 * Returning '' disables every call to it. Nothing about sending mail
		 * depends on this service, so a site that would rather not talk to it
		 * loses only the licence.
		 *
		 * @param string $url Base URL, with trailing slash.
		 */
		$url = (string) apply_filters( 'mmoa_portal_url', $url );

		return '' === $url ? '' : trailingslashit( $url );
	}

	public static function is_available(): bool {
		return '' !== self::base_url();
	}

	/**
	 * State that is not a secret: when we last checked in, and what came back.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * @param array<string,mixed> $values
	 */
	public static function remember( array $values ): void {
		// Autoloaded: the licence screen and the send path both read this, and
		// a separate query for a handful of scalars on those requests is waste.
		update_option( self::OPTION, array_merge( self::state(), $values ), true );
	}

	public function token(): string {
		return ( new Secrets() )->get( self::TOKEN_KEY );
	}

	public function is_registered(): bool {
		return '' !== $this->token();
	}

	/**
	 * Register this installation, or re-register after losing the token.
	 *
	 * Re-registration is normal rather than exceptional: a site restored from a
	 * backup, or one whose options table was cleared, has no other way back in.
	 * The portal reuses the row and issues a fresh token.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function register() {
		if ( ! self::is_available() ) {
			return new WP_Error( 'mmoa_portal_off', __( 'The licensing service is switched off on this site.', 'modern-mailer-oauth' ) );
		}

		$response = $this->http->request(
			self::base_url() . 'v1/installs',
			[
				'method'  => 'POST',
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
				'body'    => (string) wp_json_encode( $this->describe() ),
			]
		);

		$body = $this->decode( $response );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$token = (string) ( $body['install_token'] ?? '' );

		if ( '' === $token ) {
			return new WP_Error( 'mmoa_portal_no_token', __( 'The licensing service did not issue a token.', 'modern-mailer-oauth' ) );
		}

		( new Secrets() )->set( self::TOKEN_KEY, $token );

		self::remember(
			[
				// The portal proves this site owns its domain by calling back
				// and asking for this value. It is not a secret - it is only
				// meaningful to whoever issued it, and it is single-purpose.
				'challenge'    => (string) ( $body['challenge'] ?? '' ),
				'registered'   => time(),
				'verified'     => ! empty( $body['install']['verified'] ),
				'verify_error' => (string) ( $body['install']['verify_error'] ?? '' ),
				'cap'          => (int) ( $body['free_monthly_cap'] ?? 0 ),
				'last_error'   => '',
			]
		);

		return $body;
	}

	/**
	 * A signed call to the portal.
	 *
	 * @param string                   $path Route below the base URL, e.g. 'v1/installs/heartbeat'.
	 * @param array<string,mixed>|null $body JSON body, or null for none.
	 * @return array<string,mixed>|WP_Error
	 */
	public function call( string $method, string $path, ?array $body = null ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'mmoa_portal_off', __( 'The licensing service is switched off on this site.', 'modern-mailer-oauth' ) );
		}

		$token = $this->token();

		if ( '' === $token ) {
			return new WP_Error( 'mmoa_portal_unregistered', __( 'This site has not registered with the licensing service yet.', 'modern-mailer-oauth' ) );
		}

		$url  = self::base_url() . ltrim( $path, '/' );
		$raw  = null === $body ? '' : (string) wp_json_encode( $body );
		$time = time();
		$once = bin2hex( random_bytes( 16 ) );

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 15,
			'headers' => [
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'X-MM-Install'    => $this->identity->get(),
				'X-MM-Timestamp'  => (string) $time,
				'X-MM-Nonce'      => $once,
				'X-MM-Signature'  => self::sign( $token, strtoupper( $method ), self::path_of( $url ), $time, $once, $raw ),
			],
		];

		if ( '' !== $raw ) {
			$args['body'] = $raw;
		}

		return $this->decode( $this->http->request( $url, $args ) );
	}

	/**
	 * The signature the portal recomputes.
	 *
	 * Every part of it is there for a reason. Without the method and path a
	 * signature could be lifted onto a different route; without the timestamp
	 * it would be valid forever; without the nonce it could be replayed inside
	 * the clock-skew window; without the body hash the payload could be
	 * rewritten in flight.
	 *
	 * Both ends must build this string identically. The portal's copy is in
	 * src/Crypto.php, Crypto::canonical(), and the two are not allowed to
	 * disagree.
	 */
	public static function sign( string $token, string $method, string $path, int $timestamp, string $nonce, string $body ): string {
		$canonical = implode(
			"\n",
			[
				strtoupper( $method ),
				$path,
				(string) $timestamp,
				$nonce,
				hash( 'sha256', $body ),
			]
		);

		return hash_hmac( 'sha256', $canonical, $token );
	}

	/**
	 * The path portion of a URL, which is what gets signed.
	 *
	 * Taken from the URL actually being requested rather than assembled by
	 * hand, so a portal mounted under a sub-path signs what it will receive.
	 */
	public static function path_of( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $path ? '/' : $path;
	}

	/**
	 * What this site tells the portal about itself.
	 *
	 * Deliberately short, and every field here is in the plugin's own privacy
	 * documentation. Note what is absent: the administrator's email address.
	 * It is the one field that is unambiguously personal, it is not needed to
	 * count installations, and it arrives with a purchase anyway.
	 *
	 * @return array<string,mixed>
	 */
	public function describe(): array {
		global $wpdb, $wp_version;

		$providers = [];

		foreach ( $this->connections->all() as $connection ) {
			$slug = (string) $this->settings->for_slot( (string) $connection['slot'] )->get( 'provider' );

			if ( '' !== $slug ) {
				$providers[] = $slug;
			}
		}

		return [
			'site_id'          => $this->identity->get(),
			'site_url'         => home_url(),
			'plugin_version'   => VERSION,
			'wp_version'       => (string) $wp_version,
			'php_version'      => PHP_VERSION,
			'db_version'       => is_object( $wpdb ) ? (string) $wpdb->db_version() : '',
			'web_server'       => isset( $_SERVER['SERVER_SOFTWARE'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 0, 64 ) : '',
			'locale'           => get_locale(),
			'timezone'         => wp_timezone_string(),
			'is_multisite'     => is_multisite(),
			'active_providers' => array_values( array_unique( $providers ) ),
		];
	}

	/**
	 * Turn a transport result into something a caller can act on.
	 *
	 * @param array{code:int,body:string,headers:array}|WP_Error $response
	 * @return array<string,mixed>|WP_Error
	 */
	private function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) $response['body'], true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'mmoa_portal_bad_response',
				__( 'The licensing service returned something this plugin could not read.', 'modern-mailer-oauth' )
			);
		}

		if ( $response['code'] >= 400 ) {
			return new WP_Error(
				'mmoa_portal_' . ( $body['error'] ?? 'error' ),
				(string) ( $body['message'] ?? __( 'The licensing service refused the request.', 'modern-mailer-oauth' ) )
			);
		}

		return $body;
	}

	/**
	 * Forget everything this site knows about the portal.
	 */
	public function forget(): void {
		( new Secrets() )->set( self::TOKEN_KEY, '' );
		delete_option( self::OPTION );
	}
}
