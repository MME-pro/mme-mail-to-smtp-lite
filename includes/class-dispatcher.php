<?php
/**
 * Routes a built message to the configured provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Providers\Provider_Interface;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses a connection, sends, and records the outcome.
 *
 * Two things stand between a transient fault and a lost email, in the order
 * they are tried:
 *
 * 1. Http retries the request a few times inside this page load. Good for a
 *    dropped packet or a brief throttle.
 * 2. If the connection still fails and the failure looks transient, the message
 *    goes on the queue and is retried across later requests over the following
 *    hours.
 *
 * The escalation only happens when it can help, which is what Failure decides.
 * An oversized attachment is not retried anywhere, because no amount of
 * patience will make it fit.
 */
class Dispatcher {

	/** @var array<string,?Provider_Interface> Slot => provider, once built. */
	private array $providers = [];

	/**
	 * Set while a queue drain is running, so a retry cannot re-enqueue itself.
	 */
	private bool $draining = false;

	/**
	 * Set while a test message is being sent, so it cannot be rescued by
	 * anything and reports the primary connection's own answer.
	 */
	private bool $testing = false;

	public function __construct(
		private Settings $settings,
		private Token_Store $tokens,
		private Http $http,
		private Health_Monitor $health,
		private Queue $queue
	) {}

	/**
	 * The provider for a connection slot, or null if that slot is unconfigured.
	 */
	public function provider( string $slot = Settings::SLOT_PRIMARY ): ?Provider_Interface {
		// Deliberately isset() rather than array_key_exists(): a null result
		// must not be memoized. An unconfigured slot asked about early in the
		// request - by Site Health, by an admin screen - would otherwise cache
		// "no provider" and keep answering that after the settings are saved,
		// which reads as the plugin silently refusing to send.
		if ( isset( $this->providers[ $slot ] ) ) {
			return $this->providers[ $slot ];
		}

		$scoped = $this->settings->for_slot( $slot );
		$class  = Provider_Registry::class_for( (string) $scoped->get( 'provider' ) );

		// Providers receive the slot-scoped settings and are otherwise
		// identical, which is why an add-on can introduce a second connection
		// without changing any provider.
		$this->providers[ $slot ] = null === $class
			? null
			: new $class( $scoped, $this->tokens, $this->http );

		return $this->providers[ $slot ];
	}

	/**
	 * Discard the memoized providers.
	 *
	 * Providers capture their credentials when constructed, so anything that
	 * changes settings mid-request - saving the form, a test suite reconfiguring
	 * the site - has to invalidate them or the next send uses the old ones.
	 */
	public function reset_providers(): void {
		$this->providers = [];
	}

	/**
	 * Run a send with every safety net switched off.
	 *
	 * A test message exists to answer one question: does the primary
	 * connection work? Everything that makes ordinary sending resilient makes
	 * that question unanswerable: a test that got queued reported success
	 * for a message that had not been sent at all.
	 *
	 * So for the duration of the callback: no queue. What comes back is the
	 * connection's own answer.
	 *
	 * @template T
	 * @param callable():T $send
	 * @return T
	 */
	public function without_fallbacks( callable $send ) {
		$this->testing = true;

		try {
			return $send();
		} finally {
			$this->testing = false;
		}
	}

	/**
	 * Send a built MIME message, escalating to the retry queue.
	 *
	 * @return true|WP_Error
	 */
	public function dispatch( string $raw_mime, PHPMailer $mailer, ?string $forced_slot = null ) {
		// $forced_slot is set when a message comes off the queue, where the
		// choice was already made and recorded. Everything else goes out on the
		// primary connection, and a failure there falls through to the retry
		// queue.
		$slot = $forced_slot ?? Settings::SLOT_PRIMARY;

		$result = $this->attempt( $slot, $raw_mime, $mailer );

		if ( true === $result ) {
			$this->health->record_success();

			return true;
		}

		// Sending genuinely failed, whatever happens to the message next. The
		// admin needs to know that even if the queue later delivers it, because
		// a queue quietly absorbing every send is exactly the silent breakage
		// this plugin exists to prevent.
		//
		// The message's own details travel with the failure so an alert can
		// name what failed. Recipients and subject only - never the body,
		// never a credential.
		$provider = $this->provider( $slot );

		$this->health->record_failure(
			$result,
			[
				'recipients' => implode( ', ', $this->recipients( $mailer ) ),
				'subject'    => (string) $mailer->Subject,
				'mailer'     => null !== $provider ? $provider->get_label() : '',
				'slot'       => $slot,
				'time'       => time(),
			]
		);

		// Last resort: keep the message so a later request can try again. Only
		// worth doing for a failure that could plausibly resolve on its own.
		if ( ! $this->draining && ! $this->testing && Failure::is_retryable( $result ) ) {
			$queued = $this->queue->enqueue(
				$raw_mime,
				$this->recipients( $mailer ),
				(string) $mailer->Subject,
				$result,
				$slot
			);

			if ( $queued ) {
				// Reported as success to the caller, because from wp_mail()'s
				// point of view the message has been accepted for delivery and
				// no longer needs the caller to do anything. Returning false
				// here would make correctly-written plugins show the user an
				// error for mail that is about to arrive.
				return true;
			}
		}

		return $result;
	}

	/**
	 * Send a message that came off the queue.
	 *
	 * Same path, minus the re-enqueue: the row already exists and the queue is
	 * managing its own attempt count and backoff.
	 *
	 * @return true|WP_Error
	 */
	public function dispatch_raw( string $raw_mime, PHPMailer $mailer, ?string $slot = null ) {
		$this->draining = true;

		try {
			return $this->dispatch( $raw_mime, $mailer, $slot ?? Settings::SLOT_PRIMARY );
		} finally {
			$this->draining = false;
		}
	}

	/**
	 * Put this connection's From address on the message, rebuilding it if so.
	 *
	 * Returns the new MIME, or null when nothing needed changing - which is the
	 * common case, and worth keeping cheap: rebuilding means re-encoding every
	 * attachment.
	 *
	 * `force_from` is honoured per connection. Off, whatever the caller set is
	 * left alone, because a site that has deliberately let a plugin choose its
	 * own sender should not have that quietly overridden by the connection that
	 * happened to pick the message up.
	 *
	 * @return string|null
	 */
	private function apply_from( string $slot, PHPMailer $mailer ): ?string {
		$scoped = $this->settings->for_slot( $slot );

		if ( ! $scoped->get( 'force_from' ) ) {
			return null;
		}

		$email = trim( (string) $scoped->get( 'from_email' ) );

		if ( '' === $email ) {
			return null;
		}

		$name = trim( (string) $scoped->get( 'from_name' ) );

		if ( 0 === strcasecmp( $email, (string) $mailer->From ) && $name === (string) $mailer->FromName ) {
			return null;
		}

		try {
			// Third argument false: PHPMailer would otherwise reset Sender to
			// match, and Sender is the envelope address some hosts rewrite.
			$mailer->setFrom( $email, $name, false );

			if ( ! $mailer->preSend() ) {
				return null;
			}

			return $mailer->getSentMIMEMessage();
		} catch ( \Throwable $e ) {
			// A rebuild that fails leaves the original message intact and the
			// send goes ahead with the sender it already had. Refusing to send
			// over a From address would turn a cosmetic mismatch into lost mail.
			return null;
		}
	}

	/**
	 * One attempt against one connection, logged and recorded.
	 *
	 * @return true|WP_Error
	 */
	private function attempt( string $slot, string $raw_mime, PHPMailer $mailer ) {
		$provider = $this->provider( $slot );

		if ( null === $provider ) {
			return new WP_Error(
				'mmoa_no_provider',
				__( 'No mail provider is configured.', 'modern-mailer-oauth' )
			);
		}

		// The From address belongs to the connection, and which connection is
		// sending is only known here - a message off the queue carries its own.
		// wp_mail() built the message long before that, so if this connection
		// wants a different sender the message is rebuilt with it.
		$rebuilt = $this->apply_from( $slot, $mailer );

		if ( null !== $rebuilt ) {
			$raw_mime = $rebuilt;
		}

		$bytes  = strlen( $raw_mime );
		$result = $this->check_size( $provider, $bytes );

		if ( true === $result ) {
			/**
			 * Fires immediately before a message is handed to a provider.
			 *
			 * @param string             $raw_mime Complete RFC 822 message.
			 * @param Provider_Interface $provider Active provider.
			 */
			do_action( 'mmoa_before_send', $raw_mime, $provider );

			$result = $provider->send( $raw_mime, $mailer );

		}

		/**
		 * Fires after every send attempt, whether it succeeded or failed.
		 *
		 * Every attempt is reported, including one a later retry went on to
		 * rescue - what an add-on records here is the account of what actually
		 * happened on the wire. Health is decided separately, once, by
		 * dispatch(), from the final outcome.
		 *
		 * The slot travels with it so that a record names which connection's
		 * settings it was describing. Without it, an add-on that introduces
		 * a second connection on the same provider produces two records that
		 * otherwise look identical.
		 *
		 * @param Provider_Interface $provider The provider that was tried.
		 * @param PHPMailer          $mailer   The message, as PHPMailer built it.
		 * @param int                $bytes    Size of the raw MIME message.
		 * @param true|WP_Error      $result   The outcome of this attempt.
		 * @param string             $slot     Connection slot that carried it.
		 */
		do_action( 'mmoa_send_attempted', $provider, $mailer, $bytes, $result, $slot );

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function recipients( PHPMailer $mailer ): array {
		return array_map(
			static fn( array $addr ): string => $addr[0],
			array_merge( $mailer->getToAddresses(), $mailer->getCcAddresses(), $mailer->getBccAddresses() )
		);
	}

	/**
	 * Reject an oversized message before it hits the wire.
	 *
	 * Catching this locally means the admin gets a message naming the actual
	 * limit, rather than a generic API rejection that gives them nothing to
	 * act on.
	 *
	 * @return true|WP_Error
	 */
	private function check_size( Provider_Interface $provider, int $bytes ) {
		$max = $provider->get_max_message_bytes();

		if ( $bytes <= $max ) {
			return true;
		}

		return new WP_Error(
			'mmoa_message_too_large',
			sprintf(
				/* translators: 1: message size, 2: maximum size, 3: provider name. */
				__( 'The message is %1$s, which is over the %2$s that %3$s accepts in one request. Attachments are encoded twice on this path, so the usable attachment size is roughly half the limit.', 'modern-mailer-oauth' ),
				size_format( $bytes, 1 ),
				size_format( $max, 1 ),
				$provider->get_label()
			)
		);
	}
}
