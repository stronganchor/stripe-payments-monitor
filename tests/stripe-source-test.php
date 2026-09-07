<?php
/** Run with: php tests/stripe-source-test.php (no credentials, WordPress, or network). */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public $code;
    public $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $name, $default = '' ) { return $GLOBALS['test_key']; }
function spm_get_stripe_client( $key ) { return $GLOBALS['test_stripe']; }
require dirname( __DIR__ ) . '/includes/stripe-source.php';

class SPM_Test_Service {
    public $calls = array();
    public $list;
    public $get;
    public $lines;
    public function __construct( $list = null, $get = null, $lines = null ) { $this->list = $list; $this->get = $get; $this->lines = $lines; }
    public function all( $params ) {
        $this->calls[] = array( 'all', $params );
        return $this->list ? call_user_func( $this->list, $params ) : page( array() );
    }
    public function retrieve( $id, $params ) {
        $this->calls[] = array( 'retrieve', $id );
        if ( ! $this->get ) { throw new RuntimeException( 'Unexpected exact read' ); }
        return call_user_func( $this->get, $id );
    }
    public function allLines( $id, $params ) {
        $this->calls[] = array( 'allLines', $id, $params );
        if ( ! $this->lines ) { throw new RuntimeException( 'Unexpected invoice lines read' ); }
        return call_user_func( $this->lines, $id, $params );
    }
}
function page( $rows, $more = false ) { return (object) array( 'data' => $rows, 'has_more' => $more ); }
function obj( $data ) { return json_decode( json_encode( $data ) ); }
function check( $condition, $description ) {
    if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $description ); }
    ++$GLOBALS['checks'];
}
function indexed( $rows ) { return array_column( $rows, null, 'source_ref' ); }
function item( $id, $interval = 'month', $start = 100, $end = 200 ) {
    return obj( array( 'id' => $id, 'quantity' => 1, 'current_period_start' => $start, 'current_period_end' => $end,
        'price' => array( 'id' => 'price_' . $id, 'unit_amount' => 1000, 'currency' => 'usd', 'billing_scheme' => 'per_unit',
            'recurring' => array( 'interval' => $interval, 'interval_count' => 1, 'usage_type' => 'licensed' ) ) ) );
}
function subscription( $id, $status, $customer, $items = null ) {
    return obj( array( 'id' => $id, 'status' => $status, 'customer' => $customer, 'items' => $items ?: page( array( item( 'si_' . $id ) ) ),
        'latest_invoice' => null, 'cancel_at_period_end' => false, 'livemode' => true ) );
}
function invoice_fixture( $id, $subscription_id, $status, $created, $payment_intent = '' ) {
    return obj( array( 'id' => $id, 'parent' => array( 'subscription_details' => array( 'subscription' => $subscription_id ) ),
        'customer' => 'cus_one', 'customer_name' => 'Same client', 'customer_email' => 'same@example.invalid',
        'status' => $status, 'amount_remaining' => 'paid' === $status ? 0 : 1000, 'amount_paid' => 'paid' === $status ? 1000 : 0,
        'currency' => 'usd', 'due_date' => null, 'created' => $created, 'attempted' => true, 'attempt_count' => 2,
        'next_payment_attempt' => null, 'livemode' => true,
        'lines' => page( array( obj( array( 'id' => 'il_' . $id, 'period' => array( 'start' => 100, 'end' => 200 ),
            'parent' => array( 'subscription_item_details' => array( 'subscription' => $subscription_id, 'proration' => false ) ) ) ) ) ),
        'payments' => page( $payment_intent ? array( obj( array( 'id' => 'inpay_' . $id, 'payment' => array( 'type' => 'payment_intent', 'payment_intent' => $payment_intent ) ) ) ) : array() ) ) );
}
function charge_fixture( $id, $intent, $refunded = 0, $status = 'succeeded' ) {
    return obj( array( 'id' => $id, 'customer' => array( 'id' => 'cus_one', 'name' => 'Same client', 'email' => 'same@example.invalid' ),
        'payment_intent' => $intent, 'status' => $status, 'paid' => 'succeeded' === $status, 'captured' => 'succeeded' === $status,
        'amount' => 1000, 'amount_captured' => 'succeeded' === $status ? 1000 : 0, 'amount_refunded' => $refunded,
        'currency' => 'usd', 'created' => time() - 3600, 'livemode' => true ) );
}
function client_fixture( $subscriptions = array(), $invoices = array(), $charges = array() ) {
    return (object) array(
        'subscriptions' => new SPM_Test_Service( function( $params ) use ( $subscriptions ) { return page( $subscriptions ); } ),
        'invoices' => new SPM_Test_Service( function( $params ) use ( $invoices ) { return page( $invoices ); } ),
        'charges' => new SPM_Test_Service( function( $params ) use ( $charges ) { return page( $charges ); } ),
        'subscriptionItems' => new SPM_Test_Service(), 'invoicePayments' => new SPM_Test_Service(),
        'customers' => new SPM_Test_Service( null, function( $id ) { return obj( array( 'id' => $id, 'name' => 'Exact customer', 'email' => 'exact@example.invalid' ) ); } ),
    );
}
$checks = 0;
$test_key = 'fake-key-never-used-on-network';
$customer = array( 'id' => 'cus_one', 'name' => 'Same client', 'email' => 'same@example.invalid' );
$old = time() - 600 * 86400;
$active = subscription( 'sub_active', 'active', $customer );
$canceled = subscription( 'sub_canceled', 'canceled', $customer );
$recent = invoice_fixture( 'in_recent', 'sub_active', 'paid', time(), 'pi_recent' );
$old_open = invoice_fixture( 'in_old_open', 'sub_canceled', 'open', $old, 'pi_old_open' );
$old_uncollectible = invoice_fixture( 'in_old_uncollectible', 'sub_canceled', 'uncollectible', $old );
$old_paid = invoice_fixture( 'in_old_paid', 'sub_canceled', 'paid', $old, 'pi_old_paid' );
$test_stripe = client_fixture( array( $active, $canceled ), array( $recent, $old_open, $old_uncollectible, $old_paid ), array(
    charge_fixture( 'ch_recent', 'pi_recent' ), charge_fixture( 'ch_refund', 'pi_old_paid', 400 ),
    charge_fixture( 'ch_failure', 'pi_old_open', 0, 'failed' ), charge_fixture( 'ch_oneoff', 'pi_oneoff' ),
) );
$result = SPM_Stripe_Source::collect();
check( ! is_wp_error( $result ), 'baseline collector succeeds' );
$records = indexed( $result['records'] );
$payments = indexed( $result['payments'] );
$invoices = indexed( $result['invoices'] );
check( 2 === count( $records ), 'same customer keeps separate subscription identities' );
check( 'canceled' === $records['sub_canceled']['status'], 'canceled subscription remains visible' );
check( 'all' === $test_stripe->subscriptions->calls[0][1]['status'], 'all subscription statuses explicitly requested' );
check( 100 === $records['sub_active']['current_period_start'] && 200 === $records['sub_active']['current_period_end'], 'post-Basil item periods are read' );
check( 1000 === $records['sub_active']['amount_cents'], 'fixed recurring item total is correct' );
check( isset( $invoices['in_old_open'], $invoices['in_old_uncollectible'] ), 'all old outstanding balances are retained' );
check( 0 === $invoices['in_old_open']['due_at'] && 1000 === $invoices['in_old_open']['amount_remaining'], 'null due date does not hide an unpaid invoice' );
check( ! isset( $invoices['in_old_paid'] ), 'old paid invoices outside report lookback are omitted' );
check( 'stripe:sub_active' === $payments['ch_recent']['record_id'], 'modern invoice payment proves subscription association' );
check( 'stripe:sub_canceled' === $payments['ch_refund']['record_id'], 'recent payment can map to old paid invoice and canceled subscription' );
check( 'refunded' === $payments['ch_refund']['status'] && 400 === $payments['ch_refund']['refunded_cents'], 'partial refunds are explicit' );
check( 'failed' === $payments['ch_failure']['status'] && 0 === $payments['ch_failure']['paid_at'], 'failed attempts do not masquerade as paid' );
check( '' === $payments['ch_oneoff']['record_id'] && 'cus_one' === $payments['ch_oneoff']['customer_ref'], 'oneoff does not inherit latest subscription of same customer' );
check( 100 === $payments['ch_recent']['period_start'] && 200 === $payments['ch_recent']['period_end'], 'service period comes from invoice line' );
check( isset( $test_stripe->charges->calls[0][1]['created']['gte'] ), 'charge window is bounded to 400 days' );

// Multi-year subscriptions can legitimately still be in a period invoiced over 400 days ago.
$long_term = subscription( 'sub_multiyear', 'active', $customer );
$long_term->latest_invoice = 'in_multiyear';
$long_invoice = invoice_fixture( 'in_multiyear', 'sub_multiyear', 'paid', $old );
$test_stripe = client_fixture( array( $long_term ), array( $long_invoice ) );
$result = SPM_Stripe_Source::collect();
check( ! is_wp_error( $result ) && isset( indexed( $result['invoices'] )['in_multiyear'] ), 'latest invoice retained beyond400day window for multi-year period verification' );

// Pagination across subscriptions, embedded items, invoice pages, invoice payments, and lines.
$paged_sub = subscription( 'sub_paged', 'active', 'cus_missing', page( array( item( 'si_first' ) ), true ) );
$paged_invoice = invoice_fixture( 'in_paged', 'sub_paged', 'paid', time() );
$paged_invoice->payments = page( array( obj( array( 'id' => 'inpay_first', 'payment' => array( 'type' => 'payment_intent', 'payment_intent' => 'pi_first' ) ) ) ), true );
$paged_invoice->lines = page( $paged_invoice->lines->data, true );
$test_stripe = client_fixture( array(), array(), array( charge_fixture( 'ch_paged', 'pi_second' ) ) );
$test_stripe->subscriptions = new SPM_Test_Service( function( $params ) use ( $paged_sub, $canceled ) {
    return isset( $params['starting_after'] ) ? page( array( $canceled ) ) : page( array( $paged_sub ), true );
} );
$test_stripe->subscriptionItems = new SPM_Test_Service( function( $params ) {
    check( 'sub_paged' === $params['subscription'] && 'si_first' === $params['starting_after'], 'subscription item cursor and parent are exact' );
    return page( array( item( 'si_second' ) ) );
} );
$test_stripe->invoices = new SPM_Test_Service( function( $params ) use ( $paged_invoice, $old_open ) {
    return isset( $params['starting_after'] ) ? page( array( $old_open ) ) : page( array( $paged_invoice ), true );
}, null, function( $id, $params ) {
    check( 'in_paged' === $id && 'il_in_paged' === $params['starting_after'], 'invoice line cursor and parent are exact' );
    return page( array( obj( array( 'id' => 'il_extra', 'period' => array( 'start' => 50, 'end' => 75 ),
        'parent' => array( 'subscription_item_details' => array( 'subscription' => 'sub_paged', 'proration' => true ) ) ) ) ) );
} );
$test_stripe->invoicePayments = new SPM_Test_Service( function( $params ) {
    check( 'in_paged' === $params['invoice'] && 'inpay_first' === $params['starting_after'], 'invoice payment cursor and parent are exact' );
    return page( array( obj( array( 'id' => 'inpay_second', 'payment' => array( 'payment_intent' => 'pi_second' ) ) ) ) );
} );
$result = SPM_Stripe_Source::collect();
check( ! is_wp_error( $result ), 'paginated collector succeeds' );
$records = indexed( $result['records'] );
$payments = indexed( $result['payments'] );
check( 2000 === $records['sub_paged']['amount_cents'], 'additional embedded item page contributes to amount' );
check( 1 === count( $test_stripe->customers->calls ) && 'Exact customer' === $records['sub_paged']['name'], 'missing expansion uses a bounded exact customer read' );
check( 'stripe:sub_paged' === $payments['ch_paged']['record_id'], 'additional invoice payment page establishes payment identity' );
check( 100 === $payments['ch_paged']['period_start'], 'proration line cannot replace ordinary service period' );

// Mixed schedules must not turn into a fabricated amount or renewal date.
$mixed = subscription( 'sub_mixed', 'active', $customer, page( array( item( 'si_month' ), item( 'si_year', 'year', 100, 400 ) ) ) );
$test_stripe = client_fixture( array( $mixed ) );
$result = SPM_Stripe_Source::collect();
check( $result['records'][0]['mixed_schedule'] && null === $result['records'][0]['amount_cents'], 'mixed schedules preserve unknown total' );
check( '' === $result['records'][0]['interval'] && 0 === $result['records'][0]['current_period_end'], 'mixed intervals and periods are not guessed' );
check( 2 === count( $result['records'][0]['items'] ), 'original item schedule metadata remains available' );

// A single charge allocated to two subscriptions cannot be credited to either alone.
$a = invoice_fixture( 'in_a', 'sub_a', 'paid', time(), 'pi_shared' );
$b = invoice_fixture( 'in_b', 'sub_b', 'paid', time(), 'pi_shared' );
$test_stripe = client_fixture( array(), array( $a, $b ), array( charge_fixture( 'ch_shared', 'pi_shared' ) ) );
$result = SPM_Stripe_Source::collect();
check( '' === $result['payments'][0]['record_id'] && 2 === count( $result['payments'][0]['invoice_refs'] ), 'multi-invoice charge preserves ambiguity' );

// Legacy fields still resolve with an old account API response.
$legacy = subscription( 'sub_legacy', 'active', $customer );
unset( $legacy->items->data[0]->current_period_start, $legacy->items->data[0]->current_period_end );
$legacy->current_period_start = 111;
$legacy->current_period_end = 222;
$legacy_invoice = invoice_fixture( 'in_legacy', 'sub_legacy', 'paid', time() );
unset( $legacy_invoice->parent, $legacy_invoice->payments );
$legacy_invoice->subscription = 'sub_legacy';
$legacy_invoice->charge = 'ch_legacy';
$test_stripe = client_fixture( array( $legacy ), array( $legacy_invoice ), array( charge_fixture( 'ch_legacy', '' ) ) );
$result = SPM_Stripe_Source::collect();
check( 111 === $result['records'][0]['current_period_start'], 'legacy subscription period still works' );
check( 'stripe:sub_legacy' === $result['payments'][0]['record_id'], 'legacy invoice subscription and charge fields still work' );

$uncaptured = charge_fixture( 'ch_hold', '' );
$uncaptured->captured = false;
$uncaptured->amount_captured = 0;
$test_stripe = client_fixture( array(), array(), array( $uncaptured ) );
$result = SPM_Stripe_Source::collect();
check( 'pending' === $result['payments'][0]['status'] && 0 === $result['payments'][0]['paid_at'], 'authorization hold is not paid revenue' );
$partial = charge_fixture( 'ch_partial_capture', '' );
$partial->amount_captured = 600;
$test_stripe = client_fixture( array(), array(), array( $partial ) );
$result = SPM_Stripe_Source::collect();
check( 600 === $result['payments'][0]['amount_cents'] && 1000 === $result['payments'][0]['authorized_cents'], 'partial capture counts only captured revenue' );

// A broken pagination response must fail the entire snapshot, not clear flags.
$test_stripe = client_fixture();
$test_stripe->subscriptions = new SPM_Test_Service( function( $params ) { return page( array(), true ); } );
$result = SPM_Stripe_Source::collect();
check( is_wp_error( $result ) && 'spm_stripe_incomplete' === $result->code, 'empty page with has_more is rejected' );
$test_stripe->subscriptions = new SPM_Test_Service( function( $params ) use ( $active ) { return page( array( $active ), true ); } );
$result = SPM_Stripe_Source::collect();
check( is_wp_error( $result ) && 'spm_stripe_incomplete' === $result->code, 'repeating cursor/data is rejected' );
$test_stripe->subscriptions = new SPM_Test_Service( function( $params ) use ( $active ) { return (object) array( 'data' => array( $active ) ); } );
$result = SPM_Stripe_Source::collect();
check( is_wp_error( $result ) && 'spm_stripe_incomplete' === $result->code, 'missing pagination completeness flag is rejected' );
$page_index = 0;
$test_stripe->subscriptions = new SPM_Test_Service( function( $params ) use ( &$page_index, $active ) {
    $row = clone $active;
    $row->id = 'sub_page_' . ++$page_index;
    return page( array( $row ), true );
} );
$result = SPM_Stripe_Source::collect();
check( is_wp_error( $result ) && 'spm_stripe_incomplete' === $result->code && SPM_Stripe_Source::MAX_PAGES === $page_index, 'maximum pages aborts rather than returning a truncated snapshot' );
$test_stripe->subscriptions = new SPM_Test_Service( function( $params ) { throw new RuntimeException( 'SECRET sk_live_should_never_be_echoed' ); } );
$result = SPM_Stripe_Source::collect();
check( is_wp_error( $result ) && false === strpos( $result->message, 'sk_live' ), 'API exception text and credentials are not exposed' );
$test_stripe = new WP_Error( 'raw', 'SECRET sk_live_factory_should_not_echo' );
$result = SPM_Stripe_Source::collect();
check( is_wp_error( $result ) && false === strpos( $result->message, 'sk_live' ), 'factory error text and credentials are not exposed' );
$test_key = '';
$result = SPM_Stripe_Source::collect();
check( is_wp_error( $result ) && 'spm_stripe_not_configured' === $result->code, 'unconfigured source is explicit' );
echo "Stripe source: {$checks} checks passed.\n";
