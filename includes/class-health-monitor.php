<?php
/**
 * Failure detection and alerting.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Alerts\Alert;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Watches for repeated send failures and makes sure somebody hears about it.
 *
 * This is the piece that addresses the actual reported symptom. The underlying
 * cause of "our site stopped sending email" is rarely that a send failed once;
 * it is that nothing surfaced the failure, because almost no WordPress code
 * checks what wp_mail() returned. Weeks pass before a customer mentions they
 * never got their receipt.
 */
class Health_Monitor {

	private const OPTION = 'mmoa_health';

	/**
	 * @param Alerts|null $alerts Optional so the monitor can still be built on
	 *                            its own - it counts perfectly well without
	 *                            anywhere to send the result.
	 */
	public function __construct( private Settings $settings, private ?Alerts $alerts = null ) {}

	/**
	 * @return array{streak:int,alerted:bool,last_error:array,last_success:int}
	 */
	public function state(): array {
		$state = get_option( self::OPTION, [] );

		return wp_parse_args(
			is_array( $state ) ? $state : [],
			[
				'streak'       => 0,
				'alerted'      => false,
				'last_error'   => [],
				'last_success' => 0,
			]
		);
	}

	public function record_success(): void {
		$state = $this->state();

		// The message that ends the silence. Only sent if an alert went out in
		// the first place - a site that never broke does not need telling it
		// is working.
		if ( $state['alerted'] ) {
			$this->fire( new Alert( Alert::RECOVERED, time: time() ) );
		}

		if ( 0 === $state['streak'] && ! $state['alerted'] ) {
			// Nothing to clear; avoid a write on every single email.
			if ( $state['last_success'] > ( time() - HOUR_IN_SECONDS ) ) {
				return;
			}
		}

		update_option(
			self::OPTION,
			[
				'streak'       => 0,
				'alerted'      => false,
				'last_error'   => [],
				'last_success' => time(),
			],
			false
		);
	}

	/**
	 * @param array<string,mixed> $context Recipients, subject, mailer and slot
	 *                                     from the message that failed. Absent
	 *                                     when the caller has no message - a
	 *                                     queue drain, say.
	 */
	public function record_failure( WP_Error $error, array $context = [] ): void {
		$state = $this->state();

		$state['streak']    = (int) $state['streak'] + 1;
		$state['last_error'] = [
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'time'    => time(),
		];

		$threshold = max( 1, (int) $this->settings->get( 'alert_threshold' ) );
		$crossed   = ! $state['alerted'] && $state['streak'] >= $threshold;

		if ( $crossed ) {
			$state['alerted'] = true;
		}

		update_option( self::OPTION, $state, false );

		$context['streak'] = (int) $state['streak'];

		// Per-message alerting, for sites that want to hear about each one.
		// Alerts decides whether this mode is on; the monitor does not need to
		// know, and asking it to would put the same rule in two places.
		if ( null !== $this->alerts && Alerts::WHEN_EVERY === $this->alerts->settings()['when'] ) {
			$this->fire( Alert::from_failure( Alert::SEND_FAILED, $error, $context ) );
		}

		if ( $crossed ) {
			$this->alert( $error, (int) $state['streak'], $context );
		}
	}

	public function is_failing(): bool {
		return (bool) $this->state()['alerted'];
	}

	public function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function alert( WP_Error $error, int $streak, array $context = [] ): void {
		/**
		 * Fires once when sending has failed enough times in a row to count as
		 * an outage. Wire this to Slack, PagerDuty, or an uptime monitor.
		 *
		 * Still here, and still the documented integration point, although the
		 * Alerts tab now covers the same ground with a form. Somebody has this
		 * in a mu-plugin.
		 *
		 * @param WP_Error $error  The most recent failure.
		 * @param int      $streak Consecutive failures.
		 */
		do_action( 'mmoa_send_failing', $error, $streak );

		$this->fire( Alert::from_failure( Alert::NOW_FAILING, $error, $context ) );
	}

	/**
	 * Hand an alert over, if there is anywhere to hand it.
	 *
	 * Nothing here may throw. This runs inside a failed send, which is already
	 * inside somebody's checkout, and an exception raised while reporting a
	 * problem would replace a failed email with a fatal error.
	 */
	private function fire( Alert $alert ): void {
		if ( null === $this->alerts ) {
			return;
		}

		try {
			$this->alerts->fire( $alert );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
