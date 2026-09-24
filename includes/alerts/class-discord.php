<?php
/**
 * Discord.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Posts to a Discord channel webhook.
 */
class Discord extends Abstract_Webhook {

	/** Discord takes a decimal colour, not a hex string. */
	private const RED   = 0xD93025;
	private const GREEN = 0x2E7D4F;

	public static function slug(): string {
		return 'discord';
	}

	public static function label(): string {
		return __( 'Discord', 'modern-mailer-oauth' );
	}

	public static function summary(): string {
		return __( 'Posts to a channel through a channel webhook.', 'modern-mailer-oauth' );
	}

	public static function docs(): string {
		return 'https://support.discord.com/hc/en-us/articles/228383668';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'url' => [
				'type'        => 'url',
				'required'    => true,
				'label'       => __( 'Channel webhook URL', 'modern-mailer-oauth' ),
				'placeholder' => 'https://discord.com/api/webhooks/...',
				'help'        => __(
					'Server Settings, Integrations, Webhooks, New Webhook.',
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
				'name'   => mb_substr( $label, 0, 256 ),
				'value'  => mb_substr( $value, 0, 1024 ),

				// Short fields sit two to a row. The subject and the error are
				// long enough to want the whole width to themselves.
				'inline' => mb_strlen( $value ) < 40,
			];
		}

		return [
			'username' => 'MME-Mail to SMTP',
			'embeds'   => [
				[
					'title'       => mb_substr( $alert->title(), 0, 256 ),
					'url'         => $alert->log_url(),
					'color'       => $alert->is_bad() ? self::RED : self::GREEN,
					'fields'      => array_slice( $fields, 0, 25 ),
					'timestamp'   => gmdate( 'c', $alert->time ?: time() ),
					'footer'      => [ 'text' => (string) get_bloginfo( 'name' ) ],
				],
			],
		];
	}
}
