<?php
/**
 * What an alert channel has to be able to do.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Alerts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One place an alert can be sent.
 *
 * Deliberately narrow. A channel knows how to format an Alert for one service
 * and how to hand it over; it does not decide whether to send, does not retry,
 * does not rate-limit and does not know about any other channel. All of that
 * belongs to Alerts, which is the only caller.
 *
 * ## The rule every channel obeys
 *
 * **A channel must never send email through this plugin.** The whole system
 * exists to report that sending is broken, and a report that travels down the
 * broken path is not a report. The email channel uses the server's native
 * mail() for exactly this reason; every other channel is HTTP and has the
 * property for free.
 */
interface Channel_Interface {

	/**
	 * Stable identifier, stored in settings and used in the REST payload.
	 */
	public static function slug(): string;

	/**
	 * Name as an administrator would say it.
	 */
	public static function label(): string;

	/**
	 * One sentence for the settings screen: what this is and what it needs.
	 */
	public static function summary(): string;

	/**
	 * Where to go to get whatever this channel needs.
	 */
	public static function docs(): string;

	/**
	 * The fields this channel needs configuring, in Field's shape.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array;

	/**
	 * Is this channel configured well enough to be worth trying?
	 *
	 * Checked before sending so a half-filled channel is reported on the
	 * settings screen rather than failing silently at three in the morning.
	 *
	 * @param array<string,mixed> $config This channel's saved settings.
	 */
	public static function is_configured( array $config ): bool;

	/**
	 * Hand the alert over.
	 *
	 * @param array<string,mixed> $config This channel's saved settings.
	 * @return true|WP_Error True on delivery, WP_Error with a message an
	 *                       administrator can act on otherwise.
	 */
	public function deliver( Alert $alert, array $config );
}
