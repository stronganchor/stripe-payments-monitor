<?php
/*
Plugin Name: Stripe Payments Monitor
Plugin URI:  https://github.com/stronganchor/stripe-payments-monitor
Description: Displays per-client revenue & subscription health inside MainWP. Flags overdue/failed payers and lets you map customers ⇄ websites.
Version:     0.1.1
Author:      Strong Anchor Tech
Author URI:  https://stronganchortech.com/
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------- */
/* 1. SETTINGS – Stripe secret key & misc.                                    */
/* -------------------------------------------------------------------------- */
add_action( 'admin_menu', 'spm_register_menu' );

function spm_register_menu() {
	add_menu_page(
		'Payments Monitor',
		'Payments Monitor',
		'manage_options',
		'stripe-payments-monitor',
		'spm_dashboard_page',
		'dashicons-chart-line'
	);

	add_submenu_page(
		'stripe-payments-monitor',
		'Stripe Monitor Settings',
		'Settings',
		'manage_options',
		'stripe-payments-monitor-settings',
		'spm_settings_page'
	);
}

function spm_settings_page() {
	if ( isset( $_POST['spm_save_settings'] ) ) {
		check_admin_referer( 'spm_save' );
		update_option( 'spm_stripe_secret_key', sanitize_text_field( $_POST['spm_secret_key'] ) );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	$secret = esc_attr( get_option( 'spm_stripe_secret_key', '' ) );
	?>
	<div class="wrap">
		<h1>Stripe Payments Monitor – Settings</h1>
		<form method="post">
			<?php wp_nonce_field( 'spm_save' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="spm_secret_key">Stripe <code>sk_live_*</code> Secret&nbsp;Key</label></th>
					<td><input type="text" class="regular-text" id="spm_secret_key" name="spm_secret_key" value="<?php echo $secret; ?>" required></td>
				</tr>
			</table>
			<p class="submit"><button type="submit" name="spm_save_settings" class="button button-primary">Save</button></p>
		</form>
		<p>You must have the Stripe PHP SDK installed in this plugin folder (<code>composer require stripe/stripe-php</code>).</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------- */
/* 2. MAIN DASHBOARD PAGE                                                     */
/* -------------------------------------------------------------------------- */
function spm_dashboard_page() {
	$secret = get_option( 'spm_stripe_secret_key', '' );
	if ( empty( $secret ) ) {
		echo '<div class="wrap"><h1>Payments Monitor</h1><p>Please enter your Stripe secret key in the Settings tab first.</p></div>';
		return;
	}

	// Handle manual mapping submission.
	if ( isset( $_POST['spm_save_mapping'] ) ) {
		check_admin_referer( 'spm_save_mapping' );
		$map                    = get_option( 'stripe_pm_site_customer_map', [] );
		$site                   = esc_url_raw( $_POST['site_url'] );
		$cust                   = sanitize_text_field( $_POST['customer_id'] );
		$map[ $site ]           = $cust;
		update_option( 'stripe_pm_site_customer_map', $map );
	}

	// ---------------------------------------------------------------------
	// Load Stripe SDK
	require_once __DIR__ . '/vendor/autoload.php';
	$stripe = new \Stripe\StripeClient( $secret );

	// ---------------------------------------------------------------------
	// 2a. Pull MainWP sites list
	$sites = spm_get_mainwp_sites();       // ['https://example.com' => 'Example Site']

	// ---------------------------------------------------------------------
	// 2b. Pull Stripe customers + revenue figures
	$customers        = [];
	$overdue_ids      = [];
	$site_map         = get_option( 'stripe_pm_site_customer_map', [] );
	$matched_sites    = [];
	$matched_custs    = [];

	$iterator = $stripe->customers->all([
		'limit'  => 100,
		'expand' => ['data.subscriptions'],
	])->autoPagingIterator();

	foreach ( $iterator as $cust ) {
		$cust_id   = $cust->id;
		$name      = $cust->name ?: '(No name)';
		$email     = $cust->email ?: '(No email)';

		// ----- Charges (lifetime) ---------------------------------------
		$total_cents = 0;
		foreach ( $stripe->charges->all( [ 'customer' => $cust_id, 'limit' => 100 ] )->autoPagingIterator() as $charge ) {
			if ( $charge->status === 'succeeded' && $charge->paid ) {
				$total_cents += $charge->amount;
			}
		}

		// ----- Subscriptions (current monthly revenue) ------------------
		$mrr_cents = 0;
		$has_overdue_invoice = false;

		foreach ( $cust->subscriptions->data as $sub ) {
			if ( $sub->status !== 'active' ) { continue; }
			foreach ( $sub->items->data as $item ) {
				$p = $item->plan;
				$amount = $item->quantity * $p->amount;
				if ( $p->interval === 'month' ) {
					$mrr_cents += $amount;
				} elseif ( $p->interval === 'year' ) {
					$mrr_cents += $amount / 12;
				}
			}
		}

		// ----- Last successful payment date -----------------------------
		$last_charge_list = $stripe->charges->all( [
			'customer' => $cust_id,
			'limit'    => 1,               // newest-first by default
		] );
		$last_paid = isset( $last_charge_list->data[0] ) ? $last_charge_list->data[0]->created : 0;

		// Flag overdue (>30 d) or failed invoice
		$days_since_last = $last_paid ? ( time() - $last_paid ) / DAY_IN_SECONDS : 9999;
		if ( $days_since_last > 30 ) { $has_overdue_invoice = true; }

		$latest_inv = $stripe->invoices->all( [
			'customer' => $cust_id,
			'limit'    => 1,
		] );
		if ( isset( $latest_inv->data[0] ) && $latest_inv->data[0]->status === 'open' ) {
			$has_overdue_invoice = true;
		}

		if ( $has_overdue_invoice ) { $overdue_ids[] = $cust_id; }

		$customers[ $cust_id ] = [
			'id'        => $cust_id,
			'name'      => $name,
			'email'     => $email,
			'total'     => $total_cents / 100,
			'mrr'       => round( $mrr_cents / 100, 2 ),
			'last_paid' => $last_paid,
		];
	}

	// ---------------------------------------------------------------------
	// 2c. AUTO MATCHING – customer ⇄ site
	foreach ( $sites as $site_url => $title ) {

		$domain = spm_extract_domain( $site_url );

		if ( isset( $site_map[ $site_url ] ) && isset( $customers[ $site_map[ $site_url ] ] ) ) {
			$matched_sites[ $site_url ]        = $site_map[ $site_url ];
			$matched_custs[ $site_map[ $site_url ] ][] = $site_url;
			continue;
		}

		foreach ( $customers as $cid => $c ) {
			if ( strpos( strtolower( $c['email'] . ' ' . $c['name'] ), $domain ) !== false ) {
				$matched_sites[ $site_url ]  = $cid;
				$matched_custs[ $cid ][]     = $site_url;
				break;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* 3. OUTPUT                                                          */
	/* ------------------------------------------------------------------ */
	echo '<div class="wrap"><h1>Stripe Payments Monitor</h1>';

	/* ---------- Matched table ---------------------------------------- */
	echo '<h2>Matched Clients</h2><table class="widefat fixed"><thead>
		<tr><th>Website</th><th>Customer</th><th>E-mail</th>
		<th style="text-align:right">Lifetime &dollar;</th>
		<th style="text-align:right">MRR &dollar;</th>
		<th>Last Payment</th></tr></thead><tbody>';

	$rows = [];
	foreach ( $matched_sites as $site_url => $cid ) {
		$c      = $customers[ $cid ];
		$is_red = in_array( $cid, $overdue_ids, true );
		$rows[] = [
			'red'  => $is_red,
			'html' => sprintf(
				'<tr class="%s"><td><a href="%s" target="_blank">%s</a></td><td>%s</td><td>%s</td><td style="text-align:right">%0.2f</td><td style="text-align:right">%0.2f</td><td>%s</td></tr>',
				$is_red ? 'spm-error' : '',
				esc_url( $site_url ),
				esc_html( $site_url ),
				esc_html( $c['name'] ),
				esc_html( $c['email'] ),
				$c['total'],
				$c['mrr'],
				$c['last_paid'] ? date_i18n( 'Y-m-d', $c['last_paid'] ) : '—'
			)
		];
	}
	usort( $rows, fn( $a, $b ) => $a['red'] === $b['red'] ? 0 : ( $a['red'] ? -1 : 1 ) );
	foreach ( $rows as $r ) { echo $r['html']; }
	echo '</tbody></table>';

	/* ---------- Unmatched items ------------------------------------- */
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
				echo '<option value="' . esc_attr( $cid ) . '">' . esc_html( $c['name'] . ' (' . $c['email'] . ')' ) . '</option>';
			}
			echo '</select> <button type="submit" name="spm_save_mapping" class="button">Save</button></form></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p><em>All websites are matched.</em></p>';
	}

	echo '<h2>Unmatched Stripe Customers</h2>';
	if ( $unmatched_custs ) {
		echo '<table class="widefat"><thead><tr><th>Customer</th><th>E-mail</th><th>Lifetime&nbsp;$</th><th>Link New Site URL</th></tr></thead><tbody>';
		foreach ( $unmatched_custs as $cid => $c ) {
			echo '<tr><td>' . esc_html( $c['name'] ) . '</td><td>' . esc_html( $c['email'] ) . '</td>
				<td>' . number_format( $c['total'], 2 ) . '</td><td>
				<form method="post">' . wp_nonce_field( 'spm_save_mapping', '', true, false ) . '
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
/* 4. UTILITIES                                                               */
/* -------------------------------------------------------------------------- */
function spm_extract_domain( $url ) {
	$host = parse_url( $url, PHP_URL_HOST );
	return preg_replace( '/^www\./', '', strtolower( $host ) );
}

function spm_get_mainwp_sites() {
	global $wpdb;
	$table = $wpdb->prefix . 'mainwp_wp';
	$rows  = $wpdb->get_results( "SELECT url, name FROM $table" );
	return array_column( $rows, 'name', 'url' );
}

/* -------------------------------------------------------------------------- */
/* 5. SIMPLE CSS                                                              */
/* -------------------------------------------------------------------------- */
add_action( 'admin_head', function () {
	echo '<style>
		.spm-error { background:#fee !important; }
		.spm-error td { color:#c00; font-weight:bold; }
		table.widefat td { vertical-align:top; }
	</style>';
} );
