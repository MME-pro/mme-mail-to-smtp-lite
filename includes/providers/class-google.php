<?php
/**
 * Google, however you connect to it.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Gmail and Google Workspace as one choice.
 *
 * Two ways in, and which one is right is decided by the account rather than by
 * preference:
 *
 * - **Your own OAuth client** is a sign-in against a Google Cloud project you
 *   registered. It works for any account, consumer or Workspace, and it depends
 *   on nothing of ours - but it keeps a refresh token, which a password change
 *   or a revoked grant will invalidate.
 * - **Service account** uses domain-wide delegation. No consent screen and no
 *   refresh token to be revoked, which makes it the sturdier of the two - but
 *   it is Workspace-only and needs a domain administrator to authorise it.
 *
 * The tile exists so that the chooser asks which mail service you use, not
 * which authentication method you prefer. Somebody arriving at this screen
 * knows they want to send through Google; the method is the second question.
 */
class Google extends Abstract_Merged_Provider {

	/** A sign-in against an OAuth client the site registered itself. */
	public const MODE_OWN_CLIENT = 'own_client';

	/** Domain-wide delegation, as distinct from the sign-in path. */
	public const MODE_SERVICE_ACCOUNT = 'service_account';

	public static function slug(): string {
		return 'google';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Google', 'mme-mail-to-smtp' ),
			'summary'  => __( 'Gmail and Google Workspace.', 'mme-mail-to-smtp' ),
			'docs'     => 'https://developers.google.com/gmail/api/guides/sending',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	protected static function mode_key(): string {
		return 'google_setup_mode';
	}

	protected static function default_mode(): string {
		return self::MODE_OWN_CLIENT;
	}

	/**
	 * Ordered so each transport's fields gate to the mode that uses them.
	 *
	 * Own-client comes first so the OAuth client ID and secret attach to it -
	 * they are meaningless in service-account mode, which reads a signing key
	 * instead.
	 */
	protected static function transports(): array {
		return [
			self::MODE_OWN_CLIENT      => Gmail_OAuth::class,
			self::MODE_SERVICE_ACCOUNT => Gmail_Service_Account::class,
		];
	}

	protected static function mode_field(): Field {
		return new Field(
			key: self::mode_key(),
			label: __( 'How to connect', 'mme-mail-to-smtp' ),
			type: Field::RADIO,
			options: [
				self::MODE_OWN_CLIENT      => __( 'My own OAuth client', 'mme-mail-to-smtp' ),
				self::MODE_SERVICE_ACCOUNT => __( 'Service account', 'mme-mail-to-smtp' ),
			],
			default: self::default_mode()
		);
	}
}
