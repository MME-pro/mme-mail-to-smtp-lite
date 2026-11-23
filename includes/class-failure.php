<?php
/**
 * Failure classification.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a failed send is worth trying again.
 *
 * This is the judgement the rest of the resilience machinery depends on, and
 * getting it wrong is expensive in both directions. Retrying a permanent
 * failure - a wrong client secret, an unlicensed mailbox - burns the queue on
 * something that will never succeed and delays the mail behind it. Not
 * retrying a transient one throws away a message that would have gone out
 * fine seconds later.
 *
 * The distinction is not "did it fail" but "would the identical request
 * plausibly succeed later with no human involved". Only transport faults and
 * explicit back-pressure qualify.
 */
class Failure {

	/**
	 * Error codes that describe a fault between us and the API, not a fault in
	 * what we asked for.
	 *
	 * `http_request_failed` is the one that matters most here: it is what
	 * WordPress returns for every cURL-level fault, which covers DNS
	 * resolution failure, connection refused, TLS handshake failure and
	 * timeout. A site whose host has flaky outbound DNS produces a long run of
	 * these while the credentials and configuration are entirely correct.
	 */
	/**
	 * Codes that mean the connection, not the message, is what failed: its
	 * credentials, its grant, or a setting it cannot send without.
	 */
	private const CONNECTION_CODES = [
		'mmoa_smtp_auth_failed',
		'mmoa_api_unauthorized',
		'mmoa_bad_key',
		'mmoa_provider_incomplete',
		'mmoa_mailgun_unauthorized',
		'mmoa_gmail_invalid_grant',
		'mmoa_gmail_not_connected',
		'mmoa_gmail_oauth_incomplete',
		'mmoa_gmail_sa_incomplete',
		'mmoa_gmail_unauthorized_client',
		'mmoa_graph_incomplete',
		'mmoa_graph_unauthorized',
		'mmoa_graph_access_denied',
		'mmoa_ms_bad_secret',
		'mmoa_ms_grant_expired',
		'mmoa_ms_not_connected',
		'mmoa_ms_oauth_incomplete',
		'mmoa_ms_token_failed',
		'mmoa_ms_send_as_denied',
		'mmoa_outlook_unauthorized',
		'mmoa_outlook_forbidden',
		'mmoa_one_click_not_connected',
		'mmoa_broker_no_access_token',
		'mmoa_broker_no_refresh_token',
	];

	private const TRANSPORT_CODES = [
		'http_request_failed',
		'http_request_timeout',
		'mmoa_http_failed',
	];

	/**
	 * Provider-level codes that mean "not now" rather than "not ever".
	 */
	private const BACKPRESSURE_CODES = [

		'mmoa_gmail_rate_limited',

		// SMTP inverts the convention the HTTP providers use: a 4xx reply is the
		// temporary one - greylisting, "try again later", over quota - and a 5xx
		// is the permanent refusal. Reading these through the HTTP status rule
		// below would get it exactly backwards, retrying a rejected recipient
		// for two days and dropping a greylisted message on the first attempt.
		// The SMTP provider therefore classifies the reply itself and says so in
		// the error code.
		'mmoa_smtp_temporary',
		'mmoa_smtp_connect_failed',
	];

	/**
	 * Would this failure plausibly succeed on a later identical attempt?
	 */
	public static function is_retryable( WP_Error $error ): bool {
		$code = $error->get_error_code();

		if ( in_array( $code, self::TRANSPORT_CODES, true ) ) {
			return true;
		}

		if ( in_array( $code, self::BACKPRESSURE_CODES, true ) ) {
			return true;
		}

		// Providers annotate mapped API errors with the HTTP status where the
		// status alone is the deciding factor, so a 503 from an endpoint we
		// have no specific mapping for is still recognised as transient.
		$status = (int) ( $error->get_error_data()['status'] ?? 0 );

		if ( 429 === $status || ( $status >= 500 && $status <= 599 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Did the connection fail, rather than the message or the network?
	 *
	 * A rejected password, a revoked API key, an expired sign-in, a connection
	 * missing a credential. Retrying on a timer does not fix any of these - but
	 * an administrator does, usually within the hour, and until 1.1.2 every
	 * email sent in between was thrown away. These are now held in the queue
	 * and sent the moment the connection is saved, verified or signed in again.
	 *
	 * Failures of the message itself - a refused recipient, a body too large -
	 * are not here: no fix to the connection changes them.
	 */
	public static function is_connection_fault( WP_Error $error ): bool {
		$code = $error->get_error_code();

		$is_fault = in_array( $code, self::CONNECTION_CODES, true )
			// Entra token-endpoint failures: a wrong, expired or rotated
			// client secret, a missing consent.
			|| 0 === strpos( (string) $code, 'mmoa_aad_' )
			|| 401 === (int) ( ( (array) $error->get_error_data() )['status'] ?? 0 );

		/**
		 * Filters whether a failure is the connection's, and so held in the
		 * queue until the connection is fixed.
		 *
		 * @since 1.1.3
		 *
		 * @param bool     $is_fault Whether it is.
		 * @param WP_Error $error    The failure.
		 */
		return (bool) apply_filters( 'mmoa_connection_fault', $is_fault, $error );
	}

	/**
	 * Should a failed message be kept in the queue at all?
	 *
	 * Yes for a fault that may pass on its own, and for one an administrator
	 * fixes by repairing the connection.
	 */
	public static function is_queueable( WP_Error $error ): bool {
		return self::is_retryable( $error ) || self::is_connection_fault( $error );
	}

	/**
	 * Is this failure one a different connection might survive?
	 *
	 * Broader than is_retryable(): a fallback connection has its own
	 * credentials and its own endpoint, so it can get past things that are
	 * permanent for the primary. A revoked API key is fatal on the primary
	 * forever, and says nothing about a backup on another provider.
	 *
	 * The exclusions are failures of the *message* rather than of the
	 * connection. A second provider would refuse those too, and the log would
	 * carry the same rejection twice.
	 */
	public static function should_fail_over( WP_Error $error ): bool {
		$fatal_for_any_connection = [
			'mmoa_message_too_large',
			'mmoa_graph_too_large',
			'mmoa_gmail_too_large',
			'mmoa_no_provider',
			'mmoa_invalid_url',
			'mmoa_no_recipient',
			'mmoa_gmail_no_recipient',
		];

		return ! in_array( $error->get_error_code(), $fatal_for_any_connection, true );
	}
}
