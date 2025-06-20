<?php
/*
Plugin Name: Stripe Payments Monitor
Plugin URI:  https://github.com/stronganchor/stripe-payments-monitor
Description: Displays per-client revenue & subscription health inside MainWP. Flags overdue/failed payers and lets you map customers ⇄ websites.
Version:     0.2.0
Author:      Strong Anchor Tech
Author URI:  https://stronganchortech.com/
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------- */
/* 0. CONFIG – tweak here if needed                                           */
/* -------------------------------------------------------------------------- */
const SPM_CACHE_MINS = 15;          // how long the report stays cached
const SPM_OVERDUE_DAYS = 30;        // “last payment” age → mark red

/* -------------------------------------------------------------------------- */
/* 1. SETTINGS PAGE                                                           */
/* -------------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	add_menu_page(
		'Payments Monitor', 'Payments Monitor', 'manage_options',
		'stripe-payments-monitor', 'spm_dashboard_page', 'dashicons-chart-line'
	);
	add_submenu_page(
		'stripe-payments-monitor', 'Stripe Monitor Settings', 'Settings',
		'manage_options', 'stripe-payments-monitor-settings', 'spm_settings_page'
	);
});

function spm_settings_page() {
	if ( isset( $_POST['spm_save_settings'] ) ) {
		check_admin_referer( 'spm_save' );
		update_option( 'spm_stripe_secret_key', sanitize_text_field( $_POST['spm_secret_key'] ) );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}
	$secret = esc_attr( get_option( 'spm_stripe_secret_key', '' ) );
	?>
	<div class="wrap"><h1>Stripe Payments Monitor – Settings</h1>
	<form method="post"><?php wp_nonce_field( 'spm_save' ); ?>
	<table class="form-table">
	<tr><th><label for="spm_secret_key">Stripe <code>sk_live_*</code> Secret Key</label></th>
	    <td><input type="text" class="regular-text" id="spm_secret_key" name="spm_secret_key" value="<?php echo $secret; ?>" required></td></tr>
	</table>
	<p class="submit"><button type="submit" name="spm_save_settings" class="button button-primary">Save</button></p>
	<p>You need the Stripe PHP SDK in this plugin folder (<code>composer require stripe/stripe-php</code>).</p>
	</form></div><?php
}

/* -------------------------------------------------------------------------- */
/* 2. DASHBOARD PAGE                                                          */
/* -------------------------------------------------------------------------- */
function spm_dashboard_page() {

	$secret = get_option( 'spm_stripe_secret_key', '' );
	if ( empty( $secret ) ) {
		echo '<div class="wrap"><h1>Payments Monitor</h1><p>Please enter your Stripe secret key in the Settings tab first.</p></div>';
		return;
	}

	/* ---------- allow manual matching save -------------------------------- */
	if ( isset( $_POST['spm_save_mapping'] ) ) {
		check_admin_referer( 'spm_save_mapping' );
		$map          = get_option( 'stripe_pm_site_customer_map', [] );
		$site         = esc_url_raw( $_POST['site_url'] );
		$cid          = sanitize_text_field( $_POST['customer_id'] );
		$map[ $site ] = $cid;
		update_option( 'stripe_pm_site_customer_map', $map );
	}

	/* ---------- get (or refresh) processed data --------------------------- */
	$force_refresh = isset( $_GET['spm_refresh'] );
	$data = spm_get_cached_report( $secret, $force_refresh );

	if ( is_wp_error( $data ) ) {
		echo '<div class="wrap"><h1>Stripe Payments Monitor</h1><p>Error: ' .
		     esc_html( $data->get_error_message() ) . '</p></div>';
		return;
	}

	[
		'customers'      => $customers,
		'overdue_ids'    => $overdue_ids,
		'sites'          => $sites,
		'match_site2cid' => $matched_sites,
		'match_cid2site' => $matched_custs,
	] = $data;

	/* ------------------------------------------------------------------ */
	/* 3. OUTPUT                                                          */
	/* ------------------------------------------------------------------ */
	echo '<div class="wrap"><h1>Stripe Payments Monitor</h1>
	      <p><a href="' . esc_url( add_query_arg( 'spm_refresh', '1' ) ) .
	      '" class="button">Refresh data</a></p>';

	/* ---- Matched table ------------------------------------------------ */
	echo '<h2>Matched Clients</h2><table class="widefat fixed"><thead>
	<tr><th>Website</th><th>Customer</th><th>E-mail</th>
	    <th style="text-align:right">Lifetime&nbsp;$</th>
	    <th style="text-align:right">MRR&nbsp;$</th><th>Last&nbsp;Payment</th></tr></thead><tbody>';

	$rows = [];
	foreach ( $matched_sites as $site_url => $cid ) {
		$c      = $customers[ $cid ];
		$is_red = isset( $overdue_ids[ $cid ] );
		$rows[] = [
			'red'  => $is_red,
			'html' => sprintf(
				'<tr class="%s"><td><a href="%s" target="_blank">%s</a></td>
				 <td>%s</td><td>%s</td><td style="text-align:right">%0.2f</td>
				 <td style="text-align:right">%0.2f</td><td>%s</td></tr>',
				$is_red ? 'spm-error' : '',
				esc_url( $site_url ), esc_html( $site_url ),
				esc_html( $c['name'] ), esc_html( $c['email'] ),
				$c['total'], $c['mrr'],
				$c['last_paid'] ? date_i18n( 'Y-m-d', $c['last_paid'] ) : '—'
			)
		];
	}
	usort( $rows, fn( $a, $b ) => $a['red'] === $b['red'] ? 0 : ( $a['red'] ? -1 : 1 ) );
	foreach ( $rows as $r ) { echo $r['html']; }
	echo '</tbody></table>';

	/* ---- Unmatched websites ------------------------------------------ */
	$unmatched_sites = array_diff_key( $sites, $matched_sites );
	$unmatched_custs = array_diff_key( $customers, $matched_custs );

	echo '<h2>Unmatched Websites</h2>';
	if ( $unmatched_sites ) {
		echo '<table class="widefat"><thead><tr><th>Site</th><th>Pick Customer</th></tr></thead><tbody>';
		foreach ( $unmatched_sites as $site_url => $title ) {
			echo '<tr><td>' . esc_html( $site_url ) . '</td><td>
				  <form method="post">' . wp_nonce_field( 'spm_save_mapping', '', true, false ) . '
				  <input type="hidden" name="site_url" value="' . esc_attr( $site_url ) . '">
				  <select name="customer_id">';
			foreach ( $customers as $cid => $c ) {
				echo '<option value="' . esc_attr( $cid ) . '">' .
				     esc_html( $c['name'] . ' (' . $c['email'] . ')' ) . '</option>';
			}
			echo '</select> <button type="submit" name="spm_save_mapping" class="button">Save</button></form></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p><em>All websites are matched.</em></p>';
	}

	/* ---- Unmatched customers ----------------------------------------- */
	echo '<h2>Unmatched Stripe Customers</h2>';
	if ( $unmatched_custs ) {
		echo '<table class="widefat"><thead><tr><th>Customer</th><th>E-mail</th><th>Lifetime&nbsp;$</th><th>Link New Site URL</th></tr></thead><tbody>';
		foreach ( $unmatched_custs as $cid => $c ) {
			echo '<tr><td>' . esc_html( $c['name'] ) . '</td><td>' .
			     esc_html( $c['email'] ) . '</td><td>' . number_format( $c['total'], 2 ) .
			     '</td><td><form method="post">' . wp_nonce_field( 'spm_save_mapping', '', true, false ) . '
			     <input type="hidden" name="customer_id" value="' . esc_attr( $cid ) . '">
			     <input type="text" name="site_url" placeholder="https://site.com" style="width:200px">
			     <button type="submit" name="spm_save_mapping" class="button">Save</button></form></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p><em>All Stripe customers are matched.</em></p>';
	}

	echo '</div>';
}

/* -------------------------------------------------------------------------- */
/* 3. DATA FETCH & CACHE                                                      */
/* -------------------------------------------------------------------------- */
function spm_get_cached_report( string $secret, bool $force_refresh = false ) {

	$cache_key = 'spm_cached_report';
	if ( ! $force_refresh && ( $cached = get_transient( $cache_key ) ) ) {
		return $cached;
	}

	require_once __DIR__ . '/vendor/autoload.php';
	$stripe = new \Stripe\StripeClient( $secret );

	set_time_limit( 0 );   // long tasks
	ignore_user_abort( true );

	/* -------------------------- 3a. Customers ------------------------- */
	$customers_raw = [];
	$customers_it  = $stripe->customers->all([
		'limit'  => 100,
		'expand' => ['data.subscriptions'],
	])->autoPagingIterator();
	foreach ( $customers_it as $c ) { $customers_raw[] = $c; }

	/* -------------------------- 3b. Charges (all) --------------------- */
	$life_tot   = [];      // id => cents
	$last_paid  = [];      // id => ts
	$charges_it = $stripe->charges->all([ 'limit' => 100 ])->autoPagingIterator();
	foreach ( $charges_it as $ch ) {
		if ( $ch->status !== 'succeeded' || ! $ch->paid ) { continue; }
		$cid = $ch->customer ?? null;
		if ( ! $cid ) { continue; }
		$life_tot[ $cid ] = ( $life_tot[ $cid ] ?? 0 ) + $ch->amount;
		if ( empty( $last_paid[ $cid ] ) || $ch->created > $last_paid[ $cid ] ) {
			$last_paid[ $cid ] = $ch->created;
		}
	}

	/* -------------------------- 3c. Open invoices --------------------- */
	$overdue_ids = [];
	$inv_it = $stripe->invoices->all([
		'limit'  => 100,
		'status' => 'open',
	])->autoPagingIterator();
	foreach ( $inv_it as $inv ) {
		if ( ! empty( $inv->customer ) ) {
			$overdue_ids[ $inv->customer ] = true;
		}
	}

	/* -------------------------- 3d. Process customers ----------------- */
	$customers = [];
	foreach ( $customers_raw as $c ) {
		$cid  = $c->id;
		$mrr_cents = 0;
		foreach ( $c->subscriptions->data as $sub ) {
			if ( $sub->status !== 'active' ) { continue; }
			foreach ( $sub->items->data as $item ) {
				$plan   = $item->plan;
				$amount = $item->quantity * $plan->amount;
				$mrr_cents += ( $plan->interval === 'year' ) ? $amount / 12 : $amount;
			}
		}
		$total_cents = $life_tot[ $cid ] ?? 0;
		$paid_ts     = $last_paid[ $cid ] ?? 0;

		// mark overdue by rules
		if ( ( time() - $paid_ts ) / DAY_IN_SECONDS > SPM_OVERDUE_DAYS ) {
			$overdue_ids[ $cid ] = true;
		}

		$customers[ $cid ] = [
			'id'        => $cid,
			'name'      => $c->name ?: '(No name)',
			'email'     => $c->email ?: '(No email)',
			'total'     => $total_cents / 100,
			'mrr'       => round( $mrr_cents / 100, 2 ),
			'last_paid' => $paid_ts,
		];
	}

	/* -------------------------- 3e. Site matching --------------------- */
	$sites           = spm_get_mainwp_sites();
	$manual_map      = get_option( 'stripe_pm_site_customer_map', [] );
	$matched_sites   = [];  // site → cid
	$matched_custs   = [];  // cid → [sites]

	foreach ( $sites as $site_url => $title ) {
		$domain = spm_extract_domain( $site_url );

		if ( isset( $manual_map[ $site_url ] ) && isset( $customers[ $manual_map[ $site_url ] ] ) ) {
			$cid = $manual_map[ $site_url ];
		} else {
			$cid = null;
			foreach ( $customers as $id => $cu ) {
				if ( strpos( strtolower( $cu['email'] . ' ' . $cu['name'] ), $domain ) !== false ) {
					$cid = $id; break;
				}
			}
		}

		if ( $cid ) {
			$matched_sites[ $site_url ]   = $cid;
			$matched_custs[ $cid ][]      = $site_url;
		}
	}

	$package = compact( 'customers', 'overdue_ids', 'sites', 'matched_sites', 'matched_custs' );
	set_transient( $cache_key, $package, SPM_CACHE_MINS * MINUTE_IN_SECONDS );

	return $package;
}

/* -------------------------------------------------------------------------- */
/* 4. HELPERS                                                                 */
/* -------------------------------------------------------------------------- */
function spm_extract_domain( $url ) {
	return preg_replace( '/^www\./', '', strtolower( parse_url( $url, PHP_URL_HOST ) ) );
}
function spm_get_mainwp_sites() {
	global $wpdb;
	$table = $wpdb->prefix . 'mainwp_wp';
	$rows  = $wpdb->get_results( "SELECT url, name FROM $table" );
	return array_column( $rows, 'name', 'url' );
}

/* -------------------------------------------------------------------------- */
/* 5. MINIMAL CSS                                                             */
/* -------------------------------------------------------------------------- */
add_action( 'admin_head', function () {
	echo '<style>
		.spm-error { background:#fee !important; }
		.spm-error td { color:#c00; font-weight:bold; }
		table.widefat td { vertical-align:top; }
	</style>';
} );
