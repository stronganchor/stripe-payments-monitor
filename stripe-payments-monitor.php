<?php
/*
Plugin Name: Stripe Payments Monitor
Description: Revenue & subscription health report in MainWP. Duplicate customers merged; ignore lists; unlink & internal lists; hourly auto-refresh.
Plugin URI:  https://github.com/stronganchor/stripe-payments-monitor/
Version:     0.5.2
Update URI:  https://github.com/stronganchor/stripe-payments-monitor
Author:      Strong Anchor Tech
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 *  0. BASIC CONSTANTS
 * ---------------------------------------------------------------------- */

// How long (in minutes) we cache the heavy Stripe pull.
if ( ! defined( 'SPM_CACHE_MINS' ) ) {
	define( 'SPM_CACHE_MINS', 15 );
}
// How many days since the last successful payment before a client is flagged red.
if ( ! defined( 'SPM_OVERDUE_DAYS' ) ) {
	define( 'SPM_OVERDUE_DAYS', 30 );
}

/* -------------------------------------------------------------------------
 *  1. PATH SHORTCUTS & AUTO‑LOADER
 * ---------------------------------------------------------------------- */

define( 'SPM_FILE', __FILE__ );
define( 'SPM_DIR',  plugin_dir_path( __FILE__ ) );

function spm_get_update_branch() {
	$branch = 'main';

	if ( defined( 'STRIPE_PAYMENTS_MONITOR_UPDATE_BRANCH' ) && is_string( STRIPE_PAYMENTS_MONITOR_UPDATE_BRANCH ) ) {
		$override = trim( STRIPE_PAYMENTS_MONITOR_UPDATE_BRANCH );
		if ( '' !== $override ) {
			$branch = $override;
		}
	}

	return (string) apply_filters( 'stripe_payments_monitor_update_branch', $branch );
}

function spm_bootstrap_update_checker() {
	$checker_file = SPM_DIR . 'plugin-update-checker/plugin-update-checker.php';
	if ( ! file_exists( $checker_file ) ) {
		return;
	}

	require_once $checker_file;

	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}

	$repo_url = (string) apply_filters( 'stripe_payments_monitor_update_repository', 'https://github.com/stronganchor/stripe-payments-monitor' );
	$slug     = dirname( plugin_basename( __FILE__ ) );

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$repo_url,
		__FILE__,
		$slug
	);

	$update_checker->setBranch( spm_get_update_branch() );

	foreach ( array( 'STRIPE_PAYMENTS_MONITOR_GITHUB_TOKEN', 'STRONGANCHOR_GITHUB_TOKEN', 'ANCHOR_GITHUB_TOKEN' ) as $constant_name ) {
		if ( ! defined( $constant_name ) || ! is_string( constant( $constant_name ) ) ) {
			continue;
		}

		$token = trim( (string) constant( $constant_name ) );
		if ( '' !== $token ) {
			$update_checker->setAuthentication( $token );
			break;
		}
	}
}

spm_bootstrap_update_checker();

autoload_stripe_payments_monitor();

/**
 * Very small autoloader for the plugin's internal files (not the Stripe SDK).
 */
function autoload_stripe_payments_monitor() {
	spl_autoload_register( function ( $class ) {
		if ( 0 !== strpos( $class, 'SPM_' ) ) {
			return;
		}
		$parts   = explode( '_', strtolower( $class ) );
		$path    = SPM_DIR . 'includes/' . implode( '-', $parts ) . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	} );
}

/* -------------------------------------------------------------------------
 *  2. BOOTSTRAP CORE MODULES
 * ---------------------------------------------------------------------- */

// 2.1: Settings page + main menu.
require_once SPM_DIR . 'includes/admin-menu.php';

// 2.2: Action handler (runs early, before output).
require_once SPM_DIR . 'includes/action-handler.php';

// 2.3: Dashboard renderer.
require_once SPM_DIR . 'includes/admin-dashboard.php';

// 2.4: Data layer (Stripe pull, cache, cron).
require_once SPM_DIR . 'includes/data-cache.php';

// 2.5: Tiny CSS for red rows & buttons.
add_action( 'admin_head', function () {
	echo '<style>
		.spm-error{background:#fee !important}
		.spm-error td{color:#c00;font-weight:bold}
		table.widefat td{vertical-align:top}
		form button.button-link{background:none;border:none;color:#2271b1;cursor:pointer;padding:0;margin:0}
		form button.button-link:hover{text-decoration:underline}
		em{color:#666}
		.spm-dashboard-actions{display:flex;align-items:center;gap:12px;margin:16px 0}
		.spm-dashboard-progress{margin:0 0 18px;padding:14px 16px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
		.spm-dashboard-progress.is-loading{border-color:#72aee6}
		.spm-dashboard-progress.is-complete{border-color:#00a32a}
		.spm-dashboard-progress.is-error{border-color:#d63638}
		.spm-dashboard-progress-bar{height:10px;border-radius:999px;background:#f0f0f1;overflow:hidden}
		.spm-dashboard-progress-fill{display:block;height:100%;width:0;background:linear-gradient(90deg,#2271b1,#72aee6);transition:width .2s ease}
		.spm-dashboard-progress-meta{margin-top:10px;font-size:13px}
		.spm-dashboard-progress-error{margin-top:10px;color:#d63638}
		.spm-dashboard-progress-error.is-hidden{display:none}
		.spm-dashboard-results-shell h2{margin-top:24px}
	</style>';
} );
