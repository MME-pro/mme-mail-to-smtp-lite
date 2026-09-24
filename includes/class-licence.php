<?php
/**
 * The licence this site holds, and whether it can be believed.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the entitlement, checks its signature, and answers "is this site paid".
 *
 * The signature is the whole point. An entitlement travels here over a
 * connection this site cannot fully vouch for - a hosts file, a corporate
 * proxy, a security plugin that filters HTTP - so it is signed by the portal
 * with a private key and verified here with the public half below.
 *
 * That public key is not a secret and there is no harm in it being read. A
 * shared secret could not do this job at all: the plugin is GPL, so anything
 * shipped inside it can be read by anyone who installs it, and a secret
 * everyone has verifies nothing.
 *
 * None of this pretends to be unbreakable. A determined administrator can edit
 * the plugin and delete the check; that is what GPL means, and building around
 * it would cost real effort, break on some hosts, and stop nobody who was
 * actually motivated. What this does is make the honest path the easy one.
 */
class Licence {

	/**
	 * The portal's public key.
	 *
	 * Generated once, alongside the private half that lives only on the portal.
	 * Replacing this means every existing entitlement stops verifying, so it is
	 * changed only when the portal's key is rotated - and then both must ship
	 * together.
	 */
	public const PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\n"
		. "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAz92HYMfCksjE1i2o2rgI\n"
		. "gFJ+9a+aa1K8tj9yWhaVcPFyyPR2RdSVXOYjUETGi/qAgOHEydyBRyd1+GYAk3XZ\n"
		. "ZK1g5aD3CNea0/5hKv9lRm0YIAApgv6ZgjMMabo9r+767LxFzMMh0I+UbyXy43TJ\n"
		. "ky+pw3++O153oozw7bfULvtGKbB6pQSgI5B/ItI4N022YXshbQSy0Yf3p3BhBAq1\n"
		. "hTQkghAsbcbQ78sVCer9SSICQtx4tnDt2ZqjX5cSY4IG7mmVEK8GOa760CpUoSbK\n"
		. "cPg7LLv5TGr+b/3a5ye8tRkhqWCVLW5T8FBG2ZysUyCskBW7eYSWOMQo+sI2tjIV\n"
		. "WQIDAQAB\n"
		. '-----END PUBLIC KEY-----';

	/** Where the verified claims and the raw token are cached. */
	public const OPTION = 'mmoa_licence';

	/** Where the customer's key itself lives, encrypted. */
	private const KEY_SECRET = 'licence_key';

	/**
	 * How long an expired entitlement is still honoured.
	 *
	 * The portal issues short-lived entitlements so that a revoked licence
	 * stops working reasonably soon. This grace is the other half of that
	 * bargain: a site whose portal is unreachable - an outage, a firewall, a
	 * DNS problem, a lapsed domain on our side - keeps working for a fortnight
	 * rather than losing its paid features because someone else's server is
	 * down.
	 */
	private const GRACE = 14 * DAY_IN_SECONDS;

	/**
	 * The licence key an administrator typed, if any.
	 */
	public function key(): string {
		return ( new Secrets() )->get( self::KEY_SECRET );
	}

	public function set_key( string $key ): void {
		( new Secrets() )->set( self::KEY_SECRET, trim( $key ) );
	}

	/**
	 * The verified claims, or null when there is no usable entitlement.
	 *
	 * @return array<string,mixed>|null
	 */
	public function claims(): ?array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) || empty( $stored['token'] ) ) {
			return null;
		}

		$claims = self::verify( (string) $stored['token'] );

		if ( null === $claims ) {
			return null;
		}

		// Bound to this site and this domain, so an entitlement lifted from
		// another installation - copied with the database, say - does not
		// license this one.
		if ( ( $claims['sub'] ?? '' ) !== ( new Site_Identity() )->get() ) {
			return null;
		}

		$expires = (int) ( $claims['exp'] ?? 0 );

		if ( time() > $expires + self::GRACE ) {
			return null;
		}

		return $claims;
	}

	public function is_pro(): bool {
		return null !== $this->claims();
	}

	/**
	 * Is the entitlement past its own expiry but still inside the grace period?
	 *
	 * Worth showing on the licence screen: the site is working, and something
	 * is nonetheless wrong enough to need attention before the grace runs out.
	 */
	public function in_grace(): bool {
		$claims = $this->claims();

		return null !== $claims && time() > (int) ( $claims['exp'] ?? 0 );
	}

	/**
	 * Remember whatever the portal last said about this site's licence.
	 *
	 * Called after every check-in and every activation. An answer with no
	 * entitlement in it is itself meaningful - it is how a site learns that its
	 * key was moved elsewhere or revoked - so it clears rather than being
	 * ignored.
	 *
	 * @param array<string,mixed> $response
	 */
	public function store( array $response ): void {
		$token = (string) ( $response['entitlement'] ?? '' );

		if ( '' === $token || null === self::verify( $token ) ) {
			delete_option( self::OPTION );

			return;
		}

		update_option(
			self::OPTION,
			[
				'token'   => $token,
				'licence' => is_array( $response['licence'] ?? null ) ? $response['licence'] : [],
				'stored'  => time(),
			],
			true
		);
	}

	public function forget(): void {
		delete_option( self::OPTION );
		( new Secrets() )->set( self::KEY_SECRET, '' );
	}

	/**
	 * Check an RS256 JWT against the portal's public key.
	 *
	 * @return array<string,mixed>|null The claims, or null if anything is wrong.
	 */
	public static function verify( string $token ): ?array {
		if ( ! function_exists( 'openssl_verify' ) ) {
			// Without OpenSSL nothing can be verified, and an unverifiable
			// entitlement is treated as no entitlement. Refusing to check is
			// not the same as passing.
			return null;
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		[ $header, $payload, $signature ] = $parts;

		$algorithm = json_decode( (string) self::base64url_decode( $header ), true );

		// Pinned to RS256. Accepting whatever the token nominates is the classic
		// JWT hole - a forged token saying "alg: none" would otherwise verify
		// itself.
		if ( ! is_array( $algorithm ) || 'RS256' !== ( $algorithm['alg'] ?? '' ) ) {
			return null;
		}

		$verified = openssl_verify(
			$header . '.' . $payload,
			(string) self::base64url_decode( $signature ),
			self::PUBLIC_KEY,
			OPENSSL_ALGO_SHA256
		);

		if ( 1 !== $verified ) {
			return null;
		}

		$claims = json_decode( (string) self::base64url_decode( $payload ), true );

		return is_array( $claims ) ? $claims : null;
	}

	private static function base64url_decode( string $value ) {
		$padded = strtr( $value, '-_', '+/' );
		$remain = strlen( $padded ) % 4;

		if ( $remain ) {
			$padded .= str_repeat( '=', 4 - $remain );
		}

		return base64_decode( $padded, true );
	}

	/**
	 * What the licence screen and the dashboard need to show.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		$claims = $this->claims();
		$stored = get_option( self::OPTION, [] );
		$portal = Portal::state();

		return [
			'available'    => Portal::is_available(),

			// Read from what registration recorded rather than by reaching for
			// the token itself. This class has no other reason to know how the
			// portal client is built, and giving it one would mean constructing
			// four collaborators to answer a boolean.
			'registered'   => ! empty( $portal['registered'] ),
			'verified'     => ! empty( $portal['verified'] ),
			'verify_error' => (string) ( $portal['verify_error'] ?? '' ),
			'has_key'      => '' !== $this->key(),
			'key_hint'     => (string) ( is_array( $stored ) ? ( $stored['licence']['key_hint'] ?? '' ) : '' ),
			'pro'          => null !== $claims,
			'plan'         => (string) ( $claims['plan'] ?? '' ),
			'expires'      => (string) ( is_array( $stored ) ? ( $stored['licence']['expires_at'] ?? '' ) : '' ),
			'in_grace'     => $this->in_grace(),
			'checked_in'   => (int) ( $portal['checked_in'] ?? 0 ),
			'last_error'   => (string) ( $portal['last_error'] ?? '' ),
		];
	}
}
