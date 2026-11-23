<?php
/**
 * The plugin's own encryption key.
 *
 * Credentials were encrypted with a key derived from AUTH_KEY and
 * SECURE_AUTH_SALT, which "log everybody out" and "revoke all passwords"
 * regenerate - so securing a site silently emptied every connection. They are
 * now encrypted with 32 random bytes the plugin keeps in wp-content (or in
 * wp-config.php as MMOA_ENCRYPTION_KEY), and the salts are not involved.
 *
 * The salts cannot be changed inside one PHP process, so their change is
 * simulated the way it looks on disk: a v1 value sealed with a different key.
 * The real key file and credentials are put back at the end.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Secrets;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();
$file   = Secrets::key_file();

// Put the site's own state aside.
$saved_file     = is_file( $file ) ? file_get_contents( $file ) : null;
$saved_secrets  = get_option( 'mmoa_secrets' );
$saved_settings = get_option( Settings::OPTION );
$saved_flag     = get_option( 'mmoa_secrets_migrated' );

function raw( string $key ): string {
	return (string) ( get_option( 'mmoa_secrets', [] )[ $key ] ?? '' );
}

function file_key(): string {
	preg_match( '/MMOA_KEY:([A-Za-z0-9+\/=]+)/', (string) @file_get_contents( Secrets::key_file() ), $m );
	return (string) base64_decode( $m[1] ?? '', true );
}

function seal_v1( string $value, string $key ): string {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) );
}

function open_with( string $stored, string $key ): string {
	$raw   = base64_decode( substr( $stored, 3 ), true );
	$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
	return false === $plain ? '' : $plain;
}

$salt_key = hash( 'sha256', AUTH_KEY . '|mmoa|' . SECURE_AUTH_SALT, true );
$secrets  = $plugin->settings->secrets();

echo "\n=== 1. The first credential creates the key ===\n";
@unlink( $file );
Secrets::reset_key();
check( 'no key file to begin with', ! is_file( $file ) );
$secrets->set( 'smtp_password', 'correct horse' );
check( 'a key file now exists', is_file( $file ) );
check( 'holding 32 random bytes', 32 === strlen( file_key() ), (string) strlen( file_key() ) );
check( 'the file exits before anything a browser could read', 0 === strpos( (string) file_get_contents( $file ), "<?php\n" ) && false !== strpos( (string) file_get_contents( $file ), "exit;\n" ) );
check( 'the credential is stored as v2', 0 === strpos( raw( 'smtp_password' ), 'v2:' ), substr( raw( 'smtp_password' ), 0, 3 ) );
check( 'and reads back', 'correct horse' === $secrets->get( 'smtp_password' ) );

echo "\n=== 2. The salts play no part in a v2 value ===\n";
check( 'it opens with the file key alone', 'correct horse' === open_with( raw( 'smtp_password' ), file_key() ) );
check( 'and does not open with the salts', '' === open_with( raw( 'smtp_password' ), $salt_key ) );

echo "\n=== 3. Old credentials move to the new key ===\n";
$stored = get_option( 'mmoa_secrets', [] );
$stored['api_key']               = seal_v1( 'readable before the update', $salt_key );
$stored['backup_smtp_password']  = seal_v1( 'the backup connection too', $salt_key );
$stored['alerts_slack_webhook']  = seal_v1( 'and an alert webhook', $salt_key );
$stored['mailgun_api_key']       = seal_v1( 'sealed before the salts changed', hash( 'sha256', 'old|mmoa|salts', true ) );
update_option( 'mmoa_secrets', $stored, false );
delete_option( 'mmoa_secrets_migrated' );

$moved = Secrets::migrate();
check( 'three readable v1 values were moved', 3 === $moved, (string) $moved );
check( 'each is v2 now', 0 === strpos( raw( 'api_key' ), 'v2:' ) && 0 === strpos( raw( 'backup_smtp_password' ), 'v2:' ) && 0 === strpos( raw( 'alerts_slack_webhook' ), 'v2:' ) );
check( 'and reads back unchanged', 'readable before the update' === $secrets->get( 'api_key' ) );
check( 'in another slot as well', 'the backup connection too' === $secrets->for_slot( 'backup' )->get( 'smtp_password' ) );
check( 'one sealed with salts that already changed is left as it was', 0 === strpos( raw( 'mailgun_api_key' ), 'v1:' ) );
check( 'and called unreadable, because of the salts', $secrets->is_unreadable( 'mailgun_api_key' ) && 'salts' === $secrets->unreadable_reason( 'mailgun_api_key' ) );
check( 'the migration is marked done', (bool) get_option( 'mmoa_secrets_migrated' ) );
check( 'and does not run twice', 0 === Secrets::migrate() );

echo "\n=== 4. A lost key file is reported as such ===\n";
$kept = file_get_contents( $file );
unlink( $file );
Secrets::reset_key();
// A new key is minted in its place, so a password entered again works; the
// ones sealed with the lost key stay unreadable, and say why.
check( 'the credential cannot be read', '' === $secrets->get( 'smtp_password' ) );
check( 'is_unreadable() says so', $secrets->is_unreadable( 'smtp_password' ) );
check( 'naming the missing key as the reason', 'key' === $secrets->unreadable_reason( 'smtp_password' ) );
file_put_contents( $file, $kept );
Secrets::reset_key();
check( 'restoring the file brings it back', 'correct horse' === $secrets->get( 'smtp_password' ) );

echo "\n=== 5. Two requests creating the key at once agree ===\n";
// The loser of the race finds a file already there and must use it, not its
// own freshly minted key.
unlink( $file );
$winner = random_bytes( 32 );
file_put_contents( $file, "<?php\nexit;\n// MMOA_KEY:" . base64_encode( $winner ) . "\n" );
Secrets::reset_key();
$secrets->set( 'smtp_user_probe', 'x' );
check( 'the value was sealed with the key already on disk', 'x' === open_with( raw( 'smtp_user_probe' ), $winner ) );
$secrets->set( 'smtp_user_probe', '' );
file_put_contents( $file, $kept );
Secrets::reset_key();

echo "\n=== 6. The health payload names the reason ===\n";
$plugin->settings->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.example.com', 'from_email' => 'info@example.com' ] );
Settings::flush_cache();
unlink( $file );
Secrets::reset_key();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );
$health = rest_do_request( new WP_REST_Request( 'GET', '/modern-mailer/v1/bootstrap' ) )->get_data()['health'] ?? [];
check( 'the connection is reported', 1 === count( $health['unreadable'] ?? [] ), wp_json_encode( $health['unreadable'] ?? null ) );
check( 'with reason "key"', 'key' === ( $health['unreadable'][0]['reason'] ?? '' ) );
file_put_contents( $file, $kept );
Secrets::reset_key();

echo "\n=== 7. A key in wp-config.php wins over the file ===\n";
define( 'MMOA_ENCRYPTION_KEY', 'a passphrase the site keeps in wp-config.php' );
Secrets::reset_key();
$secrets->set( 'smtp_password', 'from the constant' );
$constant_key = hash( 'sha256', 'mmoa|' . MMOA_ENCRYPTION_KEY, true );
check( 'sealed with the constant', 'from the constant' === open_with( raw( 'smtp_password' ), $constant_key ) );
check( 'not with the file', '' === open_with( raw( 'smtp_password' ), file_key() ) );
check( 'and reads back', 'from the constant' === $secrets->get( 'smtp_password' ) );
check( 'what the file key sealed before the constant still reads', 'readable before the update' === $secrets->get( 'api_key' ) );

echo "\n=== Cleanup ===\n";
null === $saved_file ? @unlink( $file ) : file_put_contents( $file, $saved_file );
false === $saved_secrets ? delete_option( 'mmoa_secrets' ) : update_option( 'mmoa_secrets', $saved_secrets, false );
false === $saved_flag ? delete_option( 'mmoa_secrets_migrated' ) : update_option( 'mmoa_secrets_migrated', $saved_flag, true );
update_option( Settings::OPTION, $saved_settings );
Settings::flush_cache();
check( 'key file restored', ( null === $saved_file ) ? ! is_file( $file ) : file_get_contents( $file ) === $saved_file );
check( 'credentials restored', get_option( 'mmoa_secrets' ) === $saved_secrets );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
