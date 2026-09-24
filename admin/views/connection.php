<?php
/**
 * One connection's provider choice and credential panels.
 *
 * Included once per slot. Every field name is slot-prefixed by Admin_Page, so
 * the same markup drives the primary and the backup connection without either
 * knowing about the other.
 *
 * @package ModernMailer
 *
 * @var ModernMailer\Admin\Admin_Page $this
 * @var ModernMailer\Settings         $slot_settings Settings scoped to this slot.
 * @var string                        $slot          '' for primary, 'backup' otherwise.
 */

use ModernMailer\Settings;

defined( 'ABSPATH' ) || exit;

$field_name  = static fn( string $key ): string => ( '' === $slot ? $key : $slot . '_' . $key );
$select_id   = $field_name( 'provider' );
$current     = (string) $slot_settings->get( 'provider' );
$panel_class = 'mmoa-panel-' . ( '' === $slot ? 'primary' : $slot );
?>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="<?php echo esc_attr( $select_id ); ?>"><?php esc_html_e( 'Provider', 'modern-mailer-oauth' ); ?></label></th>
		<td>
			<select name="<?php echo esc_attr( $select_id ); ?>" id="<?php echo esc_attr( $select_id ); ?>"
				class="mmoa-provider-select" data-panels="<?php echo esc_attr( $panel_class ); ?>"
				<?php disabled( $slot_settings->is_constant( 'provider' ) ); ?>>
				<?php foreach ( Settings::provider_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
</table>

<div class="<?php echo esc_attr( $panel_class ); ?>" data-provider="gmail_sa">
	<h3><?php esc_html_e( 'Google Workspace service account', 'modern-mailer-oauth' ); ?></h3>
	<p><?php esc_html_e( 'The Google equivalent of app-only auth: no consent screen and no refresh token to expire. Workspace domains only.', 'modern-mailer-oauth' ); ?></p>
	<table class="form-table" role="presentation">
		<?php
		$this->field( 'google_sa_email', __( 'Service account email', 'modern-mailer-oauth' ), __( 'The client_email value from the downloaded JSON key.', 'modern-mailer-oauth' ), 'text', $slot );
		$this->secret_field( 'google_sa_key', __( 'Private key', 'modern-mailer-oauth' ), __( 'The private_key value from the same JSON, including the BEGIN and END lines.', 'modern-mailer-oauth' ), true, $slot );
		$this->field( 'google_sender', __( 'Send as mailbox', 'modern-mailer-oauth' ), __( 'The Workspace user this service account impersonates.', 'modern-mailer-oauth' ), 'email', $slot );
		?>
	</table>
	<p class="description">
		<?php esc_html_e( 'Authorize the service account client ID for the https://www.googleapis.com/auth/gmail.send scope in Admin console, Security, Access and data control, API controls, Domain-wide delegation.', 'modern-mailer-oauth' ); ?>
	</p>
</div>

<div class="<?php echo esc_attr( $panel_class ); ?>" data-provider="gmail_oauth">
	<h3><?php esc_html_e( 'Gmail', 'modern-mailer-oauth' ); ?></h3>
	<p><strong><?php esc_html_e( 'Set your Google Cloud consent screen to In production before connecting.', 'modern-mailer-oauth' ); ?></strong>
		<?php esc_html_e( 'While it is left in Testing, Google expires the refresh token every seven days and sending stops without warning. This is the most common cause of a Gmail connection that works for a week and then quietly dies.', 'modern-mailer-oauth' ); ?></p>
	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'This uses your own OAuth client, created in your own Google Cloud project. Nothing is routed through a shared or third-party application, so the tokens Google issues are only ever seen by this site.', 'modern-mailer-oauth' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<?php
		$this->field( 'google_client_id', __( 'OAuth client ID', 'modern-mailer-oauth' ), __( 'From Credentials in your Google Cloud project. It must be a Web application client.', 'modern-mailer-oauth' ), 'text', $slot );
		$this->secret_field( 'google_client_sec', __( 'OAuth client secret', 'modern-mailer-oauth' ), '', false, $slot );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Redirect URI', 'modern-mailer-oauth' ); ?></th>
			<td>
				<code><?php echo esc_html( ModernMailer\Auth\Google_Consent::redirect_uri() ); ?></code>
				<p class="description"><?php esc_html_e( 'Add this exact value to the authorized redirect URIs of your OAuth client. Google matches it character for character, and requires HTTPS for anything other than localhost.', 'modern-mailer-oauth' ); ?></p>
				<p class="description"><?php esc_html_e( 'Both connections share this one URI, so it only needs registering once.', 'modern-mailer-oauth' ); ?></p>
			</td>
		</tr>
		<?php $this->google_connect_control( $slot ); ?>
	</table>
	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'The prompt asks only for permission to send mail. This plugin never requests read access to the mailbox.', 'modern-mailer-oauth' ); ?>
	</p>
</div>
