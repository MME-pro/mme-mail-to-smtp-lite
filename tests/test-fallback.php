<?php
/**
 * The seams an add-on uses to add a fallback connection.
 *
 * Free sends on the primary and nothing else. Pro's backup arrives through
 * three seams: `mmoa_connections` declares the slot, `mmoa_fallback_slot`
 * names it when a send fails, and `mmoa_backup_used` reports that it carried
 * a message. This suite stands in for the add-on with its own filters, so it
 * proves the seams whatever Pro's licence says, and proves free alone does not
 * reach for a second slot.
 *
 * Primary: Google service account. Backup: Brevo. Different hosts, so the stub
 * can tell which connection a request was meant for.
 */

require __DIR__ . '/bootstrap.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

use ModernMailer\Settings;

$plugin = ModernMailer\Plugin::instance();

ModernMailer\Queue::install();

// Whatever an active Pro registered is set aside: this suite is the add-on.
remove_all_filters( 'mmoa_connections' );
remove_all_filters( 'mmoa_fallback_slot' );
remove_all_actions( 'mmoa_backup_used' );

$plugin->settings->update( [
	'provider'        => 'gmail_sa',
	'from_email'      => 'noreply@contoso.com',
	'google_sa_email' => 'sa@project.iam.gserviceaccount.com',
	'google_sender'   => 'noreply@contoso.com',
	'queue_enabled'   => true,
] );
$plugin->secrets->set( 'google_sa_key', file_get_contents( __DIR__ . '/test-sa-key.pem' ) );

$backup = $plugin->settings->for_slot( Settings::SLOT_BACKUP );
$backup->update( [ 'provider' => 'brevo', 'from_email' => 'noreply@contoso.com' ] );
$backup->secrets()->set( 'brevo_api_key', 'xkeysib-test' );

$plugin->install_mailer();

function reset_state( ModernMailer\Plugin $plugin ): void {
	$plugin->tokens->flush();
	$plugin->health->reset();
	$plugin->queue->purge();
	$plugin->dispatcher->reset_providers();
	Settings::flush_cache();
}

function json_response( int $code, array $body ): array {
	return [ 'headers' => [], 'body' => wp_json_encode( $body ),
		'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
}

/** Which service each request went to, and what to answer. */
$hits      = [ 'google' => 0, 'brevo' => 0 ];
$google_up = true;
$brevo_up  = true;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$hits, &$google_up, &$brevo_up ) {
	if ( false !== strpos( $url, 'oauth2.googleapis' ) ) {
		return json_response( 200, [ 'access_token' => 'T', 'expires_in' => 3600 ] );
	}
	if ( false !== strpos( $url, 'googleapis' ) ) {
		$hits['google']++;
		return $google_up
			? json_response( 200, [ 'id' => 'g' ] )
			: new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds' );
	}
	if ( false !== strpos( $url, 'api.brevo.com' ) ) {
		$hits['brevo']++;
		return $brevo_up
			? json_response( 201, [ 'messageId' => '<b@brevo>' ] )
			: new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds' );
	}
	return $pre;
}, 10, 3 );

$attempts = [];
add_action( 'mmoa_send_attempted', function ( $provider, $mailer, $bytes, $result, $slot ) use ( &$attempts ) {
	$attempts[] = $slot;
}, 10, 5 );

$used = [];
add_action( 'mmoa_backup_used', function ( $error, $context ) use ( &$used ) {
	$used[] = $context;
}, 10, 2 );

/** What an add-on like Pro does to declare and name a backup. */
function declare_backup(): void {
	add_filter( 'mmoa_connections', fn( $c ) => array_merge( (array) $c, [ [
		'id' => 'backup', 'name' => 'Backup', 'role' => 'fallback', 'description' => 'Fallback.',
	] ] ) );
	add_filter( 'mmoa_fallback_slot', fn( $fallback, $error, $slot ) => $fallback ?? ( 'backup' !== $slot ? 'backup' : null ), 10, 3 );
}

function begin( array &$hits, array &$attempts, array &$used, ModernMailer\Plugin $plugin ): void {
	$hits = [ 'google' => 0, 'brevo' => 0 ]; $attempts = []; $used = [];
	reset_state( $plugin );
}

wp_set_current_user( 1 );

echo "\n=== 1. Without an add-on there is one connection ===\n";
check( 'one connection listed', 1 === count( $plugin->connections->all() ) );
check( '"backup" names no connection', null === $plugin->connections->slot_for( 'backup' ) );

$req = new WP_REST_Request( 'POST', '/modern-mailer/v1/connections/backup' );
$req->set_body_params( [ 'provider' => 'smtp', 'smtp_host' => 'evil.example.com' ] );
$res = rest_do_request( $req );
Settings::flush_cache();
check( 'a save aimed at it is refused', $res->get_status() >= 400, (string) $res->get_status() );
check( 'and the primary is untouched', 'gmail_sa' === $plugin->settings->get( 'provider' ), (string) $plugin->settings->get( 'provider' ) );

begin( $hits, $attempts, $used, $plugin );
$google_up = false;
$sent = wp_mail( 'a@example.com', 'no addon', 'body' );
check( 'a failing primary is not rescued by a slot nobody declared', 0 === $hits['brevo'], wp_json_encode( $hits ) );
check( 'the message is queued instead', 1 === (int) $plugin->queue->stats()['pending'] );

declare_backup();

echo "\n=== 2. A declared backup is a connection ===\n";
$ids = array_column( $plugin->connections->all(), 'id' );
check( 'primary and backup are listed', [ 'primary', 'backup' ] === $ids, wp_json_encode( $ids ) );
check( 'with its role', 'fallback' === ( $plugin->connections->all()[1]['role'] ?? '' ) );
check( 'and configured', true === ( $plugin->connections->all()[1]['configured'] ?? null ) );

echo "\n=== 3. The primary times out; the backup carries it ===\n";
begin( $hits, $attempts, $used, $plugin );
$google_up = false;
$sent = wp_mail( 'a@example.com', 'rescued', 'body' );
check( 'wp_mail() reports success', true === $sent );
check( 'the primary was tried', $hits['google'] >= 1, wp_json_encode( $hits ) );
check( 'the backup carried it', 1 === $hits['brevo'], wp_json_encode( $hits ) );
check( 'nothing is queued', 0 === (int) $plugin->queue->stats()['pending'] );
check( 'health counts it as working', 0 === (int) $plugin->health->state()['streak'] );
check( 'mmoa_backup_used fired once', 1 === count( $used ) );
check( 'naming both connections', '' === ( $used[0]['slot'] ?? null ) && 'backup' === ( $used[0]['fallback_slot'] ?? null ), wp_json_encode( $used[0] ?? [] ) );
check( 'both attempts were reported, each with its slot', [ '', 'backup' ] === $attempts, wp_json_encode( $attempts ) );

echo "\n=== 4. Both are down ===\n";
begin( $hits, $attempts, $used, $plugin );
$google_up = false;
$brevo_up  = false;
$sent = wp_mail( 'a@example.com', 'both down', 'body' );
check( 'both were tried', $hits['google'] >= 1 && $hits['brevo'] >= 1, wp_json_encode( $hits ) );
check( 'queued exactly once', 1 === (int) $plugin->queue->stats()['pending'], wp_json_encode( $plugin->queue->stats() ) );
global $wpdb;
check( 'against the primary', '' === (string) $wpdb->get_var( 'SELECT slot FROM ' . ModernMailer\Queue::table() . ' ORDER BY id DESC LIMIT 1' ) );
check( 'one failure on the health streak', 1 === (int) $plugin->health->state()['streak'] );
check( 'no backup_used', 0 === count( $used ) );

echo "\n=== 5. The queue drains on the primary once it is back ===\n";
$hits      = [ 'google' => 0, 'brevo' => 0 ];
$google_up = true;
$brevo_up  = true;
$plugin->queue->reschedule_all();
$plugin->queue->drain( $plugin->dispatcher );
check( 'delivered by the primary', 1 === $hits['google'] && 0 === $hits['brevo'], wp_json_encode( $hits ) );

echo "\n=== 6. A test message answers for its own connection alone ===\n";
begin( $hits, $attempts, $used, $plugin );
$google_up = false;
$req = new WP_REST_Request( 'POST', '/modern-mailer/v1/test-email' );
$req->set_body_params( [ 'to' => 'a@example.com' ] );
$res = rest_do_request( $req );
check( 'a failing primary fails its test', false === ( $res->get_data()['ok'] ?? null ), wp_json_encode( $res->get_data() ) );
check( 'without touching the backup', 0 === $hits['brevo'], wp_json_encode( $hits ) );
check( 'or the queue', 0 === (int) $plugin->queue->stats()['pending'] );

$hits = [ 'google' => 0, 'brevo' => 0 ];
$req  = new WP_REST_Request( 'POST', '/modern-mailer/v1/test-email' );
$req->set_body_params( [ 'to' => 'a@example.com', 'slot' => 'backup' ] );
$res = rest_do_request( $req );
check( 'the backup can be tested on its own', true === ( $res->get_data()['ok'] ?? null ), wp_json_encode( $res->get_data() ) );
check( 'and only the backup is used', 0 === $hits['google'] && 1 === $hits['brevo'], wp_json_encode( $hits ) );

$req = new WP_REST_Request( 'POST', '/modern-mailer/v1/test-email' );
$req->set_body_params( [ 'to' => 'a@example.com', 'slot' => 'nope' ] );
check( 'an unknown connection is refused', rest_do_request( $req )->get_status() >= 400 );

echo "\n=== 7. A message already on the backup does not fail over to itself ===\n";
begin( $hits, $attempts, $used, $plugin );
$brevo_up = false;
$plugin->dispatcher->dispatch_raw( "To: a@example.com\r\nSubject: x\r\n\r\nbody", ( function () {
	global $phpmailer;
	if ( ! ( $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );
	}
	$phpmailer->clearAllRecipients();
	$phpmailer->addAddress( 'a@example.com' );
	$phpmailer->Subject = 'x';
	$phpmailer->Body    = 'body';
	return $phpmailer;
} )(), 'backup' );
check( 'the backup was attempted once', [ 'backup' ] === $attempts, wp_json_encode( $attempts ) );
check( 'and the primary not at all', 0 === $hits['google'], wp_json_encode( $hits ) );

// Leave the site as it was found: one connection, nothing queued.
$backup->update( [ 'provider' => '' ] );
$backup->secrets()->set( 'brevo_api_key', '' );
reset_state( $plugin );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
