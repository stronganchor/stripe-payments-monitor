<?php
/**
 * Admin‑menu + settings sub‑page.
 *
 * Registers the “Payments Monitor” top‑level page and its Settings child.
 * All heavy lifting (dashboard render, settings form) happens in the
 * SPM_Admin_Dashboard and SPM_Admin_Settings classes that live
 * in their respective include files.  Here we only wire up the
 * WordPress hooks so the loader can stay slim.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Main menu + sub‑page.
 */
add_action( 'admin_menu', function () {

	/* Top‑level page – the dashboard */
	add_menu_page(
		__( 'Payments Monitor', 'spm' ),
		__( 'Payments Monitor', 'spm' ),
		'manage_options',
		'stripe-payments-monitor',
		[ 'SPM_Admin_Dashboard', 'render' ],
		'dashicons-chart-line'
	);

	/* Settings sub‑page */
	add_submenu_page(
		'stripe-payments-monitor',
		__( 'Stripe Monitor Settings', 'spm' ),
		__( 'Settings', 'spm' ),
		'manage_options',
		'stripe-payments-monitor-settings',
		[ 'SPM_Admin_Settings', 'render' ]
	);
});

/**
 * Dashicons help – enqueue a custom icon in WP < 5.5 if needed.
 * (Optional – can be removed if you always run modern WP.)
 */
add_action( 'admin_enqueue_scripts', function () {
	if ( ! wp_script_is( 'dashicons' ) ) {
		wp_enqueue_style( 'dashicons' );
	}
} );


/* -----------------------------------------------------------------------
 *  Thin wrapper classes – keep view logic in one spot.
 * -------------------------------------------------------------------- */

class SPM_Admin_Dashboard {
	public static function render() {
		/* The actual markup lives in includes/admin-dashboard.php */
		SPM_Admin_Dashboard_View::output();
	}
}

class SPM_Admin_Settings {
	public static function render() {
		/* The actual markup lives in includes/admin-settings-view.php (inside dashboard file) */
		SPM_Admin_Settings_View::output();
	}
}
