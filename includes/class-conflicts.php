<?php
/**
 * Detection of other mail plugins.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

use ModernMailer\Admin\App_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the other plugins that want to send this site's email, and says so.
 *
 * wp_mail() is pluggable, which means exactly one plugin can replace it: the
 * first one loaded wins and every other mailer on the site is left installed,
 * configured, and doing absolutely nothing. That is a genuinely confusing
 * state to be in - the settings look right, the test button fails, and nothing
 * anywhere says why - and it is the single most common reason a working mail
 * plugin appears to be broken.
 *
 * Two different questions are asked here, because they have different answers.
 *
 * Which plugins are *known* mailers is a lookup against a list of slugs. It
 * catches the case that matters most in practice - somebody installing this
 * alongside the plugin they are replacing - and it can name the plugin, which
 * a person can act on.
 *
 * Who actually *owns* wp_mail() is a different question, answered by asking PHP
 * where the function was defined. That catches anything the list has never
 * heard of, including a mailer bundled inside a theme or a custom plugin, at
 * the cost of only being able to point at a file.
 *
 * Neither check can be done from the list alone, which is why both are here.
 */
class Conflicts {

	/**
	 * Where the dismissal of the advisory notice is remembered, per user.
	 *
	 * Per user rather than per site: one administrator deciding they are happy
	 * with a dormant plugin is not the same as everybody deciding it.
	 */
	private const DISMISSED_META = 'mmoa_dismissed_mailers';

	private const DISMISS_ACTION = 'mmoa_dismiss_mailers';

	private const CAPABILITY = 'manage_options';

	/**
	 * Plugins that replace wp_mail(), by directory slug.
	 *
	 * The value is only a fallback label - the real name is read from the
	 * plugin's own header, so a renamed or translated plugin still reads
	 * correctly. Matching is on the slug, which is what survives a version
	 * bump and a rename.
	 *
	 * Deliberately restricted to plugins whose job is to take over sending.
	 * Newsletter plugins, form plugins and mail *logging* plugins are not here:
	 * they sit alongside a mailer rather than competing with it, and warning
	 * about them would teach people to ignore this notice.
	 */
	private const KNOWN = [
		'wp-mail-smtp'                       => 'WP Mail SMTP',
		'wp-mail-smtp-pro'                   => 'WP Mail SMTP Pro',
		'easy-wp-smtp'                       => 'Easy WP SMTP',
		'post-smtp'                          => 'Post SMTP',
		'postman-smtp'                       => 'Postman SMTP',
		'fluent-smtp'                        => 'FluentSMTP',
		'wp-smtp'                            => 'WP SMTP',
		'smtp-mailer'                        => 'SMTP Mailer',
		'gmail-smtp'                         => 'Gmail SMTP',
		'wp-offload-ses-lite'                => 'WP Offload SES Lite',
		'wp-ses'                             => 'WP Offload SES',
		'sendgrid-email-delivery-simplified' => 'SendGrid',
		'mailgun'                            => 'Mailgun',
		'mailjet-for-wordpress'              => 'Mailjet',
		'mailin'                             => 'Brevo',
		'smtp2go'                            => 'SMTP2GO',
		'postmark-approved-wordpress-plugin' => 'Postmark',
		'sparkpost'                          => 'SparkPost',
		'elastic-email-sender'               => 'Elastic Email Sender',
		'turbosmtp'                          => 'turboSMTP',
		'cimy-swift-smtp'                    => 'Cimy Swift SMTP',
		'sar-friendly-smtp'                  => 'SAR Friendly SMTP',
		'wp-mail-bank'                       => 'Mail Bank',
	];

	/**
	 * Memoized: the notice asks for this two or three times in a request, and
	 * get_plugins() reads the header of every plugin on the site.
	 *
	 * @var array<string,array{name:string,active:bool}>|null
	 */
	private ?array $found = null;

	/**
	 * Memoized separately from found(), because it is worked out separately.
	 *
	 * @var array<string,array{name:string,active:bool}>|null
	 */
	private ?array $active = null;

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss' ] );
	}

	/**
	 * Every known mailer installed on this site, ours excluded.
	 *
	 * @return array<string,array{name:string,active:bool}> Keyed by plugin file.
	 */
	public function found(): array {
		if ( null !== $this->found ) {
			return $this->found;
		}

		$this->ensure_plugin_api();

		$known = $this->known();
		$ours  = plugin_basename( PLUGIN_FILE );
		$out   = [];

		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $ours ) {
				continue;
			}

			$slug = $this->slug_of( $file );

			if ( ! isset( $known[ $slug ] ) ) {
				continue;
			}

			$out[ $file ] = [
				'name'   => '' !== (string) ( $data['Name'] ?? '' ) ? (string) $data['Name'] : (string) $known[ $slug ],
				'active' => $this->is_active( $file ),
			];
		}

		$this->found = $out;

		return $this->found;
	}

	/**
	 * get_plugins() and friends live in an admin file that is not always loaded.
	 *
	 * It is, on every screen this notice prints on. It is not on admin-post.php,
	 * which is where the dismissal lands.
	 */
	private function ensure_plugin_api(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * The mailers that are switched on.
	 *
	 * Deliberately does not go through found(). This is asked on every single
	 * admin page load - it is what decides whether the notice prints at all -
	 * and found() calls get_plugins(), which opens and reads the header of
	 * every plugin installed on the site. The list of active plugins is a
	 * single autoloaded option that WordPress has already read, so the common
	 * case, where nothing conflicts, costs one array walk and no file access.
	 *
	 * A plugin's real name is only worth fetching once there is something to
	 * name, and then it is one file rather than all of them.
	 *
	 * @return array<string,array{name:string,active:bool}>
	 */
	public function active(): array {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$known = $this->known();
		$files = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		$ours = plugin_basename( PLUGIN_FILE );
		$out  = [];

		foreach ( $files as $file ) {
			$file = (string) $file;
			$slug = $this->slug_of( $file );

			if ( $file === $ours || ! isset( $known[ $slug ] ) ) {
				continue;
			}

			$out[ $file ] = [
				'name'   => $this->name_of( $file, (string) $known[ $slug ] ),
				'active' => true,
			];
		}

		$this->active = $out;

		return $this->active;
	}

	/**
	 * One plugin's own name, read from its header.
	 *
	 * Falls back to the label in the list, which is what a plugin whose file
	 * has been deleted from under an active entry leaves us with.
	 */
	private function name_of( string $file, string $fallback ): string {
		$this->ensure_plugin_api();

		$path = WP_PLUGIN_DIR . '/' . $file;

		if ( is_readable( $path ) ) {
			$data = get_plugin_data( $path, false, false );
			$name = (string) ( $data['Name'] ?? '' );

			if ( '' !== $name ) {
				return $name;
			}
		}

		return $fallback;
	}

	/**
	 * @return array<string,string>
	 */
	private function known(): array {
		/**
		 * Adjust the list of plugins treated as competing mailers.
		 *
		 * Keyed by directory slug - or by filename without its extension for a
		 * single-file plugin - with a human label as the value. Removing an
		 * entry silences the notice for it, which is the supported way to say
		 * "I know, and I want both".
		 *
		 * @param array<string,string> $known Slug => label.
		 */
		return (array) apply_filters( 'mmoa_known_mailers', self::KNOWN );
	}

	/**
	 * Installed, but switched off.
	 *
	 * This one does need the full scan - an inactive plugin is, by definition,
	 * not in the list of active ones - so it is only ever asked on a screen
	 * where that cost is already being paid or is clearly worth it: the Plugins
	 * screen, this plugin's own screen, and Site Health.
	 *
	 * @return array<string,array{name:string,active:bool}>
	 */
	public function dormant(): array {
		return array_filter( $this->found(), static fn( array $p ): bool => ! $p['active'] );
	}

	/**
	 * Which plugin file defined wp_mail(), if it was not WordPress.
	 *
	 * Core's own wp_mail lives in wp-includes/pluggable.php. Anything else
	 * means a plugin declared it first and holds the only copy there can be.
	 *
	 * @return string Plugin-relative path, or '' when core still owns it.
	 */
	public function wp_mail_owner(): string {
		if ( ! function_exists( 'wp_mail' ) ) {
			return '';
		}

		try {
			$file = (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();
		} catch ( \ReflectionException ) {
			return '';
		}

		$file = str_replace( '\\', '/', $file );

		if ( '' === $file || false !== strpos( $file, 'wp-includes' ) ) {
			return '';
		}

		// Both sides are normalised before the prefix is taken off, and that is
		// not tidiness. On Windows the constants hold backslashes while the path
		// PHP reports for the file comes back with forward slashes, so stripping
		// first matched nothing and the notice printed the full server path -
		// C:/sites/example/wp-content/plugins/... - instead of the plugin.
		foreach ( [ defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '', WP_PLUGIN_DIR ] as $dir ) {
			if ( '' === $dir ) {
				continue;
			}

			$prefix = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';

			if ( 0 === strpos( $file, $prefix ) ) {
				return substr( $file, strlen( $prefix ) );
			}
		}

		// Outside the plugin directories altogether - a theme's functions.php,
		// or something a host has dropped in. Reported whole, because the path
		// is then the only useful thing to say about it.
		return $file;
	}

	/**
	 * The notice itself.
	 *
	 * Three states, and they are not the same message, because the reader is
	 * not in the same situation:
	 *
	 * - Another mailer is *active*. Sending is already being fought over. Said
	 *   on every admin screen, and not dismissible, because it is very likely
	 *   the reason mail is not arriving.
	 * - Something unrecognised has taken wp_mail(). Same severity, less detail,
	 *   and only worth saying once this plugin is configured - before that we
	 *   are not trying to send anything and whatever has it is doing the job.
	 * - A known mailer is installed but *inactive*. Nothing is wrong today. Said
	 *   quietly, only where plugins are managed, and dismissible.
	 */
	public function notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$active = $this->active();

		if ( $active ) {
			$this->render_active( $active );

			return;
		}

		$owner = $this->wp_mail_owner();

		if ( '' !== $owner && $this->settings->is_active() ) {
			$this->render_unknown_owner( $owner );

			return;
		}

		// The screen is checked before the list is built, not after. Building it
		// is the expensive part - it reads the header of every plugin on the
		// site - and on every screen that is not about plugins the answer is
		// thrown away, which is nearly every page load.
		if ( ! $this->on_plugin_screen() ) {
			return;
		}

		$dormant = $this->dormant();

		if ( $dormant && ! $this->is_dismissed( $dormant ) ) {
			$this->render_dormant( $dormant );
		}
	}

	/**
	 * @param array<string,array{name:string,active:bool}> $plugins Active mailers.
	 */
	private function render_active( array $plugins ): void {
		$names   = wp_list_pluck( $plugins, 'name' );
		$owner   = $this->wp_mail_owner();
		$winning = '';

		// Compared directory to directory. The owner is the file that happens
		// to define wp_mail(), which is rarely the plugin's main file - WP Mail
		// SMTP declares it from somewhere under src/ - so only the folder they
		// share can match.
		foreach ( $plugins as $file => $plugin ) {
			if ( '' !== $owner && $this->slug_of( $owner ) === $this->slug_of( $file ) ) {
				$winning = $plugin['name'];
			}
		}

		echo '<div class="notice notice-error"><p><strong>';

		echo esc_html(
			_n(
				'Another plugin is also set up to send this site\'s email.',
				'Other plugins are also set up to send this site\'s email.',
				count( $plugins ),
				'modern-mailer-oauth'
			)
		);

		echo '</strong> ';

		printf(
			/* translators: %s: comma-separated plugin names. */
			esc_html__( 'WordPress lets exactly one plugin take over sending, so whichever loads first wins and every other one is left configured and doing nothing. %s is installed and active alongside this plugin.', 'modern-mailer-oauth' ),
			'<strong>' . esc_html( implode( ', ', $names ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		echo '</p>';

		if ( '' !== $winning ) {
			echo '<p>';
			printf(
				/* translators: %s: the name of the plugin currently sending. */
				esc_html__( 'Right now %s is the one sending, and this plugin is the one doing nothing.', 'modern-mailer-oauth' ),
				'<strong>' . esc_html( $winning ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			echo '</p>';
		}

		// What to do about it depends entirely on whether this plugin can send
		// yet. "Deactivate the other one" is the right advice once it can, and
		// is close to sabotage before then: it would leave the site with no
		// mailer at all, and the person following it would have no idea why
		// their email stopped rather than improved.
		$configured = $this->settings->is_active();

		echo '<p>';

		echo esc_html(
			$configured
				? __( 'Deactivate and delete the other one, so this connection is the one that sends.', 'modern-mailer-oauth' )
				: __( 'Set this plugin up first - deactivating the other one before then leaves the site with nothing sending at all. Once mail is going out through this connection, deactivate and delete it.', 'modern-mailer-oauth' )
		);

		echo '</p><p>';

		if ( ! $configured ) {
			printf(
				'<a class="button button-primary" style="margin-right:6px" href="%s">%s</a>',
				esc_url( Setup::url() ),
				esc_html__( 'Set up sending', 'modern-mailer-oauth' )
			);
		}

		foreach ( $plugins as $file => $plugin ) {
			$url = $this->deactivate_url( $file );

			if ( '' === $url ) {
				continue;
			}

			printf(
				'<a class="button %s" style="margin-right:6px" href="%s">%s</a>',
				// Only the primary action when this plugin can actually take
				// over. Before that, setting it up is.
				$configured ? 'button-primary' : '',
				esc_url( $url ),
				sprintf(
					/* translators: %s: plugin name. */
					esc_html__( 'Deactivate %s', 'modern-mailer-oauth' ),
					esc_html( $plugin['name'] )
				)
			);
		}

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Manage plugins', 'modern-mailer-oauth' )
		);

		echo '</p></div>';
	}

	/**
	 * Something took wp_mail() that the list does not recognise.
	 */
	private function render_unknown_owner( string $file ): void {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
			esc_html__( 'Another plugin has taken over email sending.', 'modern-mailer-oauth' ),
			esc_html__(
				'It defined wp_mail() before WordPress could, so MME-Mail to SMTP is configured but not sending anything. Deactivate one of the two.',
				'modern-mailer-oauth'
			),
			esc_html( $file )
		);
	}

	/**
	 * @param array<string,array{name:string,active:bool}> $plugins Inactive mailers.
	 */
	private function render_dormant( array $plugins ): void {
		$names = implode( ', ', wp_list_pluck( $plugins, 'name' ) );

		echo '<div class="notice notice-warning"><p><strong>';

		printf(
			/* translators: %s: comma-separated plugin names. */
			esc_html(
				_n(
					'%s is installed but switched off.',
					'%s are installed but switched off.',
					count( $plugins ),
					'modern-mailer-oauth'
				)
			),
			esc_html( $names )
		);

		echo '</strong> ';

		echo esc_html__(
			'Nothing is wrong today - an inactive plugin sends nothing. It is worth deleting all the same: while it is installed, activating it, or a host restoring every plugin at once, quietly takes sending away from this one and there is no error when that happens.',
			'modern-mailer-oauth'
		);

		echo '</p><p>';

		printf(
			'<a class="button" href="%s">%s</a> <a href="%s" style="margin-left:8px">%s</a>',
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Open the Plugins screen', 'modern-mailer-oauth' ),
			esc_url( $this->dismiss_url( $plugins ) ),
			esc_html__( 'Dismiss', 'modern-mailer-oauth' )
		);

		echo '</p></div>';
	}

	/**
	 * Remember that this administrator has read the advisory notice.
	 *
	 * Keyed on the set of plugins it named rather than on a plain flag, so
	 * installing a different mailer later says so again instead of being
	 * silenced by a decision taken about something else.
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'modern-mailer-oauth' ) );
		}

		check_admin_referer( self::DISMISS_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked immediately above.
		$key = isset( $_GET['mmoa_set'] ) ? sanitize_text_field( wp_unslash( $_GET['mmoa_set'] ) ) : '';

		if ( '' !== $key ) {
			update_user_meta( get_current_user_id(), self::DISMISSED_META, $key );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * @param array<string,array{name:string,active:bool}> $plugins Plugins the notice names.
	 */
	private function is_dismissed( array $plugins ): bool {
		return (string) get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) === $this->key_for( $plugins );
	}

	/**
	 * @param array<string,array{name:string,active:bool}> $plugins Plugins the notice names.
	 */
	private function dismiss_url( array $plugins ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => self::DISMISS_ACTION,
					'mmoa_set' => $this->key_for( $plugins ),
				],
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION
		);
	}

	/**
	 * A stable fingerprint of one set of plugins.
	 *
	 * @param array<string,array{name:string,active:bool}> $plugins Plugins the notice names.
	 */
	private function key_for( array $plugins ): string {
		$files = array_keys( $plugins );
		sort( $files );

		return md5( implode( '|', $files ) );
	}

	/**
	 * The deactivation link WordPress's own plugin row uses.
	 *
	 * Built rather than borrowed because the notice appears on screens that
	 * never load the plugins list table. Returns nothing for a user who cannot
	 * deactivate the plugin, or for one that is network activated - that has to
	 * be done from the network admin, and a button that fails is worse than no
	 * button.
	 */
	private function deactivate_url( string $file ): string {
		$this->ensure_plugin_api();

		if ( ! current_user_can( 'deactivate_plugin', $file ) || is_plugin_active_for_network( $file ) ) {
			return '';
		}

		return wp_nonce_url(
			admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $file ) ),
			'deactivate-plugin_' . $file
		);
	}

	private function is_active( string $file ): bool {
		return is_plugin_active( $file ) || is_plugin_active_for_network( $file );
	}

	/**
	 * The folder a plugin lives in.
	 *
	 * 'wp-mail-smtp/wp_mail_smtp.php' => 'wp-mail-smtp'. A plugin that is a
	 * single file in the plugins directory has no folder of its own, so the
	 * filename stands in for one - with the extension taken off, so that
	 * 'some-mailer.php' and 'some-mailer/some-mailer.php' answer to the same
	 * entry in the list. Without that the single-file case could never match
	 * anything, because no entry in the list ends in .php.
	 */
	private function slug_of( string $file ): string {
		$file = ltrim( str_replace( '\\', '/', $file ), '/' );

		if ( false !== strpos( $file, '/' ) ) {
			return substr( $file, 0, strpos( $file, '/' ) );
		}

		return preg_replace( '/\.php$/i', '', $file );
	}

	/**
	 * Where the advisory notice is allowed to appear.
	 *
	 * Only where somebody is already thinking about plugins or about mail. The
	 * condition it describes is not urgent, and a warning that follows an
	 * administrator onto every screen for something that is switched off is how
	 * a notice teaches people to stop reading notices.
	 */
	private function on_plugin_screen(): bool {
		$screen = get_current_screen();

		if ( null === $screen ) {
			return false;
		}

		return in_array( $screen->id, [ 'plugins', 'plugins-network', 'toplevel_page_' . App_Page::SLUG ], true );
	}
}
