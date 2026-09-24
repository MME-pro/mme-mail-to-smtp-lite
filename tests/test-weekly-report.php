<?php
/**
 * The weekly report, and its recipient list.
 *
 * The list is the interesting part. It is stored as one comma-separated string
 * so that an existing single address and MMOA_REPORT_EMAIL both keep working,
 * which means the sanitiser is doing real work on every read and write - and a
 * report that silently goes to nobody, or to one of the three people who were
 * supposed to get it, is the exact failure this feature exists to prevent.
 *
 * Nothing is really sent: wp_mail() is intercepted.
 *
 * @package ModernMailer
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ModernMailer\Plugin;
use ModernMailer\Settings;

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

$plugin = Plugin::instance();
$report = $plugin->report;

/** Store a value and read back what the recipient list becomes. */
$set = static function ( $value ) use ( $plugin ): array {
	$plugin->settings->update( [ 'report_email' => $value ] );
	Settings::flush_cache();

	return $plugin->report->recipients();
};

/** What actually got stored, which is what wp-config and the admin app see. */
$stored = static fn(): string => (string) Plugin::instance()->settings->get( 'report_email' );

section( 'One address, as it always was' );

check( 'a single address survives', $set( 'ops@example.com' ), [ 'ops@example.com' ] );
check( 'and is stored unchanged', $stored(), 'ops@example.com' );

section( 'Several addresses' );

check(
	'a comma-separated string becomes a list',
	$set( 'ops@example.com, cto@example.com' ),
	[ 'ops@example.com', 'cto@example.com' ]
);

check(
	'an array - what the chips send - works too',
	$set( [ 'ops@example.com', 'cto@example.com', 'dev@example.com' ] ),
	[ 'ops@example.com', 'cto@example.com', 'dev@example.com' ]
);

check(
	'semicolons and newlines separate as well as commas',
	$set( "ops@example.com;cto@example.com\ndev@example.com" ),
	[ 'ops@example.com', 'cto@example.com', 'dev@example.com' ]
);

check(
	'ragged spacing is tidied rather than stored',
	$set( '   ops@example.com ,,   cto@example.com  ' ),
	[ 'ops@example.com', 'cto@example.com' ]
);

section( 'What must never reach the list' );

check(
	'a junk entry is dropped and the good ones kept',
	$set( 'ops@example.com, not-an-address, cto@example.com' ),
	[ 'ops@example.com', 'cto@example.com' ]
);

// sanitize_email() lowercases, so the surviving entry is the lowercase form.
// What matters is that there is one of them.
check(
	'the same person twice is one recipient, not two reports',
	$set( 'Ops@Example.com, ops@example.com' ),
	[ 'ops@example.com' ]
);

section( 'Nobody configured' );

$plugin->settings->update( [ 'report_email' => '' ] );
Settings::flush_cache();

check(
	'an empty setting falls back to the site administrator',
	$plugin->report->recipients(),
	[ (string) get_option( 'admin_email' ) ]
);

check(
	'a setting that was nothing but junk falls back too, rather than sending nowhere',
	$set( 'not-an-address' ),
	[ (string) get_option( 'admin_email' ) ]
);

section( 'The send itself' );

$sent = [];

add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) use ( &$sent ) {
		$sent[] = $atts;

		return true;
	},
	10,
	2
);

$plugin->settings->update(
	[
		'report_enabled' => true,
		'report_email'   => 'ops@example.com, cto@example.com',
		'log_enabled'    => true,
	]
);
Settings::flush_cache();

// The report deliberately sends nothing in a week with no mail at all, so the
// log needs something in it before send() will do anything. Inserted directly
// rather than sent, because what is under test here is who the report reaches,
// not the delivery path that put the row there.
global $wpdb;

ModernMailer\Logger::install();

$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	ModernMailer\Logger::table(),
	[
		'created_at'    => current_time( 'mysql', true ),
		'provider'      => 'Microsoft 365',
		'recipients'    => 'someone@example.com',
		'subject'       => 'A delivered message',
		'status'        => 'sent',
		'error_code'    => '',
		'error_message' => '',
		'bytes'         => 2048,
		'diagnostics'   => '',
	],
	[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
);

$ok = $plugin->report->send();

check( 'send() reports success', $ok, true );
check( 'exactly one message was sent, not one per person', count( $sent ), 1 );
check(
	'and it carries both recipients',
	$sent ? $sent[0]['to'] : null,
	[ 'ops@example.com', 'cto@example.com' ]
);

section( 'Off means off' );

$sent = [];
$plugin->settings->update( [ 'report_enabled' => false ] );
Settings::flush_cache();

check( 'a disabled report sends nothing', $plugin->report->send(), false );
check( 'and really nothing', count( $sent ), 0 );

// --- restore ---------------------------------------------------------------
$plugin->settings->update(
	[
		'report_enabled' => false,
		'report_email'   => '',
	]
);
Settings::flush_cache();

echo "\n";
echo $failed > 0 ? "{$failed} failed, {$passed} passed\n" : "All {$passed} checks passed\n";

exit( $failed > 0 ? 1 : 0 );
