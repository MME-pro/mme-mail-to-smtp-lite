<?php
/**
 * The thing an alert is about.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One alert, in a shape every channel can render.
 *
 * Channels differ in format - Slack wants blocks, Teams wants an adaptive
 * card, Twilio wants 1,600 characters of plain text - but none of them differ
 * in *what* they need to say. So the payload is assembled once, here, and each
 * channel is only responsible for arranging it.
 *
 * ## What is in it, and why that is a decision
 *
 * The recipient address and the subject line are in the payload because an
 * alert without them cannot be acted on: "a message failed" sends an admin to
 * the log to find out which one, which is the delay the alert existed to
 * remove.
 *
 * But that means an outgoing recipient address and subject leave the site for
 * whichever service the administrator configured. The log deliberately never
 * holds a message body, and that is not changed here - no body, no
 * attachment, no credential is ever put in an alert. The two fields that do
 * travel are named on the settings screen, so the choice is made knowingly.
 */
final class Alert {

	public const SEND_FAILED  = 'send_failed';
	public const NOW_FAILING  = 'now_failing';
	public const BACKUP_USED  = 'backup_used';
	public const RECOVERED    = 'recovered';
	public const TEST         = 'test';

	/**
	 * @param string $event      One of the constants above.
	 * @param string $recipients Comma-separated, as the log stores them.
	 * @param string $subject    The failed message's subject.
	 * @param int    $time       Unix timestamp, UTC.
	 * @param string $code       Machine-readable error code.
	 * @param string $message    Human-readable reason.
	 * @param string $mailer     The connection's provider label, e.g. "Microsoft 365".
	 * @param string $slot       Which connection: primary, backup, or a custom id.
	 * @param int    $streak     Consecutive failures at the moment of the alert.
	 */
	public function __construct(
		public readonly string $event,
		public readonly string $recipients = '',
		public readonly string $subject = '',
		public readonly int $time = 0,
		public readonly string $code = '',
		public readonly string $message = '',
		public readonly string $mailer = '',
		public readonly string $slot = '',
		public readonly int $streak = 0
	) {}

	/**
	 * Build one from a failed send.
	 */
	public static function from_failure( string $event, WP_Error $error, array $context = [] ): self {
		return new self(
			$event,
			(string) ( $context['recipients'] ?? '' ),
			(string) ( $context['subject'] ?? '' ),
			(int) ( $context['time'] ?? time() ),
			(string) $error->get_error_code(),
			(string) $error->get_error_message(),
			(string) ( $context['mailer'] ?? '' ),
			(string) ( $context['slot'] ?? '' ),
			(int) ( $context['streak'] ?? 0 )
		);
	}

	/**
	 * A test alert, filled with obviously fake but correctly shaped values.
	 *
	 * Fake rather than the last real failure: a test sent from a working site
	 * would otherwise have nothing to put in it, and an alert with five empty
	 * fields does not prove the channel renders them.
	 */
	public static function test(): self {
		return new self(
			self::TEST,
			'recipient@example.com',
			__( 'Test alert - your order is confirmed', 'modern-mailer-oauth' ),
			time(),
			'mmoa_test_alert',
			__( 'This is a test. Nothing is wrong.', 'modern-mailer-oauth' ),
			__( 'Microsoft 365', 'modern-mailer-oauth' ),
			'primary',
			0
		);
	}

	/**
	 * One line, for a channel that only gets one - a push notification, an SMS.
	 */
	public function title(): string {
		$site = (string) get_bloginfo( 'name' );

		switch ( $this->event ) {
			case self::NOW_FAILING:
				return sprintf(
					/* translators: 1: site name, 2: consecutive failures. */
					__( '[%1$s] Email is failing - %2$d in a row', 'modern-mailer-oauth' ),
					$site,
					$this->streak
				);

			case self::BACKUP_USED:
				return sprintf(
					/* translators: %s: site name. */
					__( '[%s] Sending fell back to the backup connection', 'modern-mailer-oauth' ),
					$site
				);

			case self::RECOVERED:
				return sprintf(
					/* translators: %s: site name. */
					__( '[%s] Email is sending again', 'modern-mailer-oauth' ),
					$site
				);

			case self::TEST:
				return sprintf(
					/* translators: %s: site name. */
					__( '[%s] Test alert from MME-Mail to SMTP', 'modern-mailer-oauth' ),
					$site
				);

			default:
				return sprintf(
					/* translators: %s: site name. */
					__( '[%s] An email failed to send', 'modern-mailer-oauth' ),
					$site
				);
		}
	}

	/**
	 * The payload, as label => value, in the order a reader wants it.
	 *
	 * Empty values are dropped rather than rendered blank: a "now failing"
	 * alert has a streak and no single recipient, and a channel should not
	 * have to know that to lay it out.
	 *
	 * @return array<string,string>
	 */
	public function lines(): array {
		$lines = [
			__( 'Failed recipient', 'modern-mailer-oauth' ) => $this->recipients,
			__( 'Subject', 'modern-mailer-oauth' )          => $this->subject,
			__( 'When', 'modern-mailer-oauth' )             => $this->when(),
			__( 'Error', 'modern-mailer-oauth' )            => $this->reason(),
			__( 'Sent via', 'modern-mailer-oauth' )         => $this->connection(),
		];

		if ( $this->streak > 1 ) {
			$lines[ __( 'Consecutive failures', 'modern-mailer-oauth' ) ] = (string) $this->streak;
		}

		$lines[ __( 'Site', 'modern-mailer-oauth' ) ] = home_url();

		return array_filter( $lines, static fn( string $v ): bool => '' !== trim( $v ) );
	}

	/**
	 * The timestamp in the site's own timezone, because that is the one the
	 * administrator reading the alert keeps their day in.
	 */
	public function when(): string {
		if ( 0 === $this->time ) {
			return '';
		}

		return (string) wp_date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			$this->time
		);
	}

	/**
	 * Code and reason together - neither is much use alone. The code is what
	 * you search for; the message is what you read.
	 */
	public function reason(): string {
		if ( '' === $this->code ) {
			return $this->message;
		}

		if ( '' === $this->message ) {
			return $this->code;
		}

		return sprintf( '%s (%s)', $this->message, $this->code );
	}

	public function connection(): string {
		$slot = '' === $this->slot
			? ''
			: sprintf(
				/* translators: %s: connection slot name, e.g. "primary". */
				__( '%s connection', 'modern-mailer-oauth' ),
				$this->slot
			);

		if ( '' === $this->mailer ) {
			return $slot;
		}

		return '' === $slot ? $this->mailer : sprintf( '%s - %s', $this->mailer, $slot );
	}

	/**
	 * Plain text, for email bodies and anything with no formatting at all.
	 */
	public function as_text(): string {
		$out = $this->title() . "\n\n";

		foreach ( $this->lines() as $label => $value ) {
			$out .= sprintf( "%s: %s\n", $label, $value );
		}

		$out .= "\n" . sprintf(
			/* translators: %s: URL of the plugin's log screen. */
			__( 'Send log: %s', 'modern-mailer-oauth' ),
			$this->log_url()
		);

		return $out;
	}

	public function log_url(): string {
		return admin_url( 'admin.php?page=modern-mailer-oauth#/logs' );
	}

	/**
	 * Is this an alert about something being wrong, rather than resolved?
	 *
	 * Channels that colour their messages use this rather than testing the
	 * event, so a new event type gets a sensible colour without every channel
	 * needing to learn about it.
	 */
	public function is_bad(): bool {
		return ! in_array( $this->event, [ self::RECOVERED, self::TEST ], true );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'event'      => $this->event,
			'recipients' => $this->recipients,
			'subject'    => $this->subject,
			'time'       => $this->time,
			'code'       => $this->code,
			'message'    => $this->message,
			'mailer'     => $this->mailer,
			'slot'       => $this->slot,
			'streak'     => $this->streak,
			'site'       => home_url(),
			'site_name'  => get_bloginfo( 'name' ),
		];
	}
}
