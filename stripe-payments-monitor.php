<?php
/*
Plugin Name: Stripe Payments Monitor
Plugin URI:  https://github.com/stronganchor/stripe-payments-monitor
Description: Displays per-client revenue & subscription health inside MainWP. Flags overdue/failed payers and lets you map customers ⇄ websites. Duplicate customers merged, ignore lists & notes supported.
Version:     0.3.0
Author:      Strong Anchor Tech
Author URI:  https://stronganchortech.com/
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ─────────────────────────────────────────────────────────────────────────── */
/* 0. CONSTANTS                                                              */
/* ─────────────────────────────────────────────────────────────────────────── */
const SPM_CACHE_MINS   = 15;  // Stripe data cache
const SPM_OVERDUE_DAYS = 30;  // days since last payment → red

/* ─────────────────────────────────────────────────────────────────────────── */
/* 1. ADMIN MENU & SETTINGS PAGE                                             */
/* ─────────────────────────────────────────────────────────────────────────── */
add_action( 'admin_menu', function () {
	add_menu_page( 'Payments Monitor', 'Payments Monitor', 'manage_options',
	               'stripe-payments-monitor', 'spm_dashboard_page', 'dashicons-chart-line' );

	add_submenu_page( 'stripe-payments-monitor', 'Stripe Monitor Settings', 'Settings',
	                  'manage_options', 'stripe-payments-monitor-settings', 'spm_settings_page' );
} );

function spm_settings_page() {
	if ( isset( $_POST['spm_save_settings'] ) ) {
		check_admin_referer( 'spm_save' );
		update_option( 'spm_stripe_secret_key', sanitize_text_field( $_POST['spm_secret_key'] ) );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	/* clear caches / ignores */
	if ( isset( $_POST['spm_clear_everything'] ) ) {
		delete_transient( 'spm_cached_report' );
		delete_option( 'spm_ignore_clients' );
		delete_option( 'spm_ignore_sites' );
		delete_option( 'spm_client_notes' );
		delete_option( 'spm_site_notes' );
		echo '<div class="notice notice-success"><p>All caches & ignore lists cleared.</p></div>';
	}

	$secret = esc_attr( get_option( 'spm_stripe_secret_key', '' ) );
	?>
	<div class="wrap"><h1>Stripe Payments Monitor – Settings</h1>
	<form method="post"><?php wp_nonce_field( 'spm_save' ); ?>
	<table class="form-table">
		<tr><th><label for="spm_secret_key">Stripe <code>sk_live_…</code> Secret Key</label></th>
		    <td><input type="text" id="spm_secret_key" name="spm_secret_key"
		               class="regular-text" value="<?php echo $secret; ?>" required></td></tr>
	</table>
	<p class="submit"><button name="spm_save_settings" class="button button-primary">Save</button></p>
	</form>

	<hr><h2>Maintenance</h2>
	<form method="post"><?php wp_nonce_field( 'spm_save' ); ?>
		<p><button name="spm_clear_everything" class="button">Clear cache & ignore lists</button></p>
	</form>

	<p>You must have the Stripe PHP SDK installed (<code>composer require stripe/stripe-php</code>).</p>
	</div>
	<?php
}

/* ─────────────────────────────────────────────────────────────────────────── */
/* 2. DASHBOARD – MAIN REPORT                                                */
/* ─────────────────────────────────────────────────────────────────────────── */
function spm_dashboard_page() {

	$secret = get_option( 'spm_stripe_secret_key', '' );
	if ( empty( $secret ) ) {
		echo '<div class="wrap"><h1>Payments Monitor</h1><p>Please enter your Stripe secret key in the Settings tab first.</p></div>';
		return;
	}

	/* ── OPTION ARRAYS (create defaults) ────────────────────────────── */
	$map           = get_option( 'stripe_pm_site_customer_map', [] );   // site → cid
	$ignore_sites  = get_option( 'spm_ignore_sites',  [] );
	$ignore_cids   = get_option( 'spm_ignore_clients', [] );
	$site_notes    = get_option( 'spm_site_notes',    [] );
	$client_notes  = get_option( 'spm_client_notes',  [] );

	/* ── HANDLE FORM ACTIONS ────────────────────────────────────────── */
	if ( isset( $_POST['spm_action'] ) ) {
		check_admin_referer( 'spm_action_nonce' );
		$act = sanitize_text_field( $_POST['spm_action'] );

		switch ( $act ) {
			case 'unlink':
				$site = esc_url_raw( $_POST['site_url'] );
				unset( $map[ $site ] );
				break;

			case 'ignore_client':
				$cid  = sanitize_text_field( $_POST['cid'] );
				$note = sanitize_text_field( $_POST['note'] );
				$ignore_cids[ $cid ] = true;
				if ( $note ) { $client_notes[ $cid ] = $note; }
				break;

			case 'unignore_client':
				$cid = sanitize_text_field( $_POST['cid'] );
				unset( $ignore_cids[ $cid ], $client_notes[ $cid ] );
				break;

			case 'ignore_site':
				$site = esc_url_raw( $_POST['site_url'] );
				$note = sanitize_text_field( $_POST['note'] );
				$ignore_sites[ $site ] = true;
				if ( $note ) { $site_notes[ $site ] = $note; }
				break;

			case 'unignore_site':
				$site = esc_url_raw( $_POST['site_url'] );
				unset( $ignore_sites[ $site ], $site_notes[ $site ] );
				break;

			case 'save_mapping':
				$site          = esc_url_raw( $_POST['site_url'] );
				$cid           = sanitize_text_field( $_POST['customer_id'] );
				$map[ $site ]  = $cid;
				break;
		}

		/* persist */
		update_option( 'stripe_pm_site_customer_map', $map );
		update_option( 'spm_ignore_sites',  $ignore_sites );
		update_option( 'spm_ignore_clients', $ignore_cids );
		update_option( 'spm_site_notes',    $site_notes );
		update_option( 'spm_client_notes',  $client_notes );

		/* redirect to avoid resubmission */
		wp_safe_redirect( remove_query_arg( ['_wp_http_referer'] ) );
		exit;
	}

	/* ── FETCH DATA (from cache) ────────────────────────────────────── */
	$force   = isset( $_GET['spm_refresh'] );
	$data    = spm_get_cached_report( $secret, $force );

	if ( is_wp_error( $data ) ) {
		echo '<div class="wrap"><h1>Stripe Payments Monitor</h1><p>Error: ' .
		     esc_html( $data->get_error_message() ) . '</p></div>';
		return;
	}

	$customers      = $data['customers'];
	$overdue_ids    = $data['overdue_ids'];
	$sites          = $data['sites'];
	$matched_sites  = $data['matched_sites'];
	$matched_custs  = $data['matched_custs'];

	/* ── APPLY IGNORE FILTERS ───────────────────────────────────────── */
	foreach ( $ignore_cids as $cid => $_ ) {
		unset( $overdue_ids[ $cid ] ); // never flag as overdue
	}
	$sites = array_diff_key( $sites, $ignore_sites ); // hide internal from pools

	/* ───────────────────────────────────────────────────────────────── */
	/* 3. RENDER                                                        */
	/* ───────────────────────────────────────────────────────────────── */
	echo '<div class="wrap"><h1>Stripe Payments Monitor</h1>
	      <p><a href="' . esc_url( add_query_arg( 'spm_refresh', '1' ) ) .
	      '" class="button">Refresh data</a></p>';

	/* -------- Matched table ---------------------------------------- */
	echo '<h2>Matched Clients</h2><table class="widefat"><thead>
	<tr><th>Website</th><th>Customer</th><th>E-mail</th>
	    <th style="text-align:right">Lifetime&nbsp;$</th>
	    <th style="text-align:right">MRR&nbsp;$</th>
	    <th>Last&nbsp;Payment</th><th>Actions</th></tr></thead><tbody>';

	$rows = [];
	foreach ( $matched_sites as $site_url => $cid ) {
		if ( isset( $ignore_sites[ $site_url ] ) ) { continue; } // internal
		$c      = $customers[ $cid ];
		$is_red = isset( $overdue_ids[ $cid ] );
		$note   = isset( $client_notes[ $cid ] ) ? ' <em>(' . esc_html( $client_notes[ $cid ] ) . ')</em>' : '';
		$rows[] = [
			'red'  => $is_red,
			'html' => sprintf(
				'<tr class="%s"><td><a href="%s" target="_blank">%s</a></td>
				 <td>%s%s</td><td>%s</td><td style="text-align:right">%0.2f</td>
				 <td style="text-align:right">%0.2f</td><td>%s</td><td>%s</td></tr>',
				$is_red ? 'spm-error' : '',
				esc_url( $site_url ), esc_html( $site_url ),
				esc_html( $c['name'] ), $note, esc_html( $c['email'] ),
				$c['total'], $c['mrr'],
				$c['last_paid'] ? date_i18n( 'Y-m-d', $c['last_paid'] ) : '—',
				spm_action_buttons( [
					['unlink',      'Unlink',           ['site_url' => $site_url] ],
					[ isset( $ignore_cids[ $cid ] ) ? 'unignore_client' : 'ignore_client',
					  isset( $ignore_cids[ $cid ] ) ? 'Un-ignore' : 'Ignore',
					  ['cid' => $cid], true ]
				] )
			)
		];
	}
	usort( $rows, fn( $a, $b ) => $a['red'] === $b['red'] ? 0 : ( $a['red'] ? -1 : 1 ) );
	foreach ( $rows as $r ) { echo $r['html']; }
	echo '</tbody></table>';

	/* -------- Unmatched websites ----------------------------------- */
	$unmatched_sites = array_diff_key( $sites, $matched_sites, $ignore_sites );

	echo '<h2>Unmatched Websites</h2>';
	if ( $unmatched_sites ) {
		echo '<table class="widefat"><thead><tr><th>Site</th><th>Pick Customer</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $unmatched_sites as $site_url => $title ) {
			echo '<tr><td>' . esc_html( $site_url ) . '</td><td>
				  <form method="post" style="margin:0;">' . spm_nonce() . '
				  <input type="hidden" name="spm_action" value="save_mapping">
				  <input type="hidden" name="site_url"   value="' . esc_attr( $site_url ) . '">
				  <select name="customer_id">';
			foreach ( $customers as $cid => $c ) {
				echo '<option value="' . esc_attr( $cid ) . '">' .
				     esc_html( $c['name'] . ' (' . $c['email'] . ')' ) . '</option>';
			}
			echo '</select> <button class="button">Save</button></form></td><td>' .
			     spm_action_buttons( [
				     [ 'ignore_site', 'Mark internal', ['site_url' => $site_url], true ]
			     ] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p><em>No unmatched websites (excluding internal).</em></p>';
	}

	/* -------- Unmatched customers ---------------------------------- */
	$unmatched_custs = array_diff_key( $customers, $matched_custs, $ignore_cids );

	echo '<h2>Unmatched Stripe Customers</h2>';
	if ( $unmatched_custs ) {
		echo '<table class="widefat"><thead><tr><th>Customer</th><th>E-mail</th><th>Lifetime&nbsp;$</th><th>Link New Site</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $unmatched_custs as $cid => $c ) {
			echo '<tr><td>' . esc_html( $c['name'] ) . '</td><td>' . esc_html( $c['email'] ) . '</td>
				<td>' . number_format( $c['total'], 2 ) . '</td><td>
				<form method="post" style="margin:0;">' . spm_nonce() . '
				<input type="hidden" name="spm_action" value="save_mapping">
				<input type="hidden" name="customer_id" value="' . esc_attr( $cid ) . '">
				<input type="text" name="site_url" placeholder="https://site.com" style="width:200px">
				<button class="button">Link</button></form></td><td>' .
				spm_action_buttons( [
					['ignore_client', 'Ignore', ['cid' => $cid], true]
				] ) .
				'</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p><em>No unmatched Stripe customers (excluding ignored).</em></p>';
	}

	echo '</div>';
}

/* ─────────────────────────────────────────────────────────────────────────── */
/* 3. DATA FETCH, CACHE & DUPLICATE MERGE                                    */
/* ─────────────────────────────────────────────────────────────────────────── */
function spm_get_cached_report( string $secret, bool $force = false ) {
	$key = 'spm_cached_report';
	if ( ! $force && ( $cached = get_transient( $key ) ) ) { return $cached; }

	require_once __DIR__ . '/vendor/autoload.php';
	$stripe = new \Stripe\StripeClient( $secret );
	try {

		set_time_limit( 0 ); ignore_user_abort( true );

		/* 3a. customers (expanded) */
		$raw = iterator_to_array(
			$stripe->customers->all([
				'limit'  => 100,
				'expand' => ['data.subscriptions'],
			])->autoPagingIterator()
		);

		/* ── 3b. consolidate duplicates by email OR exact name ───────── */
		$merged = [];           // key = email|name
		foreach ( $raw as $cu ) {
			$keyEmail = strtolower( trim( $cu->email ?? '' ) );
			$keyName  = $keyEmail ?: strtolower( trim( $cu->name ?? '' ) );
			if ( ! $keyName ) { $keyName = $cu->id; } // fallback unique

			if ( ! isset( $merged[ $keyName ] ) ) {
				$merged[ $keyName ] = ['ids'=>[], 'obj'=> $cu];
			} else {
				$merged[ $keyName ]['ids'][] = $cu->id;
				// merge subscriptions later by recalculating totals
				$merged[ $keyName ]['obj']->subscriptions->data =
					array_merge(
						$merged[ $keyName ]['obj']->subscriptions->data,
						$cu->subscriptions->data
					);
			}
		}
		$customers_raw = array_column( $merged, 'obj' );

		/* 3c. charges */
		$life_tot = $last_paid = [];
		foreach ( $stripe->charges->all([ 'limit' => 100 ])->autoPagingIterator() as $ch ) {
			if ( $ch->status !== 'succeeded' || ! $ch->paid ) { continue; }
			$c = strtolower( trim( $ch->billing_details->email ?? '' ) );
			if ( ! $c ) { $c = $ch->customer ?? ''; }
			if ( ! $c ) { continue; }
			$life_tot [ $c ] = ( $life_tot [ $c ] ?? 0 ) + $ch->amount;
			$last_paid[ $c ] = max( $last_paid[ $c ] ?? 0, $ch->created );
		}

		/* 3d. open invoices */
		$overdue_ids = [];
		foreach ( $stripe->invoices->all([ 'limit' => 100, 'status' => 'open' ])->autoPagingIterator() as $inv ) {
			$e = strtolower( trim( $inv->customer_email ?? '' ) );
			if ( $e ) { $overdue_ids[ $e ] = true; }
		}

		/* 3e. build final customer array */
		$customers = [];
		foreach ( $customers_raw as $cu ) {
			$key = strtolower( trim( $cu->email ?? '' ) );
			if ( ! $key ) { $key = strtolower( trim( $cu->name ?? '' ) ); }
			$cidAlias = $key ?: $cu->id; // unique alias to reference elsewhere

			/* mrr */
			$mrr = 0;
			foreach ( $cu->subscriptions->data as $sub ) {
				if ( $sub->status !== 'active' ) { continue; }
				foreach ( $sub->items->data as $it ) {
					$p = $it->plan; $amt = $it->quantity * $p->amount;
					$mrr += ( $p->interval === 'year' ) ? $amt / 12 : $amt;
				}
			}

			$total = $life_tot [ $key ] ?? 0;
			$paid  = $last_paid[ $key ] ?? 0;
			if ( ( time() - $paid ) / DAY_IN_SECONDS > SPM_OVERDUE_DAYS ) {
				$overdue_ids[ $key ] = true;
			}

			$customers[ $cidAlias ] = [
				'id'        => $cidAlias,
				'name'      => $cu->name ?: '(No name)',
				'email'     => $cu->email ?: '(No email)',
				'total'     => $total / 100,
				'mrr'       => round( $mrr / 100, 2 ),
				'last_paid' => $paid,
			];
		}

		/* 3f. sites + matching */
		$sites           = spm_get_mainwp_sites();
		$manual_map      = get_option( 'stripe_pm_site_customer_map', [] );
		$matched_sites   = $matched_custs = [];

		foreach ( $sites as $site_url => $_ ) {
			$domain = spm_extract_domain( $site_url );
			$cid    = $manual_map[ $site_url ] ?? null;

			if ( ! $cid ) {
				foreach ( $customers as $id => $cu ) {
					if ( strpos( strtolower( $cu['email'] . ' ' . $cu['name'] ), $domain ) !== false ) {
						$cid = $id; break;
					}
				}
			}
			if ( $cid && isset( $customers[ $cid ] ) ) {
				$matched_sites[ $site_url ] = $cid;
				$matched_custs[ $cid ][]    = $site_url;
			}
		}

		$package = compact( 'customers', 'overdue_ids', 'sites',
		                    'matched_sites', 'matched_custs' );
		set_transient( $key, $package, SPM_CACHE_MINS * MINUTE_IN_SECONDS );

	} catch ( \Throwable $e ) {
		return new WP_Error( 'spm-stripe', $e->getMessage() );
	}
	return $package;
}

/* ─────────────────────────────────────────────────────────────────────────── */
/* 4. HELPERS                                                                 */
/* ─────────────────────────────────────────────────────────────────────────── */
function spm_extract_domain( $url ) {
	return preg_replace( '/^www\./', '', strtolower( parse_url( $url, PHP_URL_HOST ) ) );
}
function spm_get_mainwp_sites() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT url, name FROM {$wpdb->prefix}mainwp_wp" );
	return array_column( $rows, 'name', 'url' );
}
function spm_nonce() { return wp_nonce_field( 'spm_action_nonce', '', true, false ); }

/* render small action forms */
function spm_action_buttons( array $btns ) {
	$out = '';
	foreach ( $btns as $b ) {
		[$action, $label, $hidden, $needNote] = array_pad( $b, 4, null );
		$out .= '<form method="post" style="display:inline">' . spm_nonce() .
		        '<input type="hidden" name="spm_action" value="' . esc_attr( $action ) . '">';
		foreach ( $hidden as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		if ( $needNote ) {
			$out .= '<input type="text" name="note" placeholder="note…" style="width:120px">';
		}
		$out .= '<button class="button-link">' . esc_html( $label ) . '</button></form> ';
	}
	return $out;
}

/* ─────────────────────────────────────────────────────────────────────────── */
/* 5. MINIMAL CSS                                                             */
/* ─────────────────────────────────────────────────────────────────────────── */
add_action( 'admin_head', function () {
	echo '<style>
		.spm-error { background:#fee !important; }
		.spm-error td { color:#c00; font-weight:bold; }
		table.widefat td { vertical-align:top; }
		form button.button-link { background:none;border:none;color:#2271b1;cursor:pointer;padding:0;margin:0; }
		form button.button-link:hover { text-decoration:underline; }
		em { color:#666; }
	</style>';
} );
