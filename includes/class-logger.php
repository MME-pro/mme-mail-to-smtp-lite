<?php
/**
 * Send log.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Providers\Provider_Interface;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Records the outcome of every send attempt.
 *
 * Message bodies are never stored - only envelope metadata and the error. A
 * mail log that keeps message content is a liability the moment the site is
 * compromised, and it is not needed to diagnose delivery problems.
 */
class Logger {

	public const CRON_HOOK = 'mmoa_prune_log';

	private const DB_VERSION_OPTION = 'mmoa_db_version';
	private const DB_VERSION        = '2';

	/**
	 * The page sizes the log offers.
	 *
	 * Fixed rather than free-form: per_page arrives from the browser and ends
	 * up in a LIMIT, and an admin who asks for fifty thousand rows gets a
	 * timeout rather than a log.
	 */
	public const PAGE_SIZES = [ 10, 25, 50, 100 ];

	public const DEFAULT_PAGE_SIZE = 25;

	public function __construct( private Settings $settings ) {}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mmoa_log';
	}

	/**
	 * Create or migrate the log table.
	 */
	public static function install(): void {
		global $wpdb;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				provider varchar(32) NOT NULL DEFAULT '',
				recipients text NOT NULL,
				subject text NOT NULL,
				status varchar(16) NOT NULL DEFAULT '',
				error_code varchar(64) NOT NULL DEFAULT '',
				error_message text NOT NULL,
				bytes int(10) unsigned NOT NULL DEFAULT 0,

				-- The diagnostic report, as JSON. Written only for a failure:
				-- a successful send has nothing to explain, and storing a
				-- transcript for every message would make the log larger than
				-- the mail it describes.
				diagnostics longtext NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),

				-- Enough on its own for the paginated log, though it does not
				-- look it. Paging reads newest-first and usually filtered by
				-- status, which sounds like it wants KEY (status, id) - but
				-- InnoDB appends the primary key to every secondary index, so
				-- this index already is (status, id) on disk. Adding the
				-- explicit pair costs a second write on every message sent and
				-- buys nothing; EXPLAIN picks this one and reports a backward
				-- index scan with no filesort.
				KEY status (status)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function uninstall(): void {
		global $wpdb;

		$table = self::table();

		// Table name cannot be parameterized; it is built from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Record one send attempt.
	 *
	 * @param true|WP_Error $result Outcome.
	 */
	public function record( Provider_Interface $provider, PHPMailer $mailer, int $bytes, $result, string $slot = Settings::SLOT_PRIMARY ): void {
		if ( ! $this->settings->get( 'log_enabled' ) ) {
			return;
		}

		global $wpdb;

		$recipients = array_map(
			static fn( array $addr ): string => $addr[0],
			array_merge( $mailer->getToAddresses(), $mailer->getCcAddresses(), $mailer->getBccAddresses() )
		);

		// Only for failures. A successful send has nothing to explain, and a
		// transcript per message would make the log larger than the mail it
		// describes.
		$diagnostics = null;

		if ( is_wp_error( $result ) ) {
			$diagnostics = wp_json_encode(
				Diagnostics::collect(
					$this->settings,
					$provider::slug(),
					$slot,
					$result,
					// Asked for rather than required: only a protocol with a
					// conversation has one to report.
					method_exists( $provider, 'transcript' ) ? (string) $provider->transcript() : ''
				)
			);
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'created_at'    => current_time( 'mysql', true ),
				'provider'      => $provider->get_label(),
				'recipients'    => implode( ', ', $recipients ),
				'subject'       => $mailer->Subject,
				'status'        => is_wp_error( $result ) ? 'failed' : 'sent',
				'error_code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
				'error_message' => is_wp_error( $result ) ? $result->get_error_message() : '',
				'bytes'         => $bytes,
				'diagnostics'   => $diagnostics,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * One entry, with its diagnostic report.
	 */
	public function entry( int $id ): ?object {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ?: null;
	}

	/**
	 * One page of the log, newest first, with the total the filter matches.
	 *
	 * ## Why the filtering happens here
	 *
	 * It used to happen in the browser, over a fixed two hundred rows, and the
	 * comment said a round trip per keystroke would cost more than it saved.
	 * That stops being true the moment the list is paginated: a search that
	 * only looks at the page you happen to be on is not a search, it is a
	 * find-on-page with extra steps. So status and search go into the SQL, and
	 * the browser debounces instead.
	 *
	 * ## Two queries, not SQL_CALC_FOUND_ROWS
	 *
	 * MySQL deprecated SQL_CALC_FOUND_ROWS in 8.0.17 and removed it in 8.4,
	 * and it was never faster than a separate COUNT over the same WHERE - it
	 * forced the server to materialise every matching row to count them.
	 *
	 * ## The WHERE is built, not parameterised into existence
	 *
	 * Note that an absent filter adds no condition at all, rather than the
	 * tempting `WHERE (%s = '' OR status = %s)`. MySQL tolerates comparing a
	 * bound parameter to a literal; MariaDB rejects it outright with "Illegal
	 * mix of collations", and the failure only appears on the database the
	 * customer is actually running.
	 *
	 * @param int    $page     One-based. Clamped to the last page that exists.
	 * @param int    $per_page One of self::PAGE_SIZES.
	 * @param string $status   'sent', 'failed', or '' for everything.
	 * @param string $search   Matched against recipients, subject and error.
	 * @return array{entries:array<int,object>,total:int,page:int,pages:int,per_page:int}
	 */
	public function page( int $page = 1, int $per_page = self::DEFAULT_PAGE_SIZE, string $status = '', string $search = '' ): array {
		global $wpdb;

		$table    = self::table();
		$per_page = in_array( $per_page, self::PAGE_SIZES, true ) ? $per_page : self::DEFAULT_PAGE_SIZE;

		$where = [];
		$args  = [];

		if ( 'sent' === $status || 'failed' === $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$needle = trim( $search );

		if ( '' !== $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';

			$where[] = '(recipients LIKE %s OR subject LIKE %s OR error_message LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$clause}";

		// prepare() with no placeholders is an error in current WordPress, so
		// the unfiltered count runs as the constant string it is.
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$args ? $wpdb->prepare( $count_sql, $args ) : $count_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$pages = max( 1, (int) ceil( $total / $per_page ) );

		// Clamping rather than returning an empty table: retention prunes the
		// log under the reader, so the page they were on can simply stop
		// existing between one request and the next.
		$page   = max( 1, min( $page, $pages ) );
		$offset = ( $page - 1 ) * $per_page;

		$entries = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} {$clause} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $args, [ $per_page, $offset ] )
			)
		);

		return [
			'entries'  => $entries,
			'total'    => $total,
			'page'     => $page,
			'pages'    => $pages,
			'per_page' => $per_page,
		];
	}

	/**
	 * The newest handful, for the dashboard.
	 *
	 * Deliberately not routed through page(): the dashboard wants eight rows
	 * and no count, and making it pay for a COUNT over the whole table on
	 * every page load is exactly the cost this feature is supposed to avoid.
	 *
	 * @return array<int,object>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
		);
	}

	/**
	 * What happened over the last N days, for the weekly report.
	 *
	 * Three grouped queries rather than pulling the rows and counting them in
	 * PHP: a busy site's week is hundreds of thousands of rows, and the report
	 * runs on cron where nobody is watching to notice it ran out of memory.
	 *
	 * Bounded by the log's own retention window, which is the honest
	 * limitation - a site keeping seven days of log cannot be asked about the
	 * eighth, and the report says so rather than reporting zero.
	 *
	 * @return array{sent:int,failed:int,days:int,retention:int,truncated:bool,
	 *               providers:array<int,object>,errors:array<int,object>,busiest:?object}
	 */
	public function summary( int $days = 7 ): array {
		global $wpdb;

		$days  = max( 1, $days );
		$table = self::table();

		$totals = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM {$table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY status",
				$days
			)
		);

		$sent   = 0;
		$failed = 0;

		foreach ( $totals as $row ) {
			if ( 'sent' === $row->status ) {
				$sent = (int) $row->n;
			} elseif ( 'failed' === $row->status ) {
				$failed = (int) $row->n;
			}
		}

		$providers = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT provider,
				        SUM(status = 'sent')   AS sent,
				        SUM(status = 'failed') AS failed
				 FROM {$table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY provider
				 ORDER BY (sent + failed) DESC",
				$days
			)
		);

		// Grouped by code, not message: the message often carries a request id
		// or a recipient, which would make every occurrence of one fault look
		// like a separate fault.
		$errors = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT error_code, COUNT(*) AS n, MAX(error_message) AS example
				 FROM {$table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				   AND status = 'failed'
				 GROUP BY error_code
				 ORDER BY n DESC
				 LIMIT 5",
				$days
			)
		);

		$busiest = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS n
				 FROM {$table}
				 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY DATE(created_at)
				 ORDER BY n DESC
				 LIMIT 1",
				$days
			)
		);

		$retention = max( 1, (int) $this->settings->get( 'log_retention' ) );

		return [
			'sent'      => $sent,
			'failed'    => $failed,
			'days'      => $days,
			'retention' => $retention,

			// The window asked for is longer than the log keeps, so the
			// numbers below are a floor rather than a count.
			'truncated' => $retention < $days,
			'providers' => $providers,
			'errors'    => $errors,
			'busiest'   => $busiest ?: null,
		];
	}

	/**
	 * Drop entries past the retention window. Runs on cron.
	 */
	public function prune(): void {
		global $wpdb;

		$days = max( 1, (int) $this->settings->get( 'log_retention' ) );
		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
