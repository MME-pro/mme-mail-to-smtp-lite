<?php
/**
 * Registering with the portal, and checking in.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the vendor's portal that this site exists, and keeps it current.
 *
 * Three moments: once at activation, once a day afterwards, and once more when
 * the plugin is deleted. Nothing here is on the path a message takes - if the
 * portal is unreachable, or switched off, mail is entirely unaffected. That is
 * not an accident and it is not negotiable: a licensing service that can stop a
 * customer's email is worse than the piracy it prevents.
 *
 * What is sent is listed in Portal::describe() and in the plugin's own privacy
 * documentation. It is versions, a domain, and counts. It is never a recipient,
 * a subject, a message body or a credential.
 */
class Install_Report {

	/** Runs daily. */
	public const CRON_HOOK = 'mmoa_portal_checkin';

	/** Set at activation, acted on by the next admin request. */
	private const REGISTER_FLAG = 'mmoa_portal_register';

	/**
	 * How long to wait after repeated failures before trying again.
	 *
	 * A portal that is down should be retried tomorrow, not every five minutes
	 * for a week. The check-in carries nothing urgent - the licence is cached
	 * and has its own grace period - so backing off costs nothing and spares
	 * both ends.
	 */
	private const BACKOFF = [ 0, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, 3 * DAY_IN_SECONDS ];

	public function __construct( private Portal $portal, private Usage $usage ) {}

	public function register_hooks(): void {
		add_action( self::CRON_HOOK, [ $this, 'check_in' ] );
		add_action( 'admin_init', [ $this, 'maybe_register' ], 5 );
	}

	/**
	 * Ask for registration on the next request.
	 *
	 * Called from the activation hook, which runs inside the activation request
	 * itself - a network call there would make activating the plugin feel slow
	 * on a host with a poor route to the portal, and would fail in a way
	 * WordPress reports as a plugin error.
	 */
	public static function on_activate(): void {
		update_option( self::REGISTER_FLAG, time(), false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Spread across the day rather than on the hour, so ten thousand
			// sites installed from the same tutorial do not all arrive at once.
			wp_schedule_event( time() + random_int( HOUR_IN_SECONDS, DAY_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	public static function on_deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Register, once, shortly after activation.
	 *
	 * The flag is cleared before the attempt, so a portal that hangs or refuses
	 * costs one missed registration rather than a network call on every admin
	 * page load forever. The daily check-in picks it up afterwards anyway.
	 */
	public function maybe_register(): void {
		if ( ! get_option( self::REGISTER_FLAG ) ) {
			return;
		}

		delete_option( self::REGISTER_FLAG );

		if ( ! Portal::is_available() || $this->portal->is_registered() ) {
			return;
		}

		$result = $this->portal->register();

		if ( is_wp_error( $result ) ) {
			Portal::remember( [ 'last_error' => $result->get_error_message(), 'failures' => 1 ] );
		}
	}

	/**
	 * The daily check-in.
	 *
	 * Also the fallback for a site that never managed to register: a site with
	 * no token registers here instead, which is what rescues an installation
	 * whose activation happened while the portal was down.
	 */
	public function check_in(): void {
		if ( ! Portal::is_available() || ! $this->due() ) {
			return;
		}

		if ( ! $this->portal->is_registered() ) {
			$result = $this->portal->register();

			if ( is_wp_error( $result ) ) {
				$this->failed( $result->get_error_message() );
			}

			return;
		}

		$result = $this->portal->call(
			'POST',
			'v1/installs/heartbeat',
			array_merge(
				$this->portal->describe(),
				[ 'usage' => $this->usage->report() ]
			)
		);

		if ( is_wp_error( $result ) ) {
			// An install the portal has forgotten - its row deleted, or the
			// database restored from before it registered - is told so plainly.
			// Registering again is the only way back, and doing it here means
			// the site recovers without anybody noticing it had a problem.
			if ( 'mmoa_portal_unknown_install' === $result->get_error_code() ) {
				$this->portal->forget();
				$again = $this->portal->register();

				if ( ! is_wp_error( $again ) ) {
					return;
				}
			}

			$this->failed( $result->get_error_message() );

			return;
		}

		Portal::remember(
			[
				'checked_in'   => time(),
				'verified'     => ! empty( $result['install']['verified'] ),
				'verify_error' => (string) ( $result['install']['verify_error'] ?? '' ),
				'cap'          => (int) ( $result['free_monthly_cap'] ?? 0 ),
				'failures'     => 0,
				'last_error'   => '',
			]
		);

		( new Licence() )->store( $result );
	}

	/**
	 * Tell the portal this site is going away.
	 *
	 * Best effort by its nature: a site deleted wholesale never gets to say so,
	 * which is why the portal also watches how long ago each install last
	 * checked in.
	 */
	public function farewell(): void {
		if ( Portal::is_available() && $this->portal->is_registered() ) {
			$this->portal->call( 'DELETE', 'v1/installs/me' );
		}
	}

	/**
	 * Ask the portal to try the ownership callback again.
	 *
	 * Offered on the licence screen, because the usual reason a site is
	 * unverified is something the administrator has just fixed - a certificate,
	 * a firewall rule, a holding page in front of the site.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function reverify() {
		$result = $this->portal->call( 'POST', 'v1/installs/verify' );

		if ( ! is_wp_error( $result ) ) {
			Portal::remember(
				[
					'verified'     => ! empty( $result['install']['verified'] ),
					'verify_error' => (string) ( $result['install']['verify_error'] ?? '' ),
				]
			);
		}

		return $result;
	}

	/**
	 * Has the backoff period elapsed?
	 */
	private function due(): bool {
		$state    = Portal::state();
		$failures = (int) ( $state['failures'] ?? 0 );

		if ( 0 === $failures ) {
			return true;
		}

		$wait = self::BACKOFF[ min( $failures, count( self::BACKOFF ) - 1 ) ];

		return time() - (int) ( $state['attempted'] ?? 0 ) >= $wait;
	}

	private function failed( string $message ): void {
		$state = Portal::state();

		Portal::remember(
			[
				'failures'   => (int) ( $state['failures'] ?? 0 ) + 1,
				'attempted'  => time(),
				'last_error' => $message,
			]
		);
	}
}
