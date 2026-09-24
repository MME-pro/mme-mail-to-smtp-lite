<?php
/**
 * Disconnecting a connection empties it.
 *
 * The behaviour this guards is easy to half-implement and hard to notice when
 * it is wrong. Disconnect used to clear the provider and the declared
 * credentials, which looks complete on screen: the form goes blank because
 * nothing is selected. Underneath, the From address, the setup mode, every
 * non-secret provider field and - worst - the refresh tokens were all still in
 * the database, waiting to be shown to whoever configured the slot next.
 *
 * So this asserts absence rather than presence, and it does it after filling
 * the slot with every kind of value a connection can hold: a plain setting, a
 * declared credential, a flow-written credential nobody types, and the two
 * common fields that belong to the connection rather than to a provider.
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

/**
 * Fill one slot with something of every shape.
 */
function fill( string $slot ): void {
	$plugin = ModernMailer\Plugin::instance();
	$scoped = $plugin->settings->for_slot( $slot );

	// SLOT_PRIMARY is the empty string, so the slot itself cannot be used to
	// make values distinguishable.
	$label = '' === $slot ? 'primary' : $slot;

	$scoped->update(
		[
			'provider'        => 'microsoft',
			'from_email'      => "billing-{$label}@example.com",
			'from_name'       => "Accounts {$label}",
			'force_from'      => false,
			'ms_setup_mode'   => 'own_signin',
			'ms_tenant_id'    => 'tenant-' . $label,
			'ms_client_id'    => 'client-' . $label,
			'ms_sender'       => "shared-{$label}@example.com",
			'msoauth_account' => "signed-in-{$label}@example.com",
			'smtp_host'       => 'smtp.example.com',
			'zoho_region'     => 'eu',
		]
	);

	$secrets = $scoped->secrets();
	$secrets->set( 'ms_client_secret', 'entra-secret-' . $label );
	$secrets->set( 'msoauth_client_sec', 'delegated-secret-' . $label );
	$secrets->set( 'smtp_password', 'smtp-password-' . $label );

	// The ones no form declares, written by an authorization flow. These are
	// the values the old disconnect left behind.
	$secrets->set( 'google_refresh', 'google-refresh-' . $label );
	$secrets->set( 'ms_refresh', 'ms-refresh-' . $label );
	$secrets->set( 'msoauth_refresh', 'msoauth-refresh-' . $label );

	Settings::flush_cache();
}

echo "\n=== 1. A filled connection reads back everything ===\n";
fill( Settings::SLOT_PRIMARY );

check( 'the provider is set', 'microsoft' === (string) $plugin->settings->get( 'provider' ) );
check( 'the From address is set', 'billing-primary@example.com' === (string) $plugin->settings->get( 'from_email' ) );
check( 'a credential is stored', 'entra-secret-primary' === $plugin->secrets->get( 'ms_client_secret' ) );
check( 'and a refresh token is stored', 'msoauth-refresh-primary' === $plugin->secrets->get( 'msoauth_refresh' ) );

echo "\n=== 2. Disconnecting deletes all of it ===\n";
$plugin->settings->reset_connection();
Settings::flush_cache();

foreach (
	[
		'provider',
		'from_email',
		'from_name',
		'ms_tenant_id',
		'ms_client_id',
		'ms_sender',
		'msoauth_account',
		'smtp_host',
	] as $key
) {
	check( "{$key} is empty", '' === (string) $plugin->settings->get( $key ), (string) $plugin->settings->get( $key ) );
}

foreach (
	[
		'ms_client_secret',
		'msoauth_client_sec',
		'smtp_password',
		'google_refresh',
		'ms_refresh',
		'msoauth_refresh',
	] as $key
) {
	check( "the {$key} credential is gone", '' === $plugin->secrets->get( $key ), 'still set' );
}

echo "\n=== 3. Deleted, not blanked ===\n";
// The distinction that matters: a key holding "" reads back as "", while a key
// that was never written reads back as whatever the schema declares. Blanking
// would leave a disconnected connection sitting on values a fresh one never has.
check(
	'force_from is back at its default rather than left off',
	true === $plugin->settings->get( 'force_from' ),
	var_export( $plugin->settings->get( 'force_from' ), true )
);
check(
	'a provider field with a declared default returns to it',
	'com' === (string) $plugin->settings->get( 'zoho_region' ),
	(string) $plugin->settings->get( 'zoho_region' )
);
check(
	'the Microsoft setup mode is back at its default',
	'own_signin' === (string) $plugin->settings->get( 'ms_setup_mode' ),
	(string) $plugin->settings->get( 'ms_setup_mode' )
);
check( 'and the connection is inactive', ! $plugin->settings->is_active() );

echo "\n=== 4. Only that connection ===\n";
// Two slots share one option row, distinguished by a key prefix. A reset that
// got the prefix wrong would empty the primary while clearing the backup, and
// the site would stop sending with nothing on screen to explain it.
fill( Settings::SLOT_PRIMARY );
fill( 'backup' );

$plugin->settings->for_slot( 'backup' )->reset_connection();
Settings::flush_cache();

check( 'the backup is empty', '' === (string) $plugin->settings->for_slot( 'backup' )->get( 'from_email' ) );
check( 'its credentials are gone', '' === $plugin->secrets->for_slot( 'backup' )->get( 'ms_client_secret' ) );

check(
	'the primary is untouched',
	'billing-primary@example.com' === (string) $plugin->settings->get( 'from_email' ),
	(string) $plugin->settings->get( 'from_email' )
);
check( 'and keeps its credentials', 'entra-secret-primary' === $plugin->secrets->get( 'ms_client_secret' ) );
check( 'and keeps its refresh token', 'msoauth-refresh-primary' === $plugin->secrets->get( 'msoauth_refresh' ) );

echo "\n=== 5. The site's own settings are not connection settings ===\n";
// Log retention, the connection list and the routing rules belong to the site.
// Clearing a connection must not reach them - the connection list in
// particular holds the names, and losing it would delete the connections
// themselves rather than empty one.
$plugin->settings->update( [ 'log_retention' => 21, 'log_enabled' => true ] );
Settings::flush_cache();

$connections_before = $plugin->settings->get( 'connections' );

$plugin->settings->reset_connection();
Settings::flush_cache();

check( 'log retention survives', 21 === (int) $plugin->settings->get( 'log_retention' ) );
check( 'logging stays on', true === $plugin->settings->get( 'log_enabled' ) );
check(
	'and the connection list is intact, so no connection was deleted',
	$connections_before === $plugin->settings->get( 'connections' ),
	wp_json_encode( $plugin->settings->get( 'connections' ) )
);

echo "\n=== 6. Restoring a clean state ===\n";
$plugin->settings->for_slot( 'backup' )->reset_connection();
$plugin->settings->reset_connection();
$plugin->settings->update( [ 'log_retention' => 30 ] );
$plugin->tokens->flush();
$plugin->health->reset();
Settings::flush_cache();

check( 'left inactive', ! $plugin->settings->is_active() );
check( 'no credentials remain', '' === $plugin->secrets->get( 'ms_client_secret' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
