<?php
/**
 * Plugin Name:       MME-Mail to SMTP
 * Description:       Sends WordPress email through Gmail, Google Workspace, SendGrid, Mailgun, Brevo, Postmark, Resend, SMTP2GO or any SMTP server, instead of the server mail function. A Google service account needs no refresh token and has nothing that expires. A persistent retry queue means a transient fault delays an email rather than losing it.
 * Version:           0.16.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            MME-pro
 * Author URI:        https://mme-pro.de/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mme-mail-to-smtp
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.16.0';
const PLUGIN_FILE = __FILE__;

define( 'ModernMailer\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ModernMailer\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Map a namespaced class to its file and load it.
 *
 * ModernMailer\Token_Store            => includes/class-token-store.php
 * ModernMailer\Providers\Brevo        => includes/providers/class-brevo.php
 * ModernMailer\Providers\Provider_Interface => includes/providers/interface-provider.php
 * ModernMailer\Providers\Abstract_Provider  => includes/providers/abstract-provider.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		if ( 0 === strpos( $name, 'Abstract_' ) ) {
			$file = 'abstract-' . substr( $name, 9 );
		} elseif ( substr( $name, -10 ) === '_Interface' ) {
			$file = 'interface-' . substr( $name, 0, -10 );
		} else {
			$file = 'class-' . $name;
		}

		$dir  = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path = PLUGIN_DIR . 'includes/' . $dir . strtolower( str_replace( '_', '-', $file ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Admin classes live outside includes/, so they get their own tiny loader.
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\Admin\\' ) ) {
			return;
		}

		$name = substr( $class, strlen( __NAMESPACE__ . '\\Admin\\' ) );
		$path = PLUGIN_DIR . 'admin/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

Plugin::instance()->boot();
