<?php
/**
 * Counting what has been sent.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Providers\Provider_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * A month's send count.
 *
 * This used to be the free tier's meter, and a send past the monthly cap was
 * refused. There is no cap now: nothing here can stop a message, and the
 * dispatcher no longer asks. What is left is the count itself.
 *
 * Two things about it are still worth knowing.
 *
 * **Plain SMTP is counted separately.** `sent` is every message; `metered`
 * leaves out anything sent through an SMTP host the administrator configured
 * themselves. The split is kept because it answers a real question - how much
 * of this site's mail goes through a connection the plugin authenticates - and
 * it is the number the portal is told about.
 *
 * **The count is its own store.** It would be tempting to derive it from the
 * delivery log, which already counts sends - but logging is a setting an
 * administrator can switch off, and a count that stops when logging is
 * disabled is not a count.
 */
class Usage {

	private const DB_VERSION_OPTION = 'mmoa_usage_db_version';
	private const DB_VERSION        = '1';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mmoa_usage';
	}

	/**
	 * One row per month.
	 *
	 * A table rather than an option, and for one reason: two simultaneous sends
	 * both read-modify-writing an option lose a count each time they collide.
	 * `UPDATE ... SET sent = sent + 1` cannot.
	 */
	public static function install(): void {
		global $wpdb;

		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				period CHAR(7) NOT NULL,
				sent INT UNSIGNED NOT NULL DEFAULT 0,
				metered INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (period)
			) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function uninstall(): void {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		delete_option( self::DB_VERSION_OPTION );
	}

	/** The calendar month a count belongs to, in UTC. */
	public static function period(): string {
		return gmdate( 'Y-m' );
	}

	/**
	 * Does this provider's traffic count against the free cap?
	 *
	 * Asked of the provider class, which may answer for itself. Anything that
	 * does not say otherwise is metered, so a provider added by another plugin
	 * needs to know nothing about this.
	 *
	 * @param Provider_Interface|null $provider
	 */
	public static function is_metered( ?object $provider ): bool {
		if ( null === $provider ) {
			return false;
		}

		return ! method_exists( $provider, 'is_metered' ) || $provider::is_metered();
	}

	/**
	 * This month's counts.
	 *
	 * @return array{month:string,sent:int,metered:int}
	 */
	public function report(): array {
		global $wpdb;

		$table  = self::table();
		$period = self::period();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT sent, metered FROM {$table} WHERE period = %s", $period )
		);

		return [
			'month'   => $period,
			'sent'    => (int) ( $row->sent ?? 0 ),
			'metered' => (int) ( $row->metered ?? 0 ),
		];
	}

	/**
	 * Record one send.
	 *
	 * Written with an upsert so two requests arriving together cannot lose a
	 * count between them.
	 */
	public function record( bool $metered ): void {
		global $wpdb;

		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT INTO {$table} (period, sent, metered) VALUES (%s, 1, %d)
				 ON DUPLICATE KEY UPDATE sent = sent + 1, metered = metered + %d",
				self::period(),
				$metered ? 1 : 0,
				$metered ? 1 : 0
			)
		);
	}

	/**
	 * What this month came to, for the screens that show it.
	 *
	 * There is no monthly cap. Sending is never refused on the strength of a
	 * count, so `cap` is zero, `remaining` is -1 and `over` is false for every
	 * site - the shape is kept because the admin app and the REST responses
	 * read these keys, and a missing key is a blank card rather than an honest
	 * "unlimited".
	 *
	 * The counts themselves stay: `sent` and `metered` are what the site
	 * actually put through, which is worth showing whether or not anything
	 * limits it.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		$report = $this->report();

		return [
			'month'     => $report['month'],
			'sent'      => $report['sent'],
			'metered'   => $report['metered'],
			'cap'       => 0,
			'unlimited' => true,
			'remaining' => -1,
			'over'      => false,
		];
	}
}
