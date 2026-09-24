<?php
/**
 * Intelligent forwarding: primary, backup, queue - and never a dropped message.
 *
 * The plugin has three places a message can end up when the primary connection
 * will not take it: the backup connection, the retry queue, or a reported
 * failure. The one outcome that must never happen is a fourth - wp_mail()
 * returning without the message being any of delivered, queued or reported.
 *
 * So every scenario below asserts the same invariant at the end, whatever else
 * it is testing: **exactly one of delivered, queued, reported**. A bug that
 * loses a message quietly would pass every individual assertion about backups
 * and retries and still fail that one.
 *
 * Every outbound call is stubbed. No mail is sent and no credential is needed.
 *
 * @package ModernMailer
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ModernMailer\Plugin;
use ModernMailer\Settings;

$passed = 0;
$failed = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $passed, $failed;

	if ( $ok ) {
		$passed++;
		echo "  PASS  {$label}\n";

		return;
	}

	$failed++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? "  <- {$detail}" : '' ) . "\n";
}

function section( string $name ): void {
	echo "\n--- {$name} ---\n";
}

$plugin = Plugin::instance();

ModernMailer\Logger::install();
ModernMailer\Queue::install();

/* ------------------------------------------------------------ harness ---- */

function configure_primary( Plugin $plugin ): void {
	$plugin->settings->update(
		[
			'provider'      => 'graph',
			'from_email'    => 'noreply@contoso.com',
			'ms_tenant_id'  => 'tid',
			'ms_client_id'  => 'cid',
			'ms_sender'     => 'noreply@contoso.com',
			'log_enabled'   => true,
			'queue_enabled' => true,
			'alert_email'   => '',
			'routing_enabled' => false,
			'routing_rules'   => [],
		]
	);
	$plugin->secrets->set( 'ms_client_secret', 'secret' );
}

function configure_backup( Plugin $plugin, bool $on ): void {
	$backup = $plugin->settings->for_slot( Settings::SLOT_BACKUP );

	if ( ! $on ) {
		$backup->update( [ 'provider' => '' ] );

		return;
	}

	$backup->update(
		[
			'provider'        => 'gmail_sa',
			'from_email'      => 'backup@contoso.com',
			'google_sa_email' => 'sa@project.iam.gserviceaccount.com',
			'google_sender'   => 'backup@contoso.com',
		]
	);

	$plugin->secrets->for_slot( Settings::SLOT_BACKUP )->set(
		'google_sa_key',
		(string) file_get_contents( __DIR__ . '/test-sa-key.pem' )
	);
}

function reset_state( Plugin $plugin ): void {
	$plugin->tokens->flush();
	$plugin->health->reset();
	$plugin->queue->purge();
	Settings::flush_cache();
}

$calls  = 0;
$script = null;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$script, &$calls ) {
		$calls++;

		return $script ? ( $script )( $url, $args, $calls ) : $pre;
	},
	10,
	3
);

function json_response( int $code, array $body, array $headers = [] ): array {
	return [
		'headers'  => $headers,
		'body'     => (string) wp_json_encode( $body ),
		'response' => [ 'code' => $code, 'message' => '' ],
		'cookies'  => [],
		'filename' => null,
	];
}

function ok_token(): array {
	return json_response( 200, [ 'access_token' => 'T' . wp_rand(), 'expires_in' => 3600 ] );
}

function is_token_url( string $url ): bool {
	return false !== strpos( $url, 'login.microsoftonline' ) || false !== strpos( $url, 'oauth2.googleapis' );
}

/** The transport error a real SMTP/API timeout produces. */
function timeout(): WP_Error {
	return new WP_Error(
		'http_request_failed',
		'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received'
	);
}

/** Connection refused, as a dead host actually reports it. */
function refused(): WP_Error {
	return new WP_Error(
		'http_request_failed',
		"cURL error 7: Failed to connect to graph.microsoft.com port 443 after 1 ms: Couldn't connect to server"
	);
}

/**
 * The invariant. Whatever a scenario was testing, the message has to be in
 * exactly one of three states afterwards.
 */
function accounted_for( Plugin $plugin, $result, int $errors ): string {
	// Queued wins over a true return, and this is not a fudge: wp_mail()
	// returning true for a queued message means "accepted for later delivery",
	// not "delivered". Reading it as delivery is precisely the mistake the
	// queue makes it easy to make.
	if ( (int) $plugin->queue->stats()['pending'] > 0 ) {
		return 'queued';
	}

	if ( true === $result ) {
		return 'delivered';
	}

	if ( $errors > 0 ) {
		return 'reported';
	}

	// The state that must never occur: wp_mail() came back, the message is not
	// queued, and nobody was told.
	return 'DROPPED';
}

/** Count wp_mail_failed firings for the current scenario. */
$errors = 0;
add_action( 'wp_mail_failed', static function () use ( &$errors ) { $errors++; } );

configure_primary( $plugin );
$plugin->install_mailer();

/* ================================================== 1. a timeout ========== */

section( 'the primary times out' );

reset_state( $plugin );
configure_backup( $plugin, true );
$errors = 0;
$hit    = [ 'graph' => 0, 'gmail' => 0 ];

$script = static function ( $url ) use ( &$hit ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	if ( false !== strpos( $url, 'graph.microsoft.com' ) ) {
		$hit['graph']++;

		return timeout();
	}

	$hit['gmail']++;

	return json_response( 200, [ 'id' => 'ok' ] );
};

$result = wp_mail( 'customer@example.com', 'Timeout', 'body' );

check( 'the primary was tried', $hit['graph'] > 0 );
check( 'the backup carried it', $hit['gmail'] > 0 );
check( 'wp_mail() reports success', true === $result, var_export( $result, true ) );
check( 'nothing was queued, because nothing was lost', 0 === (int) $plugin->queue->stats()['pending'] );
check( 'health is clean - the site did deliver', ! $plugin->health->is_failing() );
check( 'accounted for exactly once', 'delivered' === accounted_for( $plugin, $result, $errors ), accounted_for( $plugin, $result, $errors ) );

/* ================================================== 2. a rate limit ======= */

section( 'the primary is rate limited' );

reset_state( $plugin );
$errors = 0;
$hit    = [ 'graph' => 0, 'gmail' => 0 ];

$script = static function ( $url ) use ( &$hit ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	if ( false !== strpos( $url, 'graph.microsoft.com' ) ) {
		$hit['graph']++;

		// Retry-After 0 so the provider's own backoff does not stall the test.
		return json_response( 429, [ 'error' => [ 'code' => 'tooManyRequests' ] ], [ 'retry-after' => '0' ] );
	}

	$hit['gmail']++;

	return json_response( 200, [ 'id' => 'ok' ] );
};

$result = wp_mail( 'customer@example.com', 'Throttled', 'body' );

check( 'a 429 does not stop the message', true === $result, var_export( $result, true ) );
check( 'the backup carried it', $hit['gmail'] > 0 );
check( 'accounted for exactly once', 'delivered' === accounted_for( $plugin, $result, $errors ) );

/* ============================ 3. both connections refuse ================== */

section( 'both connections are down' );

reset_state( $plugin );
$errors = 0;
$hit    = [ 'graph' => 0, 'gmail' => 0 ];

$script = static function ( $url ) use ( &$hit ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	if ( false !== strpos( $url, 'graph.microsoft.com' ) ) {
		$hit['graph']++;
	} else {
		$hit['gmail']++;
	}

	return refused();
};

$result = wp_mail( 'customer@example.com', 'Both down', 'body' );

check( 'both connections were tried', $hit['graph'] > 0 && $hit['gmail'] > 0, wp_json_encode( $hit ) );
check( 'the message is on the queue, not lost', 1 === (int) $plugin->queue->stats()['pending'], wp_json_encode( $plugin->queue->stats() ) );
check( 'it is queued exactly once, not once per connection', 1 === (int) $plugin->queue->stats()['pending'] );
check( 'the failure was recorded against health', 1 === (int) $plugin->health->state()['streak'], wp_json_encode( $plugin->health->state() ) );
check( 'accounted for exactly once', 'queued' === accounted_for( $plugin, $result, $errors ), accounted_for( $plugin, $result, $errors ) );

/* ================================ 4. a hard bounce ======================== */

section( 'a permanently rejected recipient' );

reset_state( $plugin );
$errors = 0;

$script = static function ( $url ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	// 550-equivalent: the address does not exist. No connection can fix that.
	return json_response(
		400,
		[
			'error' => [
				'code'    => 'ErrorInvalidRecipients',
				'message' => 'At least one recipient is not valid.',
			],
		]
	);
};

$result = wp_mail( 'nobody@invalid.example', 'Bounce', 'body' );

check( 'the send fails rather than pretending', false === $result, var_export( $result, true ) );
check( 'it is not queued - no retry can fix a bad address', 0 === (int) $plugin->queue->stats()['pending'], wp_json_encode( $plugin->queue->stats() ) );
check( 'and it was reported to the caller', $errors > 0, "wp_mail_failed fired {$errors}x" );
check( 'accounted for exactly once', 'reported' === accounted_for( $plugin, $result, $errors ), accounted_for( $plugin, $result, $errors ) );

/* ========================= 5. no backup configured ======================== */

section( 'no backup is configured' );

reset_state( $plugin );
configure_backup( $plugin, false );
$errors = 0;
$calls  = 0;

$script = static function ( $url ) {
	return is_token_url( $url ) ? ok_token() : timeout();
};

$result = wp_mail( 'customer@example.com', 'No backup', 'body' );

check( 'the message is queued rather than dropped', 1 === (int) $plugin->queue->stats()['pending'] );
check( 'accounted for exactly once', 'queued' === accounted_for( $plugin, $result, $errors ), accounted_for( $plugin, $result, $errors ) );

/* =============================== 6. the drain ============================= */

section( 'the queue delivers when the fault clears' );

$script = static function ( $url ) {
	return is_token_url( $url ) ? ok_token() : json_response( 200, [ 'id' => 'ok' ] );
};

$plugin->queue->reschedule_all();
$drained = $plugin->queue->drain( $plugin->dispatcher );

check( 'the queued message was attempted', 1 === (int) $drained['attempted'], wp_json_encode( $drained ) );
check( 'and delivered', 1 === (int) $drained['sent'], wp_json_encode( $drained ) );
check( 'and removed from the queue', 0 === (int) $plugin->queue->stats()['pending'] );

/* ================== 7. a retry does not wander to another connection ====== */

section( 'a retry stays on the connection it started on' );

reset_state( $plugin );
configure_backup( $plugin, true );
$errors = 0;

// Fail everything so the message queues against the primary.
$script = static function ( $url ) {
	return is_token_url( $url ) ? ok_token() : timeout();
};

wp_mail( 'customer@example.com', 'Sticky', 'body' );

check( 'it is queued', 1 === (int) $plugin->queue->stats()['pending'] );

$hit    = [ 'graph' => 0, 'gmail' => 0 ];
$script = static function ( $url ) use ( &$hit ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	if ( false !== strpos( $url, 'graph.microsoft.com' ) ) {
		$hit['graph']++;
	} else {
		$hit['gmail']++;
	}

	return json_response( 200, [ 'id' => 'ok' ] );
};

$plugin->queue->reschedule_all();
$plugin->queue->drain( $plugin->dispatcher );

// A half-delivered message must not silently change sender on retry: the
// recipient's DMARC, the From address and the reputation all belong to the
// connection it was queued against.
check( 'the retry used the connection it was queued against', $hit['graph'] > 0, wp_json_encode( $hit ) );
check( 'and did not divert to the backup', 0 === $hit['gmail'], wp_json_encode( $hit ) );

/* ====================== 8. routing still falls back ======================= */

section( 'a routed message falls back like any other' );

reset_state( $plugin );
$errors = 0;

$plugin->settings->update(
	[
		'routing_enabled' => true,
		'routing_rules'   => [
			[
				'connection' => 'primary',
				'groups'     => [ [ [ 'field' => 'to_domain', 'operator' => 'is', 'value' => 'example.com' ] ] ],
			],
		],
	]
);
Settings::flush_cache();

$hit    = [ 'graph' => 0, 'gmail' => 0 ];
$script = static function ( $url ) use ( &$hit ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	if ( false !== strpos( $url, 'graph.microsoft.com' ) ) {
		$hit['graph']++;

		return timeout();
	}

	$hit['gmail']++;

	return json_response( 200, [ 'id' => 'ok' ] );
};

$result = wp_mail( 'customer@example.com', 'Routed', 'body' );

check( 'the rule chose the primary', $hit['graph'] > 0 );
check( 'and the backup still rescued it', $hit['gmail'] > 0 );
check( 'routing does not opt a message out of the backup', true === $result, var_export( $result, true ) );
check( 'accounted for exactly once', 'delivered' === accounted_for( $plugin, $result, $errors ) );

$plugin->settings->update( [ 'routing_enabled' => false, 'routing_rules' => [] ] );
Settings::flush_cache();

/* ============ 9. a rule pointing at the backup does not try it twice ====== */

section( 'a message routed to the backup is not tried twice' );

reset_state( $plugin );
$errors = 0;

$plugin->settings->update(
	[
		'routing_enabled' => true,
		'routing_rules'   => [
			[
				'connection' => 'backup',
				'groups'     => [ [ [ 'field' => 'to_domain', 'operator' => 'is', 'value' => 'example.com' ] ] ],
			],
		],
	]
);
Settings::flush_cache();

$fellback = 0;
add_action( 'mmoa_backup_used', static function () use ( &$fellback ) { $fellback++; } );

$hit    = [ 'graph' => 0, 'gmail' => 0 ];
$script = static function ( $url ) use ( &$hit ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	if ( false !== strpos( $url, 'graph.microsoft.com' ) ) {
		$hit['graph']++;
	} else {
		$hit['gmail']++;
	}

	return timeout();
};

$result = wp_mail( 'customer@example.com', 'Backup routed', 'body' );

check( 'the rule sent it to the backup', $hit['gmail'] > 0, wp_json_encode( $hit ) );
check( 'the primary was never touched', 0 === $hit['graph'], wp_json_encode( $hit ) );

// Falling back from the backup to the backup would be a wasted round trip and
// a second identical error in the log. Counted through the hook rather than
// through HTTP calls, because one dispatcher attempt is already several
// requests - Http retries a timeout on its own, which is why gmail is hit
// three times here and not once.
check( 'it did not fall back from the backup to itself', 0 === $fellback, "mmoa_backup_used fired {$fellback}x" );
check( 'and it is queued once', 1 === (int) $plugin->queue->stats()['pending'] );
check( 'accounted for exactly once', 'queued' === accounted_for( $plugin, $result, $errors ) );

$plugin->settings->update( [ 'routing_enabled' => false, 'routing_rules' => [] ] );
Settings::flush_cache();

/* ================== 10. a test message is never rescued =================== */

section( 'a test message answers about the primary alone' );

reset_state( $plugin );
$errors = 0;
$hit    = [ 'graph' => 0, 'gmail' => 0 ];

$script = static function ( $url ) use ( &$hit ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	if ( false !== strpos( $url, 'graph.microsoft.com' ) ) {
		$hit['graph']++;

		return timeout();
	}

	$hit['gmail']++;

	return json_response( 200, [ 'id' => 'ok' ] );
};

$result = $plugin->dispatcher->without_fallbacks(
	static fn() => wp_mail( 'customer@example.com', 'Test', 'body' )
);

check( 'the test fails when the primary fails', false === $result, var_export( $result, true ) );
check( 'the backup was not consulted', 0 === $hit['gmail'], wp_json_encode( $hit ) );
check( 'and nothing was queued behind it', 0 === (int) $plugin->queue->stats()['pending'] );

/* ================= 11. the alert knows what actually failed =============== */

section( 'the failure alert carries the message it is about' );

reset_state( $plugin );
configure_backup( $plugin, false );
$errors = 0;

$seen = null;
add_action(
	'mmoa_alert_sent',
	static function ( $alert ) use ( &$seen ) {
		$seen = $alert;
	}
);

$plugin->alerts->save(
	[
		'enabled'  => true,
		'when'     => ModernMailer\Alerts::WHEN_EVERY,
		'quiet'    => 0,
		'channels' => [ 'email' => [ 'enabled' => false ] ],
	]
);

$script = static function ( $url ) {
	if ( is_token_url( $url ) ) {
		return ok_token();
	}

	return json_response( 400, [ 'error' => [ 'code' => 'ErrorInvalidRecipients', 'message' => 'Not valid.' ] ] );
};

wp_mail( 'someone@example.com', 'Alerting subject', 'body' );

check( 'an alert was raised', null !== $seen, 'no mmoa_alert_sent' );
check( 'it names the recipient', null !== $seen && false !== strpos( $seen->recipients, 'someone@example.com' ), (string) ( $seen->recipients ?? '' ) );
check( 'it names the subject', null !== $seen && 'Alerting subject' === $seen->subject, (string) ( $seen->subject ?? '' ) );
check( 'it names the connection', null !== $seen && '' !== $seen->mailer, (string) ( $seen->mailer ?? '' ) );
check( 'it carries an error code', null !== $seen && '' !== $seen->code, (string) ( $seen->code ?? '' ) );
check( 'and a timestamp', null !== $seen && $seen->time > 0 );

// The alert is a notification, not an export of the mail.
check( 'the body is nowhere in the payload', null !== $seen && ! in_array( 'body', array_keys( $seen->to_array() ), true ) );

delete_option( ModernMailer\Alerts::OPTION );

/* ----------------------------------------------------------- teardown ---- */

reset_state( $plugin );
configure_backup( $plugin, false );
$plugin->settings->update( [ 'routing_enabled' => false, 'routing_rules' => [] ] );

echo "\n";
echo $failed > 0 ? "{$failed} failed, {$passed} passed\n" : "All {$passed} checks passed\n";

exit( $failed > 0 ? 1 : 0 );
