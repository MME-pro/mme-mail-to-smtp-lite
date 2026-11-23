<?php
/**
 * Mail refused by a broken connection waits for the fix, then goes out.
 *
 * A revoked password or API key used to be a permanent failure: the email was
 * dropped on the spot, never queued, never retried - every message sent while
 * the connection was broken was lost. Connection faults are now held in the
 * queue, and saving, verifying or signing in again sends them straight away.
 *
 * Brevo stands in for any API provider: a 401 is a bad key, a 400 a bad
 * message. Nothing leaves the machine.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Failure;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

$plugin = ModernMailer\Plugin::instance();
ModernMailer\Queue::install();

$saved_settings = get_option( Settings::OPTION );
$saved_secrets  = get_option( 'mmoa_secrets' );
$saved_health   = get_option( 'mmoa_health' );

remove_all_filters( 'mmoa_fallback_slot' );

$plugin->settings->update( [ 'provider' => 'brevo', 'from_email' => 'noreply@contoso.com', 'queue_enabled' => true ] );
$plugin->secrets->set( 'brevo_api_key', 'xkeysib-revoked' );
$plugin->install_mailer();

function reset_state( ModernMailer\Plugin $plugin ): void {
	$plugin->tokens->flush();
	$plugin->health->reset();
	$plugin->queue->purge();
	$plugin->dispatcher->reset_providers();
	Settings::flush_cache();
}

/** What Brevo answers: 401 for a revoked key, 400 for a bad message, 201 when sent. */
$answer = 401;
$hits   = 0;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$answer, &$hits ) {
	if ( false === strpos( $url, 'brevo.com' ) ) {
		return $pre;
	}
	++$hits;
	$bodies = [
		401 => [ 'code' => 'unauthorized', 'message' => 'Key not found' ],
		400 => [ 'code' => 'invalid_parameter', 'message' => 'email is not valid in to' ],
		201 => [ 'messageId' => '<1@brevo>' ],
	];
	return [ 'headers' => [], 'body' => wp_json_encode( $bodies[ $answer ] ), 'response' => [ 'code' => $answer, 'message' => '' ], 'cookies' => [], 'filename' => null ];
}, 10, 3 );

echo "\n=== 1. Which failures are the connection's ===\n";
check( 'an SMTP authentication failure', Failure::is_connection_fault( new WP_Error( 'mmoa_smtp_auth_failed', '535' ) ) );
check( 'an expired Microsoft grant', Failure::is_connection_fault( new WP_Error( 'mmoa_ms_grant_expired', 'x' ) ) );
check( 'an Entra token error', Failure::is_connection_fault( new WP_Error( 'mmoa_aad_aadsts7000222', 'x' ) ) );
check( 'any 401 from an API', Failure::is_connection_fault( new WP_Error( 'mmoa_whatever', 'x', [ 'status' => 401 ] ) ) );
check( 'not a refused recipient', ! Failure::is_connection_fault( new WP_Error( 'mmoa_no_recipient', 'x' ) ) );
check( 'not a message that is too large', ! Failure::is_connection_fault( new WP_Error( 'mmoa_message_too_large', 'x' ) ) );

echo "\n=== 2. A send refused for a revoked key is held, not lost ===\n";
reset_state( $plugin );
$answer = 401;
$hits   = 0;
$sent   = wp_mail( 'kunde@example.org', 'Buchungsbestätigung', 'Ihre Buchung ist bestätigt.' );
$stats  = $plugin->queue->stats();
check( 'Brevo was asked', $hits > 0 );
check( 'the message is in the queue', 1 === $stats['pending'], wp_json_encode( $stats ) );
check( 'wp_mail() reports it accepted, as for any queued message', true === $sent );
check( 'and the failure is still recorded for the header', 1 <= (int) $plugin->health->state()['streak'] && $plugin->health->last_failed() );

echo "\n=== 3. While the key is still wrong, it keeps waiting ===\n";
// Make it due and drain: still refused - but not abandoned after one go.
$plugin->queue->wake();
$plugin->queue->drain( $plugin->dispatcher );
$stats = $plugin->queue->stats();
check( 'still pending', 1 === $stats['pending'], wp_json_encode( $stats ) );
check( 'not abandoned', 0 === $stats['failed'] );

echo "\n=== 4. Fixing the connection sends it ===\n";
$answer = 201;
$plugin->secrets->set( 'brevo_api_key', 'xkeysib-new' );
wp_clear_scheduled_hook( 'mmoa_retry_held' );
do_action( 'mmoa_connection_saved', Settings::SLOT_PRIMARY );
check( 'a retry is scheduled for the next request', false !== wp_next_scheduled( 'mmoa_retry_held' ) );
$hits = 0;
do_action( 'mmoa_retry_held' );
$stats = $plugin->queue->stats();
check( 'it went out', $hits > 0 && 0 === $stats['pending'], wp_json_encode( $stats ) );
check( 'and the queue is empty', 0 === $stats['pending'] && 0 === $stats['failed'] );
wp_clear_scheduled_hook( 'mmoa_retry_held' );

echo "\n=== 5. A failure of the message itself is not held ===\n";
reset_state( $plugin );
$answer = 400;
$sent   = wp_mail( 'not-an-address', 'Kaputt', 'x' );
check( 'wp_mail() reports the failure', false === $sent );
check( 'nothing is queued', 0 === $plugin->queue->stats()['pending'] );

echo "\n=== 6. Nothing waiting, nothing scheduled ===\n";
reset_state( $plugin );
wp_clear_scheduled_hook( 'mmoa_retry_held' );
do_action( 'mmoa_connection_saved', Settings::SLOT_PRIMARY );
check( 'no pointless cron event', false === wp_next_scheduled( 'mmoa_retry_held' ) );

echo "\n=== Cleanup ===\n";
reset_state( $plugin );
update_option( Settings::OPTION, $saved_settings );
false === $saved_secrets ? delete_option( 'mmoa_secrets' ) : update_option( 'mmoa_secrets', $saved_secrets, false );
false === $saved_health ? delete_option( 'mmoa_health' ) : update_option( 'mmoa_health', $saved_health, false );
Settings::flush_cache();
check( 'settings restored', get_option( Settings::OPTION ) === $saved_settings );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
