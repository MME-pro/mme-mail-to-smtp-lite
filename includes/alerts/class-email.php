<?php
/**
 * Email, deliberately not sent through this plugin.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the alert over the server's native mail().
 *
 * ## The whole point of this class is what it does not do
 *
 * It does not call wp_mail(), because this plugin has replaced wp_mail(), and
 * the message it is trying to send is "wp_mail is not working". Routing the
 * alert down the path that just failed produces either nothing at all or - if
 * the failure was intermittent - an alert that arrives only when it was least
 * needed.
 *
 * So it builds a bare PHPMailer and calls isMail(), which is PHP's mail()
 * function and the web server's own MTA.
 *
 * ## And the honest limitation
 *
 * On a great many managed hosts that MTA does not exist, or is unconfigured,
 * or silently drops everything not addressed to the account holder. mail()
 * returns true in most of those cases, so this channel can report success for
 * a message nobody will ever receive.
 *
 * That is not a bug this plugin can fix, but it is one it can stop hiding:
 * "Send test alert" on the settings screen exists precisely so an
 * administrator finds out now rather than during an outage, and the screen
 * says in as many words that a passing test means the server accepted it, not
 * that it arrived.
 */
class Email implements Channel_Interface {

	public static function slug(): string {
		return 'email';
	}

	public static function label(): string {
		return __( 'Email', 'modern-mailer-oauth' );
	}

	public static function summary(): string {
		return __( "Sent by the web server itself, not by this plugin - so it still works when sending is broken.", 'modern-mailer-oauth' );
	}

	public static function docs(): string {
		return '';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'to' => [
				'type'        => 'email',
				'required'    => true,
				'label'       => __( 'Alert address', 'modern-mailer-oauth' ),
				'placeholder' => 'you@example.com',
				'help'        => __(
					'Use an address on a different domain from this site. An alert about your mail server being down, sent to a mailbox on that same server, is not an alert.',
					'modern-mailer-oauth'
				),
			],
		];
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public static function is_configured( array $config ): bool {
		return is_email( (string) ( $config['to'] ?? '' ) ) !== false;
	}

	/**
	 * @param array<string,mixed> $config
	 * @return true|WP_Error
	 */
	public function deliver( Alert $alert, array $config ) {
		$to = (string) ( $config['to'] ?? '' );

		if ( ! is_email( $to ) ) {
			return new WP_Error(
				'mmoa_alert_no_address',
				__( 'No valid alert address is set.', 'modern-mailer-oauth' )
			);
		}

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		try {
			$mailer = new PHPMailer( true );
			$mailer->isMail();
			$mailer->CharSet = 'UTF-8';
			$mailer->setFrom(
				'wordpress@' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'WordPress'
			);
			$mailer->addAddress( $to );
			$mailer->Subject = $alert->title();
			$mailer->Body    = $alert->as_text();

			$mailer->send();

			return true;
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'mmoa_alert_mail_failed',
				sprintf(
					/* translators: %s: the underlying mail error. */
					__( "The server's own mail function refused the alert: %s", 'modern-mailer-oauth' ),
					$e->getMessage()
				)
			);
		}
	}
}
