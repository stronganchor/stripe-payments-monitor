<?php
/*
Plugin Name: Stripe Payments Monitor
Description: Revenue & subscription health report in MainWP. Duplicate customers merged, ignore lists, notes, unlinking supported; hourly auto-refresh; robust overdue logic.
Version:     0.4.1
Author:      Strong Anchor Tech
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ───────────── 0. CONFIG ───────────── */
const SPM_CACHE_MINS   = 15;          // Stripe cache lifetime
const SPM_OVERDUE_DAYS = 30;          // last-payment threshold

/* ═════════════ 1. ACTION HANDLER (runs before output) ════════════ */
add_action( 'admin_init', 'spm_handle_actions' );
function spm_handle_actions() {
	if ( ! isset( $_POST['spm_action'] ) ) { return; }

	check_admin_referer( 'spm_action', 'spm_nonce' );

	$map          = get_option( 'stripe_pm_site_customer_map', [] );
	$ignore_sites = get_option( 'spm_ignore_sites',  [] );
	$ignore_cids  = get_option( 'spm_ignore_clients', [] );
	$site_notes   = get_option( 'spm_site_notes',    [] );
	$client_notes = get_option( 'spm_client_notes',  [] );

	switch ( sanitize_text_field( $_POST['spm_action'] ) ) {

		case 'unlink':
			unset( $map[ esc_url_raw( $_POST['site_url'] ) ] );
			break;

		case 'ignore_client':
			$c = sanitize_text_field( $_POST['cid'] );
			$ignore_cids[ $c ] = true;
			if ( ! empty( $_POST['note'] ) ) { $client_notes[ $c ] = sanitize_text_field( $_POST['note'] ); }
			break;

		case 'unignore_client':
			$c = sanitize_text_field( $_POST['cid'] );
			unset( $ignore_cids[ $c ], $client_notes[ $c ] );
			break;

		case 'ignore_site':
			$s = esc_url_raw( $_POST['site_url'] );
			$ignore_sites[ $s ] = true;
			if ( ! empty( $_POST['note'] ) ) { $site_notes[ $s ] = sanitize_text_field( $_POST['note'] ); }
			break;

		case 'unignore_site':
			$s = esc_url_raw( $_POST['site_url'] );
			unset( $ignore_sites[ $s ], $site_notes[ $s ] );
			break;

		case 'save_mapping':
			$map[ esc_url_raw( $_POST['site_url'] ) ] = sanitize_text_field( $_POST['customer_id'] );
			break;
	}

	update_option( 'stripe_pm_site_customer_map', $map );
	update_option( 'spm_ignore_sites',   $ignore_sites );
	update_option( 'spm_ignore_clients', $ignore_cids );
	update_option( 'spm_site_notes',     $site_notes );
	update_option( 'spm_client_notes',   $client_notes );

	/* redirect back to the report */
	wp_safe_redirect( admin_url( 'admin.php?page=stripe-payments-monitor' ) );
	exit;
}

/* ═════════════ 2. MENU & SETTINGS ═══════════ */
add_action( 'admin_menu', function () {
	add_menu_page( 'Payments Monitor', 'Payments Monitor', 'manage_options',
	               'stripe-payments-monitor', 'spm_dashboard_page', 'dashicons-chart-line' );
	add_submenu_page( 'stripe-payments-monitor', 'Stripe Monitor Settings', 'Settings',
	                  'manage_options', 'stripe-payments-monitor-settings', 'spm_settings_page' );
} );

function spm_settings_page() {
	if ( isset( $_POST['spm_save_settings'] ) && check_admin_referer( 'spm_settings', 'spm_nonce' ) ) {
		update_option( 'spm_stripe_secret_key', sanitize_text_field( $_POST['spm_secret_key'] ) );
		delete_transient( 'spm_cached_report' );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}
	if ( isset( $_POST['spm_clear'] ) && check_admin_referer( 'spm_settings', 'spm_nonce' ) ) {
		delete_transient( 'spm_cached_report' );
		delete_option( 'spm_ignore_clients' );
		delete_option( 'spm_ignore_sites' );
		delete_option( 'spm_client_notes' );
		delete_option( 'spm_site_notes' );
		echo '<div class="notice notice-success"><p>Cache & ignore lists cleared.</p></div>';
	}
	$key = esc_attr( get_option( 'spm_stripe_secret_key', '' ) );
	?>
	<div class="wrap"><h1>Stripe Payments Monitor – Settings</h1>
	<form method="post"><?php wp_nonce_field( 'spm_settings', 'spm_nonce' ); ?>
	<table class="form-table">
		<tr><th><label for="spm_secret_key">Stripe <code>sk_live_…</code> Secret&nbsp;Key</label></th>
		    <td><input type="text" id="spm_secret_key" name="spm_secret_key" class="regular-text"
		               value="<?php echo $key; ?>" required></td></tr>
	</table>
	<p class="submit">
		<button name="spm_save_settings" class="button button-primary">Save</button>
		<button name="spm_clear"         class="button">Clear cache & ignore lists</button>
	</p>
	</form>
	<p>You must have the Stripe PHP SDK installed
	   (<code>composer require stripe/stripe-php</code>).</p>
	</div>
	<?php
}

/* ═════════════ 3. DASHBOARD PAGE ════════════ */
function spm_dashboard_page() {

	$key = get_option( 'spm_stripe_secret_key', '' );
	if ( ! $key ) { echo '<div class="wrap"><h1>Payments Monitor</h1><p>Please set your Stripe secret key first.</p></div>'; return; }

	/* build fetch */
	$force = isset( $_GET['spm_refresh'] ) && wp_verify_nonce( $_GET['spm_refresh'], 'spm_refresh' );
	$data  = spm_get_cached_report( $key, $force );
	if ( is_wp_error( $data ) ) {
		echo '<div class="wrap"><h1>Stripe Payments Monitor</h1><p>Error: ' .
		     esc_html( $data->get_error_message() ) . '</p></div>'; return;
	}
	extract( $data );  // customers, overdue_ids, sites, matched_sites, matched_custs

	/* ignore filters */
	$ignore_sites = get_option( 'spm_ignore_sites', [] );
	$ignore_cids  = get_option( 'spm_ignore_clients', [] );
	foreach ( $ignore_cids as $cid => $_ ) { unset( $overdue_ids[ $cid ] ); }
	$sites = array_diff_key( $sites, $ignore_sites );
	$site_notes   = get_option( 'spm_site_notes',   [] );
	$client_notes = get_option( 'spm_client_notes', [] );

	/* helper button */
	function spm_btn( $action,$label,$hidden=[],$note=false ){
		$o='<form method="post" style="display:inline">'.wp_nonce_field('spm_action','spm_nonce',true,false).
		   '<input type="hidden" name="spm_action" value="'.$action.'">';
		foreach($hidden as $k=>$v){$o.='<input type="hidden" name="'.$k.'" value="'.esc_attr($v).'">';}
		if($note){$o.='<input type="text" name="note" placeholder="note…" style="width:120px">';}
		return $o.'<button class="button-link">'.$label.'</button></form>';
	}

	/* header */
	echo '<div class="wrap"><h1>Stripe Payments Monitor</h1>
		  <p><a href="'.esc_url( add_query_arg('spm_refresh', wp_create_nonce('spm_refresh')) ).'"
		        class="button">Refresh data</a></p>';

	/* Matched */
	echo '<h2>Matched Clients</h2><table class="widefat"><thead><tr>
	<th>Website</th><th>Customer</th><th>E-mail</th>
	<th style="text-align:right">Lifetime&nbsp;$</th><th style="text-align:right">MRR&nbsp;$</th>
	<th>Last&nbsp;Pay</th><th>Actions</th></tr></thead><tbody>';

	$rows=[];
	foreach($matched_sites as $site=>$cid){
		if(isset($ignore_sites[$site]))continue;
		$c=$customers[$cid]; $red=isset($overdue_ids[$cid]);
		$note=$client_notes[$cid]??'';
		$rows[]=[
			'red'=>$red,
			'html'=>'<tr class="'.($red?'spm-error':'').'"><td><a href="'.esc_url($site).
			        '" target="_blank">'.esc_html($site).'</a></td><td>'.esc_html($c['name']).
			        ($note?' <em>('.esc_html($note).')</em>':'').'</td><td>'.esc_html($c['email']).'</td>'.
			        '<td style="text-align:right">'.number_format($c['total'],2).'</td>'.
			        '<td style="text-align:right">'.number_format($c['mrr'],2).'</td>'.
			        '<td>'.($c['last_paid']?date_i18n('Y-m-d',$c['last_paid']):'—').'</td><td>'.
			        spm_btn('unlink','Unlink',['site_url'=>$site]).
			        spm_btn(isset($ignore_cids[$cid])?'unignore_client':'ignore_client',
			                isset($ignore_cids[$cid])?'Un-ignore':'Ignore',['cid'=>$cid],true).
			        '</td></tr>'
		];
	}
	usort($rows,fn($a,$b)=>$a['red']===$b['red']?0:($a['red']?-1:1));
	foreach($rows as $r){echo $r['html'];}
	echo '</tbody></table>';

	/* Unmatched sites */
	$unmatched_sites=array_diff_key($sites,$matched_sites,$ignore_sites);
	echo '<h2>Unmatched Websites</h2>';
	if($unmatched_sites){
		echo '<table class="widefat"><thead><tr><th>Site</th><th>Link</th><th>Actions</th></tr></thead><tbody>';
		foreach($unmatched_sites as $site=>$_){
			echo '<tr><td>'.esc_html($site).'</td><td>'.
			     '<form method="post" style="margin:0">'.wp_nonce_field('spm_action','spm_nonce',true,false).
			     '<input type="hidden" name="spm_action" value="save_mapping">'.
			     '<input type="hidden" name="site_url"   value="'.esc_attr($site).'">'.
			     '<select name="customer_id">';
			foreach($customers as $cid=>$c){
				echo '<option value="'.$cid.'">'.esc_html($c['name'].' ('.$c['email'].')').'</option>';
			}
			echo '</select> <button class="button">Save</button></form></td><td>'.
			     spm_btn('ignore_site','Mark internal',['site_url'=>$site],true).'</td></tr>';
		}
		echo '</tbody></table>';
	}else echo '<p><em>No unmatched websites (excluding internal).</em></p>';

	/* Unmatched customers */
	$unmatched_cust=array_diff_key($customers,$matched_custs,$ignore_cids);
	echo '<h2>Unmatched Stripe Customers</h2>';
	if($unmatched_cust){
		echo '<table class="widefat"><thead><tr><th>Customer</th><th>E-mail</th><th>Lifetime&nbsp;$</th><th>Link new site</th><th>Actions</th></tr></thead><tbody>';
		foreach($unmatched_cust as $cid=>$c){
			echo '<tr><td>'.esc_html($c['name']).'</td><td>'.esc_html($c['email']).'</td>'.
			     '<td>'.number_format($c['total'],2).'</td><td>'.
			     '<form method="post" style="margin:0">'.wp_nonce_field('spm_action','spm_nonce',true,false).
			     '<input type="hidden" name="spm_action" value="save_mapping">'.
			     '<input type="hidden" name="customer_id" value="'.$cid.'">'.
			     '<input type="text" name="site_url" placeholder="https://site.com" style="width:200px">'.
			     '<button class="button">Link</button></form></td><td>'.
			     spm_btn('ignore_client','Ignore',['cid'=>$cid],true).'</td></tr>';
		}
		echo '</tbody></table>';
	}else echo '<p><em>No unmatched customers (excluding ignored).</em></p>';

	echo '</div>';
}

/* ═════════════ 4. DATA / CACHE ═════════════ */
function spm_get_cached_report( string $secret, bool $force=false ){
	$key='spm_cached_report'; if(!$force && ($d=get_transient($key))) return $d;

	require_once __DIR__.'/vendor/autoload.php';
	$stripe=new \Stripe\StripeClient($secret);
	try{
		set_time_limit(300); ignore_user_abort(true);

		/* 4.1 customers */
		$raw=[]; $id2alias=[];
		foreach($stripe->customers->all(['limit'=>100,'expand'=>['data.subscriptions']])->autoPagingIterator() as $cu){
			$alias=strtolower(trim($cu->email?:$cu->name));
			if(!$alias){ $alias=$cu->id; }
			$id2alias[ $cu->id ] = $alias;
			if(!isset($raw[$alias])){
				$raw[$alias]=$cu;
			}else{
				$raw[$alias]->subscriptions->data=array_merge(
					$raw[$alias]->subscriptions->data,$cu->subscriptions->data );
			}
		}

		/* 4.2 charges (totals & last_paid) */
		$life=$last=[];
		foreach($stripe->charges->all(['limit'=>100])->autoPagingIterator() as $ch){
			if($ch->status!=='succeeded'||!$ch->paid)continue;
			$alias=$id2alias[$ch->customer]??null; if(!$alias)continue;
			$life[$alias]=($life[$alias]??0)+$ch->amount;
			$last[$alias]=max($last[$alias]??0,$ch->created);
		}

		/* 4.3 open invoices truly past due */
		$overdue=[];
		foreach($stripe->invoices->all(['limit'=>100,'status'=>'open'])->autoPagingIterator() as $inv){
			$alias=$id2alias[$inv->customer]??null; if(!$alias)continue;
			$due=$inv->due_date ?: $inv->next_payment_attempt ?: $inv->created;
			if($due && $due<time()) $overdue[$alias]=true;
		}

		/* 4.4 build customers array */
		$customers=[]; foreach($raw as $alias=>$cu){
			$mrr=0; foreach($cu->subscriptions->data as $sub){
				if($sub->status!=='active')continue;
				foreach($sub->items->data as $it){
					$amt=$it->quantity*$it->plan->amount;
					$mrr+=($it->plan->interval==='year')?$amt/12:$amt;
				}
			}
			$total=$life[$alias]??0; $paid=$last[$alias]??0;
			if((time()-$paid)/DAY_IN_SECONDS>SPM_OVERDUE_DAYS) $overdue[$alias]=true;
			$customers[$alias]=[
				'id'=>$alias,'name'=>$cu->name?:'(No name)','email'=>$cu->email?:'(No email)',
				'total'=>$total/100,'mrr'=>round($mrr/100,2),'last_paid'=>$paid
			];
		}

		/* 4.5 sites & matching */
		$sites=spm_get_mainwp_sites(); $manual=get_option('stripe_pm_site_customer_map',[]);
		$matched_sites=[]; $matched_custs=[];
		foreach($sites as $site=>$title){
			$cid=$manual[$site]??null;
			if(!$cid){
				$d=preg_replace('/^www\./','',strtolower(parse_url($site,PHP_URL_HOST)));
				foreach($customers as $id=>$c){
					if(strpos(strtolower($c['email'].' '.$c['name']),$d)!==false){$cid=$id;break;}
				}
			}
			if($cid&&isset($customers[$cid])){$matched_sites[$site]=$cid;$matched_custs[$cid][]= $site;}
		}

		$pkg=compact('customers','overdue','sites','matched_sites','matched_custs');
		set_transient($key,$pkg,SPM_CACHE_MINS*MINUTE_IN_SECONDS); return $pkg;

	}catch(Throwable $e){return new WP_Error('spm-stripe',$e->getMessage());}
}

/* ═════════════ 5. CRON ════════════════════ */
function spm_schedule_cron(){
	if(!wp_next_scheduled('spm_hourly_refresh')){
		wp_schedule_event(time(),'hourly','spm_hourly_refresh');
	}
}
add_action('init','spm_schedule_cron');
add_action('spm_hourly_refresh',function(){
	$key=get_option('spm_stripe_secret_key',''); if($key) spm_get_cached_report($key,true);
});
register_deactivation_hook(__FILE__,function(){
	if($ts=wp_next_scheduled('spm_hourly_refresh')){
		wp_unschedule_event($ts,'spm_hourly_refresh');
	}
});

/* ═════════════ 6. STYLES ═══════════════════ */
add_action('admin_head',function(){echo'<style>
.spm-error{background:#fee !important}.spm-error td{color:#c00;font-weight:bold}
table.widefat td{vertical-align:top}form button.button-link{background:none;border:none;color:#2271b1;cursor:pointer;padding:0;margin:0}
form button.button-link:hover{text-decoration:underline}em{color:#666}
</style>';});
