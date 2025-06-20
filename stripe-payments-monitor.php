<?php
/*
Plugin Name: Stripe Payments Monitor
Description: Revenue & subscription health report in MainWP. Duplicate customers merged; ignore lists; unlink & internal lists; hourly auto-refresh.
Version:     0.5.0
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
	</style>';
} );
