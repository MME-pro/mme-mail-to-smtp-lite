<?php
/**
 * A plain webhook, for everything not listed.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * POSTs the alert as JSON to any URL.
 *
 * The escape hatch. Push services, an internal incident tool, Zapier, n8n, a
 * Lambda - anything that can receive JSON. The body is the alert's own shape
 * with nothing service-specific bolted on, and an optional secret header so
 * the receiver can tell a genuine alert from anybody who guessed the URL.
 */
class Webhook extends Abstract_Webhook {

	public static function slug(): string {
		return 'webhook';
	}

	public static function label(): string {
		return __( 'Custom webhook', 'modern-mailer-oauth' );
	}

	public static function summary(): string {
		return __( 'POSTs the alert as JSON to any address you control.', 'modern-mailer-oauth' );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'url'    => [
				'type'        => 'url',
				'required'    => true,
				'label'       => __( 'Endpoint', 'modern-mailer-oauth' ),
				'placeholder' => 'https://',
			],
			'secret' => [
				'type'     => 'password',
				'required' => false,
				'secret'   => true,
				'label'    => __( 'Shared secret', 'modern-mailer-oauth' ),
				'help'     => __(
					'Optional. Sent as the X-MMOA-Secret header so your endpoint can reject anything that did not come from this site.',
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
	 * @return array<string,string>
	 */
	protected function headers( array $config ): array {
		$secret = trim( (string) ( $config['secret'] ?? '' ) );

		return '' === $secret ? [] : [ 'X-MMOA-Secret' => $secret ];
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	protected function body( Alert $alert, array $config ): array {
		unset( $config );

		return array_merge(
			$alert->to_array(),
			[
				'title'   => $alert->title(),
				'text'    => $alert->as_text(),
				'log_url' => $alert->log_url(),
			]
		);
	}
}
