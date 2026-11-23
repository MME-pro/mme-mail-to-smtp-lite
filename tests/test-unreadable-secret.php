<?php
/**
 * A saved password the site can no longer decrypt is reported before a send.
 *
 * Credentials are encrypted with a key derived from AUTH_KEY and
 * SECURE_AUTH_SALT. Changing those - "log everybody out", "revoke all
 * passwords" - leaves every saved credential unreadable, and get() answers ''
 * for it just as for one never entered. Health only learned of it from the next
 * failed send, so the header said "Sending normally" until then.
 *
 * The key change is simulated by writing a value sealed with a different key,
 * which is exactly what the stored value looks like after the salts move.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

/** Write a credential as it would read after the site's keys changed. */
function seal_with_old_key( string $storage_key, string $value ): void {
	$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$old    = hash( 'sha256', 'the-key-before|mmoa|the-salt-before', true );
	$stored = get_option( 'mmoa_secrets', [] );
	$stored = is_array( $stored ) ? $stored : [];

	$stored[ $storage_key ] = 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $old ) );
	update_option( 'mmoa_secrets', $stored, false );
}

/** What the header and the dashboard are given. */
function health(): array {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) $admins[0] );
	$data = rest_do_request( new WP_REST_Request( 'GET', '/modern-mailer/v1/bootstrap' ) )->get_data();

	return (array) ( $data['health'] ?? [] );
}

$saved_settings = get_option( Settings::OPTION );
$saved_secrets  = get_option( 'mmoa_secrets' );

$plugin->settings->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.example.com', 'from_email' => 'info@example.com' ] );
$plugin->settings->secrets()->set( 'smtp_password', 'correct horse' );
Settings::flush_cache();

echo "\n=== 1. A readable password is not reported ===\n";
check( 'the password reads back', 'correct horse' === $plugin->settings->secrets()->get( 'smtp_password' ) );
check( 'and is not called unreadable', ! $plugin->settings->secrets()->is_unreadable( 'smtp_password' ) );
check( 'the connection has nothing unreadable', [] === $plugin->settings->unreadable_credentials() );
check( 'health reports nothing', [] === ( health()['unreadable'] ?? null ), wp_json_encode( health()['unreadable'] ?? null ) );

echo "\n=== 2. After the site's keys change, it is ===\n";
seal_with_old_key( 'smtp_password', 'correct horse' );
Settings::flush_cache();
check( 'get() can no longer read it', '' === $plugin->settings->secrets()->get( 'smtp_password' ) );
check( 'is_unreadable() says so', $plugin->settings->secrets()->is_unreadable( 'smtp_password' ) );
$unreadable = $plugin->settings->unreadable_credentials();
check( 'the connection names the field', isset( $unreadable['smtp_password'] ), wp_json_encode( $unreadable ) );
$h = health();
check( 'health carries it for the header', 1 === count( $h['unreadable'] ?? [] ), wp_json_encode( $h['unreadable'] ?? null ) );
check( 'naming the connection', __( 'Primary', 'mme-mail-to-smtp' ) === ( $h['unreadable'][0]['connection'] ?? '' ), (string) ( $h['unreadable'][0]['connection'] ?? '' ) );
check( 'while health still says it is not failing - nothing has been sent', false === ( $h['failing'] ?? null ) );

echo "\n=== 3. A password never entered is not unreadable ===\n";
$plugin->settings->secrets()->set( 'smtp_password', '' );
check( 'absent is not unreadable', ! $plugin->settings->secrets()->is_unreadable( 'smtp_password' ) );
check( 'and health is quiet', [] === ( health()['unreadable'] ?? null ) );

echo "\n=== 4. Another provider's leftover credential raises nothing ===\n";
// A key the SMTP connection does not use, sealed with the old key: it is not
// what sends mail, so it must not turn the header red.
seal_with_old_key( 'api_key', 'left over from a previous provider' );
check( 'it is unreadable', $plugin->settings->secrets()->is_unreadable( 'api_key' ) );
check( 'but not reported for an SMTP connection', [] === $plugin->settings->unreadable_credentials() );

echo "\n=== 5. Re-entering it clears the warning ===\n";
seal_with_old_key( 'smtp_password', 'correct horse' );
$plugin->settings->secrets()->set( 'smtp_password', 'correct horse' );
check( 'readable again', 'correct horse' === $plugin->settings->secrets()->get( 'smtp_password' ) );
check( 'health is quiet again', [] === ( health()['unreadable'] ?? null ) );

echo "\n=== Cleanup ===\n";
update_option( Settings::OPTION, $saved_settings );
false === $saved_secrets ? delete_option( 'mmoa_secrets' ) : update_option( 'mmoa_secrets', $saved_secrets, false );
Settings::flush_cache();
check( 'settings restored', get_option( Settings::OPTION ) === $saved_settings );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
