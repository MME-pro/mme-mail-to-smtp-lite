<?php
/**
 * Settings screen: site-wide options and the primary connection.
 *
 * @package ModernMailer
 *
 * @var ModernMailer\Admin\Admin_Page $this
 * @var ModernMailer\Settings         $settings
 * @var string                        $page
 */

use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'MME-Mail to SMTP', 'mme-mail-to-smtp' ); ?></h1>

	<?php $this->render_notice(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="mmoa_save" />
		<?php
		$this->return_field( $page );
		wp_nonce_field( 'mmoa_save' );
		?>

		<table class="form-table" role="presentation">
			<?php
			$this->field( 'from_email', __( 'From address', 'mme-mail-to-smtp' ), __( 'Must be a mailbox the connected identity is permitted to send as.', 'mme-mail-to-smtp' ), 'email' );
			$this->field( 'from_name', __( 'From name', 'mme-mail-to-smtp' ) );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Force sender', 'mme-mail-to-smtp' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="force_from" value="1" <?php checked( (bool) $settings->get( 'force_from' ) ); ?> />
						<?php esc_html_e( 'Override the From address set by other plugins', 'mme-mail-to-smtp' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Recommended. Both APIs reject or rewrite a From address the authenticated identity may not use.', 'mme-mail-to-smtp' ); ?></p>
				</td>
			</tr>
		</table>

		<hr />
		<h2><?php esc_html_e( 'Primary connection', 'mme-mail-to-smtp' ); ?></h2>
		<?php
		$slot          = Settings::SLOT_PRIMARY;
		$slot_settings = $settings;
		require __DIR__ . '/connection.php';
		?>

		<hr />
		<h2><?php esc_html_e( 'Retry queue', 'mme-mail-to-smtp' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Queue failed sends', 'mme-mail-to-smtp' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="queue_enabled" value="1" <?php checked( (bool) $settings->get( 'queue_enabled' ) ); ?> />
						<?php esc_html_e( 'Hold on to messages that failed for a temporary reason and retry them', 'mme-mail-to-smtp' ); ?>
					</label>
					<p class="description" style="max-width:46em">
						<?php esc_html_e( 'Strongly recommended. The failure that actually loses mail is a brief network or DNS fault at your host, which clears in minutes - far longer than the few seconds a single page request can wait. Retrying across later requests is the only thing that survives it. Attempts back off from five minutes and give up after about two days.', 'mme-mail-to-smtp' ); ?>
					</p>
					<p class="description" style="max-width:46em">
						<?php esc_html_e( 'Note that a queued message is stored complete, body included, until it is delivered - unlike the send log, which never stores content. Delivered messages are deleted immediately; abandoned ones are kept for seven days so you can see what was lost, then removed.', 'mme-mail-to-smtp' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Failure detection', 'mme-mail-to-smtp' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$this->field( 'alert_threshold', __( 'Report broken after N failures', 'mme-mail-to-smtp' ), __( 'Consecutive failures before the plugin reports sending as broken, in the admin notice and under Site Health.', 'mme-mail-to-smtp' ), 'number' );
			?>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Check the connection', 'mme-mail-to-smtp' ); ?></h2>
	<p style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mmoa_verify" />
			<?php
			$this->return_field( $page );
			wp_nonce_field( 'mmoa_verify' );
			submit_button( __( 'Verify credentials', 'mme-mail-to-smtp' ), 'secondary', 'submit', false );
			?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mmoa_test_email" />
			<?php
			$this->return_field( $page );
			wp_nonce_field( 'mmoa_test_email' );
			?>
			<input type="email" name="test_to" required
				value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
			<?php submit_button( __( 'Send test email', 'mme-mail-to-smtp' ), 'secondary', 'submit', false ); ?>
		</form>
	</p>

	<p class="description">
		<?php
		printf(
			/* translators: 1: link to the Backup screen, 2: link to the Logs screen. */
			esc_html__( 'A test message goes out over the primary connection. Configure a fallback on the %1$s screen, and see what was actually sent on the %2$s screen.', 'mme-mail-to-smtp' ),
			'<a href="' . esc_url( ModernMailer\Admin\Admin_Page::url( 'modern-mailer-backup' ) ) . '">' . esc_html__( 'Backup', 'mme-mail-to-smtp' ) . '</a>',
			'<a href="' . esc_url( ModernMailer\Admin\Admin_Page::url( 'modern-mailer-logs' ) ) . '">' . esc_html__( 'Logs', 'mme-mail-to-smtp' ) . '</a>'
		);
		?>
	</p>
</div>

<?php require __DIR__ . '/panel-script.php'; ?>
