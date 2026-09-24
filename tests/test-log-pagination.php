<?php
/**
 * Server-side pagination of the send log.
 *
 * The log is the only table here with no ceiling but the retention window, so
 * it is the only screen where fetching everything and sorting it in the browser
 * eventually stops working. Paginating it moves three things into SQL - the
 * page, the status filter and the search - and each of them can be wrong in a
 * way that still renders a plausible-looking table.
 *
 * Nothing here sends mail. Rows are written straight into the table so the
 * counts are exact.
 *
 * @package ModernMailer
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ModernMailer\Logger;
use ModernMailer\Plugin;

global $wpdb;

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

$logger = Plugin::instance()->logger;
$table  = Logger::table();

// Make sure the status_id index exists on this site before anything reads
// through it.
Logger::install();

/* ------------------------------------------------------------- fixture --- */

$existing = (array) $wpdb->get_col( "SELECT id FROM {$table}" ); // phpcs:ignore

/**
 * 60 rows: 40 sent, 20 failed, interleaved so a page boundary always straddles
 * both. Ids ascend, so "newest first" means descending id.
 */
$seeded = [];

for ( $i = 1; $i <= 60; $i++ ) {
	$is_failure = 0 === $i % 3;

	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table,
		[
			'created_at'    => gmdate( 'Y-m-d H:i:s', strtotime( "-{$i} minutes" ) ),
			'provider'      => 'smtp',
			'recipients'    => sprintf( 'person%02d@example.test', $i ),
			'subject'       => $is_failure ? "Broken message {$i}" : "Ordinary message {$i}",
			'status'        => $is_failure ? 'failed' : 'sent',
			'error_code'    => $is_failure ? 'smtp_refused' : '',
			'error_message' => $is_failure ? 'The server said no. Haystack needle.' : '',
			'bytes'         => 1024,
			'diagnostics'   => null,
		]
	);

	$seeded[] = (int) $wpdb->insert_id;
}

// One row that only the search should ever find.
$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$table,
	[
		'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		'provider'      => 'smtp',
		'recipients'    => 'auditor@unmistakable.test',
		'subject'       => 'Quarterly reconciliation',
		'status'        => 'sent',
		'error_code'    => '',
		'error_message' => '',
		'bytes'         => 1024,
		'diagnostics'   => null,
	]
);

$seeded[]  = (int) $wpdb->insert_id;
$total_all = count( $seeded ) + count( $existing );

register_shutdown_function(
	static function () use ( $wpdb, $table, $seeded ): void {
		if ( ! $seeded ) {
			return;
		}

		$ids = implode( ',', array_map( 'intval', $seeded ) );
		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" ); // phpcs:ignore
	}
);

/* --------------------------------------------------------- the count ------ */

section( 'the total' );

$first = $logger->page( 1, 25 );

check( 'counts every row, not just the page', $first['total'], $total_all );
check( 'and reports the page size it used', $first['per_page'], 25 );
check( 'returns exactly that many rows', count( $first['entries'] ), 25 );

check(
	'pages is the ceiling, not the floor',
	$first['pages'],
	(int) ceil( $total_all / 25 )
);

/* ---------------------------------------------------------- the pages ----- */

section( 'paging through' );

check( 'the first page is newest first', $first['entries'][0]->id > $first['entries'][1]->id, true );

$second = $logger->page( 2, 25 );

check( 'page two reports where it is', $second['page'], 2 );

$ids_one = array_map( static fn( object $r ): int => (int) $r->id, $first['entries'] );
$ids_two = array_map( static fn( object $r ): int => (int) $r->id, $second['entries'] );

// An OFFSET without a deterministic ORDER BY silently repeats and drops rows.
check( 'no row appears on two pages', array_intersect( $ids_one, $ids_two ), [] );
check( 'and page two continues below page one', max( $ids_two ) < min( $ids_one ), true );

/* ------------------------------------------------------- the page sizes --- */

section( 'page size' );

foreach ( Logger::PAGE_SIZES as $size ) {
	$page = $logger->page( 1, $size );

	check(
		"{$size} rows per page",
		count( $page['entries'] ),
		min( $size, $total_all )
	);
}

// per_page arrives from the browser. Left unchecked it becomes a LIMIT.
check( 'an absurd page size falls back to the default', $logger->page( 1, 500000 )['per_page'], 25 );
check( 'and so does a nonsensical one', $logger->page( 1, -3 )['per_page'], 25 );

/* --------------------------------------------------- out of range --------- */

section( 'a page that is not there' );

// Retention prunes the log under the reader, so the page somebody is on can
// stop existing between one request and the next.
$beyond = $logger->page( 9999, 25 );

check( 'clamps to the last page that exists', $beyond['page'], $beyond['pages'] );
check( 'and still returns rows', count( $beyond['entries'] ) > 0, true );
check( 'page zero clamps up to the first', $logger->page( 0, 25 )['page'], 1 );

/* ------------------------------------------------------------ filtering --- */

section( 'the status filter' );

$failed_page = $logger->page( 1, 100, 'failed' );

check( 'the total narrows with the filter', $failed_page['total'] < $total_all, true );

check(
	'every row on the page matches',
	array_values( array_unique( array_map( static fn( object $r ): string => $r->status, $failed_page['entries'] ) ) ),
	[ 'failed' ]
);

check( 'exactly the failures that were seeded', $failed_page['total'] >= 20, true );

$sent_page = $logger->page( 1, 100, 'sent' );

check(
	'sent and failed account for the whole log',
	$sent_page['total'] + $failed_page['total'],
	$total_all
);

check( 'an unknown status is ignored rather than matched', $logger->page( 1, 25, 'banana' )['total'], $total_all );

/* -------------------------------------------------------------- search ---- */

section( 'search' );

$hit = $logger->page( 1, 25, '', 'unmistakable' );

check( 'matches the recipient', $hit['total'], 1 );
check( 'and returns it', $hit['entries'][0]->recipients, 'auditor@unmistakable.test' );

check( 'matches the subject', $logger->page( 1, 25, '', 'reconciliation' )['total'], 1 );
check( 'matches the error text', $logger->page( 1, 25, '', 'Haystack needle' )['total'] >= 20, true );

// A search that only narrowed the rows and not the count would page through
// results that are not there.
$narrow = $logger->page( 1, 10, '', 'unmistakable' );

check( 'the count narrows with the search too', $narrow['pages'], 1 );

check( 'a search matching nothing is empty, not everything', $logger->page( 1, 25, '', 'zzz-no-such-thing' )['total'], 0 );

section( 'search and filter together' );

check(
	'both conditions apply, not the last one written',
	$logger->page( 1, 100, 'sent', 'Haystack needle' )['total'],
	0
);

check(
	'and the combination that does match, does',
	$logger->page( 1, 100, 'failed', 'Haystack needle' )['total'] >= 20,
	true
);

section( 'a search that looks like SQL' );

// esc_like, not just prepare: a bare % or _ is a wildcard, and a user typing
// one would otherwise match rows they did not ask for.
check( 'a percent sign is a character, not a wildcard', $logger->page( 1, 25, '', '%' )['total'], 0 );
check( 'and so is an underscore', $logger->page( 1, 25, '', '_' )['total'], 0 );
check( "a quote does not break the query", $logger->page( 1, 25, '', "o'brien" )['total'], 0 );

/* ---------------------------------------------------------- the dashboard - */

section( 'the dashboard is not made to pay for this' );

// recent() feeds /bootstrap and /dashboard on every admin page load. Routing
// it through page() would add a COUNT over the whole table to both.
$recent = $logger->recent( 8 );

check( 'recent() still returns a plain list', is_array( $recent ) && ! isset( $recent['total'] ), true );
check( 'of the size asked for', count( $recent ), 8 );
check( 'newest first', (int) $recent[0]->id > (int) $recent[1]->id, true );

/* ------------------------------------------------------------- the route -- */

section( 'over REST' );

wp_set_current_user( 1 );

$request = new WP_REST_Request( 'GET', '/modern-mailer/v1/logs' );
$request->set_param( 'page', 2 );
$request->set_param( 'per_page', 10 );

$response = rest_do_request( $request );
$body     = $response->get_data();

check( 'the route answers', $response->get_status(), 200 );
check( 'with the page asked for', $body['page'], 2 );
check( 'at the size asked for', $body['per_page'], 10 );
check( 'and that many rows', count( $body['entries'] ), 10 );
check( 'carrying the total', $body['total'], $total_all );

check( 'and the header WordPress list routes set', $response->get_headers()['X-WP-Total'] ?? '', (string) $total_all );
check( 'plus the page count', $response->get_headers()['X-WP-TotalPages'] ?? '', (string) $body['pages'] );

// enum on the route argument, so a hand-rolled request cannot ask for 50,000.
$rogue = new WP_REST_Request( 'GET', '/modern-mailer/v1/logs' );
$rogue->set_param( 'per_page', 50000 );

check( 'an out-of-range page size is refused', rest_do_request( $rogue )->get_status(), 400 );

$searched = new WP_REST_Request( 'GET', '/modern-mailer/v1/logs' );
$searched->set_param( 'search', 'unmistakable' );
$searched->set_param( 'per_page', 10 );

check( 'search reaches the query', rest_do_request( $searched )->get_data()['total'], 1 );

$filtered = new WP_REST_Request( 'GET', '/modern-mailer/v1/logs' );
$filtered->set_param( 'status', 'failed' );
$filtered->set_param( 'per_page', 10 );

check( 'so does the status filter', rest_do_request( $filtered )->get_data()['total'], $failed_page['total'] );

// The log never held bodies; pagination must not become the reason one appears.
$leak = rest_do_request( new WP_REST_Request( 'GET', '/modern-mailer/v1/logs' ) )->get_data();

check(
	'no message body on the list route',
	array_filter(
		$leak['entries'],
		static fn( array $row ): bool => isset( $row['body'] ) || isset( $row['diagnostics'] )
	),
	[]
);

echo "\n";
echo $failed > 0 ? "{$failed} failed, {$passed} passed\n" : "All {$passed} checks passed\n";

exit( $failed > 0 ? 1 : 0 );
