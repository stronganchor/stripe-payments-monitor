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

add_action( 'init', 'spm_schedule_cron' );
function spm_schedule_cron() {
    if ( ! wp_next_scheduled( 'spm_hourly_refresh' ) ) {
        wp_schedule_event( time(), 'hourly', 'spm_hourly_refresh' );
    }
}

add_action( 'spm_hourly_refresh', 'spm_cron_refresh' );
function spm_cron_refresh() {
    $key = get_option( 'spm_stripe_secret_key', '' );
    if ( $key ) {
        spm_get_cached_report( $key, true );
    }
}

register_activation_hook( SPM_FILE, 'spm_schedule_cron' );
register_deactivation_hook(
    SPM_FILE,
    static function() {
        $timestamp = wp_next_scheduled( 'spm_hourly_refresh' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'spm_hourly_refresh' );
        }
    }
);

/* -------------------------------------------------------------------------
 * 2. REPORT CACHE + BATCH STATE HELPERS
 * ---------------------------------------------------------------------- */

function spm_get_report_cache_key() {
    return 'spm_cached_report';
}

function spm_get_report_job_key( $job_id ) {
    return 'spm_report_job_' . md5( (string) $job_id );
}

function spm_get_cached_report_snapshot() {
    $cached = get_transient( spm_get_report_cache_key() );
    return is_array( $cached ) ? $cached : null;
}

function spm_get_stripe_client( $secret ) {
    $autoload = SPM_DIR . 'vendor/autoload.php';
    if ( ! file_exists( $autoload ) ) {
        return new WP_Error( 'spm_missing_sdk', 'Stripe SDK is missing from the plugin install.' );
    }

    require_once $autoload;

    try {
        return new \Stripe\StripeClient( $secret );
    } catch ( \Throwable $throwable ) {
        return new WP_Error( 'spm_stripe_client', $throwable->getMessage() );
    }
}

function spm_initialize_report_state() {
    return [
        'stage'             => 'customers',
        'customers_cursor'  => '',
        'charges_cursor'    => '',
        'invoices_cursor'   => '',
        'customers'         => [],
        'id_to_alias'       => [],
        'life_totals'       => [],
        'last_paid'         => [],
        'overdue_ids'       => [],
        'customer_rows'     => 0,
        'charge_rows'       => 0,
        'invoice_rows'      => 0,
        'customer_pages'    => 0,
        'charge_pages'      => 0,
        'invoice_pages'     => 0,
        'package'           => null,
        'created_at'        => time(),
    ];
}

function spm_get_customer_alias( $customer ) {
    $email = isset( $customer->email ) && is_scalar( $customer->email ) ? trim( (string) $customer->email ) : '';
    $name  = isset( $customer->name ) && is_scalar( $customer->name ) ? trim( (string) $customer->name ) : '';

    $alias = strtolower( $email !== '' ? $email : $name );
    if ( $alias === '' && isset( $customer->id ) && is_scalar( $customer->id ) ) {
        $alias = strtolower( trim( (string) $customer->id ) );
    }

    return $alias;
}

function spm_get_customer_mrr_cents( $customer ) {
    $mrr_cents = 0;
    $subscriptions = [];

    if ( isset( $customer->subscriptions ) && isset( $customer->subscriptions->data ) && is_array( $customer->subscriptions->data ) ) {
        $subscriptions = $customer->subscriptions->data;
    }

    foreach ( $subscriptions as $subscription ) {
        if ( ! is_object( $subscription ) || ( $subscription->status ?? '' ) !== 'active' ) {
            continue;
        }

        $items = [];
        if ( isset( $subscription->items ) && isset( $subscription->items->data ) && is_array( $subscription->items->data ) ) {
            $items = $subscription->items->data;
        }

        foreach ( $items as $item ) {
            if ( ! is_object( $item ) || ! isset( $item->plan ) || ! is_object( $item->plan ) ) {
                continue;
            }

            $quantity = isset( $item->quantity ) && is_numeric( $item->quantity ) ? (int) $item->quantity : 1;
            $amount   = isset( $item->plan->amount ) && is_numeric( $item->plan->amount ) ? (int) $item->plan->amount : 0;
            $interval = isset( $item->plan->interval ) && is_scalar( $item->plan->interval ) ? (string) $item->plan->interval : '';
            $line_total = $quantity * $amount;

            $mrr_cents += ( 'year' === $interval ) ? (int) round( $line_total / 12 ) : $line_total;
        }
    }

    return $mrr_cents;
}

function spm_merge_customer_batch_record( &$state, $customer ) {
    if ( ! is_object( $customer ) ) {
        return;
    }

    $alias = spm_get_customer_alias( $customer );
    if ( '' === $alias ) {
        return;
    }

    $name = isset( $customer->name ) && is_scalar( $customer->name ) && trim( (string) $customer->name ) !== ''
        ? trim( (string) $customer->name )
        : '(No name)';
    $email = isset( $customer->email ) && is_scalar( $customer->email ) && trim( (string) $customer->email ) !== ''
        ? trim( (string) $customer->email )
        : '(No email)';

    if ( ! isset( $state['customers'][ $alias ] ) ) {
        $state['customers'][ $alias ] = [
            'id'        => $alias,
            'name'      => $name,
            'email'     => $email,
            'mrr_cents' => 0,
        ];
    } else {
        if ( '(No name)' === $state['customers'][ $alias ]['name'] && '(No name)' !== $name ) {
            $state['customers'][ $alias ]['name'] = $name;
        }
        if ( '(No email)' === $state['customers'][ $alias ]['email'] && '(No email)' !== $email ) {
            $state['customers'][ $alias ]['email'] = $email;
        }
    }

    $state['customers'][ $alias ]['mrr_cents'] += spm_get_customer_mrr_cents( $customer );

    if ( isset( $customer->id ) && is_scalar( $customer->id ) ) {
        $state['id_to_alias'][ (string) $customer->id ] = $alias;
    }
}

function spm_fetch_collection_batch( $service, $params ) {
    $collection = $service->all( $params );
    $items = ( isset( $collection->data ) && is_array( $collection->data ) ) ? $collection->data : [];
    $has_more = ! empty( $collection->has_more );
    $last_id = '';

    if ( ! empty( $items ) ) {
        $last_item = end( $items );
        if ( is_object( $last_item ) && isset( $last_item->id ) && is_scalar( $last_item->id ) ) {
            $last_id = (string) $last_item->id;
        }
    }

    return [
        'items'    => $items,
        'has_more' => $has_more && '' !== $last_id,
        'last_id'  => $last_id,
    ];
}

function spm_process_customers_batch( &$state, $stripe ) {
    $params = [
        'limit'  => 100,
        'expand' => [ 'data.subscriptions' ],
    ];

    if ( ! empty( $state['customers_cursor'] ) ) {
        $params['starting_after'] = $state['customers_cursor'];
    }

    $batch = spm_fetch_collection_batch( $stripe->customers, $params );
    foreach ( $batch['items'] as $customer ) {
        $state['customer_rows']++;
        spm_merge_customer_batch_record( $state, $customer );
    }

    $state['customer_pages']++;

    if ( $batch['has_more'] ) {
        $state['customers_cursor'] = $batch['last_id'];
        return;
    }

    $state['stage'] = 'charges';
}

function spm_process_charges_batch( &$state, $stripe ) {
    $params = [
        'limit' => 100,
    ];

    if ( ! empty( $state['charges_cursor'] ) ) {
        $params['starting_after'] = $state['charges_cursor'];
    }

    $batch = spm_fetch_collection_batch( $stripe->charges, $params );
    foreach ( $batch['items'] as $charge ) {
        if ( ! is_object( $charge ) ) {
            continue;
        }

        $state['charge_rows']++;

        $customer_id = isset( $charge->customer ) && is_scalar( $charge->customer ) ? (string) $charge->customer : '';
        if ( '' === $customer_id || ! isset( $state['id_to_alias'][ $customer_id ] ) ) {
            continue;
        }

        $status = isset( $charge->status ) && is_scalar( $charge->status ) ? (string) $charge->status : '';
        if ( 'succeeded' !== $status || empty( $charge->paid ) ) {
            continue;
        }

        $alias   = $state['id_to_alias'][ $customer_id ];
        $amount  = isset( $charge->amount ) && is_numeric( $charge->amount ) ? (int) $charge->amount : 0;
        $created = isset( $charge->created ) && is_numeric( $charge->created ) ? (int) $charge->created : 0;

        $state['life_totals'][ $alias ] = ( $state['life_totals'][ $alias ] ?? 0 ) + $amount;
        $state['last_paid'][ $alias ] = max( $state['last_paid'][ $alias ] ?? 0, $created );
    }

    $state['charge_pages']++;

    if ( $batch['has_more'] ) {
        $state['charges_cursor'] = $batch['last_id'];
        return;
    }

    $state['stage'] = 'invoices';
}

function spm_process_invoices_batch( &$state, $stripe ) {
    $params = [
        'limit'  => 100,
        'status' => 'open',
    ];

    if ( ! empty( $state['invoices_cursor'] ) ) {
        $params['starting_after'] = $state['invoices_cursor'];
    }

    $batch = spm_fetch_collection_batch( $stripe->invoices, $params );
    foreach ( $batch['items'] as $invoice ) {
        if ( ! is_object( $invoice ) ) {
            continue;
        }

        $state['invoice_rows']++;

        $customer_id = isset( $invoice->customer ) && is_scalar( $invoice->customer ) ? (string) $invoice->customer : '';
        if ( '' === $customer_id || ! isset( $state['id_to_alias'][ $customer_id ] ) ) {
            continue;
        }

        $alias = $state['id_to_alias'][ $customer_id ];
        $due   = isset( $invoice->due_date ) && is_numeric( $invoice->due_date )
            ? (int) $invoice->due_date
            : ( isset( $invoice->next_payment_attempt ) && is_numeric( $invoice->next_payment_attempt ) ? (int) $invoice->next_payment_attempt : 0 );

        if ( $due > 0 && $due < time() ) {
            $state['overdue_ids'][ $alias ] = true;
        }
    }

    $state['invoice_pages']++;

    if ( $batch['has_more'] ) {
        $state['invoices_cursor'] = $batch['last_id'];
        return;
    }

    $state['stage'] = 'matching';
}

function spm_build_site_matches( $customers ) {
    global $wpdb;

    $rows = $wpdb->get_results( "SELECT url, name FROM {$wpdb->prefix}mainwp_wp" );
    $sites = is_array( $rows ) ? array_column( $rows, 'name', 'url' ) : [];
    $matched_sites = [];

    foreach ( $sites as $site_url => $title ) {
        $host = parse_url( $site_url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            continue;
        }

        $domain = preg_replace( '/^www\./', '', strtolower( $host ) );
        if ( '' === $domain ) {
            continue;
        }

        foreach ( $customers as $alias => $customer ) {
            $haystack = strtolower( ( $customer['email'] ?? '' ) . ' ' . ( $customer['name'] ?? '' ) );
            if ( false !== strpos( $haystack, $domain ) ) {
                $matched_sites[ $site_url ] = $alias;
                break;
            }
        }
    }

    return [
        'sites'         => $sites,
        'matched_sites' => $matched_sites,
        'matched_custs' => spm_build_matched_customer_index( $matched_sites ),
    ];
}

function spm_build_matched_customer_index( $matched_sites ) {
    $matched_customers = [];

    foreach ( $matched_sites as $site_url => $alias ) {
        if ( ! isset( $matched_customers[ $alias ] ) ) {
            $matched_customers[ $alias ] = [];
        }
        $matched_customers[ $alias ][] = $site_url;
    }

    return $matched_customers;
}

function spm_finalize_report_state( $state ) {
    $customers = [];
    $overdue_ids = isset( $state['overdue_ids'] ) && is_array( $state['overdue_ids'] ) ? $state['overdue_ids'] : [];

    foreach ( $state['customers'] as $alias => $customer ) {
        $total_paid = $state['life_totals'][ $alias ] ?? 0;
        $last_paid  = $state['last_paid'][ $alias ] ?? 0;

        if ( ( time() - $last_paid ) / DAY_IN_SECONDS > SPM_OVERDUE_DAYS ) {
            $overdue_ids[ $alias ] = true;
        }

        $customers[ $alias ] = [
            'id'        => $alias,
            'name'      => $customer['name'] ?? '(No name)',
            'email'     => $customer['email'] ?? '(No email)',
            'total'     => $total_paid / 100,
            'mrr'       => round( ( (int) ( $customer['mrr_cents'] ?? 0 ) ) / 100, 2 ),
            'last_paid' => $last_paid,
        ];
    }

    $matches = spm_build_site_matches( $customers );

    return [
        'customers'     => $customers,
        'overdue_ids'   => $overdue_ids,
        'sites'         => $matches['sites'],
        'matched_sites' => $matches['matched_sites'],
        'matched_custs' => $matches['matched_custs'],
    ];
}

function spm_process_report_state_batch( &$state, $stripe ) {
    switch ( $state['stage'] ) {
        case 'customers':
            spm_process_customers_batch( $state, $stripe );
            break;

        case 'charges':
            spm_process_charges_batch( $state, $stripe );
            break;

        case 'invoices':
            spm_process_invoices_batch( $state, $stripe );
            break;

        case 'matching':
            $state['package'] = spm_finalize_report_state( $state );
            $state['stage'] = 'done';
            break;
    }
}

function spm_get_report_progress_payload( $state ) {
    $customer_total = isset( $state['customers'] ) && is_array( $state['customers'] ) ? count( $state['customers'] ) : 0;

    switch ( $state['stage'] ) {
        case 'customers':
            return [
                'progress_pct' => min( 30, max( 5, (int) $state['customer_pages'] * 8 ) ),
                'status_text'  => 'Fetching Stripe customers in batches...',
                'counts_text'  => 'Fetched ' . number_format_i18n( (int) $state['customer_rows'] ) . ' customer records; ' . number_format_i18n( $customer_total ) . ' unique customers so far.',
            ];

        case 'charges':
            return [
                'progress_pct' => min( 65, 30 + ( (int) $state['charge_pages'] * 6 ) ),
                'status_text'  => 'Processing Stripe charges...',
                'counts_text'  => 'Processed ' . number_format_i18n( (int) $state['charge_rows'] ) . ' charge records.',
            ];

        case 'invoices':
            return [
                'progress_pct' => min( 90, 65 + ( (int) $state['invoice_pages'] * 10 ) ),
                'status_text'  => 'Checking open Stripe invoices...',
                'counts_text'  => 'Processed ' . number_format_i18n( (int) $state['invoice_rows'] ) . ' open invoice records.',
            ];

        case 'matching':
            return [
                'progress_pct' => 95,
                'status_text'  => 'Matching Stripe customers to MainWP sites...',
                'counts_text'  => 'Matching ' . number_format_i18n( $customer_total ) . ' customers to MainWP child sites.',
            ];

        case 'done':
            $package = isset( $state['package'] ) && is_array( $state['package'] ) ? $state['package'] : [];
            $matched_count = isset( $package['matched_sites'] ) && is_array( $package['matched_sites'] ) ? count( $package['matched_sites'] ) : 0;
            return [
                'progress_pct' => 100,
                'status_text'  => 'Payments report ready.',
                'counts_text'  => 'Matched ' . number_format_i18n( $matched_count ) . ' MainWP site' . ( 1 === $matched_count ? '' : 's' ) . '.',
            ];
    }

    return [
        'progress_pct' => 0,
        'status_text'  => 'Preparing report...',
        'counts_text'  => 'Initializing batch state.',
    ];
}

/* -------------------------------------------------------------------------
 * 3. BLOCKING CACHE BUILDER
 * ---------------------------------------------------------------------- */

function spm_get_cached_report( string $secret, bool $force = false ) {
    if ( ! $force ) {
        $cached = spm_get_cached_report_snapshot();
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }

    $stripe = spm_get_stripe_client( $secret );
    if ( is_wp_error( $stripe ) ) {
        return $stripe;
    }

    try {
        set_time_limit( 300 );
        ignore_user_abort( true );

        $state = spm_initialize_report_state();
        while ( 'done' !== $state['stage'] ) {
            spm_process_report_state_batch( $state, $stripe );
        }

        $package = isset( $state['package'] ) && is_array( $state['package'] ) ? $state['package'] : [];
        set_transient( spm_get_report_cache_key(), $package, SPM_CACHE_MINS * MINUTE_IN_SECONDS );
        return $package;
    } catch ( \Throwable $throwable ) {
        return new WP_Error( 'spm_stripe', $throwable->getMessage() );
    }
}

/* -------------------------------------------------------------------------
 * 4. AJAX BATCH LOADER
 * ---------------------------------------------------------------------- */

function spm_ajax_process_report_batch() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'You do not have permission to view this dashboard.' ], 403 );
    }

    check_ajax_referer( SPM_Admin_Dashboard_View::BATCH_NONCE_ACTION, 'nonce' );

    $secret = get_option( 'spm_stripe_secret_key', '' );
    if ( empty( $secret ) ) {
        wp_send_json_error( [ 'message' => 'Stripe secret key is missing.' ], 400 );
    }

    $request_refresh = isset( $_POST['refresh'] ) && '1' === wp_unslash( $_POST['refresh'] );
    $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';

    try {
        if ( '' === $job_id && ! $request_refresh ) {
            $cached = spm_get_cached_report_snapshot();
            if ( is_array( $cached ) ) {
                $progress = [
                    'progress_pct' => 100,
                    'status_text'  => 'Payments report ready.',
                    'counts_text'  => 'Loaded from the cached Stripe report.',
                ];

                wp_send_json_success(
                    [
                        'done'         => true,
                        'job_id'       => '',
                        'progress_pct' => $progress['progress_pct'],
                        'status_text'  => $progress['status_text'],
                        'counts_text'  => $progress['counts_text'],
                        'html'         => SPM_Admin_Dashboard_View::get_report_html( $cached ),
                    ]
                );
            }
        }

        if ( '' === $job_id ) {
            $job_id = md5( wp_generate_uuid4() . '|' . microtime( true ) . '|' . wp_rand() );
            $state = spm_initialize_report_state();
        } else {
            $state = get_transient( spm_get_report_job_key( $job_id ) );
            if ( ! is_array( $state ) ) {
                wp_send_json_error( [ 'message' => 'The Payments Monitor batch state expired. Reload the page and try again.' ], 410 );
            }
        }

        $stripe = spm_get_stripe_client( $secret );
        if ( is_wp_error( $stripe ) ) {
            wp_send_json_error( [ 'message' => $stripe->get_error_message() ], 500 );
        }

        set_time_limit( 120 );
        ignore_user_abort( true );

        spm_process_report_state_batch( $state, $stripe );
        $progress = spm_get_report_progress_payload( $state );

        if ( 'done' === $state['stage'] ) {
            $package = isset( $state['package'] ) && is_array( $state['package'] ) ? $state['package'] : [];
            set_transient( spm_get_report_cache_key(), $package, SPM_CACHE_MINS * MINUTE_IN_SECONDS );
            delete_transient( spm_get_report_job_key( $job_id ) );

            wp_send_json_success(
                [
                    'done'         => true,
                    'job_id'       => $job_id,
                    'progress_pct' => 100,
                    'status_text'  => $progress['status_text'],
                    'counts_text'  => $progress['counts_text'],
                    'html'         => SPM_Admin_Dashboard_View::get_report_html( $package ),
                ]
            );
        }

        set_transient( spm_get_report_job_key( $job_id ), $state, HOUR_IN_SECONDS );

        wp_send_json_success(
            [
                'done'         => false,
                'job_id'       => $job_id,
                'progress_pct' => $progress['progress_pct'],
                'status_text'  => $progress['status_text'],
                'counts_text'  => $progress['counts_text'],
            ]
        );
    } catch ( \Throwable $throwable ) {
        if ( '' !== $job_id ) {
            delete_transient( spm_get_report_job_key( $job_id ) );
        }

        wp_send_json_error( [ 'message' => $throwable->getMessage() ], 500 );
    }
}
add_action( 'wp_ajax_spm_process_report_batch', 'spm_ajax_process_report_batch' );
