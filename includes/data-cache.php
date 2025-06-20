<?php
/**
 * Data layer: Stripe pull, transient cache, hourly cron refresh.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 1. CRON SCHEDULE & HOOKS
 * ---------------------------------------------------------------------- */

// Schedule the hourly refresh on init
add_action( 'init', 'spm_schedule_cron' );
function spm_schedule_cron() {
    if ( ! wp_next_scheduled( 'spm_hourly_refresh' ) ) {
        wp_schedule_event( time(), 'hourly', 'spm_hourly_refresh' );
    }
}

// Handle the hourly cron event
add_action( 'spm_hourly_refresh', 'spm_cron_refresh' );
function spm_cron_refresh() {
    $key = get_option( 'spm_stripe_secret_key', '' );
    if ( $key ) {
        spm_get_cached_report( $key, true );
    }
}

// Activation & deactivation hooks to add/remove the cron
register_activation_hook( SPM_FILE, 'spm_schedule_cron' );
register_deactivation_hook( SPM_FILE, function() {
    if ( $ts = wp_next_scheduled( 'spm_hourly_refresh' ) ) {
        wp_unschedule_event( $ts, 'spm_hourly_refresh' );
    }
} );

/* -------------------------------------------------------------------------
 * 2. STRIPE DATA FETCH & CACHE
 * ---------------------------------------------------------------------- */

/**
 * spm_get_cached_report
 *
 * Pulls customer, charge, and invoice data from Stripe; merges duplicate
 * customers; calculates MRR, lifetime revenue, last payment, and overdue flags;
 * automatically matches to MainWP child sites; caches the result.
 *
 * @param string $secret Stripe secret key
 * @param bool   $force  Whether to bypass the transient cache
 * @return array|WP_Error
 */
function spm_get_cached_report( string $secret, bool $force = false ) {
    $cache_key = 'spm_cached_report';
    if ( ! $force && ( $cached = get_transient( $cache_key ) ) ) {
        return $cached;
    }

    require_once SPM_DIR . 'vendor/autoload.php';
    $stripe = new \Stripe\StripeClient( $secret );

    try {
        set_time_limit( 300 );
        ignore_user_abort( true );

        //
        // 1) FETCH & DEDUPE CUSTOMERS
        //
        $raw = iterator_to_array(
            $stripe->customers->all([
                'limit'  => 100,
                'expand' => ['data.subscriptions'],
            ])->autoPagingIterator()
        );

        $id2alias = [];
        $merged    = [];

        foreach ( $raw as $cu ) {
            // Determine alias by email or fallback to name or ID
            $alias = strtolower( trim( $cu->email ?: $cu->name ) );
            if ( ! $alias ) {
                $alias = $cu->id;
            }

            // Map every real ID to the alias
            $id2alias[ $cu->id ] = $alias;

            if ( ! isset( $merged[ $alias ] ) ) {
                $merged[ $alias ] = $cu;
            } else {
                // Merge subscription arrays
                $merged[ $alias ]->subscriptions->data =
                    array_merge(
                        $merged[ $alias ]->subscriptions->data,
                        $cu->subscriptions->data
                    );
            }
        }

        //
        // 2) CHARGES → lifetime total & last_paid
        //
        $life_tot  = [];
        $last_paid = [];

        foreach ( $stripe->charges->all(['limit' => 100])->autoPagingIterator() as $ch ) {
            if ( $ch->status !== 'succeeded' || ! $ch->paid ) {
                continue;
            }
            $alias = $id2alias[ $ch->customer ] ?? null;
            if ( ! $alias ) {
                continue;
            }
            $life_tot[ $alias ] = ( $life_tot[ $alias ] ?? 0 ) + $ch->amount;
            $last_paid[ $alias ] = max( $last_paid[ $alias ] ?? 0, $ch->created );
        }

        //
        // 3) OPEN INVOICES → truly past due
        //
        $overdue_ids = [];
        foreach ( $stripe->invoices->all([
                'limit'  => 100,
                'status' => 'open'
            ])->autoPagingIterator() as $inv
        ) {
            $alias = $id2alias[ $inv->customer ] ?? null;
            if ( ! $alias ) {
                continue;
            }

            // Determine an effective due timestamp
            $due = $inv->due_date
                 ?: $inv->next_payment_attempt
                 ?: $inv->created;

            if ( $due && $due < time() ) {
                $overdue_ids[ $alias ] = true;
            }
        }

        //
        // 4) BUILD FINAL CUSTOMERS ARRAY
        //
        $customers = [];
        foreach ( $merged as $alias => $cu ) {
            // Calculate MRR
            $mrr = 0;
            foreach ( $cu->subscriptions->data as $sub ) {
                if ( $sub->status !== 'active' ) {
                    continue;
                }
                foreach ( $sub->items->data as $it ) {
                    $amt = $it->quantity * $it->plan->amount;
                    $mrr += ( $it->plan->interval === 'year' ) ? $amt / 12 : $amt;
                }
            }

            $total = $life_tot[ $alias ] ?? 0;
            $paid  = $last_paid[ $alias ] ?? 0;

            // Flag overdue if > X days since last successful charge
            if ( ( time() - $paid ) / DAY_IN_SECONDS > SPM_OVERDUE_DAYS ) {
                $overdue_ids[ $alias ] = true;
            }

            $customers[ $alias ] = [
                'id'         => $alias,
                'name'       => $cu->name ?: '(No name)',
                'email'      => $cu->email ?: '(No email)',
                'total'      => $total / 100,
                'mrr'        => round( $mrr / 100, 2 ),
                'last_paid'  => $paid,
            ];
        }

        //
        // 5) MATCH TO MAINWP CHILD SITES
        //
        global $wpdb;
        $rows  = $wpdb->get_results( "SELECT url, name FROM {$wpdb->prefix}mainwp_wp" );
        $sites = array_column( $rows, 'name', 'url' );

        $manual         = get_option( 'stripe_pm_site_customer_map', [] );
        $unlinked_sites = get_option( 'spm_unlinked_sites', [] );

        $matched_sites = [];
        $matched_custs = [];

        foreach ( $sites as $site_url => $title ) {
            // Skip sites the user explicitly unlinked
            if ( isset( $unlinked_sites[ $site_url ] ) ) {
                continue;
            }

            // Manual override
            $alias = $manual[ $site_url ] ?? null;

            // Auto-match by domain fragment
            if ( ! $alias ) {
                $domain = preg_replace( '/^www\./', '', strtolower( parse_url( $site_url, PHP_URL_HOST ) ) );
                foreach ( $customers as $aid => $c ) {
                    if ( strpos( strtolower( $c['email'] . ' ' . $c['name'] ), $domain ) !== false ) {
                        $alias = $aid;
                        break;
                    }
                }
            }

            if ( $alias && isset( $customers[ $alias ] ) ) {
                $matched_sites[ $site_url ] = $alias;
                $matched_custs[ $alias ][]  = $site_url;
            }
        }

        // Pack & cache the result
        $package = compact( 'customers', 'overdue_ids', 'sites', 'matched_sites', 'matched_custs' );

        set_transient( $cache_key, $package, SPM_CACHE_MINS * MINUTE_IN_SECONDS );
        return $package;

    } catch ( \Throwable $e ) {
        return new WP_Error( 'spm-stripe', $e->getMessage() );
    }
}
