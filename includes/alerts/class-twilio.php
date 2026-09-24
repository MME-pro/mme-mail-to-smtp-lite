<?php
/**
 * WhatsApp and SMS, through Twilio.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a WhatsApp message or an SMS through Twilio's Messages API.
 *
 * ## Why this one is deliberately terse
 *
 * Every other channel lands somewhere a person reads at their desk. This one
 * lands on a phone, probably at night, and it costs money per message. So it
 * sends a single line rather than the full payload, and the quiet period in
 * Alerts matters more here than anywhere else - a provider outage with a
 * hundred queued messages must not become a hundred WhatsApp messages and a
 * bill.
 */
class Twilio extends Abstract_Webhook {

	/** Twilio truncates beyond this and charges per segment well before it. */
	private const MAX_BODY = 1500;

	public static function slug(): string {
		return 'twilio';
	}

	public static function label(): string {
		return __( 'WhatsApp / SMS (Twilio)', 'modern-mailer-oauth' );
	}

	public static function summary(): string {
		return __( 'Sends one line to a phone. Costs money per message.', 'modern-mailer-oauth' );
	}

	public static function docs(): string {
		return 'https://www.twilio.com/docs/messaging/api/message-resource';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'sid'   => [
				'type'        => 'text',
				'required'    => true,
				'label'       => __( 'Account SID', 'modern-mailer-oauth' ),
				'placeholder' => 'AC...',
			],
			'token' => [
				'type'     => 'password',
				'required' => true,
				'secret'   => true,
				'label'    => __( 'Auth token', 'modern-mailer-oauth' ),
			],
			'from'  => [
				'type'        => 'text',
				'required'    => true,
				'label'       => __( 'From', 'modern-mailer-oauth' ),
				'placeholder' => 'whatsapp:+14155238886',
				'help'        => __(
					'Your Twilio number. Prefix it with whatsapp: for WhatsApp, or leave it bare for SMS.',
					'modern-mailer-oauth'
				),
			],
			'to'    => [
				'type'        => 'text',
				'required'    => true,
				'label'       => __( 'To', 'modern-mailer-oauth' ),
				'placeholder' => 'whatsapp:+441234567890',
				'help'        => __(
					'Who gets woken up. Must match the From channel: a whatsapp: address can only message another whatsapp: address.',
					'modern-mailer-oauth'
				),
			],
		];
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public static function is_configured( array $config ): bool {
		foreach ( [ 'sid', 'token', 'from', 'to' ] as $key ) {
			if ( '' === trim( (string) ( $config[ $key ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $config
	 */
	protected function endpoint( array $config ): string {
		$sid = trim( (string) ( $config['sid'] ?? '' ) );

		if ( '' === $sid ) {
			return '';
		}

		return sprintf( 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode( $sid ) );
	}

	protected function content_type(): string {
		return 'application/x-www-form-urlencoded';
	}

	/**
	 * @param array<string,mixed> $body
	 */
	protected function encode( array $body ): string {
		return http_build_query( $body );
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,string>
	 */
	protected function headers( array $config ): array {
		return [
			'Authorization' => 'Basic ' . base64_encode(
				trim( (string) ( $config['sid'] ?? '' ) ) . ':' . trim( (string) ( $config['token'] ?? '' ) )
			),
		];
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	protected function body( Alert $alert, array $config ): array {
		$text = $alert->title();

		// Enough to act on without opening a laptop: what failed and why.
		if ( '' !== $alert->recipients ) {
			$text .= "\n" . sprintf(
				/* translators: %s: the recipient the message failed to reach. */
				__( 'To: %s', 'modern-mailer-oauth' ),
				$alert->recipients
			);
		}

		if ( '' !== $alert->reason() ) {
			$text .= "\n" . $alert->reason();
		}

		return [
			'From' => trim( (string) ( $config['from'] ?? '' ) ),
			'To'   => trim( (string) ( $config['to'] ?? '' ) ),
			'Body' => mb_substr( $text, 0, self::MAX_BODY ),
		];
	}

	/**
	 * Twilio returns 201 on success and puts a numeric code and a URL to its
	 * own documentation in the error body, which is worth passing through
	 * verbatim - their error pages are genuinely good.
	 *
	 * @param array<string,mixed> $response
	 * @return true|WP_Error
	 */
	protected function interpret( array $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $json ) && isset( $json['message'] ) ) {
			return new WP_Error(
				'mmoa_alert_rejected',
				sprintf(
					/* translators: 1: Twilio error message, 2: Twilio error code. */
					__( 'Twilio refused the alert: %1$s (code %2$s)', 'modern-mailer-oauth' ),
					(string) $json['message'],
					(string) ( $json['code'] ?? '' )
				)
			);
		}

		return parent::interpret( $response );
	}
}
