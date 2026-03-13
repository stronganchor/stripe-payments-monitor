<?php
/**
 * Action handler - processes all POST actions coming from the dashboard
 * buttons (unlink, ignore, mark internal, link customer → site, etc.).
 *
 * Runs on every admin request via `admin_init`, before any headers are sent,
 * so we can safely use `wp_safe_redirect()` without triggering header
 * warnings.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_init', 'SPM_Action_Handler::handle' );
add_action( 'wp_ajax_spm_dashboard_action', 'SPM_Action_Handler::ajax_handle' );

class SPM_Action_Handler {

	/**
	 * Main entry point. Detects a POSTed action and routes it.
	 */
	public static function handle() {
		if ( wp_doing_ajax() ) {
			return;
		}

		if ( ! isset( $_POST['spm_action'] ) ) {
			return; // Nothing to do.
		}

		// Bail out if the current user cannot manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'spm_action', 'spm_nonce' );

		$result = self::process_request( $_POST );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=stripe-payments-monitor' ) );
		exit;
	}

	/**
	 * AJAX entry point for in-page saves.
	 */
	public static function ajax_handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'You do not have permission to update the dashboard.' ], 403 );
		}

		check_ajax_referer( SPM_Admin_Dashboard_View::ACTION_NONCE_ACTION, 'nonce' );

		$result = self::process_request( $_POST );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}

		$cached = spm_get_cached_report_snapshot();
		if ( ! is_array( $cached ) ) {
			wp_send_json_success(
				[
					'message'       => $result['message'] . ' Reloading the dashboard report...',
					'reload_report' => true,
				]
			);
		}

		wp_send_json_success(
			[
				'message'       => $result['message'],
				'reload_report' => false,
				'html'          => SPM_Admin_Dashboard_View::get_report_html( $cached ),
			]
		);
	}

	/**
	 * Applies a dashboard action to stored options.
	 *
	 * @param array $request Raw request payload.
	 * @return array|WP_Error
	 */
	private static function process_request( $request ) {
		$action = isset( $request['spm_action'] ) ? sanitize_text_field( wp_unslash( $request['spm_action'] ) ) : '';
		if ( '' === $action ) {
			return new WP_Error( 'spm_action_missing', 'Missing dashboard action.' );
		}

		$map          = get_option( 'stripe_pm_site_customer_map', [] );
		$ignore_sites = get_option( 'spm_ignore_sites', [] );
		$ignore_cids  = get_option( 'spm_ignore_clients', [] );
		$unlinked     = get_option( 'spm_unlinked_sites', [] );
		$site_notes   = get_option( 'spm_site_notes', [] );
		$client_notes = get_option( 'spm_client_notes', [] );

		if ( ! is_array( $map ) ) {
			$map = [];
		}
		if ( ! is_array( $ignore_sites ) ) {
			$ignore_sites = [];
		}
		if ( ! is_array( $ignore_cids ) ) {
			$ignore_cids = [];
		}
		if ( ! is_array( $unlinked ) ) {
			$unlinked = [];
		}
		if ( ! is_array( $site_notes ) ) {
			$site_notes = [];
		}
		if ( ! is_array( $client_notes ) ) {
			$client_notes = [];
		}

		$message = 'Changes saved.';

		switch ( $action ) {
			case 'unlink':
				$site = isset( $request['site_url'] ) ? esc_url_raw( wp_unslash( $request['site_url'] ) ) : '';
				if ( '' === $site ) {
					return new WP_Error( 'spm_missing_site', 'Missing site URL.' );
				}
				unset( $map[ $site ] );
				$unlinked[ $site ] = true;
				$message = 'Site unlinked.';
				break;

			case 'allow_automatch':
				$site = isset( $request['site_url'] ) ? esc_url_raw( wp_unslash( $request['site_url'] ) ) : '';
				if ( '' === $site ) {
					return new WP_Error( 'spm_missing_site', 'Missing site URL.' );
				}
				unset( $unlinked[ $site ] );
				$message = 'Automatic matching re-enabled.';
				break;

			case 'ignore_client':
				$cid = isset( $request['cid'] ) ? sanitize_text_field( wp_unslash( $request['cid'] ) ) : '';
				if ( '' === $cid ) {
					return new WP_Error( 'spm_missing_customer', 'Missing Stripe customer ID.' );
				}
				$ignore_cids[ $cid ] = true;
				if ( ! empty( $request['note'] ) ) {
					$client_notes[ $cid ] = sanitize_text_field( wp_unslash( $request['note'] ) );
				}
				$message = 'Customer ignored.';
				break;

			case 'unignore_client':
				$cid = isset( $request['cid'] ) ? sanitize_text_field( wp_unslash( $request['cid'] ) ) : '';
				if ( '' === $cid ) {
					return new WP_Error( 'spm_missing_customer', 'Missing Stripe customer ID.' );
				}
				unset( $ignore_cids[ $cid ], $client_notes[ $cid ] );
				$message = 'Customer restored.';
				break;

			case 'ignore_site':
				$site = isset( $request['site_url'] ) ? esc_url_raw( wp_unslash( $request['site_url'] ) ) : '';
				if ( '' === $site ) {
					return new WP_Error( 'spm_missing_site', 'Missing site URL.' );
				}
				$ignore_sites[ $site ] = true;
				if ( ! empty( $request['note'] ) ) {
					$site_notes[ $site ] = sanitize_text_field( wp_unslash( $request['note'] ) );
				}
				$message = 'Site marked internal.';
				break;

			case 'unignore_site':
				$site = isset( $request['site_url'] ) ? esc_url_raw( wp_unslash( $request['site_url'] ) ) : '';
				if ( '' === $site ) {
					return new WP_Error( 'spm_missing_site', 'Missing site URL.' );
				}
				unset( $ignore_sites[ $site ], $site_notes[ $site ] );
				$message = 'Internal site restored.';
				break;

			case 'save_mapping':
				$site = isset( $request['site_url'] ) ? esc_url_raw( wp_unslash( $request['site_url'] ) ) : '';
				$customer_id = isset( $request['customer_id'] ) ? sanitize_text_field( wp_unslash( $request['customer_id'] ) ) : '';
				if ( '' === $site || '' === $customer_id ) {
					return new WP_Error( 'spm_missing_mapping', 'Both site URL and Stripe customer are required.' );
				}
				$map[ $site ] = $customer_id;
				unset( $unlinked[ $site ] );
				$message = 'Site linked.';
				break;

			default:
				return new WP_Error( 'spm_invalid_action', 'Unsupported dashboard action.' );
		}

		update_option( 'stripe_pm_site_customer_map', $map );
		update_option( 'spm_ignore_sites', $ignore_sites );
		update_option( 'spm_ignore_clients', $ignore_cids );
		update_option( 'spm_unlinked_sites', $unlinked );
		update_option( 'spm_site_notes', $site_notes );
		update_option( 'spm_client_notes', $client_notes );

		return [
			'message' => $message,
		];
	}
}
