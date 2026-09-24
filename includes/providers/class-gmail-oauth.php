<?php
	/**
 * Gmail user-consent OAuth provider.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Field;
use WP_Error;

defined( 'ABSPATH' ) || exit;

	/**
 * Sends via Gmail using a refresh token obtained through user consent.
 *
 * This path exists for consumer @gmail.com accounts, which cannot use a
 * service account. It is the one place in the plugin where a long-lived
 * refresh token is unavoidable, so it inherits the failure mode the rest of
 * the design was built to eliminate: the token can be revoked, and when it is,
 * sending stops.
 *
 * We do not pretend otherwise. The setup screen warns about the Google Cloud
 * consent screen being left in Testing status - which silently expires refresh
 * tokens every seven days and is far and away the most common cause of "Gmail
 * worked for a week and then stopped" - and Health_Monitor makes sure a
 * revocation surfaces instead of accumulating quietly.
 */
class Gmail_OAuth extends Abstract_Gmail {

	public function get_label(): string {
		return __( 'Gmail (OAuth)', 'mme-mail-to-smtp' );
	}

	public static function slug(): string {
		return 'gmail_oauth';
	}

	/**
	 * Not listed in the chooser: reached through its merged tile instead.
	 *
	 * Still registered, so a connection storing this slug stays constructible -
	 * which matters both for sites that have not run the migration yet and for
	 * anything setting the slug directly through the mmoa_providers filter.
	 */
	public static function is_listed(): bool {
		return false;
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Gmail', 'mme-mail-to-smtp' ),
			'summary'  => __( 'Consumer @gmail.com, using your own OAuth client and a one-time sign-in.', 'mme-mail-to-smtp' ),
			'docs'     => 'https://developers.google.com/gmail/api/guides/sending',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	public static function fields(): array {
		$fields = [];

		// Both are required, unconditionally. There used to be a setup-mode
		// radio above these, and a `depends` rule that relaxed the requirement in
		// the other mode. There is no other mode here.
		$fields[] = new Field(
			key: 'google_client_id',
			label: __( 'OAuth client ID', 'mme-mail-to-smtp' ),
			required: true,
			help: __( 'Must be a Web application client, not Desktop.', 'mme-mail-to-smtp' )
		);

		$fields[] = new Field(
			key: 'google_client_sec',
			label: __( 'OAuth client secret', 'mme-mail-to-smtp' ),
			type: Field::PASSWORD,
			secret: true,
			required: true
		);

		return $fields;
	}

	/**
	 * `me` resolves to whichever account granted the refresh token.
	 */
	protected function mailbox(): string {
		return 'me';
	}

	protected function token_cache_key(): string {
		// The client ID is part of the key so that rotating a client retires the
		// cached token with it.
		return 'gmail_oauth:' . md5(
			(string) $this->settings->get( 'google_client_id' ) . '|' .
			$this->settings->secrets()->get( 'google_refresh' )
		);
	}

	protected function request_token() {
		$client_id = trim( (string) $this->settings->get( 'google_client_id' ) );
		$secret    = $this->settings->secrets()->get( 'google_client_sec' );
		$refresh   = $this->settings->secrets()->get( 'google_refresh' );

		if ( '' === $client_id || '' === $secret ) {
			return new WP_Error(
				'mmoa_gmail_oauth_incomplete',
				__( 'The Google OAuth client ID or client secret is missing.', 'mme-mail-to-smtp' )
			);
		}

		if ( '' === $refresh ) {
			return new WP_Error(
				'mmoa_gmail_not_connected',
				__( 'No Google account is connected. Use the Connect button on the settings screen.', 'mme-mail-to-smtp' )
			);
		}

		$response = $this->http->request(
			self::TOKEN_URL,
			[
				'method'  => 'POST',
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'client_id'     => $client_id,
					'client_secret' => $secret,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->decode( $response['body'] );

		if ( 200 !== $response['code'] || empty( $data['access_token'] ) ) {
			return $this->map_error( $response['code'], $response['body'] );
		}

		return [
			'token'      => (string) $data['access_token'],
			'expires_in' => (int) ( $data['expires_in'] ?? 3600 ),
		];
	}
}
