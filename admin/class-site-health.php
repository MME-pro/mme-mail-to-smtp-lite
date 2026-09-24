<?php
/**
 * Site Health integration.
 *
 * @package ModernMailer
 */

namespace ModernMailer\Admin;

use ModernMailer\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces mail failures in Tools > Site Health.
 *
 * Worth doing beyond the admin notice, because Site Health is what managed
 * hosts and monitoring plugins actually read. A dismissible banner in wp-admin
 * only helps somebody who is already logged in and looking.
 */
class Site_Health {

	public function __construct( private Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'site_status_tests', [ $this, 'add_tests' ] );
	}

	/**
	 * @param array<string,mixed> $tests Registered tests.
	 * @return array<string,mixed>
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['mmoa_delivery'] = [
			'label' => __( 'Email delivery', 'modern-mailer-oauth' ),
			'test'  => [ $this, 'run_test' ],
		];

		$tests['direct']['mmoa_conflicts'] = [
			'label' => __( 'Email plugin conflicts', 'modern-mailer-oauth' ),
			'test'  => [ $this, 'run_conflict_test' ],
		];

		return $tests;
	}

	/**
	 * Is anything else on this site trying to send the mail?
	 *
	 * Its own test rather than a branch of the delivery one, because the two
	 * disagree in a way that is worth seeing: a site with two active mailers
	 * reports perfectly healthy delivery right up until it is the other plugin
	 * doing the delivering.
	 *
	 * @return array<string,mixed>
	 */
	public function run_conflict_test(): array {
		$conflicts = $this->plugin->conflicts;

		$result = [
			'label'       => __( 'No other mail plugin is competing for wp_mail()', 'modern-mailer-oauth' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Email', 'modern-mailer-oauth' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Only one plugin on this site replaces the function WordPress sends email with, which is the most that can work.', 'modern-mailer-oauth' ) . '</p>',
			'actions'     => '',
			'test'        => 'mmoa_conflicts',
		];

		$active = $conflicts->active();

		if ( $active ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = __( 'Two plugins are trying to send this site\'s email', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %s: comma-separated plugin names. */
				esc_html__( '%s is active alongside MME-Mail to SMTP. WordPress lets exactly one plugin take over sending, so one of the two is configured and doing nothing - and which one wins depends on load order rather than on anything you chose.', 'modern-mailer-oauth' ),
				esc_html( implode( ', ', wp_list_pluck( $active, 'name' ) ) )
			) . '</p>';
			$result['actions']        = $this->plugins_link();

			return $result;
		}

		$dormant = $conflicts->dormant();

		if ( $dormant ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = __( 'An unused mail plugin is still installed', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %s: comma-separated plugin names. */
				esc_html__( '%s is installed but inactive, so it is not sending anything today. Deleting it removes the chance of it being reactivated later and quietly taking sending away from this plugin.', 'modern-mailer-oauth' ),
				esc_html( implode( ', ', wp_list_pluck( $dormant, 'name' ) ) )
			) . '</p>';
			$result['actions']        = $this->plugins_link();

			return $result;
		}

		$owner = $conflicts->wp_mail_owner();

		if ( '' !== $owner ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = __( 'Something else has taken over wp_mail()', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %s: path to the file that defined wp_mail(). */
				esc_html__( 'wp_mail() was defined by %s rather than by WordPress, which means MME-Mail to SMTP cannot send even when it is configured correctly. It is not a plugin this one recognises, so it may be a custom plugin or something bundled with the theme.', 'modern-mailer-oauth' ),
				'<code>' . esc_html( $owner ) . '</code>'
			) . '</p>';
			$result['actions']        = $this->plugins_link();
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function run_test(): array {
		$settings = $this->plugin->settings;

		$result = [
			'label'       => __( 'Email is sending normally', 'modern-mailer-oauth' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Email', 'modern-mailer-oauth' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Outgoing email is being delivered through an authenticated API connection.', 'modern-mailer-oauth' ) . '</p>',
			'actions'     => '',
			'test'        => 'mmoa_delivery',
		];

		if ( ! $settings->is_active() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'No mail provider is configured', 'modern-mailer-oauth' );
			$result['description'] = '<p>' . esc_html__( 'WordPress is falling back to the server mail function, which most hosts either block or deliver straight to spam.', 'modern-mailer-oauth' ) . '</p>';
			$result['actions']     = $this->settings_link();

			return $result;
		}

		$state = $this->plugin->health->state();

		if ( $this->plugin->health->is_failing() ) {
			$result['status'] = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']  = __( 'Email is failing to send', 'modern-mailer-oauth' );
			$result['description'] = '<p>' . sprintf(
				/* translators: 1: consecutive failure count, 2: most recent error message. */
				esc_html__( '%1$d messages in a row have failed. Most recent error: %2$s', 'modern-mailer-oauth' ),
				(int) $state['streak'],
				esc_html( (string) ( $state['last_error']['message'] ?? '' ) )
			) . '</p>';
			$result['actions'] = $this->settings_link();

			return $result;
		}

		// Mail that ran out of retries is the one state worth shouting about:
		// unlike a failing send, it is finished, and nothing else will surface
		// it. Checked before the secret-expiry warning because data already lost
		// outranks an outage that has not happened yet.
		$queue = $this->plugin->queue->stats();

		if ( $queue['failed'] > 0 ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = __( 'Some email was never delivered', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %d: number of abandoned messages. */
				esc_html( _n( '%d message exhausted every retry and has been abandoned.', '%d messages exhausted every retry and have been abandoned.', (int) $queue['failed'], 'modern-mailer-oauth' ) ),
				(int) $queue['failed']
			) . '</p>';
			$result['actions']        = $this->logs_link();

			return $result;
		}

		if ( $queue['pending'] > 0 ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = __( 'Email is queued for retry', 'modern-mailer-oauth' );
			$result['description']    = '<p>' . sprintf(
				/* translators: %d: number of messages waiting. */
				esc_html( _n( '%d message could not be sent on the first attempt and is waiting to be retried. Nothing has been lost, but sending is not healthy.', '%d messages could not be sent on the first attempt and are waiting to be retried. Nothing has been lost, but sending is not healthy.', (int) $queue['pending'], 'modern-mailer-oauth' ) ),
				(int) $queue['pending']
			) . '</p>';
			$result['actions']        = $this->logs_link();

			return $result;
		}

		return $result;
	}

	/**
	 * A plugin conflict is resolved on the Plugins screen, not in mail settings.
	 */
	private function plugins_link(): string {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Open the Plugins screen', 'modern-mailer-oauth' )
		);
	}

	private function settings_link(): string {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . App_Page::SLUG ) ),
			esc_html__( 'Open mail settings', 'modern-mailer-oauth' )
		);
	}

	/**
	 * Queue problems are acted on in the app, where the queue controls are.
	 */
	private function logs_link(): string {
		return sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . App_Page::SLUG ) ),
			esc_html__( 'Review queued mail', 'modern-mailer-oauth' )
		);
	}
}
