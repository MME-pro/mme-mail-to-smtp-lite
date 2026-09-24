<?php
/**
 * Microsoft, however you connect to it.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Providers;

use ModernMailer\Auth\Broker;
use ModernMailer\Auth\One_Click;
use ModernMailer\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Microsoft 365 and Outlook as one choice.
 *
 * The two ways in are genuinely different, and the difference matters enough to
 * explain on the form rather than bury in two tiles that looked alike:
 *
 * - **Own Auth App** is delegated. A person signs in once against an app
 *   registration that needs no tenant ID and no administrator, and mail goes
 *   out as their own mailbox. It holds a refresh token, which a password or
 *   multi-factor change revokes.
 * - **One-click** is the same trade-off with nothing registered in Azure at
 *   all, the credential coming from the setup service instead.
 *
 * The app-only Graph transport is still behind this tile and still sends for
 * any connection already set to it, but it is no longer offered as a choice.
 * It is the better mechanism on paper - nothing expires but the secret, and it
 * can send as any mailbox in the tenant - and it asks for a tenant ID and an
 * administrator willing to grant tenant-wide permission, which turned out to
 * be the step most people could not complete.
 *
 * Neither is a lesser version of the other, so the form presents them as a
 * choice about circumstances rather than a recommendation.
 */
class Microsoft extends Abstract_Merged_Provider {

	public static function slug(): string {
		return 'microsoft';
	}

	public static function describe(): array {
		return [
			'label'    => __( 'Microsoft', 'modern-mailer-oauth' ),
			'summary'  => __( 'Microsoft 365 and Outlook.', 'modern-mailer-oauth' ),
			'docs'     => 'https://learn.microsoft.com/graph/auth-v2-service',
			'category' => 'oauth',
			'raw_mime' => true,
		];
	}

	protected static function mode_key(): string {
		return 'ms_setup_mode';
	}

	/**
	 * Own Auth App, since Graph is no longer offered.
	 *
	 * A default has to be something the selector actually shows, or a new
	 * connection opens with nothing chosen.
	 */
	protected static function default_mode(): string {
		return Microsoft_OAuth::MODE;
	}

	/**
	 * Ordered so each transport's fields gate to the mode that uses them:
	 * the Azure credentials belong to the own-app mode, and the one-click
	 * transport declares none.
	 */
	protected static function transports(): array {
		$out = [
			One_Click::MODE_OWN_CLIENT => Graph::class,

			// Always offered. It depends on nothing but an app registration
			// the admin makes themselves, so unlike one-click there is no
			// service that could be absent and make it unusable.
			Microsoft_OAuth::MODE     => Microsoft_OAuth::class,
		];

		// Offered only where a broker exists to answer. Without one the
		// one-click transport could never obtain a credential, and a mode that
		// cannot work must not be selectable.
		if ( Broker::is_available() ) {
			$out[ One_Click::MODE_ONE_CLICK ] = Outlook::class;
		}

		return $out;
	}

	protected static function mode_field(): Field {
		$options = [];

		if ( Broker::is_available() ) {
			$options[ One_Click::MODE_ONE_CLICK ] = __( 'One-click', 'modern-mailer-oauth' );
		}

		// Graph is deliberately absent. It stays in transports() above, so a
		// connection already set to it goes on sending exactly as before -
		// withdrawing a working transport in an update must never stop a
		// site's mail. It is simply no longer something new connections can
		// be pointed at.
		$options[ Microsoft_OAuth::MODE ]      = __( 'Own Auth App', 'modern-mailer-oauth' );

		return new Field(
			key: self::mode_key(),
			label: __( 'How to connect', 'modern-mailer-oauth' ),
			type: Field::RADIO,
			options: $options,
			default: self::default_mode()
		);
	}
}
