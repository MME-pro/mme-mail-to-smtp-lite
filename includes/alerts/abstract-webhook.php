<?php
/**
 * Shared machinery for the channels that are just an HTTP POST.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Most channels are one POST to a URL the administrator pasted in.
 *
 * What differs between them is the JSON body and, occasionally, what counts as
 * success. What does not differ is everything that makes the POST safe to do
 * from inside a page request, which is what lives here.
 *
 * ## Why the timeout is short and there is no retry
 *
 * This runs inside whatever request was sending the email - a checkout, a
 * password reset, a form submission. A Slack outage must not turn into a
 * thirty-second page load for a customer, so the timeout is five seconds and
 * a failure is reported rather than retried. An alert that arrives late is
 * worth less than a page that arrives on time.
 */
abstract class Abstract_Webhook implements Channel_Interface {

	protected const TIMEOUT = 5;

	/**
	 * The JSON body this service expects.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	abstract protected function body( Alert $alert, array $config ): array;

	/**
	 * Where to POST it.
	 *
	 * @param array<string,mixed> $config
	 */
	abstract protected function endpoint( array $config ): string;

	/**
	 * Headers beyond the JSON content type.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,string>
	 */
	protected function headers( array $config ): array {
		unset( $config );

		return [];
	}

	/**
	 * How the body is encoded. JSON for almost everything; Twilio wants form
	 * encoding, and says so by overriding this.
	 *
	 * @param array<string,mixed> $body
	 * @return string|array<string,mixed>
	 */
	protected function encode( array $body ) {
		return (string) wp_json_encode( $body );
	}

	protected function content_type(): string {
		return 'application/json';
	}

	/**
	 * @param array<string,mixed> $config
	 * @return true|WP_Error
	 */
	public function deliver( Alert $alert, array $config ) {
		$url = $this->endpoint( $config );

		if ( '' === $url ) {
			return new WP_Error(
				'mmoa_alert_no_endpoint',
				sprintf(
					/* translators: %s: channel name. */
					__( '%s has no webhook address configured.', 'modern-mailer-oauth' ),
					static::label()
				)
			);
		}

		// An administrator pasting a webhook into a settings field is one typo
		// away from posting a recipient address and subject line to an
		// arbitrary host. Requiring HTTPS does not make that impossible, but it
		// does stop it happening in clear text.
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error(
				'mmoa_alert_insecure',
				sprintf(
					/* translators: %s: channel name. */
					__( 'The %s webhook address must start with https://.', 'modern-mailer-oauth' ),
					static::label()
				)
			);
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array_merge(
					[ 'Content-Type' => $this->content_type() ],
					$this->headers( $config )
				),
				'body'        => $this->encode( $this->body( $alert, $config ) ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mmoa_alert_unreachable',
				sprintf(
					/* translators: 1: channel name, 2: the transport error. */
					__( 'Could not reach %1$s: %2$s', 'modern-mailer-oauth' ),
					static::label(),
					$response->get_error_message()
				)
			);
		}

		return $this->interpret( $response );
	}

	/**
	 * Did the service accept it?
	 *
	 * The default is "any 2xx", which is right for Discord, Teams and a plain
	 * webhook. Slack and Bitrix24 answer 200 and put the failure in the body,
	 * so they override this - otherwise a rejected alert reports success and
	 * the administrator finds out during the next outage that the channel
	 * never worked.
	 *
	 * @param array<string,mixed> $response
	 * @return true|WP_Error
	 */
	protected function interpret( array $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new WP_Error(
			'mmoa_alert_rejected',
			sprintf(
				/* translators: 1: channel name, 2: HTTP status, 3: response body. */
				__( '%1$s refused the alert (HTTP %2$d): %3$s', 'modern-mailer-oauth' ),
				static::label(),
				$code,
				$this->excerpt( $response )
			)
		);
	}

	/**
	 * Enough of the response to diagnose it, and no more. These bodies can be
	 * an HTML error page.
	 *
	 * @param array<string,mixed> $response
	 */
	protected function excerpt( array $response ): string {
		$body = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) );

		return '' === $body
			? __( 'no response body', 'modern-mailer-oauth' )
			: mb_substr( $body, 0, 200 );
	}

	public static function docs(): string {
		return '';
	}

	/**
	 * Most channels need one URL and nothing else.
	 *
	 * @param array<string,mixed> $config
	 */
	public static function is_configured( array $config ): bool {
		return '' !== trim( (string) ( $config['url'] ?? '' ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return [
			'url' => [
				'type'        => 'url',
				'required'    => true,
				'label'       => __( 'Webhook address', 'modern-mailer-oauth' ),
				'placeholder' => 'https://',
			],
		];
	}
}
