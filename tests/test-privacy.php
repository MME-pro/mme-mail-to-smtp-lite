<?php
/**
 * Data subject rights: export and erasure.
 *
 * These run through the same callbacks WordPress calls, rather than through
 * private helpers, because the thing worth proving is that a site owner using
 * Tools, Export Personal Data gets an answer - not that some method returns an
 * array.
 *
 * The case that matters most is the near-miss. Recipients are stored as one
 * comma-joined string, so the lookup has to be a LIKE, and a LIKE matches
 * substrings: bob@example.com sits inside bbob@example.com. An erasure request
 * that deleted a stranger's mail while honouring somebody's privacy rights
 * would be a bad way to comply with the regulation.
 */

require __DIR__ . '/bootstrap.php';

use ModernMailer\Privacy;
use ModernMailer\Queue;
use ModernMailer\Settings;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "  PASS  {$label}\n"; }
	else { $fail++; echo "  FAIL  {$label}" . ( $detail ? "  <- {$detail}" : '' ) . "\n"; }
}

global $wpdb;

$plugin  = ModernMailer\Plugin::instance();
$privacy = new Privacy();

Queue::install();

$subject_address = 'bob@example.com';
$lookalike       = 'bbob@example.com';

function queue_row( string $recipients, string $subject, string $status = 'pending' ): int {
	global $wpdb;

	$wpdb->insert(
		Queue::table(),
		[
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'next_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
			'slot'            => '',
			'attempts'        => 1,
			'status'          => $status,
			'recipients'      => $recipients,
			'subject'         => $subject,
			'raw_mime'        => "To: {$recipients}\r\nSubject: {$subject}\r\n\r\nThe body.",
			'bytes'           => 40,
			'error_code'      => '',
			'error_message'   => '',
		]
	);

	return (int) $wpdb->insert_id;
}

// A clean slate, so a previous run cannot be mistaken for this one's fixtures.
$wpdb->query( "DELETE FROM " . Queue::table() ); // phpcs:ignore

$queue_hit    = queue_row( $subject_address, 'Password reset' );
$queue_shared = queue_row( "someone@example.com, {$subject_address}", 'Team update' );
$queue_near   = queue_row( $lookalike, 'Also not this one' );

echo "\n=== 1. WordPress is offered an exporter and an eraser ===\n";
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );

// register() is what the plugin calls on boot; the filters above are what
// WordPress calls. Registering here proves the wiring, not just the methods.
$privacy->register();

$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );

check( 'an exporter is registered', isset( $exporters['mme-mail-to-smtp'] ) );
check( 'an eraser is registered', isset( $erasers['mme-mail-to-smtp'] ) );
check( 'the exporter callback is callable', is_callable( $exporters['mme-mail-to-smtp']['callback'] ?? null ) );
check( 'the eraser callback is callable', is_callable( $erasers['mme-mail-to-smtp']['callback'] ?? null ) );

echo "\n=== 2. Export returns what is held, and only for that person ===\n";
$result = $privacy->export( $subject_address );

check( 'it reports itself finished', true === ( $result['done'] ?? false ) );

$items = $result['data'] ?? [];
$ids   = array_column( $items, 'item_id' );

check( 'the queued message addressed to them is exported', in_array( 'mmoa-queue-' . $queue_hit, $ids, true ), implode( ',', $ids ) );
check( 'so is the one where they were among several recipients', in_array( 'mmoa-queue-' . $queue_shared, $ids, true ) );

// The near-miss. A LIKE on bob@example.com matches bbob@example.com, so this
// is the assertion that the post-filter is doing its job.
check( 'the lookalike address is not exported', ! in_array( 'mmoa-queue-' . $queue_near, $ids, true ), implode( ',', $ids ) );

$groups = array_unique( array_column( $items, 'group_id' ) );
check( 'the queued records are grouped under one heading', 1 === count( $groups ), implode( ',', $groups ) );

$queue_item = null;
foreach ( $items as $item ) {
	if ( 'mmoa-queue-' . $queue_hit === $item['item_id'] ) {
		$queue_item = $item;
	}
}

// The stored copy of the message is deliberately not in the export: it is the
// site's outgoing mail, it can name other people in Cc, and the export is a
// file somebody downloads.
$exported_values = implode( ' ', array_column( $queue_item['data'] ?? [], 'value' ) );
check( 'the message body is not included in the export', false === strpos( $exported_values, 'The body.' ), $exported_values );

echo "\n=== 3. Export of an address we hold nothing for ===\n";
$empty = $privacy->export( 'nobody@example.com' );
check( 'returns no items', [] === ( $empty['data'] ?? null ) );
check( 'and is finished', true === ( $empty['done'] ?? false ) );

$invalid = $privacy->export( 'not-an-address' );
check( 'a malformed address returns nothing rather than matching everything', [] === ( $invalid['data'] ?? null ) );

echo "\n=== 4. Erasure deletes what is still unsent ===\n";
$erased = $privacy->erase( $subject_address );

check( 'it reports something removed', true === ( $erased['items_removed'] ?? false ) );
check( 'and nothing is retained, because nothing is anonymised', false === ( $erased['items_retained'] ?? true ) );
check( 'and it explains what happened', count( $erased['messages'] ?? [] ) >= 1, wp_json_encode( $erased['messages'] ?? [] ) );

$queued_left = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Queue::table() . ' WHERE id = %d', $queue_hit ) ); // phpcs:ignore
check( 'the queued message is gone entirely', '0' === (string) $queued_left );
echo "\n=== 5. Erasure does not reach past the person who asked ===\n";
$near_queued = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Queue::table() . ' WHERE id = %d', $queue_near ) ); // phpcs:ignore
check( 'the lookalike queued message is still there', '1' === (string) $near_queued );

echo "\n=== 6. A second pass has nothing left to do ===\n";
$again = $privacy->erase( $subject_address );
check( 'nothing is removed the second time', false === ( $again['items_removed'] ?? true ) );
check( 'and it is done', true === ( $again['done'] ?? false ) );

$after = $privacy->export( $subject_address );
check( 'and the export is empty afterwards', [] === ( $after['data'] ?? null ), wp_json_encode( array_column( $after['data'] ?? [], 'item_id' ) ) );

echo "\n=== 7. The queue retention period is a setting ===\n";
check( 'it defaults to seven days', 7 === (int) $plugin->settings->get( 'queue_retention' ) );

$plugin->settings->update( [ 'queue_retention' => 3 ] );
Settings::flush_cache();
check( 'and it can be changed', 3 === (int) $plugin->settings->get( 'queue_retention' ) );

echo "\n=== 8. Restoring a clean state ===\n";
$wpdb->query( "DELETE FROM " . Queue::table() ); // phpcs:ignore
$plugin->settings->update( [ 'queue_retention' => 7 ] );
Settings::flush_cache();

check( 'the queue is empty', '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() ) ); // phpcs:ignore
check( 'retention is back to seven days', 7 === (int) $plugin->settings->get( 'queue_retention' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
