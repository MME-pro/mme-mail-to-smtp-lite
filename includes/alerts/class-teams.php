<?php
/**
 * Microsoft Teams.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Posts to Teams through a Workflows webhook.
 *
 * ## Why an Adaptive Card and not a MessageCard
 *
 * Nearly every "post to Teams" integration still sends the old Office 365
 * connector MessageCard format. Microsoft retired Office 365 connectors, and
 * the replacement - a Power Automate "Workflows" webhook, which is what the
 * Teams UI now creates - does not accept MessageCard. It accepts a message
 * with an Adaptive Card attachment, which is what this sends.
 *
 * The practical consequence for an administrator: get the URL from Workflows
 * ("Post to a channel when a webhook request is received"), not from the old
 * Incoming Webhook connector.
 */
class Teams extends Abstract_Webhook {

	public static function slug(): string {
		return 'teams';
	}

	public static function label(): string {
		return __( 'Microsoft Teams', 'modern-mailer-oauth' );
	}

	public static function summary(): string {
		return __( 'Posts to a channel through a Workflows webhook.', 'modern-mailer-oauth' );
	}

	public static function docs(): string {
		return 'https://support.microsoft.com/en-us/office/create-incoming-webhooks-with-workflows-for-microsoft-teams-8ae491c7-0394-4861-ba59-055e33f75498';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'url' => [
				'type'        => 'url',
				'required'    => true,
				'label'       => __( 'Workflows webhook URL', 'modern-mailer-oauth' ),
				'placeholder' => 'https://prod-00.westeurope.logic.azure.com:443/workflows/...',
				'help'        => __(
					'In Teams: channel menu, Workflows, "Post to a channel when a webhook request is received". The older Incoming Webhook connector has been retired by Microsoft and will not work.',
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

		$facts = [];

		foreach ( $alert->lines() as $label => $value ) {
			$facts[] = [
				'title' => $label,
				'value' => $value,
			];
		}

		return [
			'type'        => 'message',
			'attachments' => [
				[
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'contentUrl'  => null,
					'content'     => [
						'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
						'type'    => 'AdaptiveCard',
						'version' => '1.4',
						'body'    => [
							[
								'type'   => 'TextBlock',
								'text'   => $alert->title(),
								'weight' => 'Bolder',
								'size'   => 'Medium',
								'wrap'   => true,
								'color'  => $alert->is_bad() ? 'Attention' : 'Good',
							],
							[
								'type'  => 'FactSet',
								'facts' => $facts,
							],
						],
						'actions' => [
							[
								'type'  => 'Action.OpenUrl',
								'title' => __( 'Open the send log', 'modern-mailer-oauth' ),
								'url'   => $alert->log_url(),
							],
						],
					],
				],
			],
		];
	}
}
