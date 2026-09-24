<?php
/**
 * Slack.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Posts to a Slack incoming webhook.
 */
class Slack extends Abstract_Webhook {

	public static function slug(): string {
		return 'slack';
	}

	public static function label(): string {
		return __( 'Slack', 'modern-mailer-oauth' );
	}

	public static function summary(): string {
		return __( 'Posts to a channel through an incoming webhook.', 'modern-mailer-oauth' );
	}

	public static function docs(): string {
		return 'https://api.slack.com/messaging/webhooks';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'url' => [
				'type'        => 'url',
				'required'    => true,
				'label'       => __( 'Incoming webhook URL', 'modern-mailer-oauth' ),
				'placeholder' => 'https://hooks.slack.com/services/...',
				'help'        => __(
					'Slack, Your apps, Incoming Webhooks. The webhook decides which channel this lands in.',
					'modern-mailer-oauth'
				),
			],
		];
	}

	/**
	 * @param array<string,mixed> $config
	 */
	protected function endpoint( array $config ): string {
		return trim( (string) ( $config['url'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	protected function body( Alert $alert, array $config ): array {
		unset( $config );

		$fields = [];

		foreach ( $alert->lines() as $label => $value ) {
			$fields[] = [
				'type' => 'mrkdwn',
				'text' => sprintf( "*%s*\n%s", $label, $value ),
			];
		}

		return [
			// text is not decoration: it is what Slack shows in the desktop
			// notification and the channel list, where blocks are not rendered.
			'text'   => $alert->title(),
			'blocks' => [
				[
					'type' => 'header',
					'text' => [
						'type'  => 'plain_text',
						'text'  => mb_substr( $alert->title(), 0, 150 ),
						'emoji' => true,
					],
				],
				[
					'type'   => 'section',
					// Slack renders at most ten fields in a section, and the
					// payload has seven at its longest.
					'fields' => array_slice( $fields, 0, 10 ),
				],
				[
					'type'     => 'context',
					'elements' => [
						[
							'type' => 'mrkdwn',
							'text' => sprintf( '<%s|%s>', $alert->log_url(), __( 'Open the send log', 'modern-mailer-oauth' ) ),
						],
					],
				],
			],
		];
	}

	/**
	 * Slack answers 200 with the body "ok", and 200 with something else when
	 * it has taken the request but not the message.
	 *
	 * @param array<string,mixed> $response
	 * @return true|WP_Error
	 */
	protected function interpret( array $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( 200 === $code && 'ok' === strtolower( $body ) ) {
			return true;
		}

		return new WP_Error(
			'mmoa_alert_rejected',
			sprintf(
				/* translators: 1: HTTP status, 2: Slack's response. */
				__( 'Slack refused the alert (HTTP %1$d): %2$s', 'modern-mailer-oauth' ),
				$code,
				'' === $body ? __( 'no response body', 'modern-mailer-oauth' ) : mb_substr( $body, 0, 200 )
			)
		);
	}
}
