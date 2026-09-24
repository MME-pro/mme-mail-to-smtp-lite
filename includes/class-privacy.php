<?php
/**
 * Data subject rights, through WordPress's own privacy tools.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Answers export and erasure requests for the data this plugin holds.
 *
 * ## What this plugin is, in data-protection terms
 *
 * A transport. WordPress hands it a finished message and it delivers that
 * message to a provider. It has no forms, no subscribers, no cookies and no
 * tracking of any kind, and it collects no IP addresses - so there is nothing
 * here for anybody to consent to, and the lawful basis for any given email
 * belongs to whatever produced it rather than to the thing that carried it.
 *
 * What it does hold is a record of messages it carried, and that record
 * contains other people's email addresses:
 *
 * - The log keeps the recipients, the subject and, for a failure, a diagnostic
 *   report whose SMTP transcript names the recipients again.
 * - The queue keeps the complete message - body, headers and attachments -
 *   for as long as a retry might still be wanted.
 *
 * Both are personal data belonging to the recipient, not to the site. Until
 * this class existed, a site owner answering a request through Tools, Erase
 * Personal Data got nothing back from this plugin while both tables sat there
 * holding the answer.
 *
 * ## Erase means two different things here
 *
 * A queued message is deleted outright. It has not been sent, its body is
 * sitting in the database in the clear, and someone has just asked to be
 * forgotten - delivering it afterwards would be the opposite of honouring
 * that.
 *
 * A log entry is anonymised instead: the address and subject are replaced and
 * the diagnostics dropped, while the timestamp, provider and status remain.
 * That keeps the operational record - how much mail failed, and when - which
 * the site owner has their own reason to hold, without keeping anything that
 * identifies the person. Which of the two happened is reported back, because
 * "we kept a row" is exactly the kind of thing a requester is entitled to be
 * told.
 *
 * ## Matching
 *
 * Recipients are stored as one comma-joined string, so the search has to be a
 * LIKE and the LIKE has to be checked afterwards. `bob@example.com` is a
 * substring of `bbob@example.com`, and erasing the wrong person's record while
 * answering a privacy request would be a poor way to comply with one.
 */
class Privacy {

	/** Rows per page. WordPress calls back until done is true. */
	private const BATCH = 50;

	/** The exporter and eraser both answer under this key. */
	private const SLUG = 'modern-mailer-oauth';

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );

		// Suggested privacy-policy text. WordPress shows it on the policy
		// editor for the admin to accept or rewrite - it is a suggestion, and
		// deliberately not something this plugin publishes on their behalf.
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	/**
	 * @param array<string,mixed> $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::SLUG ] = [
			'exporter_friendly_name' => __( 'MME-Mail to SMTP', 'modern-mailer-oauth' ),
			'callback'               => [ $this, 'export' ],
		];

		return $exporters;
	}

	/**
	 * @param array<string,mixed> $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::SLUG ] = [
			'eraser_friendly_name' => __( 'MME-Mail to SMTP', 'modern-mailer-oauth' ),
			'callback'             => [ $this, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Everything held about one address.
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$page   = max( 1, $page );
		$export = [];

		$logs = $this->rows( Logger::table(), $email, $page, [ 'id', 'created_at', 'provider', 'recipients', 'subject', 'status', 'error_code', 'error_message' ] );

		foreach ( $logs as $row ) {
			$export[] = [
				'group_id'          => 'mmoa-log',
				'group_label'       => __( 'Email delivery log', 'modern-mailer-oauth' ),
				'group_description' => __( 'Messages this site sent to this address, and what the mail provider said about each one.', 'modern-mailer-oauth' ),
				'item_id'           => 'mmoa-log-' . (int) $row->id,
				'data'              => [
					[
						'name'  => __( 'Sent', 'modern-mailer-oauth' ),
						'value' => (string) $row->created_at,
					],
					[
						'name'  => __( 'Recipients', 'modern-mailer-oauth' ),
						'value' => (string) $row->recipients,
					],
					[
						'name'  => __( 'Subject', 'modern-mailer-oauth' ),
						'value' => (string) $row->subject,
					],
					[
						'name'  => __( 'Sent through', 'modern-mailer-oauth' ),
						'value' => (string) $row->provider,
					],
					[
						'name'  => __( 'Result', 'modern-mailer-oauth' ),
						'value' => (string) $row->status,
					],
					[
						'name'  => __( 'Failure reason', 'modern-mailer-oauth' ),
						'value' => trim( $row->error_code . ' ' . $row->error_message ),
					],
				],
			];
		}

		$queued = $this->rows( Queue::table(), $email, $page, [ 'id', 'created_at', 'recipients', 'subject', 'status', 'attempts' ] );

		foreach ( $queued as $row ) {
			// The stored copy of the message itself is not exported. It is the
			// site's outgoing mail rather than a record about the recipient,
			// it can carry other people's addresses in Cc, and a privacy
			// export is delivered as a file the requester downloads - putting
			// full message bodies in it would disclose more than it answers.
			$export[] = [
				'group_id'          => 'mmoa-queue',
				'group_label'       => __( 'Email waiting to be sent', 'modern-mailer-oauth' ),
				'group_description' => __( 'Messages held for retry after a delivery failure. The message itself is stored but is not included here.', 'modern-mailer-oauth' ),
				'item_id'           => 'mmoa-queue-' . (int) $row->id,
				'data'              => [
					[
						'name'  => __( 'Queued', 'modern-mailer-oauth' ),
						'value' => (string) $row->created_at,
					],
					[
						'name'  => __( 'Recipients', 'modern-mailer-oauth' ),
						'value' => (string) $row->recipients,
					],
					[
						'name'  => __( 'Subject', 'modern-mailer-oauth' ),
						'value' => (string) $row->subject,
					],
					[
						'name'  => __( 'State', 'modern-mailer-oauth' ),
						'value' => (string) $row->status,
					],
					[
						'name'  => __( 'Delivery attempts', 'modern-mailer-oauth' ),
						'value' => (string) (int) $row->attempts,
					],
				],
			];
		}

		return [
			'data' => $export,

			// A short page means there is nothing after it. Counting both
			// tables together is deliberate: either can run out first, and
			// stopping when the combined page is short is the only condition
			// that waits for both.
			'done' => count( $logs ) < self::BATCH && count( $queued ) < self::BATCH,
		];
	}

	/**
	 * Forget one address: delete what is unsent, anonymise what was sent.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;

		unset( $page );

		$removed  = false;
		$retained = false;
		$messages = [];

		// Always the first page. Every row this finds is either deleted or
		// rewritten so that it no longer matches, so paging forward would step
		// over rows that have just moved.
		$queued = $this->rows( Queue::table(), $email, 1, [ 'id' ] );

		foreach ( $queued as $row ) {
			$wpdb->delete( Queue::table(), [ 'id' => (int) $row->id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$removed = true;
		}

		if ( [] !== $queued ) {
			$messages[] = sprintf(
				/* translators: %d: number of queued messages. */
				_n(
					'%d message waiting to be sent to this address was deleted and will not be delivered.',
					'%d messages waiting to be sent to this address were deleted and will not be delivered.',
					count( $queued ),
					'modern-mailer-oauth'
				),
				count( $queued )
			);
		}

		$logs = $this->rows( Logger::table(), $email, 1, [ 'id' ] );

		foreach ( $logs as $row ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Logger::table(),
				[
					'recipients'    => self::REDACTED,
					'subject'       => self::REDACTED,
					'error_message' => '',

					// The transcript inside this names the recipient too, and
					// nothing in it is worth keeping once the row it explains
					// has been anonymised.
					'diagnostics'   => null,
				],
				[ 'id' => (int) $row->id ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			$removed  = true;
			$retained = true;
		}

		if ( [] !== $logs ) {
			$messages[] = sprintf(
				/* translators: %d: number of log entries. */
				_n(
					'%d delivery log entry was anonymised. The date and outcome were kept as a record that a message was sent; the address, subject and diagnostics were removed.',
					'%d delivery log entries were anonymised. The dates and outcomes were kept as a record that messages were sent; the addresses, subjects and diagnostics were removed.',
					count( $logs ),
					'modern-mailer-oauth'
				),
				count( $logs )
			);
		}

		// Done only when a pass found nothing left. Anything this pass touched
		// no longer matches the address, so the next pass sees what is left.
		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => [] === $queued && [] === $logs,
		];
	}

	/** What an anonymised log row holds instead of an address. */
	private const REDACTED = '[removed]';

	/**
	 * Rows of one table whose recipients genuinely include this address.
	 *
	 * @param array<int,string> $columns Columns to select.
	 * @return array<int,object>
	 */
	private function rows( string $table, string $email, int $page, array $columns ): array {
		global $wpdb;

		$email = trim( $email );

		if ( '' === $email || ! is_email( $email ) ) {
			return [];
		}

		// Column names are ours, never request input - they are the literals
		// passed in above - so they are safe to interpolate, and they cannot be
		// bound as parameters in any case.
		//
		// recipients is added whether the caller asked for it or not. The
		// check below needs it, and a caller that selects only ids - which the
		// eraser does, because it has no use for anything else - would
		// otherwise leave that check with nothing to look at.
		$columns[] = 'recipients';

		$select = implode( ', ', array_unique( array_map( 'sanitize_key', $columns ) ) );
		$like   = '%' . $wpdb->esc_like( $email ) . '%';
		$offset = ( max( 1, $page ) - 1 ) * self::BATCH;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT {$select} FROM {$table} WHERE recipients LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$like,
				self::BATCH,
				$offset
			)
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		// LIKE matches substrings, and an address is a substring of a longer
		// address - bob@example.com sits inside bbob@example.com. Acting on
		// that while answering an erasure request would delete a stranger's
		// mail, so every row is checked against the actual list before it
		// counts as a match. Recipients are joined with ", " by the logger.
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $email ): bool {
					// Fails closed. This decides whether a row is deleted, so
					// "cannot tell" has to mean no - the version that answered
					// yes here made the whole check vacuous for the eraser and
					// erased a bystander whose address merely contained the
					// requester's.
					if ( ! isset( $row->recipients ) ) {
						return false;
					}

					foreach ( explode( ',', (string) $row->recipients ) as $recipient ) {
						if ( 0 === strcasecmp( trim( $recipient ), $email ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
	}

	/**
	 * Suggested text for the site's privacy policy.
	 *
	 * Suggested, not published. WordPress puts it on the policy editor for the
	 * admin to read, edit and accept, which is the right division: what the
	 * plugin knows is where the mail goes, and what the site owner knows is
	 * why they are sending it and on what basis.
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'This site sends its email through a third-party mail provider rather than through the web server. The complete message - recipients, subject, body and any attachments - is transmitted to that provider in order to be delivered.', 'modern-mailer-oauth' ) . '</p>'
			. '<p>' . __( 'A record of each message sent is kept: the recipients, the subject, the time, and whether delivery succeeded. Where a delivery fails, a diagnostic report is kept with it. These records are deleted automatically after the retention period set by the site administrator.', 'modern-mailer-oauth' ) . '</p>'
			. '<p>' . __( 'A message that cannot be delivered immediately is held, in full, until it can be retried or until it is discarded as undeliverable.', 'modern-mailer-oauth' ) . '</p>'
			. '<p>' . __( 'This plugin sets no cookies, records no IP addresses, and does nothing in a visitor&#8217;s browser.', 'modern-mailer-oauth' ) . '</p>'

			// Named here as well as in the readme, because this is the text a
			// site owner publishes to their own visitors. A phone-home that is
			// documented only in a file nobody reads is not disclosed.
			. '<p>' . __( 'The plugin also checks in daily with its own vendor&#8217;s licensing service. That check-in carries this site&#8217;s address, the versions of the software it runs on, which mail providers are configured, and the number of messages sent in the current month. It never carries a recipient, a subject, a message body or a credential, and it is not involved in delivering any message.', 'modern-mailer-oauth' ) . '</p>'

			. '<p>' . __( 'The administrator should name the mail provider in use here, and link to that provider&#8217;s own privacy policy.', 'modern-mailer-oauth' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'MME-Mail to SMTP', 'modern-mailer-oauth' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
