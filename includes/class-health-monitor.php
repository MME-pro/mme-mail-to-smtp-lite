<?php
/**
 * Failure detection.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Watches for repeated send failures and records that sending is broken.
 *
 * This is the piece that addresses the actual reported symptom. The underlying
 * cause of "our site stopped sending email" is rarely that a send failed once;
 * it is that nothing surfaced the failure, because almost no WordPress code
 * checks what wp_mail() returned. Weeks pass before a customer mentions they
 * never got their receipt.
 *
 * This class only counts and records. What surfaces the result is the admin
 * notice, the Site Health test, and the two actions below - which is how an
 * add-on delivers the same news to Slack, Teams, an SMS or a webhook.
 */
class Health_Monitor {

	private const OPTION = 'mmoa_health';

	public function __construct( private Settings $settings ) {}

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

		// The event that ends the silence. Only fired if the site had actually
		// been recorded as failing - one that never broke does not need telling
		// it is working.
		if ( $state['alerted'] ) {
			/**
			 * Fires once when sending starts working again after an outage.
			 *
			 * The counterpart to `mmoa_send_failing`. Anything that announced
			 * the outage wants to announce the recovery, and firing only on the
			 * way down leaves whoever was told holding a stale warning.
			 */
			do_action( 'mmoa_send_recovered' );
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

		$state['streak']     = (int) $state['streak'] + 1;
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

		/**
		 * Fires on every failed send, whatever the streak.
		 *
		 * Use this to hear about each individual failure. For "tell me once
		 * when sending is actually broken" use `mmoa_send_failing` instead -
		 * one failed message is not an outage, and wiring a notifier here on a
		 * busy site produces a great deal of noise.
		 *
		 * @param WP_Error             $error   The failure.
		 * @param array<string,mixed>  $context Recipients, subject, mailer,
		 *                                      slot and the current streak.
		 */
		do_action( 'mmoa_send_failed', $error, $context );

		if ( ! $crossed ) {
			return;
		}

		/**
		 * Fires once when sending has failed enough times in a row to count as
		 * an outage. Wire this to Slack, PagerDuty, or an uptime monitor.
		 *
		 * Fired once per outage rather than per message, and paired with
		 * `mmoa_send_recovered`.
		 *
		 * @param WP_Error $error  The most recent failure.
		 * @param int      $streak Consecutive failures.
		 */
		do_action( 'mmoa_send_failing', $error, (int) $state['streak'] );
	}

	public function is_failing(): bool {
		return (bool) $this->state()['alerted'];
	}

	public function reset(): void {
		delete_option( self::OPTION );
	}
}
