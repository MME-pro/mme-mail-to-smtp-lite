<?php
/**
 * A fixed connection stops reading "Not delivering" before the next send.
 *
 * The report: a password was revoked, the emails failed, the password was put
 * right - and the header went on saying the site was not delivering until an
 * email happened to be sent. Saving the connection, a passing verification or a
 * new sign-in now resolves the failure; the header says "Sending normally" and,
 * because the email that failed is still lost, "The last email was not sent".
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
$health = $plugin->health;

$saved_settings = get_option( Settings::OPTION );
$saved_secrets  = get_option( 'mmoa_secrets' );
$saved_health   = get_option( 'mmoa_health' );

$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );

function payload(): array {
	return (array) ( rest_do_request( new WP_REST_Request( 'GET', '/modern-mailer/v1/bootstrap' ) )->get_data()['health'] ?? [] );
}

/** Fail as many times as it takes to count as an outage. */
function break_sending( $health ): void {
	$threshold = max( 1, (int) ModernMailer\Plugin::instance()->settings->get( 'alert_threshold' ) );

	for ( $i = 0; $i < $threshold; $i++ ) {
		$health->record_failure( new WP_Error( 'smtp_auth', '535 Authentication failed' ) );
	}
}

$plugin->settings->update( [ 'provider' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'from_email' => 'info@example.com' ] );
$plugin->settings->secrets()->set( 'smtp_password', 'right' );
Settings::flush_cache();

echo "\n=== 1. Failing sends read as failing ===\n";
$health->reset();
break_sending( $health );
check( 'is_failing()', $health->is_failing() );
check( 'the header is told', true === ( payload()['failing'] ?? null ) );
check( 'and that the last email failed', true === ( payload()['last_failed'] ?? null ) );

echo "\n=== 2. Saving the connection resolves it ===\n";
// Past the second, so the fix and the failure cannot share a timestamp; the
// comparison is by whole seconds.
sleep( 1 );
$recovered = did_action( 'mmoa_send_recovered' );
$request = new WP_REST_Request( 'POST', '/modern-mailer/v1/connections/primary' );
$request->set_param( 'provider', 'smtp' );
$request->set_param( 'smtp_host', 'smtp.example.com' );
$request->set_param( 'smtp_password', 'the new password' );
$saved = rest_do_request( $request );
check( 'the save went through', 200 === $saved->get_status(), (string) $saved->get_status() );
check( 'no longer failing', ! $health->is_failing() );
$p = payload();
check( 'the header goes green', false === ( $p['failing'] ?? null ) );
check( 'but still says the last email was not sent', true === ( $p['last_failed'] ?? null ) );
check( 'and keeps what went wrong', '535 Authentication failed' === ( $p['last_error'] ?? '' ) );
check( 'the recovery is not announced - nothing has been delivered yet', $recovered === did_action( 'mmoa_send_recovered' ) );

echo "\n=== 3. A failure after the fix counts again ===\n";
sleep( 1 );
$health->record_failure( new WP_Error( 'smtp_auth', '535 still wrong' ) );
check( 'failing again', $health->is_failing() );
check( 'the header says so', true === ( payload()['failing'] ?? null ) );

echo "\n=== 4. A failed verification undoes a resolution ===\n";
sleep( 1 );
do_action( 'mmoa_connection_saved', '' );
check( 'resolved by the save', ! $health->is_failing() );
$health->clear_resolved();
check( 'failing once verification fails', $health->is_failing() );

echo "\n=== 5. The next delivered email clears everything ===\n";
do_action( 'mmoa_connection_saved', '' );
$health->record_success();
$p = payload();
check( 'not failing', false === ( $p['failing'] ?? null ) );
check( 'and the last email went out', false === ( $p['last_failed'] ?? null ) );
check( 'the recovery is announced, once', $recovered + 1 === did_action( 'mmoa_send_recovered' ) );

echo "\n=== 6. A site that never failed is left alone ===\n";
$health->reset();
do_action( 'mmoa_connection_saved', '' );
check( 'nothing written for a healthy site', false === get_option( 'mmoa_health' ) );

echo "\n=== Cleanup ===\n";
update_option( Settings::OPTION, $saved_settings );
false === $saved_secrets ? delete_option( 'mmoa_secrets' ) : update_option( 'mmoa_secrets', $saved_secrets, false );
false === $saved_health ? delete_option( 'mmoa_health' ) : update_option( 'mmoa_health', $saved_health, false );
Settings::flush_cache();
check( 'settings restored', get_option( Settings::OPTION ) === $saved_settings );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
