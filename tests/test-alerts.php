<?php
/**
 * The multi-channel alert system.
 *
 * Alerts run while the site is broken, inside somebody else's request, and
 * talk to third-party services. That combination means the interesting tests
 * are not "does Slack get a message" but "what happens when it does not" -
 * a channel that throws, a webhook that answers 200 with a refusal in the
 * body, a credential that is empty.
 *
 * Every outbound call is stubbed. Nothing leaves the machine and no alert is
 * really sent.
 *
 * @package ModernMailer
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ModernMailer\Alerts;
use ModernMailer\Alerts\Alert;
use ModernMailer\Plugin;

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
$alerts = $plugin->alerts;

$requests = [];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests ) {
		$requests[] = [ 'url' => (string) $url, 'args' => $args ];

		global $stub;

		return $stub ? ( $stub )( $url, $args ) : [
			'headers'  => [],
			'body'     => 'ok',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	5,
	3
);

$stub = null;

function respond( int $code, string $body ): array {
	return [
		'headers'  => [],
		'body'     => $body,
		'response' => [ 'code' => $code, 'message' => '' ],
		'cookies'  => [],
		'filename' => null,
	];
}

register_shutdown_function(
	static function (): void {
		delete_option( Alerts::OPTION );
	}
);

/* ------------------------------------------------------- the payload ----- */

section( 'the payload' );

$alert = Alert::from_failure(
	Alert::SEND_FAILED,
	new WP_Error( 'mmoa_graph_auth', 'The client secret has expired.' ),
	[
		'recipients' => 'customer@example.com',
		'subject'    => 'Your order',
		'mailer'     => 'Microsoft 365',
		'slot'       => 'primary',
		'time'       => 1_760_000_000,
		'streak'     => 4,
	]
);

$lines = $alert->lines();

check( 'names the failed recipient', $lines['Failed recipient'] ?? '', 'customer@example.com' );
check( 'names the subject', $lines['Subject'] ?? '', 'Your order' );
check( 'names the sending connection', $lines['Sent via'] ?? '', 'Microsoft 365 - primary connection' );
check( 'carries the code with the reason', $alert->reason(), 'The client secret has expired. (mmoa_graph_auth)' );
check( 'and the streak once there is one worth showing', $lines['Consecutive failures'] ?? '', '4' );

// The five fields the payload is specified to carry, all present.
foreach ( [ 'Failed recipient', 'Subject', 'When', 'Error', 'Sent via' ] as $required ) {
	check( "the payload includes: {$required}", isset( $lines[ $required ] ), true );
}

$keys = array_keys( $alert->to_array() );

check( 'no message body anywhere in it', in_array( 'body', $keys, true ), false );
check( 'no attachment', in_array( 'attachments', $keys, true ), false );

// An alert about a working site has no recipient to name; the empty field is
// dropped rather than rendered as a blank row.
$plain = new Alert( Alert::RECOVERED, time: time() );

check( 'an empty field is left out rather than blank', isset( $plain->lines()['Failed recipient'] ), false );

/* -------------------------------------------------------- the channels --- */

section( 'every channel is wired up' );

$expected = [ 'email', 'slack', 'discord', 'teams', 'bitrix24', 'twilio', 'webhook' ];

check( 'all seven channels are registered', array_keys( Alerts::channels() ), $expected );

foreach ( $expected as $slug ) {
	$class = Alerts::channel_class( $slug );

	check( "{$slug} declares fields", is_array( $class::fields() ) && [] !== $class::fields(), true );
	check( "{$slug} refuses an empty configuration", $class::is_configured( [] ), false );
}

/* ---------------------------------------------------------- formatting --- */

section( 'each service gets the shape it expects' );

$alerts->save(
	[
		'enabled'  => true,
		'when'     => Alerts::WHEN_THRESHOLD,
		'quiet'    => 0,
		'channels' => [
			'slack'    => [ 'enabled' => true, 'url' => 'https://hooks.slack.com/services/T/B/X' ],
			'discord'  => [ 'enabled' => true, 'url' => 'https://discord.com/api/webhooks/1/x' ],
			'teams'    => [ 'enabled' => true, 'url' => 'https://prod-1.westeurope.logic.azure.com/workflows/x' ],
			'webhook'  => [ 'enabled' => true, 'url' => 'https://example.test/hook', 'secret' => 's3cret' ],
			'bitrix24' => [ 'enabled' => true, 'url' => 'https://portal.bitrix24.com/rest/1/tok/', 'mode' => 'chat', 'target' => '42' ],
			'twilio'   => [ 'enabled' => true, 'sid' => 'AC123', 'token' => 'tok', 'from' => 'whatsapp:+1', 'to' => 'whatsapp:+2' ],
		],
	]
);

$body_of = static function ( string $slug ) use ( $alerts, &$requests, &$stub ): array {
	$requests = [];
	$stub     = static fn( $url ) => str_contains( $url, 'bitrix24' )
		? respond( 200, (string) wp_json_encode( [ 'result' => true ] ) )
		: respond( 200, 'ok' );

	$alerts->deliver( $slug, Alert::test() );

	$sent = $requests[0]['args']['body'] ?? '';
	$json = json_decode( (string) $sent, true );

	return [
		'url'     => $requests[0]['url'] ?? '',
		'headers' => $requests[0]['args']['headers'] ?? [],
		'raw'     => (string) $sent,
		'json'    => is_array( $json ) ? $json : [],
	];
};

$slack = $body_of( 'slack' );

// text is what Slack shows in the notification; blocks are not rendered there.
check( 'Slack gets a fallback text as well as blocks', isset( $slack['json']['text'], $slack['json']['blocks'] ), true );

$discord = $body_of( 'discord' );

check( 'Discord gets an embed', isset( $discord['json']['embeds'][0]['fields'] ), true );
check( 'with a numeric colour, not a hex string', is_int( $discord['json']['embeds'][0]['color'] ?? null ), true );

$teams = $body_of( 'teams' );

// Microsoft retired the connector that took MessageCard. Workflows takes this.
check( 'Teams gets an Adaptive Card, not a MessageCard', $teams['json']['attachments'][0]['contentType'] ?? '', 'application/vnd.microsoft.card.adaptive' );

$bitrix = $body_of( 'bitrix24' );

check( 'Bitrix24 chat mode calls im.message.add', str_contains( $bitrix['url'], 'im.message.add' ), true );
check( 'and addresses the dialog it was given', $bitrix['json']['DIALOG_ID'] ?? '', '42' );

$hook = $body_of( 'webhook' );

check( 'the custom webhook signs itself', $hook['headers']['X-MMOA-Secret'] ?? '', 's3cret' );
check( 'and sends the alert as-is', $hook['json']['event'] ?? '', Alert::TEST );

$twilio = $body_of( 'twilio' );

check( 'Twilio is form encoded, not JSON', $twilio['headers']['Content-Type'] ?? '', 'application/x-www-form-urlencoded' );
check( 'and authenticates', str_starts_with( (string) ( $twilio['headers']['Authorization'] ?? '' ), 'Basic ' ), true );
check( 'and posts to the account it was given', str_contains( $twilio['url'], 'Accounts/AC123/Messages.json' ), true );

/* ------------------------------------------------------------- refusals --- */

section( 'a refused alert is reported, not swallowed' );

// Slack answers 200 with a body that is not "ok" when it has taken the
// request but not the message. Reading only the status reports success.
$stub   = static fn() => respond( 200, 'invalid_payload' );
$result = $alerts->deliver( 'slack', Alert::test() );

check( 'Slack 200 + invalid_payload is a failure', is_wp_error( $result ), true );

// Bitrix24 does the same with a JSON error object.
$stub   = static fn() => respond( 200, (string) wp_json_encode( [ 'error' => 'ERROR_CORE', 'error_description' => 'no such chat' ] ) );
$result = $alerts->deliver( 'bitrix24', Alert::test() );

check( 'Bitrix24 200 + error object is a failure', is_wp_error( $result ), true );
check( 'and the reason is passed through', is_wp_error( $result ) && str_contains( $result->get_error_message(), 'no such chat' ), true );

$stub   = static fn() => new WP_Error( 'http_request_failed', 'cURL error 28' );
$result = $alerts->deliver( 'discord', Alert::test() );

check( 'an unreachable service is a failure', is_wp_error( $result ), true );

/* -------------------------------------------------------------- safety --- */

section( 'an alert cannot break the site' );

$stub = static function () {
	throw new \RuntimeException( 'the channel exploded' );
};

$result = $alerts->deliver( 'slack', Alert::test() );

check( 'a channel that throws is caught', is_wp_error( $result ), true );
check( 'and explains itself', is_wp_error( $result ) && str_contains( $result->get_error_message(), 'exploded' ), true );

$stub = null;

// Plaintext http would put a recipient address on the wire in clear.
$alerts->save(
	[
		'enabled'  => true,
		'quiet'    => 0,
		'channels' => [ 'webhook' => [ 'enabled' => true, 'url' => 'http://example.test/hook' ] ],
	]
);

$result = $alerts->deliver( 'webhook', Alert::test() );

check( 'a plain http webhook is refused', is_wp_error( $result ) && 'mmoa_alert_insecure' === $result->get_error_code(), true );

/* ---------------------------------------------------------- the switch --- */

section( 'the master switch and the quiet period' );

/**
 * Save with exactly one channel on.
 *
 * Alerts::save() leaves a channel it was not told about alone, which is what
 * a settings form wants and what makes counting requests here misleading if
 * you forget it - the earlier formatting tests left six channels switched on.
 */
$only = static function ( string $slug, array $config, array $top = [] ) use ( $alerts ): void {
	$channels = [];

	foreach ( array_keys( Alerts::channels() ) as $other ) {
		$channels[ $other ] = [ 'enabled' => false ];
	}

	$channels[ $slug ] = array_merge( [ 'enabled' => true ], $config );

	$alerts->save( array_merge( [ 'enabled' => true, 'quiet' => 0 ], $top, [ 'channels' => $channels ] ) );
};

$only( 'webhook', [ 'url' => 'https://example.test/hook' ], [ 'enabled' => false ] );

$requests = [];
$alerts->fire( Alert::from_failure( Alert::SEND_FAILED, new WP_Error( 'x', 'y' ) ) );

check( 'nothing fires while alerts are off', count( $requests ), 0 );

// A test is asking whether the credentials work, not whether the system would
// currently choose to notify - so it ignores the switch.
$requests = [];
$alerts->test( 'webhook' );

check( 'but a test still goes out', count( $requests ), 1 );

$only(
	'webhook',
	[ 'url' => 'https://example.test/hook' ],
	[ 'when' => Alerts::WHEN_EVERY, 'quiet' => 15 ]
);

// Unique per run: the quiet period is a transient keyed on the error code, so
// a fixed code would still be held back from the previous run of this file.
$run = 'run' . wp_rand();

$repeat = static fn(): Alert => Alert::from_failure(
	Alert::SEND_FAILED,
	new WP_Error( 'mmoa_same_fault_' . $run, 'Same fault again' )
);

$requests = [];
$alerts->fire( $repeat() );
$alerts->fire( $repeat() );
$alerts->fire( $repeat() );

check( 'a repeated fault alerts once, not three times', count( $requests ), 1 );

// A different fault is genuinely new information.
$requests = [];
$alerts->fire( Alert::from_failure( Alert::SEND_FAILED, new WP_Error( 'mmoa_other_fault_' . $run, 'Different' ) ) );

check( 'a different error still gets through', count( $requests ), 1 );

// The message that ends the silence must never be throttled.
$requests = [];
$alerts->fire( new Alert( Alert::RECOVERED, time: time() ) );
$alerts->fire( new Alert( Alert::RECOVERED, time: time() ) );

check( 'recovery is never held back', count( $requests ), 2 );

/* -------------------------------------------------------- the secrets ---- */

section( 'credentials do not go back to the browser' );

$alerts->save(
	[
		'enabled'  => true,
		'channels' => [ 'twilio' => [ 'enabled' => true, 'sid' => 'AC1', 'token' => 'super-secret', 'from' => 'a', 'to' => 'b' ] ],
	]
);

$payload = $alerts->payload();
$encoded = (string) wp_json_encode( $payload );

check( 'the token is never in the payload', str_contains( $encoded, 'super-secret' ), false );
check( 'but the screen is told one is set', $payload['channels']['twilio']['fields']['token']['set'] ?? null, true );
check( 'and the channel can still use it', $alerts->config( 'twilio' )['token'] ?? '', 'super-secret' );

// The browser is never sent the value, so it cannot send it back - an empty
// secret has to mean "leave it alone", not "clear it".
$alerts->save(
	[
		'enabled'  => true,
		'channels' => [ 'twilio' => [ 'enabled' => true, 'sid' => 'AC1', 'from' => 'a', 'to' => 'b' ] ],
	]
);

check( 'a save that omits the secret keeps it', $alerts->config( 'twilio' )['token'] ?? '', 'super-secret' );

/* ------------------------------------------------- one address, one place - */

section( 'the alert address is not stored twice' );

$plugin->settings->update( [ 'alert_email' => 'ops@elsewhere.test' ] );
ModernMailer\Settings::flush_cache();

check( 'the email channel reads the Reliability setting', $alerts->config( 'email' )['to'] ?? '', 'ops@elsewhere.test' );

$alerts->save(
	[
		'enabled'  => true,
		'channels' => [ 'email' => [ 'enabled' => true, 'to' => 'someone@else.test' ] ],
	]
);
ModernMailer\Settings::flush_cache();

check( 'and writing it through updates that same setting', (string) $plugin->settings->get( 'alert_email' ), 'someone@else.test' );

$plugin->settings->update( [ 'alert_email' => '' ] );
ModernMailer\Settings::flush_cache();

/* --------------------------------------------------- never our own path --- */

section( 'an alert never travels down the path that just failed' );

$through_wp_mail = 0;
add_filter(
	'wp_mail',
	static function ( $args ) use ( &$through_wp_mail ) {
		$through_wp_mail++;

		return $args;
	}
);

$plugin->settings->update( [ 'alert_email' => 'ops@elsewhere.test' ] );
ModernMailer\Settings::flush_cache();

$alerts->save( [ 'enabled' => true, 'channels' => [ 'email' => [ 'enabled' => true ] ] ] );
$alerts->test( 'email' );

check( 'the email channel does not use wp_mail()', $through_wp_mail, 0 );

$plugin->settings->update( [ 'alert_email' => '' ] );
ModernMailer\Settings::flush_cache();

/* -------------------------------------------------------------- upgrade --- */

section( 'a site upgrading into this keeps its alerts' );

delete_option( Alerts::OPTION );
$plugin->settings->update( [ 'alert_email' => 'existing@admin.test' ] );
ModernMailer\Settings::flush_cache();

$fresh = new Alerts( $plugin->settings, $plugin->secrets );

check( 'alerts are on because an address was already set', $fresh->settings()['enabled'], true );
check( 'and email is the channel', $fresh->is_enabled( 'email' ), true );

delete_option( Alerts::OPTION );
$plugin->settings->update( [ 'alert_email' => '' ] );
ModernMailer\Settings::flush_cache();

$none = new Alerts( $plugin->settings, $plugin->secrets );

check( 'a site with no address gets no alerts it did not ask for', $none->settings()['enabled'], false );

echo "\n";
echo $failed > 0 ? "{$failed} failed, {$passed} passed\n" : "All {$passed} checks passed\n";

exit( $failed > 0 ? 1 : 0 );
