<?php
/**
 * Bitrix24.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends into Bitrix24 through an inbound REST webhook.
 *
 * Three destinations, because the right one depends on what the team does with
 * an alert rather than on anything technical:
 *
 * - **Notification** goes to one person's notification centre. Right when one
 *   administrator is responsible for the site.
 * - **Chat message** goes into a group chat. Right when a team watches
 *   together and nobody is on call.
 * - **Task** creates a task with a responsible person. Right when an alert is
 *   expected to be worked and closed rather than read.
 *
 * The webhook URL is the whole credential - anybody holding it can call the
 * REST methods it was granted - so it is stored encrypted like any other
 * secret and never returned to the browser once saved.
 */
class Bitrix24 extends Abstract_Webhook {

	public const MODE_NOTIFY = 'notify';
	public const MODE_CHAT   = 'chat';
	public const MODE_TASK   = 'task';

	public static function slug(): string {
		return 'bitrix24';
	}

	public static function label(): string {
		return __( 'Bitrix24', 'modern-mailer-oauth' );
	}

	public static function summary(): string {
		return __( 'Sends a notification, a chat message or a task through an inbound webhook.', 'modern-mailer-oauth' );
	}

	/**
	 * Bitrix24's own page on inbound webhooks.
	 *
	 * Not a training.bitrix24.com course link. Those are numbered by COURSE_ID
	 * and LESSON_ID, the numbers are reassigned when the courses are
	 * reorganised, and a stale one answers 200 with "Course not found or access
	 * denied" in the body rather than a 404 - so it looks reachable to
	 * everything except the person who clicked it. The previous link here had
	 * gone that way. apidocs.bitrix24.com is the maintained developer
	 * documentation and its paths are words rather than numbers.
	 */
	public static function docs(): string {
		return 'https://apidocs.bitrix24.com/local-integrations/local-webhooks.html';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'url'    => [
				'type'        => 'url',
				'required'    => true,
				'secret'      => true,
				'label'       => __( 'Inbound webhook URL', 'modern-mailer-oauth' ),
				'placeholder' => 'https://your-portal.bitrix24.com/rest/1/xxxxxxxx/',
				'help'        => __(
					'Bitrix24, Developer resources, Other, Inbound webhook. Grant it the im scope for notifications and chat, or tasks for tasks.',
					'modern-mailer-oauth'
				),
			],
			'mode'   => [
				'type'     => 'select',
				'required' => true,
				'label'    => __( 'Send as', 'modern-mailer-oauth' ),
				'options'  => [
					self::MODE_NOTIFY => __( 'Notification to a user', 'modern-mailer-oauth' ),
					self::MODE_CHAT   => __( 'Message in a chat', 'modern-mailer-oauth' ),
					self::MODE_TASK   => __( 'Task', 'modern-mailer-oauth' ),
				],
			],
			'target' => [
				'type'        => 'text',
				'required'    => true,
				'label'       => __( 'User or chat ID', 'modern-mailer-oauth' ),
				'placeholder' => '1',
				'help'        => __(
					'The numeric user ID for a notification or a task, or the chat ID for a chat message. A user ID is the number at the end of their Bitrix24 profile URL.',
					'modern-mailer-oauth'
				),
			],
		];
	}

	/**
	 * @param array<string,mixed> $config
	 */
	public static function is_configured( array $config ): bool {
		return '' !== trim( (string) ( $config['url'] ?? '' ) )
			&& '' !== trim( (string) ( $config['target'] ?? '' ) );
	}

	private static function mode( array $config ): string {
		$mode = (string) ( $config['mode'] ?? self::MODE_NOTIFY );

		return in_array( $mode, [ self::MODE_NOTIFY, self::MODE_CHAT, self::MODE_TASK ], true )
			? $mode
			: self::MODE_NOTIFY;
	}

	/**
	 * The base webhook with the REST method appended.
	 *
	 * @param array<string,mixed> $config
	 */
	protected function endpoint( array $config ): string {
		$base = trim( (string) ( $config['url'] ?? '' ) );

		if ( '' === $base ) {
			return '';
		}

		$method = [
			self::MODE_NOTIFY => 'im.notify.system.add',
			self::MODE_CHAT   => 'im.message.add',
			self::MODE_TASK   => 'tasks.task.add',
		][ self::mode( $config ) ];

		return rtrim( $base, '/' ) . '/' . $method . '.json';
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	protected function body( Alert $alert, array $config ): array {
		$target = trim( (string) ( $config['target'] ?? '' ) );

		switch ( self::mode( $config ) ) {
			case self::MODE_CHAT:
				return [
					'DIALOG_ID' => $target,
					'MESSAGE'   => $this->bbcode( $alert ),
				];

			case self::MODE_TASK:
				return [
					'fields' => [
						'TITLE'           => $alert->title(),
						'DESCRIPTION'     => $alert->as_text(),
						'RESPONSIBLE_ID'  => $target,
					],
				];

			default:
				return [
					'USER_ID' => $target,
					'MESSAGE' => $this->bbcode( $alert ),
				];
		}
	}

	/**
	 * Bitrix24 messages are BBCode, not Markdown and not HTML.
	 */
	private function bbcode( Alert $alert ): string {
		$out = '[B]' . $alert->title() . '[/B]' . "\n";

		foreach ( $alert->lines() as $label => $value ) {
			$out .= sprintf( "[B]%s:[/B] %s\n", $label, $value );
		}

		return $out . sprintf( '[URL=%s]%s[/URL]', $alert->log_url(), __( 'Open the send log', 'modern-mailer-oauth' ) );
	}

	/**
	 * Bitrix24 answers 200 with an error object rather than an error status
	 * for most application-level failures - a wrong user ID, a scope the
	 * webhook was not granted. Reading only the status would report those as
	 * delivered.
	 *
	 * @param array<string,mixed> $response
	 * @return true|WP_Error
	 */
	protected function interpret( array $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $json ) && isset( $json['error'] ) ) {
			return new WP_Error(
				'mmoa_alert_rejected',
				sprintf(
					/* translators: 1: Bitrix24 error code, 2: description. */
					__( 'Bitrix24 refused the alert: %1$s - %2$s', 'modern-mailer-oauth' ),
					(string) $json['error'],
					(string) ( $json['error_description'] ?? '' )
				)
			);
		}

		if ( $code >= 200 && $code < 300 && is_array( $json ) && array_key_exists( 'result', $json ) ) {
			return true;
		}

		return parent::interpret( $response );
	}
}
