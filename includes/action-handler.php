<?php
/**
 * Action handler – processes all POST actions coming from the dashboard
 * buttons (unlink, ignore, mark internal, link customer → site, etc.).
 *
 * Runs on every admin request via `admin_init`, before any headers are sent,
 * so we can safely use `wp_safe_redirect()` without triggering header
 * warnings.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_init', 'SPM_Action_Handler::handle' );

class SPM_Action_Handler {

	/**
	 * Main entry point. Detects a POSTed action and routes it.
	 */
	public static function handle() {

		if ( ! isset( $_POST['spm_action'] ) ) {
			return; // Nothing to do.
		}

		// Bail out if the current user cannot manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'spm_action', 'spm_nonce' );

		$action = sanitize_text_field( $_POST['spm_action'] );

		// Load all option arrays once – update at the end if changed.
		$map          = get_option( 'stripe_pm_site_customer_map', [] );
		$ignore_sites = get_option( 'spm_ignore_sites',   [] );
		$ignore_cids  = get_option( 'spm_ignore_clients', [] );
		$unlinked     = get_option( 'spm_unlinked_sites', [] );
		$site_notes   = get_option( 'spm_site_notes',     [] );
		$client_notes = get_option( 'spm_client_notes',   [] );

		switch ( $action ) {
			case 'unlink':
				$site = esc_url_raw( $_POST['site_url'] );
				unset( $map[ $site ] );
				$unlinked[ $site ] = true; // prevent auto‑match
				break;

			case 'allow_automatch':
				unset( $unlinked[ esc_url_raw( $_POST['site_url'] ) ] );
				break;

			case 'ignore_client':
				$cid = sanitize_text_field( $_POST['cid'] );
				$ignore_cids[ $cid ] = true;
				if ( ! empty( $_POST['note'] ) ) {
					$client_notes[ $cid ] = sanitize_text_field( $_POST['note'] );
				}
				break;

			case 'unignore_client':
				$cid = sanitize_text_field( $_POST['cid'] );
				unset( $ignore_cids[ $cid ], $client_notes[ $cid ] );
				break;

			case 'ignore_site':
				$site = esc_url_raw( $_POST['site_url'] );
				$ignore_sites[ $site ] = true;
				if ( ! empty( $_POST['note'] ) ) {
					$site_notes[ $site ] = sanitize_text_field( $_POST['note'] );
				}
				break;

			case 'unignore_site':
				$site = esc_url_raw( $_POST['site_url'] );
				unset( $ignore_sites[ $site ], $site_notes[ $site ] );
				break;

			case 'save_mapping':
				$site              = esc_url_raw( $_POST['site_url'] );
				$map[ $site ]      = sanitize_text_field( $_POST['customer_id'] );
				unset( $unlinked[ $site ] ); // manual mapping beats unlink.
				break;
		}

		// Persist any changes.
		update_option( 'stripe_pm_site_customer_map', $map );
		update_option( 'spm_ignore_sites',   $ignore_sites );
		update_option( 'spm_ignore_clients', $ignore_cids );
		update_option( 'spm_unlinked_sites', $unlinked );
		update_option( 'spm_site_notes',     $site_notes );
		update_option( 'spm_client_notes',   $client_notes );

		// Clear the cached Stripe report so our manual mapping shows up immediately
    		delete_transient( 'spm_cached_report' );

		// Redirect back to dashboard.
		wp_safe_redirect( admin_url( 'admin.php?page=stripe-payments-monitor' ) );
		exit;
	}
}
