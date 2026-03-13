<?php
/**
 * Admin settings view.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPM_Admin_Settings_View {

	/**
	 * Render the settings page and handle simple form submissions.
	 */
	public static function output() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = '';

		if ( isset( $_POST['spm_save_settings'] ) && check_admin_referer( 'spm_settings', 'spm_nonce' ) ) {
			$secret_key = isset( $_POST['spm_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['spm_secret_key'] ) ) : '';
			update_option( 'spm_stripe_secret_key', $secret_key );
			delete_transient( spm_get_report_cache_key() );
			$notice = 'Settings saved.';
		}

		if ( isset( $_POST['spm_clear'] ) && check_admin_referer( 'spm_settings', 'spm_nonce' ) ) {
			delete_transient( spm_get_report_cache_key() );
			delete_option( 'spm_ignore_clients' );
			delete_option( 'spm_ignore_sites' );
			delete_option( 'spm_unlinked_sites' );
			delete_option( 'spm_client_notes' );
			delete_option( 'spm_site_notes' );
			delete_option( 'stripe_pm_site_customer_map' );
			$notice = 'Cache and saved lists cleared.';
		}

		$key = (string) get_option( 'spm_stripe_secret_key', '' );
		$sdk_autoload = SPM_DIR . 'vendor/autoload.php';
		$sdk_installed = file_exists( $sdk_autoload );

		echo '<div class="wrap">';
		echo '<h1>Stripe Payments Monitor Settings</h1>';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
		}

		if ( ! $sdk_installed ) {
			echo '<div class="notice notice-error"><p>The Stripe PHP SDK is missing from this plugin install. Reinstall the plugin package that includes the <code>vendor</code> directory.</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'spm_settings', 'spm_nonce' );
		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row"><label for="spm_secret_key">Stripe <code>sk_live_...</code> Secret Key</label></th>';
		echo '<td><input type="text" id="spm_secret_key" name="spm_secret_key" class="regular-text" value="' . esc_attr( $key ) . '" autocomplete="off" required></td>';
		echo '</tr>';
		echo '</table>';
		echo '<p class="submit">';
		echo '<button type="submit" name="spm_save_settings" class="button button-primary">Save</button> ';
		echo '<button type="submit" name="spm_clear" class="button">Clear cache and lists</button>';
		echo '</p>';
		echo '</form>';

		echo '<p class="description">This install expects the Stripe PHP SDK inside <code>vendor/</code>. GitHub release zips and committed plugin packages should include it.</p>';
		echo '</div>';
	}
}
