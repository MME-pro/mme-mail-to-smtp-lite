<?php
/**
 * The weekly summary.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Once a week, what the site's email actually did.
 *
 * ## Why this is not an alert
 *
 * Alerts exist to interrupt. This exists to be skimmed on a Monday and closed,
 * and the two want opposite things: an alert is terse and goes to a phone, a
 * report is complete and goes to an inbox. So they share no code beyond the
 * log, and this has its own address.
 *
 * ## Why it goes through the plugin's own mail path
 *
 * Every other notification in this plugin deliberately avoids wp_mail(),
 * because it is reporting that wp_mail() is broken. This one does the
 * opposite, on purpose. A weekly report that arrives is also a weekly proof
 * that sending works end to end, through the real provider, with the real
 * credentials - the one thing no amount of internal state can tell you. If it
 * stops arriving, that is itself the signal.
 *
 * One consequence to know about: the report is an email like any other, so it
 * goes through the configured connection and lands in the month's count like
 * anything else the site sends.
 */
class Weekly_Report {

	public const CRON_HOOK = 'mmoa_weekly_report';
	public const SCHEDULE  = 'mmoa_weekly';

	public function __construct(
		private Settings $settings,
		private Logger $logger,
		private Queue $queue
	) {}

	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'send' ] );
		add_action( 'admin_init', [ $this, 'maybe_schedule' ] );
	}

	/**
	 * Keep the schedule in step with the setting.
	 *
	 * Checked on admin_init rather than scheduled once on activation, because
	 * the setting can be switched on years later and an administrator who
	 * ticks a box expects the box to mean something without reactivating the
	 * plugin.
	 */
	public function maybe_schedule(): void {
		$wanted = (bool) $this->settings->get( 'report_enabled' );
		$booked = wp_next_scheduled( self::CRON_HOOK );

		if ( $wanted && ! $booked ) {
			// Next Monday morning, site time. Nobody wants the week's summary
			// at 3am Sunday, and a fixed hour keeps it out of the midnight
			// cron pile-up every other plugin schedules into.
			wp_schedule_event( self::first_run(), self::SCHEDULE, self::CRON_HOOK );

			return;
		}

		if ( ! $wanted && $booked ) {
			wp_unschedule_event( $booked, self::CRON_HOOK );
		}
	}

	private static function first_run(): int {
		$monday = strtotime( 'next monday 08:00', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		// current_time() is site-local; wp_schedule_event() wants UTC.
		return (int) $monday - (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}

	public static function unschedule(): void {
		$booked = wp_next_scheduled( self::CRON_HOOK );

		if ( $booked ) {
			wp_unschedule_event( $booked, self::CRON_HOOK );
		}
	}

	/**
	 * Who it goes to. Falls back to the site administrator, because a report
	 * nobody receives is worse than no report - it looks like it is working.
	 *
	 * Several addresses are allowed, and they go in one message rather than
	 * one message each. A weekly summary is a thing a team reads together, so
	 * everyone seeing who else got it is correct here - and one send is one
	 * proof that the connection still works, which is half the point of the
	 * report.
	 *
	 * @return string[]
	 */
	public function recipients(): array {
		$configured = array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) $this->settings->get( 'report_email' ) ) ),
				'is_email'
			)
		);

		if ( [] !== $configured ) {
			return $configured;
		}

		$admin = (string) get_option( 'admin_email' );

		return is_email( $admin ) ? [ $admin ] : [];
	}

	/**
	 * Build and send it.
	 *
	 * @return bool Whether wp_mail() accepted it.
	 */
	public function send(): bool {
		if ( ! $this->settings->get( 'report_enabled' ) ) {
			return false;
		}

		$to = $this->recipients();

		if ( [] === $to ) {
			return false;
		}

		$summary = $this->logger->summary( 7 );

		// A week in which the site sent nothing is usually a week in which
		// nothing happened, not a week worth an email. Silence is the correct
		// report, and sending "0 messages" every Monday trains people to filter
		// the address - which is how the report that matters gets missed.
		if ( 0 === $summary['sent'] && 0 === $summary['failed'] ) {
			return false;
		}

		return wp_mail( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$to,
			$this->subject( $summary ),
			$this->body( $summary ),
			[ 'Content-Type: text/plain; charset=UTF-8' ]
		);
	}

	/**
	 * @param array<string,mixed> $summary
	 */
	public function subject( array $summary ): string {
		$failed = (int) $summary['failed'];

		if ( $failed > 0 ) {
			return sprintf(
				/* translators: 1: site name, 2: messages sent, 3: messages failed. */
				__( '[%1$s] Email this week: %2$d sent, %3$d failed', 'modern-mailer-oauth' ),
				get_bloginfo( 'name' ),
				(int) $summary['sent'],
				$failed
			);
		}

		return sprintf(
			/* translators: 1: site name, 2: messages sent. */
			__( '[%1$s] Email this week: %2$d sent, all delivered', 'modern-mailer-oauth' ),
			get_bloginfo( 'name' ),
			(int) $summary['sent']
		);
	}

	/**
	 * Plain text on purpose. It has to be readable in a terminal, in a phone
	 * preview pane and in whatever an agency forwards it into.
	 *
	 * @param array<string,mixed> $summary
	 */
	public function body( array $summary ): string {
		$sent    = (int) $summary['sent'];
		$failed  = (int) $summary['failed'];
		$total   = $sent + $failed;
		$rate    = $total > 0 ? round( $sent / $total * 100, 1 ) : 0.0;

		$out  = sprintf(
			/* translators: %s: site name. */
			__( 'Email summary for %s', 'modern-mailer-oauth' ),
			get_bloginfo( 'name' )
		) . "\n";
		$out .= str_repeat( '-', 52 ) . "\n\n";

		$out .= sprintf(
			/* translators: 1: delivered count, 2: failed count, 3: success rate. */
			__( "Delivered:     %1\$d\nFailed:        %2\$d\nSuccess rate:  %3\$s%%\n", 'modern-mailer-oauth' ),
			$sent,
			$failed,
			(string) $rate
		);

		$stats = $this->queue->stats();

		if ( (int) $stats['pending'] > 0 || (int) $stats['failed'] > 0 ) {
			$out .= sprintf(
				/* translators: 1: messages waiting to retry, 2: messages given up on. */
				__( "Waiting to retry: %1\$d\nNever delivered:  %2\$d\n", 'modern-mailer-oauth' ),
				(int) $stats['pending'],
				(int) $stats['failed']
			);
		}

		if ( $summary['busiest'] ) {
			$out .= "\n" . sprintf(
				/* translators: 1: date, 2: number of messages. */
				__( 'Busiest day: %1$s (%2$d messages)', 'modern-mailer-oauth' ),
				(string) $summary['busiest']->day,
				(int) $summary['busiest']->n
			) . "\n";
		}

		if ( $summary['providers'] ) {
			$out .= "\n" . __( 'By connection', 'modern-mailer-oauth' ) . "\n";

			foreach ( $summary['providers'] as $row ) {
				$out .= sprintf(
					"  %-24s %d sent, %d failed\n",
					(string) $row->provider,
					(int) $row->sent,
					(int) $row->failed
				);
			}
		}

		if ( $summary['errors'] ) {
			$out .= "\n" . __( 'What went wrong', 'modern-mailer-oauth' ) . "\n";

			foreach ( $summary['errors'] as $row ) {
				$out .= sprintf(
					"  %dx  %s\n      %s\n",
					(int) $row->n,
					(string) $row->error_code,
					mb_substr( (string) $row->example, 0, 160 )
				);
			}
		}

		if ( ! empty( $summary['truncated'] ) ) {
			$out .= "\n" . sprintf(
				/* translators: %d: the log retention setting, in days. */
				__( 'Note: the send log only keeps %d days, so the figures above cover that period rather than a full week.', 'modern-mailer-oauth' ),
				(int) $summary['retention']
			) . "\n";
		}

		$out .= "\n" . str_repeat( '-', 52 ) . "\n";
		$out .= sprintf(
			/* translators: %s: URL of the plugin's log screen. */
			__( 'Full log: %s', 'modern-mailer-oauth' ),
			admin_url( 'admin.php?page=modern-mailer-oauth#/logs' )
		) . "\n";
		$out .= __( 'Turn this off under Settings, Reliability.', 'modern-mailer-oauth' ) . "\n";

		return $out;
	}
}
