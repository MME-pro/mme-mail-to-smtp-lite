<?php
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();

echo "\n=== Credential storage ===\n";
check( 'libsodium available', $plugin->secrets->is_encryption_available() );

$plugin->secrets->set( 'google_sa_key', 'SuperSecretValue123' );
check( 'round-trips correctly', 'SuperSecretValue123' === $plugin->secrets->get( 'google_sa_key' ) );

global $wpdb;
$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) );
check( 'ciphertext in the database, not the plaintext', false === strpos( (string) $raw, 'SuperSecretValue123' ), substr( (string) $raw, 0, 60 ) );
check( 'stored value is versioned', false !== strpos( (string) $raw, 'v1:' ) );

$plugin->secrets->set( 'google_sa_key', 'Different' );
check( 'nonce is per-write, so rewriting the same value differs', true );
$a = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) );
$plugin->secrets->set( 'google_sa_key', 'Different' );
$b = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) );
check( 'identical plaintext encrypts to different ciphertext', $a !== $b );

$plugin->secrets->set( 'google_sa_key', '' );
check( 'empty value clears the credential', '' === $plugin->secrets->get( 'google_sa_key' ) );

echo "\n=== Constant precedence ===\n";
define( 'MMOA_GOOGLE_SA_CLIENT_EMAIL', 'sa-from-wp-config@project.iam.gserviceaccount.com' );
$plugin->settings->update( [ 'google_sa_email' => 'sa-from-db@project.iam.gserviceaccount.com' ] );
check( 'constant wins over the database', 'sa-from-wp-config@project.iam.gserviceaccount.com' === $plugin->settings->get( 'google_sa_email' ),
	(string) $plugin->settings->get( 'google_sa_email' ) );
check( 'the UI can tell it is pinned', $plugin->settings->is_constant( 'google_sa_email' ) );

echo "\n=== Inactive by default ===\n";
$plugin->settings->update( [ 'provider' => '' ] );
check( 'is_active() false with no provider', ! $plugin->settings->is_active() );
unset( $GLOBALS['phpmailer'] );
$plugin->install_mailer();
check( 'does not hijack PHPMailer when unconfigured', ! isset( $GLOBALS['phpmailer'] ) );

echo "\n=== Admin surface ===\n";
$admin_id = $wpdb->get_var( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' LIMIT 1" );
wp_set_current_user( (int) $admin_id );
check( 'test user is an administrator', current_user_can( 'manage_options' ) );

// Every screen belongs to the admin app. The one piece of server-rendered
// admin left is the Google handshake, because it navigates the browser away
// and comes back as a top-level GET. What the app needs from it is two
// nonce-signed URLs it can use as an href.
$urls = ModernMailer\Admin\Admin_Page::google_urls( ModernMailer\Settings::SLOT_PRIMARY );
check( 'connect URL posts to admin-post.php', false !== strpos( $urls['connect'], 'admin-post.php' ), $urls['connect'] );
check( 'connect URL names the connect action', false !== strpos( $urls['connect'], 'action=mmoa_connect_google' ) );
check( 'disconnect URL names the disconnect action', false !== strpos( $urls['disconnect'], 'action=mmoa_disconnect_google' ) );
check( 'both URLs are nonce-signed', false !== strpos( $urls['connect'], '_wpnonce=' ) && false !== strpos( $urls['disconnect'], '_wpnonce=' ) );

// These are serialised into JSON and assigned as an href by React, which
// decodes no entities - so an HTML-escaped separator would arrive verbatim and
// the nonce would be parsed under the name "amp;_wpnonce".
check( 'URLs are not HTML-escaped', false === strpos( $urls['connect'], '&amp;' ), $urls['connect'] );

// The notice raised while sending is broken has to link somewhere that exists.
check( 'the settings link points at the registered page',
	ModernMailer\Admin\Admin_Page::url() === admin_url( 'admin.php?page=' . ModernMailer\Admin\App_Page::SLUG ),
	ModernMailer\Admin\Admin_Page::url() );

// The redirect URI must not depend on where the menu lives, or reorganising the
// admin breaks every existing Google connection.
$redirect = ModernMailer\Auth\Google_Consent::redirect_uri();
check( 'OAuth redirect URI points at admin-post.php', false !== strpos( $redirect, 'admin-post.php?action=mmoa_google_callback' ), $redirect );
check( 'OAuth redirect URI does not reference a menu page', false === strpos( $redirect, 'page=' ), $redirect );

echo "\n=== Site Health ===\n";
$sh = ( new ModernMailer\Admin\Site_Health( $plugin ) )->run_test();
check( 'reports unconfigured as a recommendation', 'recommended' === $sh['status'], $sh['status'] );

echo "\n=== A stored credential can be read back ===\n";
// The field shows the saved value masked, with an eye to reveal it, so the
// value has to reach the browser. Withholding it left an administrator unable
// to check what had been saved - a key pasted with a truncated tail looks
// exactly like a correct one, and the only way to find out was to send a
// message and read the error.
$plugin->settings->update( [ 'provider' => 'brevo' ] );
$plugin->secrets->set( 'brevo_api_key', 'xkeysib-SECRET-VALUE-1234' );
ModernMailer\Settings::flush_cache();

$catalogue = ModernMailer\Provider_Registry::to_array( $plugin->settings );
$brevo     = null;

foreach ( $catalogue as $entry ) {
	if ( 'brevo' === $entry['slug'] ) {
		$brevo = $entry;
	}
}

$api_key = null;

foreach ( $brevo['fields'] ?? [] as $f ) {
	if ( 'brevo_api_key' === $f['key'] ) {
		$api_key = $f;
	}
}

check( 'the field is published', null !== $api_key );
check( 'it is still marked secret, so the form masks it', true === ( $api_key['secret'] ?? false ) );
check( 'it reports that one is stored', true === ( $api_key['is_set'] ?? false ) );
check( 'and it carries the stored value', 'xkeysib-SECRET-VALUE-1234' === ( $api_key['value'] ?? '' ), wp_json_encode( $api_key['value'] ?? null ) );

// Each connection keeps its own, which is the whole basis of having more than
// one - if the slots ever aliased, revealing the backup would show the primary.
$plugin->secrets->for_slot( 'backup' )->set( 'brevo_api_key', 'xkeysib-BACKUP-9999' );
ModernMailer\Settings::flush_cache();

$backup_key = null;

foreach ( ModernMailer\Provider_Registry::to_array( $plugin->settings->for_slot( 'backup' ) ) as $entry ) {
	if ( 'brevo' !== $entry['slug'] ) {
		continue;
	}
	foreach ( $entry['fields'] as $f ) {
		if ( 'brevo_api_key' === $f['key'] ) {
			$backup_key = $f['value'];
		}
	}
}

check( 'the backup carries its own key', 'xkeysib-BACKUP-9999' === $backup_key, wp_json_encode( $backup_key ) );
check( 'and the primary still carries its own', 'xkeysib-SECRET-VALUE-1234' === $plugin->secrets->get( 'brevo_api_key' ) );

$plugin->secrets->set( 'brevo_api_key', '' );
$plugin->secrets->for_slot( 'backup' )->set( 'brevo_api_key', '' );
ModernMailer\Settings::flush_cache();

$empty = null;

foreach ( ModernMailer\Provider_Registry::to_array( $plugin->settings ) as $entry ) {
	if ( 'brevo' !== $entry['slug'] ) {
		continue;
	}
	foreach ( $entry['fields'] as $f ) {
		if ( 'brevo_api_key' === $f['key'] ) {
			$empty = $f;
		}
	}
}

check( 'an unset credential reports nothing stored', false === ( $empty['is_set'] ?? true ) );
check( 'and carries an empty value', '' === ( $empty['value'] ?? 'x' ) );

echo "\n=== Restoring a clean state on this site ===\n";
$plugin->settings->update( [ 'provider' => '', 'google_sa_email' => '', 'google_client_id' => '', 'google_sender' => '',
	'google_sa_email' => '', 'google_sender' => '', 'google_client_id' => '', 'from_email' => '', 'from_name' => '' ] );
$plugin->secrets->flush();
$plugin->tokens->flush();
$plugin->health->reset();
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mmoa_log" );
check( 'plugin left inactive-by-config', ! $plugin->settings->is_active() );
check( 'no credentials remain', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mmoa_secrets' ) ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
