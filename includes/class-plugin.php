<?php
/**
 * Plugin container and bootstrap.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Admin\Admin_Page;
use ModernMailer\Alerts\Alert;
use ModernMailer\Admin\App_Page;
use ModernMailer\Admin\Site_Health;
use ModernMailer\Api\Rest_Controller;
use ModernMailer\Auth\Broker;
use ModernMailer\Auth\Google_Consent;
use ModernMailer\Auth\Microsoft_Consent;
use ModernMailer\Auth\One_Click;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and registers its hooks.
 */
class Plugin {

	/** Set once the merge migration has run, so it runs once. */
	private const MERGED_PROVIDERS_OPTION = 'mmoa_merged_providers';

	/** Set once the From address has been copied onto each connection. */
	private const PER_CONNECTION_FROM_OPTION = 'mmoa_per_connection_from';

	/** Guards the one-shot that pins ms_setup_mode before its default moved. */
	private const PINNED_MS_MODE_OPTION = 'mmoa_pinned_ms_mode';

	private static ?Plugin $instance = null;

	public Secrets $secrets;
	public Settings $settings;
	public Token_Store $tokens;
	public Http $http;
	public Logger $logger;
	public Alerts $alerts;
	public Weekly_Report $report;
	public Health_Monitor $health;
	public Queue $queue;
	public Connections $connections;
	public Router $router;
	public Site_Identity $identity;
	public Broker $broker;
	public Google_Consent $consent;
	public Microsoft_Consent $ms_consent;
	public One_Click $one_click;
	public Dispatcher $dispatcher;
	public Setup $setup;
	public Conflicts $conflicts;
	public Portal $portal;
	public Licence $licence;
	public Usage $usage;
	public Install_Report $install_report;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->secrets    = new Secrets();
		$this->settings   = new Settings( $this->secrets );
		$this->tokens     = new Token_Store();
		$this->http       = new Http();
		$this->logger     = new Logger( $this->settings );
		$this->alerts     = new Alerts( $this->settings, $this->secrets );
		$this->health     = new Health_Monitor( $this->settings, $this->alerts );
		$this->queue      = new Queue( $this->settings );
		$this->connections = new Connections( $this->settings );
		$this->router      = new Router( $this->settings, $this->connections );
		$this->identity   = new Site_Identity();
		$this->broker     = new Broker( $this->http, $this->identity );
		$this->consent    = new Google_Consent( $this->settings, $this->http, $this->connections );
		$this->ms_consent = new Microsoft_Consent( $this->settings, $this->http, $this->connections );
		$this->one_click  = new One_Click( $this->settings, $this->broker, $this->connections, $this->tokens );

		// The licensing side. None of it is on the path a message takes: the
		// dispatcher is handed the meter and nothing else, and a portal that is
		// unreachable or switched off cannot stop a send.
		$this->portal         = new Portal( $this->http, $this->identity, $this->settings, $this->connections );
		$this->licence        = new Licence();
		$this->usage          = new Usage();
		$this->install_report = new Install_Report( $this->portal, $this->usage );

		$this->dispatcher = new Dispatcher(
			$this->settings,
			$this->tokens,
			$this->http,
			$this->logger,
			$this->health,
			$this->queue,
			$this->router,
			$this->usage
		);
		$this->setup      = new Setup( $this->settings );
		$this->conflicts  = new Conflicts( $this->settings );
		$this->report     = new Weekly_Report( $this->settings, $this->logger, $this->queue );
	}

	public function boot(): void {
		add_action( 'plugins_loaded', [ $this, 'install_mailer' ], 20 );
		add_action( Logger::CRON_HOOK, [ $this->logger, 'prune' ] );

		// Falling back to the backup is a success for the message and a
		// warning for the site: sending works, but only on the spare. Hooked
		// here rather than called from the dispatcher, which has no business
		// knowing that alerts exist.
		add_action( 'mmoa_backup_used', [ $this, 'alert_backup_used' ], 10, 2 );
		add_action( Queue::CRON_HOOK, [ $this, 'drain_queue' ] );

		// Registered on every request rather than only in the admin: the daily
		// check-in runs on cron, which is not an admin request, and the
		// one-shot registration hooks admin_init itself.
		$this->install_report->register_hooks();
		$this->report->register();
		add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
		add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );

		if ( is_admin() ) {
			( new App_Page( $this ) )->register();
			( new Admin_Page( $this ) )->register();

			// Admin only: all it does is redirect an admin screen or print a
			// notice on one. The state it keeps is read through the REST
			// controller, which registers itself unconditionally below.
			$this->setup->register();

			// Admin only for the same reason: it prints a notice and handles
			// the link that dismisses it. Registered whether or not a provider
			// is configured, because a competing mailer is worth knowing about
			// before this one is set up as well as after.
			$this->conflicts->register();
		}

		// The delegated Microsoft callback is served from a path rather than
		// from admin-post.php, because Entra refuses a redirect URI with a
		// query string whenever the app registration admits personal Microsoft
		// accounts. Registered unconditionally: the request arrives on the
		// front end, where is_admin() is false and the admin classes below are
		// not loaded at all.
		Microsoft_Consent::register_routes();

		// Registered on every request, not only in the admin. The exporter
		// and eraser callbacks run from WordPress's own privacy request
		// machinery, which is driven by cron as well as by an admin screen.
		( new Privacy() )->register();

		( new Site_Health( $this ) )->register();

		// Registered unconditionally, not only in the admin: rest_api_init fires
		// on a front-end REST request too, and the admin app is served from a
		// page that is not always an admin screen by the time these run.
		( new Rest_Controller( $this ) )->register();
	}

	/**
	 * Take over wp_mail() by pre-seeding the PHPMailer global.
	 *
	 * wp_mail() only constructs a PHPMailer when the global is not already
	 * one, so placing our subclass there first is enough - no core patching,
	 * no reimplementation of header parsing.
	 *
	 * Nothing happens unless a provider is actually configured. Intercepting a
	 * send we cannot complete would be worse than not intercepting it, and it
	 * would break local development where a catcher like Mailpit is handling
	 * mail.
	 */
	public function install_mailer(): void {
		if ( ! $this->settings->is_active() ) {
			return;
		}

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';

		$catcher = new Mail_Catcher( true );
		$catcher->set_dispatcher( $this->dispatcher );

		// Match what core does when it builds its own instance.
		PHPMailer::$validator = static fn( $email ): bool => (bool) is_email( $email );

		$GLOBALS['phpmailer'] = $catcher;

		// Seeded from the primary connection, which is what an unrouted message
		// uses. If routing or a backup hands the message to a different
		// connection, Dispatcher::apply_from() rewrites it before sending -
		// this filter runs long before which connection sends is decided.
		if ( $this->settings->for_slot( Settings::SLOT_PRIMARY )->get( 'force_from' ) ) {
			add_filter( 'wp_mail_from', [ $this, 'filter_from_email' ], 100 );
			add_filter( 'wp_mail_from_name', [ $this, 'filter_from_name' ], 100 );
		}
	}

	/**
	 * Force the envelope sender to the configured mailbox.
	 *
	 * Both APIs reject, or silently rewrite, a From address the authenticated
	 * identity is not allowed to use. Overriding it here means a plugin that
	 * hardcodes its own From does not quietly break delivery.
	 */
	public function filter_from_email( string $from ): string {
		$configured = (string) $this->settings->for_slot( Settings::SLOT_PRIMARY )->get( 'from_email' );

		return '' !== $configured ? $configured : $from;
	}

	public function filter_from_name( string $name ): string {
		$configured = (string) $this->settings->for_slot( Settings::SLOT_PRIMARY )->get( 'from_name' );

		return '' !== $configured ? $configured : $name;
	}

	/**
	 * Add the five-minute interval the queue drains on.
	 *
	 * Five minutes is the shortest interval worth having: WordPress cron only
	 * runs when the site gets traffic, so a tighter schedule buys nothing on a
	 * quiet site and adds needless work on a busy one.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function register_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : [];

		$schedules[ Queue::SCHEDULE_NAME ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (MME-Mail to SMTP retry queue)', 'modern-mailer-oauth' ),
		];

		// WordPress has 'weekly' since 5.4, but registering our own keeps the
		// report on our schedule rather than sharing a slot with every other
		// weekly job on the site.
		$schedules[ Weekly_Report::SCHEDULE ] = [
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly (MME-Mail to SMTP summary report)', 'modern-mailer-oauth' ),
		];

		return $schedules;
	}

	/**
	 * Create anything a plugin update introduced.
	 *
	 * The activation hook does not fire on update, so a site that upgrades into
	 * this version would otherwise have the queue code but no queue table, and
	 * every enqueue would fail silently at the exact moment it was needed. Both
	 * installers no-op once their version option matches, so this costs one
	 * option read per admin request.
	 */
	/**
	 * @param array<string,mixed> $context
	 */
	public function alert_backup_used( \WP_Error $error, array $context = [] ): void {
		try {
			$this->alerts->fire( Alert::from_failure( Alert::BACKUP_USED, $error, $context ) );
		} catch ( \Throwable $e ) {
			// A message was just delivered. Nothing about reporting that is
			// worth turning into a fatal.
			unset( $e );
		}
	}

	public function maybe_upgrade(): void {
		Logger::install();
		Queue::install();
		Usage::install();

		if ( ! wp_next_scheduled( Queue::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), Queue::SCHEDULE_NAME, Queue::CRON_HOOK );
		}

		$this->migrate_merged_providers();
		$this->migrate_per_connection_from();
		$this->migrate_pinned_microsoft_mode();
	}

	/**
	 * Give every connection the From address that used to be site-wide.
	 *
	 * The primary needs nothing done: the site-wide value was stored under the
	 * bare key, which is exactly where the primary slot reads its own from, so
	 * it already has it. Every other connection would otherwise wake up with an
	 * empty From and refuse to send.
	 *
	 * Only fills a blank. A connection that already has its own address set it
	 * deliberately, and overwriting that would be worse than doing nothing.
	 */
	private function migrate_per_connection_from(): void {
		if ( get_option( self::PER_CONNECTION_FROM_OPTION ) ) {
			return;
		}

		$primary = $this->settings->for_slot( Settings::SLOT_PRIMARY );
		$email   = (string) $primary->get( 'from_email' );
		$name    = (string) $primary->get( 'from_name' );

		if ( '' !== $email ) {
			foreach ( $this->connections->all() as $connection ) {
				$slot = (string) $connection['slot'];

				if ( Settings::SLOT_PRIMARY === $slot ) {
					continue;
				}

				$scoped = $this->settings->for_slot( $slot );
				$values = [];

				if ( '' === (string) $scoped->get( 'from_email' ) ) {
					$values['from_email'] = $email;
				}

				if ( '' === (string) $scoped->get( 'from_name' ) ) {
					$values['from_name'] = $name;
				}

				if ( $values ) {
					$scoped->update( $values );
				}
			}
		}

		Settings::flush_cache();
		update_option( self::PER_CONNECTION_FROM_OPTION, time(), false );
	}

	/**
	 * Move connections onto the merged Microsoft and Google providers.
	 *
	 * Those two tiles used to be four - Microsoft 365 and Outlook, Google
	 * Workspace and Gmail - which asked an admin to choose an authentication
	 * method before choosing a mail service. The methods survive unchanged as
	 * the setup modes behind each tile, so this only has to restate an existing
	 * choice in the new vocabulary: no credential is touched and no connection
	 * changes how it sends.
	 *
	 * Nothing here is strictly required for a site to keep working - the old
	 * slugs are still registered and still constructible, which is deliberate,
	 * because an upgrade must not be able to stop mail. Without the migration a
	 * connection would simply keep its old tile until someone edited it.
	 */
	/**
	 * Write down which Microsoft mode each connection is already using.
	 *
	 * The Graph API mode is no longer offered in the selector, and the
	 * default moved off it. Both are safe for a connection that stored its
	 * mode explicitly - the migration from the old `graph` slug did exactly
	 * that, and so did anyone who touched the radio.
	 *
	 * What is not safe is a connection created after the tiles merged, which
	 * took Graph because Graph was the default and therefore never stored
	 * anything. Moving the default underneath it would silently re-point it
	 * at a different transport, and the first anyone would know is mail no
	 * longer going out. So the current answer is written down before the
	 * default changes meaning.
	 *
	 * Only fills a blank, and only for Microsoft. A connection that chose
	 * its mode is left exactly as it chose.
	 */
	private function migrate_pinned_microsoft_mode(): void {
		if ( get_option( self::PINNED_MS_MODE_OPTION ) ) {
			return;
		}

		foreach ( $this->connections->all() as $connection ) {
			$scoped = $this->settings->for_slot( (string) $connection['slot'] );

			if ( 'microsoft' !== (string) $scoped->get( 'provider' ) ) {
				continue;
			}

			if ( $scoped->is_stored( 'ms_setup_mode' ) ) {
				continue;
			}

			$scoped->update( [ 'ms_setup_mode' => Auth\One_Click::MODE_OWN_CLIENT ] );
		}

		Settings::flush_cache();
		update_option( self::PINNED_MS_MODE_OPTION, time(), false );
	}

	private function migrate_merged_providers(): void {
		if ( get_option( self::MERGED_PROVIDERS_OPTION ) ) {
			return;
		}

		// Slug that was stored => [ merged slug, mode setting, mode value ].
		// A null mode means "leave whatever is there": gmail_oauth already used
		// google_setup_mode to record whether its token was brokered, and that
		// answer is still the right one.
		$map = [
			'graph'       => [ 'microsoft', 'ms_setup_mode', Auth\One_Click::MODE_OWN_CLIENT ],
			'outlook'     => [ 'microsoft', 'ms_setup_mode', Auth\One_Click::MODE_ONE_CLICK ],
			'gmail_sa'    => [ 'google', 'google_setup_mode', Providers\Google::MODE_SERVICE_ACCOUNT ],
			'gmail_oauth' => [ 'google', 'google_setup_mode', null ],
		];

		foreach ( $this->connections->all() as $connection ) {
			$scoped = $this->settings->for_slot( (string) $connection['slot'] );
			$stored = (string) $scoped->get( 'provider' );

			if ( ! isset( $map[ $stored ] ) ) {
				continue;
			}

			[ $merged, $mode_key, $mode ] = $map[ $stored ];

			$values = [ 'provider' => $merged ];

			if ( null !== $mode ) {
				$values[ $mode_key ] = $mode;
			}

			$scoped->update( $values );
		}

		Settings::flush_cache();
		update_option( self::MERGED_PROVIDERS_OPTION, time(), false );
	}

	/**
	 * Drain the retry queue. Runs on cron.
	 */
	public function drain_queue(): void {
		if ( ! $this->settings->is_active() ) {
			return;
		}

		$this->queue->drain( $this->dispatcher );
	}

	public static function activate(): void {
		Logger::install();
		Queue::install();
		Usage::install();

		// Asks the portal to be told about this site. Like the wizard below it
		// cannot happen here - a network call inside the activation request
		// would make activating the plugin feel slow on a host with a poor
		// route, and would fail in a way WordPress reports as a plugin error.
		Install_Report::on_activate();

		// Asks for the wizard on the next admin screen. It cannot redirect
		// from here: this runs inside the activation request, which WordPress
		// is still in the middle of reporting on.
		Setup::on_activate();

		// The rule has to exist before the table is rebuilt, so it is added
		// here rather than left to the init hook that will not run again
		// before the flush.
		Microsoft_Consent::add_rewrite();
		flush_rewrite_rules( false );

		if ( ! wp_next_scheduled( Logger::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::CRON_HOOK );
		}

		if ( ! wp_next_scheduled( Queue::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), Queue::SCHEDULE_NAME, Queue::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		foreach ( [ Logger::CRON_HOOK, Queue::CRON_HOOK, Install_Report::CRON_HOOK ] as $hook ) {
			$timestamp = wp_next_scheduled( $hook );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
