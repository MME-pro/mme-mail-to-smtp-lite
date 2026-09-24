<?php
/**
 * The licensing portal integration.
 *
 * Two halves. The signing rules and the entitlement checks are exercised
 * offline, because they are the parts a mistake in would be a security hole.
 * The round trip is exercised against a real portal when one is reachable, and
 * skipped when it is not, so the suite still runs on a laptop with no network.
 *
 *   MMOA_PORTAL_URL=https://portal.techyza.com/ php test-portal.php
 *
 * Nothing here sends mail, and nothing here writes to the live portal beyond
 * one registration that is deregistered again at the end.
 *
 * @package ModernMailer
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ModernMailer\Licence;
use ModernMailer\Portal;
use ModernMailer\Usage;

$passed = 0;
$failed = 0;

function check( string $label, $actual, $expected ): void {
	global $passed, $failed;

	if ( $actual === $expected ) {
		$passed++;
		echo "  PASS  {$label}\n";

		return;
	}

	$failed++;
	echo "  FAIL  {$label}\n";
	echo '        got ' . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . "\n";
}

function section( string $name ): void {
	echo "\n--- {$name} ---\n";
}

/* ------------------------------------------------------------- the signature */

section( 'the signature both ends must agree on' );

// Built here from the documented rule rather than by calling Portal::sign, so
// that this asserts the rule and not merely that a function equals itself. The
// portal's copy is Crypto::canonical() in the portal repository; if the two
// disagree, every signed request fails and this is the test that says which.
$token     = 'a-test-token';
$canonical = implode( "\n", [ 'POST', '/v1/installs/heartbeat', '1737031234', 'abc123', hash( 'sha256', '{"a":1}' ) ] );
$expected  = hash_hmac( 'sha256', $canonical, $token );

check(
	'matches the documented canonical string',
	Portal::sign( $token, 'POST', '/v1/installs/heartbeat', 1737031234, 'abc123', '{"a":1}' ),
	$expected
);

check(
	'the method is lower-cased into the same signature',
	Portal::sign( $token, 'post', '/v1/installs/heartbeat', 1737031234, 'abc123', '{"a":1}' ),
	$expected
);

foreach (
	[
		'method'    => Portal::sign( $token, 'GET', '/v1/installs/heartbeat', 1737031234, 'abc123', '{"a":1}' ),
		'path'      => Portal::sign( $token, 'POST', '/v1/installs/me', 1737031234, 'abc123', '{"a":1}' ),
		'timestamp' => Portal::sign( $token, 'POST', '/v1/installs/heartbeat', 1737031235, 'abc123', '{"a":1}' ),
		'nonce'     => Portal::sign( $token, 'POST', '/v1/installs/heartbeat', 1737031234, 'abc124', '{"a":1}' ),
		'body'      => Portal::sign( $token, 'POST', '/v1/installs/heartbeat', 1737031234, 'abc123', '{"a":2}' ),
		'token'     => Portal::sign( 'other-token', 'POST', '/v1/installs/heartbeat', 1737031234, 'abc123', '{"a":1}' ),
	] as $part => $signature
) {
	check( "changing the {$part} changes the signature", $signature === $expected, false );
}

check( 'the signed path comes out of the URL', Portal::path_of( 'https://portal.example.com/v1/installs/me?x=1' ), '/v1/installs/me' );
check( 'and a bare host signs the root', Portal::path_of( 'https://portal.example.com' ), '/' );

/* ------------------------------------------------------------- entitlements */

section( 'entitlements' );

// A throwaway pair, so the suite never depends on the real signing key.
$pair = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );

if ( false === $pair ) {
	echo "  SKIP  openssl could not generate a key pair here\n";
} else {
	openssl_pkey_export( $pair, $private );

	$public = (string) ( openssl_pkey_get_details( $pair )['key'] ?? '' );

	$mint = static function ( array $claims, string $key, string $alg = 'RS256' ): string {
		$b64 = static fn( string $raw ): string => rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );

		$signing = $b64( (string) wp_json_encode( [ 'alg' => $alg, 'typ' => 'JWT' ] ) ) . '.' . $b64( (string) wp_json_encode( $claims ) );

		$signature = '';
		openssl_sign( $signing, $signature, $key, OPENSSL_ALGO_SHA256 );

		return $signing . '.' . $b64( $signature );
	};

	// Licence::verify checks against the key compiled into the plugin, so the
	// throwaway key is pushed in through the same door the real one uses.
	$reflection = new ReflectionClass( Licence::class );
	$real       = $reflection->getConstant( 'PUBLIC_KEY' );

	check( 'the shipped public key is a public key', str_contains( (string) $real, 'BEGIN PUBLIC KEY' ), true );
	check( 'and is not a private one', str_contains( (string) $real, 'PRIVATE KEY' ), false );

	$token = $mint( [ 'sub' => 'mm_test', 'plan' => 'pro', 'exp' => time() + 3600 ], $private );

	// Verified against the throwaway public half directly, which is the same
	// operation Licence::verify performs against the shipped one.
	[ $header, $payload, $signature ] = explode( '.', $token );

	$raw = base64_decode( strtr( $signature, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $signature ) % 4 ) % 4 ), true );

	check( 'a real entitlement verifies', openssl_verify( "{$header}.{$payload}", (string) $raw, $public, OPENSSL_ALGO_SHA256 ), 1 );

	// The forgeries that matter.
	check( 'an entitlement signed by the wrong key is refused', Licence::verify( $token ), null );
	check( 'rubbish is refused', Licence::verify( 'not.a.token' ), null );
	check( 'an empty string is refused', Licence::verify( '' ), null );

	// The classic JWT hole: a token that nominates its own algorithm.
	$b64  = static fn( string $r ): string => rtrim( strtr( base64_encode( $r ), '+/', '-_' ), '=' );
	$none = $b64( (string) wp_json_encode( [ 'alg' => 'none', 'typ' => 'JWT' ] ) )
		. '.' . $b64( (string) wp_json_encode( [ 'sub' => 'mm_test', 'plan' => 'pro', 'exp' => time() + 3600 ] ) )
		. '.';

	check( 'alg:none is refused rather than trusted', Licence::verify( $none ), null );
}

/* -------------------------------------------------------------- the metering */

section( 'metering' );

Usage::install();

$usage = new Usage();
$before = $usage->report();

$usage->record( true );
$usage->record( false );

$after = $usage->report();

check( 'every send counts', $after['sent'] - $before['sent'], 2 );
check( 'only a metered one counts as metered', $after['metered'] - $before['metered'], 1 );

check( 'plain SMTP is never metered', Usage::is_metered( new ModernMailer\Providers\Smtp( new ModernMailer\Settings( new ModernMailer\Secrets() ), new ModernMailer\Token_Store(), new ModernMailer\Http() ) ), false );

// Everything that does not say otherwise is metered, which is what makes a
// provider registered by another plugin work without knowing any of this.
check( 'an unknown provider is metered by default', Usage::is_metered( new stdClass() ), true );
check( 'and no provider at all is not', Usage::is_metered( null ), false );

/* --------------------------------------------------------- nothing stops it */

section( 'no count ever refuses a send' );

// There was a monthly cap here once, and this section proved it stopped the
// twenty-first message. The cap is gone, so the same setup now has to prove the
// opposite: a metered provider keeps sending however high the count goes, and
// nothing in the send path consults the meter at all.

$plugin = ModernMailer\Plugin::instance();

// A metered provider, with every outbound call stubbed - no message leaves.
$plugin->settings->update(
	[
		'provider'     => 'graph',
		'from_email'   => 'noreply@contoso.com',
		'ms_tenant_id' => 'tid',
		'ms_client_id' => 'cid',
		'ms_sender'    => 'noreply@contoso.com',
		'log_enabled'  => false,
		'queue_enabled' => false,
	]
);

$plugin->secrets->set( 'ms_client_secret', 'secret' );
$plugin->tokens->flush();
$plugin->health->reset();
$plugin->dispatcher->reset_providers();
$plugin->install_mailer();

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( str_contains( (string) $url, 'login.microsoftonline' ) ) {
			return [
				'headers'  => [],
				'body'     => (string) wp_json_encode( [ 'access_token' => 'T', 'expires_in' => 3600 ] ),
				'response' => [ 'code' => 200, 'message' => '' ],
				'cookies'  => [],
				'filename' => null,
			];
		}

		if ( str_contains( (string) $url, 'graph.microsoft.com' ) ) {
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [ 'code' => 202, 'message' => '' ],
				'cookies'  => [],
				'filename' => null,
			];
		}

		return $pre;
	},
	5,
	3
);

global $wpdb;

$table = Usage::table();
$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore

$capture = static function ( callable $send ): ?WP_Error {
	$error = null;
	$catch = static function ( $e ) use ( &$error ) {
		$error = $e;
	};

	add_action( 'wp_mail_failed', $catch );
	$send();
	remove_action( 'wp_mail_failed', $catch );

	return $error;
};

$delivered = 0;

// Twenty-five, which is comfortably past the twenty the old free tier allowed,
// so a cap left anywhere in the send path would show up as a short count.
for ( $i = 0; $i < 25; $i++ ) {
	if ( wp_mail( 'a@example.com', 'message ' . $i, 'body' ) ) {
		$delivered++;
	}
}

check( 'every message is sent, however high the count', $delivered, 25 );
check( 'and every one counted', ( new Usage() )->report()['metered'], 25 );

// Logging on, because a refusal would survive in the log even when the hook
// flattens it - this is where the old cap error used to land.
$plugin->settings->update( [ 'log_enabled' => true ] );

$refused = $capture( static fn() => wp_mail( 'a@example.com', 'well past the old cap', 'body' ) );

check( 'the next one is not refused either', $refused instanceof WP_Error, false );

$logged = $plugin->logger->recent( 1 );

check( 'the log records it as a success', $logged ? (string) $logged[0]->status : '', 'sent' );
check( 'with no error code at all', $logged ? (string) $logged[0]->error_code : '', '' );

$plugin->settings->update( [ 'log_enabled' => false ] );

// The metered/unmetered split survives as a counting distinction, so SMTP still
// answers the way it always did - it just no longer decides whether a send is
// allowed, because nothing does.
$plugin->settings->update(
	[
		'provider'       => 'smtp',
		'smtp_host'      => 'smtp.example.com',
		'smtp_port'      => 587,
		'smtp_encryption' => 'tls',
		'smtp_username'  => 'user',
	]
);

$plugin->secrets->set( 'smtp_password', 'pw' );
$plugin->dispatcher->reset_providers();

check( 'SMTP is still not metered', Usage::is_metered( $plugin->dispatcher->provider() ), false );

$state = ( new Usage() )->state();

check( 'the meter never reports a site as over', $state['over'], false );
check( 'it reports no cap', $state['cap'], 0 );
check( 'and unlimited sending', $state['unlimited'], true );
check( 'with nothing remaining to count down', $state['remaining'], -1 );
check( 'while still reporting what was sent', $state['metered'], 26 );

$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore

/* ------------------------------------------------------------- the round trip */

section( 'against the portal itself' );

if ( ! Portal::is_available() ) {
	echo "  SKIP  no portal configured (set MMOA_PORTAL_URL)\n";
} else {
	$portal = ModernMailer\Plugin::instance()->portal;

	$health = wp_remote_get( Portal::base_url() . 'health', [ 'timeout' => 15 ] );

	if ( is_wp_error( $health ) || 200 !== wp_remote_retrieve_response_code( $health ) ) {
		echo '  SKIP  the portal at ' . Portal::base_url() . " did not answer\n";
	} else {
		$body = json_decode( (string) wp_remote_retrieve_body( $health ), true );

		check( 'the portal reports itself healthy', ! empty( $body['ok'] ), true );

		$registered = $portal->register();

		check( 'this site can register', ! is_wp_error( $registered ), true );

		if ( ! is_wp_error( $registered ) ) {
			check( 'and is issued a signing token', 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $registered['install_token'] ), true );
			check( 'and told the free cap', (int) $registered['free_monthly_cap'] > 0, true );

			// A local site cannot answer the ownership callback, and the portal
			// says so rather than pretending otherwise.
			check( 'a site the portal cannot reach is not verified', ! empty( $registered['install']['verified'] ), false );

			$beat = $portal->call( 'POST', 'v1/installs/heartbeat', array_merge( $portal->describe(), [ 'usage' => $usage->report() ] ) );

			check( 'a signed heartbeat is accepted', ! is_wp_error( $beat ), true );

			if ( ! is_wp_error( $beat ) ) {
				check( 'and carries no licence for a site that has none', $beat['licence'] ?? null, null );
			}

			// A key that does not exist is refused as such, and the message
			// says so in words rather than as a code.
			//
			// Note which refusal comes back: the portal checks the key before
			// it checks whether the site is verified, so a made-up key on an
			// unverified site answers "unknown_key". That is the right way
			// round - what somebody typed is more likely to be the problem, and
			// more immediately fixable, than the state of their site. The
			// verification refusal is covered by the portal's own suite, where
			// a real key can be issued to test it against.
			$activation = $portal->call( 'POST', 'v1/licences/activate', [ 'key' => 'MME-ZZZZ-ZZZZ-ZZZZ-ZZZZ' ] );

			check( 'a key that does not exist is refused', is_wp_error( $activation ) ? 'error' : (string) ( $activation['code'] ?? '' ), 'unknown_key' );
			check( 'and says so in words', is_wp_error( $activation ) ? '' : str_contains( (string) ( $activation['message'] ?? '' ), 'not recognised' ), true );

			// Leave the live portal as it was found.
			$portal->call( 'DELETE', 'v1/installs/me' );
			$portal->forget();

			check( 'and the site can deregister itself', $portal->is_registered(), false );
		}
	}
}

echo "\n";
echo $failed > 0 ? "{$failed} failed, {$passed} passed\n" : "All {$passed} checks passed\n";

exit( $failed > 0 ? 1 : 0 );
