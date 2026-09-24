<?php
/**
 * Deciding whether to alert, and sending it everywhere it should go.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Alerts\Alert;
use ModernMailer\Alerts\Bitrix24;
use ModernMailer\Alerts\Channel_Interface;
use ModernMailer\Alerts\Discord;
use ModernMailer\Alerts\Email;
use ModernMailer\Alerts\Slack;
use ModernMailer\Alerts\Teams;
use ModernMailer\Alerts\Twilio;
use ModernMailer\Alerts\Webhook;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The alert system: which channels exist, whether to fire, and firing.
 *
 * ## Two things are easy to confuse, so they are named here once
 *
 * The plugin has two unrelated pieces of failure-handling behaviour, and
 * support questions constantly conflate them:
 *
 * 1. **The health monitor** (Health_Monitor) counts consecutive failures. It
 *    is a counter, not a notifier.
 * 2. **Alerts** - this class - is the only thing that contacts a human, and it
 *    does so in response to an event, never on a timer.
 *
 * ## When an alert fires
 *
 * Two modes, because two kinds of site want opposite things:
 *
 * - `threshold` (the default) fires once when the failure streak reaches the
 *   configured threshold, and once more when sending recovers. A provider
 *   outage produces two messages, not four hundred.
 * - `every` fires on every failed message. Right for a low-volume site where
 *   each message matters individually; wrong for a shop.
 *
 * Both are throttled per channel per error code by a quiet period, so even
 * `every` cannot turn one broken credential into a thousand WhatsApp messages
 * and a bill.
 *
 * ## Alerts can never break sending
 *
 * fire() is called from inside Dispatcher, which is called from inside
 * wp_mail(), which is called from inside somebody's checkout. Every channel is
 * wrapped: a channel that throws, times out or returns nonsense is recorded
 * and stepped over. Nothing in this file may propagate an exception, and
 * nothing in it may take long enough to matter - hence the five-second
 * per-channel timeout in Abstract_Webhook.
 */
class Alerts {

	public const OPTION      = 'mmoa_alerts';
	public const SECRET_SLOT = 'alerts';

	public const WHEN_THRESHOLD = 'threshold';
	public const WHEN_EVERY     = 'every';

	/** Minutes. Suppresses repeats of the same error on the same channel. */
	public const DEFAULT_QUIET = 15;

	/**
	 * Guards against an alert causing an alert. Nothing should be able to do
	 * that, but "should" is doing a lot of work in a system whose job is to
	 * run while things are broken.
	 */
	private bool $firing = false;

	public function __construct( private Settings $settings, private Secrets $secrets ) {}

	/**
	 * Every channel the plugin knows about, in the order the screen lists them.
	 *
	 * @return array<string,class-string<Channel_Interface>>
	 */
	public static function channels(): array {
		$channels = [
			Email::slug()    => Email::class,
			Slack::slug()    => Slack::class,
			Discord::slug()  => Discord::class,
			Teams::slug()    => Teams::class,
			Bitrix24::slug() => Bitrix24::class,
			Twilio::slug()   => Twilio::class,
			Webhook::slug()  => Webhook::class,
		];

		/**
		 * Filters the available alert channels.
		 *
		 * @param array<string,class-string<Channel_Interface>> $channels Slug => class.
		 */
		return (array) apply_filters( 'mmoa_alert_channels', $channels );
	}

	/**
	 * @return class-string<Channel_Interface>|null
	 */
	public static function channel_class( string $slug ): ?string {
		return self::channels()[ $slug ] ?? null;
	}

	/* --------------------------------------------------------- settings --- */

	/**
	 * @return array{enabled:bool,when:string,quiet:int,channels:array<string,array<string,mixed>>}
	 */
	public function settings(): array {
		$saved = get_option( self::OPTION, null );

		// Never configured. A site upgrading into this version had one alert
		// address and it worked, so it carries on working: email on, alerts
		// on, everything else off. Derived rather than migrated, because a
		// migration that runs on upgrade cannot be undone by an administrator
		// who then changes their mind about the address.
		if ( ! is_array( $saved ) ) {
			$address = (string) $this->settings->get( 'alert_email' );

			return [
				'enabled'  => '' !== $address,
				'when'     => self::WHEN_THRESHOLD,
				'quiet'    => self::DEFAULT_QUIET,
				'channels' => [ Email::slug() => [ 'enabled' => true ] ],
			];
		}

		$when = (string) ( $saved['when'] ?? self::WHEN_THRESHOLD );

		return [
			'enabled'  => (bool) ( $saved['enabled'] ?? false ),
			'when'     => in_array( $when, [ self::WHEN_THRESHOLD, self::WHEN_EVERY ], true ) ? $when : self::WHEN_THRESHOLD,
			'quiet'    => max( 0, (int) ( $saved['quiet'] ?? self::DEFAULT_QUIET ) ),
			'channels' => is_array( $saved['channels'] ?? null ) ? $saved['channels'] : [],
		];
	}

	/**
	 * One channel's configuration, with its secrets put back in.
	 *
	 * Secrets live in Secrets rather than the option, so an option dump - a
	 * migration plugin, a support request, a database export - does not carry
	 * a Bitrix24 webhook or a Twilio token with it.
	 *
	 * @return array<string,mixed>
	 */
	public function config( string $slug ): array {
		$class = self::channel_class( $slug );

		if ( null === $class ) {
			return [];
		}

		$config = $this->settings()['channels'][ $slug ] ?? [];
		$config = is_array( $config ) ? $config : [];

		// The alert address is not stored twice. It has lived in Settings as
		// alert_email since before any of this existed, it is still shown
		// under Reliability, and two fields that mean the same thing are how
		// an administrator ends up changing one of them and wondering why
		// nothing arrives.
		if ( Email::slug() === $slug ) {
			$config['to'] = (string) $this->settings->get( 'alert_email' );
		}

		foreach ( $class::fields() as $key => $field ) {
			if ( empty( $field['secret'] ) ) {
				continue;
			}

			$config[ $key ] = $this->secrets->for_slot( self::SECRET_SLOT )->get( $slug . '_' . $key );
		}

		return $config;
	}

	public function is_enabled( string $slug ): bool {
		$channel = $this->settings()['channels'][ $slug ] ?? [];

		return (bool) ( is_array( $channel ) ? ( $channel['enabled'] ?? false ) : false );
	}

	/**
	 * Save. Secrets are peeled off and stored separately; an empty secret
	 * means "leave it alone", because the browser is never sent the current
	 * value and therefore cannot send it back.
	 *
	 * A channel the caller does not mention keeps whatever it had. The settings
	 * form always sends every channel, so this only shows up through the REST
	 * route - where leaving a channel out meaning "switch it off" would be a
	 * much worse surprise than it meaning "do not touch".
	 *
	 * @param array<string,mixed> $incoming
	 */
	public function save( array $incoming ): void {
		$current = $this->settings();

		$when = (string) ( $incoming['when'] ?? $current['when'] );

		$clean = [
			'enabled'  => (bool) ( $incoming['enabled'] ?? $current['enabled'] ),
			'when'     => in_array( $when, [ self::WHEN_THRESHOLD, self::WHEN_EVERY ], true ) ? $when : self::WHEN_THRESHOLD,
			'quiet'    => max( 0, min( 1440, (int) ( $incoming['quiet'] ?? $current['quiet'] ) ) ),
			'channels' => [],
		];

		$incoming_channels = is_array( $incoming['channels'] ?? null ) ? $incoming['channels'] : [];

		foreach ( self::channels() as $slug => $class ) {
			$sent    = is_array( $incoming_channels[ $slug ] ?? null ) ? $incoming_channels[ $slug ] : [];
			$existing = is_array( $current['channels'][ $slug ] ?? null ) ? $current['channels'][ $slug ] : [];

			$clean['channels'][ $slug ] = [
				'enabled' => (bool) ( $sent['enabled'] ?? ( $existing['enabled'] ?? false ) ),
			];

			foreach ( $class::fields() as $key => $field ) {
				$value = isset( $sent[ $key ] ) ? (string) $sent[ $key ] : null;

				// Written through to where it has always lived, so the
				// Reliability panel and the Alerts tab cannot disagree.
				if ( Email::slug() === $slug && 'to' === $key ) {
					if ( null !== $value ) {
						$this->settings->update( [ 'alert_email' => $value ] );
					}

					continue;
				}

				if ( ! empty( $field['secret'] ) ) {
					// Null means the field was not sent at all; empty string
					// means the administrator cleared it deliberately.
					if ( null !== $value ) {
						$this->secrets->for_slot( self::SECRET_SLOT )->set( $slug . '_' . $key, $value );
					}

					continue;
				}

				$clean['channels'][ $slug ][ $key ] = $this->sanitize( (string) ( $value ?? ( $existing[ $key ] ?? '' ) ), (string) ( $field['type'] ?? 'text' ) );
			}
		}

		update_option( self::OPTION, $clean, false );
	}

	private function sanitize( string $value, string $type ): string {
		switch ( $type ) {
			case 'url':
				return esc_url_raw( trim( $value ) );
			case 'email':
				return sanitize_email( trim( $value ) );
			default:
				return sanitize_text_field( $value );
		}
	}

	/* ------------------------------------------------------------ firing -- */

	/**
	 * Send an alert everywhere it should go.
	 *
	 * @return array<string,true|WP_Error> Per channel, for the test button.
	 */
	public function fire( Alert $alert ): array {
		$results = [];

		if ( $this->firing ) {
			return $results;
		}

		$settings = $this->settings();

		if ( ! $settings['enabled'] && Alert::TEST !== $alert->event ) {
			return $results;
		}

		$this->firing = true;

		try {
			foreach ( array_keys( self::channels() ) as $slug ) {
				if ( ! $this->is_enabled( $slug ) ) {
					continue;
				}

				if ( $this->is_quiet( $slug, $alert ) ) {
					continue;
				}

				$results[ $slug ] = $this->deliver( $slug, $alert );

				$this->start_quiet_period( $slug, $alert, $settings['quiet'] );
			}
		} finally {
			$this->firing = false;
		}

		/**
		 * Fires after an alert has been sent to every configured channel.
		 *
		 * @param Alert                      $alert   The alert.
		 * @param array<string,true|WP_Error> $results Per-channel outcome.
		 */
		do_action( 'mmoa_alert_sent', $alert, $results );

		return $results;
	}

	/**
	 * Deliver to one channel, catching everything.
	 *
	 * @return true|WP_Error
	 */
	public function deliver( string $slug, Alert $alert ) {
		$class = self::channel_class( $slug );

		if ( null === $class ) {
			return new WP_Error( 'mmoa_alert_unknown_channel', __( 'No such alert channel.', 'modern-mailer-oauth' ) );
		}

		$config = $this->config( $slug );

		if ( ! $class::is_configured( $config ) ) {
			return new WP_Error(
				'mmoa_alert_unconfigured',
				sprintf(
					/* translators: %s: channel name. */
					__( '%s is switched on but not fully configured.', 'modern-mailer-oauth' ),
					$class::label()
				)
			);
		}

		try {
			$channel = new $class();
			$result  = $channel->deliver( $alert, $config );

			return true === $result ? true : $result;
		} catch ( \Throwable $e ) {
			// An alert channel must never be able to take down the request
			// that was trying to send an email.
			return new WP_Error(
				'mmoa_alert_threw',
				sprintf(
					/* translators: 1: channel name, 2: the exception message. */
					__( '%1$s failed unexpectedly: %2$s', 'modern-mailer-oauth' ),
					$class::label(),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Send a test alert to one channel, ignoring the master switch and the
	 * quiet period.
	 *
	 * Ignoring both is the point: an administrator pressing "test" is asking
	 * whether the credentials work, not whether the system would currently
	 * choose to notify them.
	 *
	 * @return true|WP_Error
	 */
	public function test( string $slug ) {
		return $this->deliver( $slug, Alert::test() );
	}

	/* ------------------------------------------------------ quiet period --- */

	private function quiet_key( string $slug, Alert $alert ): string {
		// Keyed on the error code, not the message: the message often carries
		// a recipient or a request id, which would make every failure look
		// distinct and defeat the throttle entirely.
		return 'mmoa_quiet_' . md5( $slug . '|' . $alert->event . '|' . $alert->code );
	}

	private function is_quiet( string $slug, Alert $alert ): bool {
		// A test and a recovery are always worth hearing: one was asked for,
		// and the other is the message that ends the silence.
		if ( in_array( $alert->event, [ Alert::TEST, Alert::RECOVERED ], true ) ) {
			return false;
		}

		return (bool) get_transient( $this->quiet_key( $slug, $alert ) );
	}

	private function start_quiet_period( string $slug, Alert $alert, int $minutes ): void {
		if ( $minutes <= 0 || in_array( $alert->event, [ Alert::TEST, Alert::RECOVERED ], true ) ) {
			return;
		}

		set_transient( $this->quiet_key( $slug, $alert ), 1, $minutes * MINUTE_IN_SECONDS );
	}

	/* ------------------------------------------------------------ status --- */

	/**
	 * What the settings screen needs to draw itself.
	 *
	 * Secret values are never included - only whether one is set, so the form
	 * can say "leave blank to keep the current value" rather than round-trip a
	 * credential through the browser on every save.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		$settings = $this->settings();
		$channels = [];

		foreach ( self::channels() as $slug => $class ) {
			$config = $this->config( $slug );
			$fields = [];

			foreach ( $class::fields() as $key => $field ) {
				$secret = ! empty( $field['secret'] );

				$fields[ $key ] = array_merge(
					$field,
					[
						'secret' => $secret,
						'value'  => $secret ? '' : (string) ( $config[ $key ] ?? '' ),
						'set'    => $secret ? ( '' !== (string) ( $config[ $key ] ?? '' ) ) : null,
					]
				);
			}

			$channels[ $slug ] = [
				'slug'       => $slug,
				'label'      => $class::label(),
				'summary'    => $class::summary(),
				'docs'       => $class::docs(),
				'enabled'    => $this->is_enabled( $slug ),
				'configured' => $class::is_configured( $config ),
				'fields'     => $fields,
			];
		}

		return [
			'enabled'  => $settings['enabled'],
			'when'     => $settings['when'],
			'quiet'    => $settings['quiet'],
			'channels' => $channels,
		];
	}

	public static function uninstall(): void {
		delete_option( self::OPTION );
	}
}
