<?php
/** Standalone reconciliation regressions: php tests/monitor-test.php */
define( 'ABSPATH', __DIR__ );
define( 'DB_NAME', 'monitor_test_database' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class Monitor_Test_DB {
	public $prefix = 'test_';
	public $busy = false;
	public $released = 0;
	public function prepare( $sql, $name ) { return str_replace( '%s', "'" . $name . "'", $sql ); }
	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) { $this->released++; return '1'; }
		return $this->busy ? '0' : '1';
	}
}
class SPM_Stripe_Source {
	public static $snapshot;
	public static function collect() { return self::$snapshot; }
}
class SPM_MoonClerk_Source {
	public static $snapshot;
	public static function collect() { return self::$snapshot; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['monitor_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['monitor_options'][ $key ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_url_raw( $url, $protocols = null ) { return preg_match( '#^https://#', $url ) ? $url : ''; }
function wp_get_current_user() { return (object) array( 'display_name' => 'Test reviewer' ); }
require_once __DIR__ . '/../includes/monitor.php';

$GLOBALS['monitor_options'] = array();
$GLOBALS['wpdb'] = new Monitor_Test_DB();
$GLOBALS['monitor_assertions'] = 0;
$GLOBALS['monitor_failures'] = array();
function monitor_check( $condition, $message ) {
	$GLOBALS['monitor_assertions']++;
	if ( ! $condition ) { $GLOBALS['monitor_failures'][] = $message; }
}
function monitor_empty_state() {
	return array( 'version' => 1, 'records' => array(), 'issues' => array(), 'sources' => array(), 'manual_payments' => array(), 'last_reconciled' => 0 );
}
function monitor_record( $source = 'stripe', $overrides = array() ) {
	return array_replace( array( 'id' => $source . ':plan1', 'source' => $source, 'source_ref' => 'plan1',
		'name' => 'Example client', 'email' => 'client@example.test', 'status' => 'active', 'amount_cents' => 10000,
		'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1,
		'current_period_start' => strtotime( '2026-09-01 UTC' ), 'current_period_end' => strtotime( '2026-10-01 UTC' ),
		'next_payment_attempt' => 0, 'cancel_at_period_end' => false, 'canceled_at' => 0,
		'customer_ref' => 'cus_example', 'url' => 'https://dashboard.stripe.com/subscriptions/plan1' ), $overrides );
}
function monitor_invoice( $overrides = array() ) {
	return array_replace( array( 'id' => 'in_example', 'record_id' => 'stripe:plan1', 'status' => 'open',
		'amount_remaining' => 10000, 'amount_paid' => 0, 'currency' => 'usd', 'attempted' => true,
		'attempt_count' => 1, 'due_at' => 0, 'created' => strtotime( '2026-09-01 UTC' ),
		'period_start' => strtotime( '2026-09-01 UTC' ), 'period_end' => strtotime( '2026-10-01 UTC' ),
		'next_payment_attempt' => strtotime( '2026-09-17 UTC' ), 'url' => 'https://dashboard.stripe.com/invoices/in_example' ), $overrides );
}
function monitor_payment( $source = 'stripe', $overrides = array() ) {
	return array_replace( array( 'id' => $source . ':payment1', 'record_id' => $source . ':plan1', 'source' => $source,
		'status' => 'paid', 'amount_cents' => 10000, 'refunded_cents' => 0, 'currency' => 'usd',
		'paid_at' => strtotime( '2026-09-01 UTC' ), 'period_start' => strtotime( '2026-09-01 UTC' ),
		'period_end' => strtotime( '2026-10-01 UTC' ) ), $overrides );
}
function monitor_snapshot( $records, $invoices = array(), $payments = array() ) {
	return array( 'checked_at' => time(), 'records' => $records, 'invoices' => $invoices, 'payments' => $payments );
}
function monitor_issues( $state, $kind, $status = null ) {
	return array_filter( $state['issues'], static function( $issue ) use ( $kind, $status ) {
		return $kind === $issue['kind'] && ( null === $status || $status === $issue['status'] );
	} );
}
function monitor_first_issue_id( $state, $kind, $status = null ) {
	$issues = monitor_issues( $state, $kind, $status );
	return $issues ? array_key_first( $issues ) : '';
}

$now = strtotime( '2026-09-15 UTC' );

// Expected relationships and human decisions survive provider cancellation/removal.
$state = monitor_empty_state();
$snapshot = monitor_snapshot( array( monitor_record() ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
monitor_check( 'active' === $state['records']['stripe:plan1']['expected'], 'New active plans should become expected relationships.' );
$state['records']['stripe:plan1']['category'] = 'maintenance';
$state['records']['stripe:plan1']['site_url'] = 'https://example.test';
$state['records']['stripe:plan1']['notes'][] = array( 'id' => 'note', 'note' => 'Agreement is still active.' );
$canceled = monitor_snapshot( array( monitor_record( 'stripe', array( 'status' => 'canceled', 'canceled_at' => $now - 100 ) ) ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $canceled );
SPM_Monitor::reconcile_state( $state, 'stripe', $canceled, $now );
monitor_check( 'active' === $state['records']['stripe:plan1']['expected'], 'Provider cancellation must never end the independent expectation.' );
monitor_check( 'maintenance' === $state['records']['stripe:plan1']['category'] && 'https://example.test' === $state['records']['stripe:plan1']['site_url'], 'Provider refresh must preserve manual category and site links.' );
monitor_check( 1 === count( $state['records']['stripe:plan1']['notes'] ), 'Provider refresh must preserve explanatory notes.' );
monitor_check( 1 === count( monitor_issues( $state, 'unexpected_end', 'open' ) ), 'Unexpected cancellation must create an actionable issue.' );
$missing = monitor_snapshot( array() );
SPM_Monitor::merge_snapshot( $state, 'stripe', $missing );
SPM_Monitor::reconcile_state( $state, 'stripe', $missing, $now );
monitor_check( isset( $state['records']['stripe:plan1'] ) && $state['records']['stripe:plan1']['missing'], 'A missing provider row must remain in the master register.' );
monitor_check( 1 === count( monitor_issues( $state, 'unexpected_end', 'open' ) ), 'Missing expected plans must stay flagged.' );
$state['records']['stripe:plan1']['expected'] = 'ended';
SPM_Monitor::reconcile_state( $state, 'stripe', $missing, $now );
monitor_check( 0 === count( monitor_issues( $state, 'unexpected_end', 'open' ) ), 'An explicitly ended expectation should clear the unexpected-end condition.' );
$baseline = monitor_empty_state();
SPM_Monitor::merge_snapshot( $baseline, 'stripe', $canceled );
SPM_Monitor::reconcile_state( $baseline, 'stripe', $canceled, $now );
monitor_check( 'review' === $baseline['records']['stripe:plan1']['expected'] && 1 === count( monitor_issues( $baseline, 'baseline', 'open' ) ), 'Historical cancellations need baseline review, not an assumed active agreement.' );

// Stable invoice identity preserves explanations across retries; money changes reopen.
$state = monitor_empty_state();
$invoice = monitor_invoice();
$snapshot = monitor_snapshot( array( monitor_record() ), array( $invoice ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
$issue_id = monitor_first_issue_id( $state, 'invoice', 'open' );
monitor_check( '' !== $issue_id, 'Failed invoice should create an issue.' );
if ( $issue_id ) {
	$state['issues'][ $issue_id ]['status'] = 'resolved';
	$state['issues'][ $issue_id ]['resolution'] = 'Aaron confirmed the client is replacing its card.';
	$state['issues'][ $issue_id ]['notes'][] = array( 'note' => 'Keep this explanation.' );
	$invoice['next_payment_attempt'] += 3 * DAY_IN_SECONDS;
	$invoice['attempt_count']++;
	$snapshot['invoices'] = array( $invoice );
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now + 60 );
	monitor_check( 'resolved' === $state['issues'][ $issue_id ]['status'], 'A retry timestamp/count change must not reopen a manually explained invoice.' );
	monitor_check( 1 === count( $state['issues'][ $issue_id ]['notes'] ) && false !== strpos( $state['issues'][ $issue_id ]['resolution'], 'Aaron' ), 'Retry refresh must preserve the explanation and notes.' );
	$invoice['amount_remaining'] = 15000;
	$snapshot['invoices'] = array( $invoice );
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now + 120 );
	monitor_check( 'open' === $state['issues'][ $issue_id ]['status'] && 15000 === $state['issues'][ $issue_id ]['amount_cents'], 'A material balance change must reopen the same invoice issue.' );
	$invoice['status'] = 'paid'; $invoice['amount_remaining'] = 0; $invoice['amount_paid'] = 15000;
	$snapshot['invoices'] = array( $invoice );
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now + 180 );
	monitor_check( 'resolved' === $state['issues'][ $issue_id ]['status'] && $state['issues'][ $issue_id ]['auto_resolved'], 'Successful payment must resolve the absent invoice condition.' );
	$invoice['status'] = 'open'; $invoice['amount_remaining'] = 15000;
	$snapshot['invoices'] = array( $invoice );
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now + 240 );
	monitor_check( 'open' === $state['issues'][ $issue_id ]['status'], 'A previously cleared condition that returns must reopen.' );
}

// A newly failed billing period cannot inherit the last period's dismissal.
$state = monitor_empty_state();
$snapshot = monitor_snapshot( array( monitor_record( 'moonclerk', array( 'status' => 'past_due' ) ) ) );
SPM_Monitor::merge_snapshot( $state, 'moonclerk', $snapshot );
SPM_Monitor::reconcile_state( $state, 'moonclerk', $snapshot, $now );
$prior_id = monitor_first_issue_id( $state, 'plan_status', 'open' );
if ( $prior_id ) { $state['issues'][ $prior_id ]['status'] = 'resolved'; $state['issues'][ $prior_id ]['resolution'] = 'Explained first period.'; }
$snapshot['records'][0]['current_period_start'] = strtotime( '2026-10-01 UTC' );
$snapshot['records'][0]['current_period_end'] = strtotime( '2026-11-01 UTC' );
SPM_Monitor::merge_snapshot( $state, 'moonclerk', $snapshot );
SPM_Monitor::reconcile_state( $state, 'moonclerk', $snapshot, strtotime( '2026-10-15 UTC' ) );
$new_id = monitor_first_issue_id( $state, 'plan_status', 'open' );
monitor_check( '' !== $new_id && $new_id !== $prior_id, 'A new failed period must have its own open issue.' );

// An old invoice ending exactly at renewal does not prove a current invoice exists.
$state = monitor_empty_state();
$previous_invoice = monitor_invoice( array( 'status' => 'paid', 'amount_remaining' => 0, 'amount_paid' => 10000,
	'created' => strtotime( '2026-08-01 UTC' ), 'period_start' => strtotime( '2026-08-01 UTC' ), 'period_end' => strtotime( '2026-09-01 UTC' ) ) );
$snapshot = monitor_snapshot( array( monitor_record() ), array( $previous_invoice ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 1 === count( monitor_issues( $state, 'invoice_missing', 'open' ) ), 'A previous invoice with period_end == new period_start must not hide a missing renewal invoice.' );
$snapshot['invoices'][0]['created'] = strtotime( '2026-09-02 UTC' );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 1 === count( monitor_issues( $state, 'invoice_missing', 'open' ) ), 'A late-created invoice for a known old period must not hide a missing current-period invoice.' );
$snapshot['invoices'][] = monitor_invoice( array( 'id' => 'in_current', 'status' => 'paid', 'amount_remaining' => 0 ) );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 0 === count( monitor_issues( $state, 'invoice_missing', 'open' ) ), 'A current-period invoice must clear the missing-invoice condition.' );

// Annual schedules stay healthy after 30 days when their current period is covered.
foreach ( array( 'stripe', 'moonclerk' ) as $source ) {
	$state = monitor_empty_state();
	$start = strtotime( '2026-01-01 UTC' ); $end = strtotime( '2027-01-01 UTC' );
	$record = monitor_record( $source, array( 'interval' => 'year', 'current_period_start' => $start, 'current_period_end' => $end ) );
	$paid = monitor_payment( $source, array( 'paid_at' => $start, 'period_start' => $start, 'period_end' => $end ) );
	$invoice = monitor_invoice( array( 'status' => 'paid', 'amount_remaining' => 0, 'created' => $start, 'period_start' => $start, 'period_end' => $end ) );
	$snapshot = monitor_snapshot( array( $record ), 'stripe' === $source ? array( $invoice ) : array(), array( $paid ) );
	SPM_Monitor::merge_snapshot( $state, $source, $snapshot );
	SPM_Monitor::reconcile_state( $state, $source, $snapshot, $now );
	monitor_check( 0 === count( monitor_issues( $state, 'period_unverified', 'open' ) ) && 0 === count( monitor_issues( $state, 'invoice_missing', 'open' ) ), $source . ' annual payment must not be flagged overdue merely because it is older than 30 days.' );
}

// Partially refunded captures are payment evidence; zero net receipts are not.
foreach ( array(
	array( 'partial refund', array( 'status' => 'refunded', 'captured_cents' => 10000, 'refunded_cents' => 2500 ), true ),
	array( 'full refund', array( 'status' => 'refunded', 'captured_cents' => 10000, 'refunded_cents' => 10000 ), false ),
	array( 'uncaptured charge', array( 'status' => 'pending', 'captured_cents' => 0 ), false ),
	array( 'fully refunded partial capture', array( 'status' => 'refunded', 'captured_cents' => 5000, 'refunded_cents' => 5000 ), false ),
	array( 'remaining partial capture', array( 'status' => 'refunded', 'captured_cents' => 5000, 'refunded_cents' => 2000 ), true ),
	array( 'paid label with zero capture', array( 'status' => 'paid', 'captured_cents' => 0 ), false ),
	array( 'MoonClerk partial refund without capture field', array( 'status' => 'refunded', 'refunded_cents' => 2500 ), true ),
) as $case ) {
	list( $label, $overrides, $should_count ) = $case;
	$source = false !== strpos( $label, 'MoonClerk' ) ? 'moonclerk' : 'stripe';
	$state = monitor_empty_state();
	$paid = monitor_payment( $source, $overrides );
	$snapshot = monitor_snapshot( array( monitor_record( $source ) ), array(), array( $paid ) );
	SPM_Monitor::merge_snapshot( $state, $source, $snapshot );
	monitor_check( $should_count === ( $state['records'][ $source . ':plan1' ]['last_paid'] > 0 ), $label . ' has incorrect last-paid evidence.' );
}

// A new successful MoonClerk payment clears an unverified installment condition.
$state = monitor_empty_state();
$snapshot = monitor_snapshot( array( monitor_record( 'moonclerk' ) ) );
SPM_Monitor::merge_snapshot( $state, 'moonclerk', $snapshot );
SPM_Monitor::reconcile_state( $state, 'moonclerk', $snapshot, $now );
monitor_check( 1 === count( monitor_issues( $state, 'period_unverified', 'open' ) ), 'An active MoonClerk plan without current payment evidence needs review.' );
$snapshot['payments'][] = monitor_payment( 'moonclerk' );
SPM_Monitor::merge_snapshot( $state, 'moonclerk', $snapshot );
SPM_Monitor::reconcile_state( $state, 'moonclerk', $snapshot, $now );
monitor_check( 0 === count( monitor_issues( $state, 'period_unverified', 'open' ) ), 'A current successful MoonClerk payment should clear the unverified-period flag.' );

// Recent ACH/processing attempts are not failed payments; exact invoice identity is required.
foreach ( array( array( 'stripe:in_processing', 'in_processing' ), array( 'in_processing', 'stripe:in_processing' ) ) as $ids ) {
	$state = monitor_empty_state();
	$invoice = monitor_invoice( array( 'id' => $ids[0], 'created' => $now - 3 * DAY_IN_SECONDS, 'due_at' => $now - DAY_IN_SECONDS ) );
	$pending = monitor_payment( 'stripe', array( 'id' => 'stripe:py_processing', 'status' => 'pending', 'created' => $now - 3 * DAY_IN_SECONDS, 'paid_at' => 0, 'captured_cents' => 0, 'invoice_refs' => array( $ids[1] ) ) );
	$snapshot = monitor_snapshot( array( monitor_record() ), array( $invoice ), array( $pending ) );
	SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
	monitor_check( 0 === count( monitor_issues( $state, 'invoice', 'open' ) ) && 0 === count( monitor_issues( $state, 'invoice_pending', 'open' ) ), 'Fresh exact pending attempt suppresses premature failure/overdue alarm for ' . $ids[0] . '.' );
	monitor_check( 0 === $state['records']['stripe:plan1']['last_paid'], 'Pending ACH remains unpaid evidence despite suppression of premature alarm.' );
	$snapshot['payments'][0]['created'] = $now - 8 * DAY_IN_SECONDS;
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
	$id = monitor_first_issue_id( $state, 'invoice_pending', 'open' );
	monitor_check( $id && 'review' === $state['issues'][ $id ]['severity'] && $invoice['created'] === $state['issues'][ $id ]['occurred_at'], 'Stale processing creates a dated review condition without calling it paid or failed.' );
}

$state = monitor_empty_state();
$invoice = monitor_invoice( array( 'id' => 'stripe:in_retry', 'created' => $now - 4 * DAY_IN_SECONDS ) );
$pending = monitor_payment( 'stripe', array( 'id' => 'stripe:py_pending', 'status' => 'pending', 'created' => $now - 2 * DAY_IN_SECONDS, 'paid_at' => 0, 'captured_cents' => 0, 'invoice_refs' => array( 'in_retry' ) ) );
$failure = monitor_payment( 'stripe', array( 'id' => 'stripe:py_failed', 'status' => 'failed', 'created' => $now - DAY_IN_SECONDS, 'paid_at' => 0, 'captured_cents' => 0, 'invoice_refs' => array( 'stripe:in_retry' ) ) );
$snapshot = monitor_snapshot( array( monitor_record() ), array( $invoice ), array( $pending, $failure ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
$id = monitor_first_issue_id( $state, 'invoice', 'open' );
monitor_check( $id && 'action' === $state['issues'][ $id ]['severity'], 'Newer failed attempt beats older pending receipt for the same invoice.' );
$snapshot['payments'][0]['created'] = $now - HOUR_IN_SECONDS;
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 0 === count( monitor_issues( $state, 'invoice', 'open' ) ), 'Newer pending retry suppresses the older failed-attempt alarm.' );
$snapshot['payments'][1]['created'] = $snapshot['payments'][0]['created'];
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 1 === count( monitor_issues( $state, 'invoice', 'open' ) ), 'When attempt timestamps tie, a failed result cannot be hidden by pending.' );
foreach ( array(
	array( 'invoice_refs' => array( 'in_unrelated' ) ),
	array( 'invoice_refs' => array() ),
	array( 'created' => 0, 'paid_at' => 0 ),
	array( 'amount_cents' => 1000 ),
	array( 'currency' => 'eur' ),
	array( 'source' => 'moonclerk' ),
) as $overrides ) {
	$snapshot['payments'] = array( array_replace( $pending, $overrides ) );
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
	monitor_check( 1 === count( monitor_issues( $state, 'invoice', 'open' ) ), 'Unrelated, undated, partial, wrong-currency or wrong-source pending evidence must not hide this invoice balance.' );
}
$snapshot['payments'] = array( array_replace( $pending, array( 'created' => $now - 8 * DAY_IN_SECONDS ) ) );
$snapshot['invoices'][0]['attempted'] = false; $snapshot['invoices'][0]['attempt_count'] = 0; $snapshot['invoices'][0]['due_at'] = 0;
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 1 === count( monitor_issues( $state, 'invoice_pending', 'open' ) ), 'Stale exact pending evidence needs review even if the invoice attempted flag is absent.' );

// Old balances and baseline/manual expectations remain visible as review, not immediate client actions.
foreach ( array(
	array( 'label' => 'recent expected subscription', 'days' => 20, 'expected' => 'active', 'source' => 'stripe', 'severity' => 'action' ),
	array( 'label' => 'historical balance', 'days' => 61, 'expected' => 'active', 'source' => 'stripe', 'severity' => 'review' ),
	array( 'label' => 'unconfirmed baseline', 'days' => 20, 'expected' => 'review', 'source' => 'stripe', 'severity' => 'review' ),
	array( 'label' => 'ended relationship', 'days' => 20, 'expected' => 'ended', 'source' => 'stripe', 'severity' => 'review' ),
	array( 'label' => 'manual check relationship', 'days' => 20, 'expected' => 'active', 'source' => 'check', 'severity' => 'review' ),
) as $case ) {
	$state = monitor_empty_state();
	$record = monitor_record( 'stripe', array( 'expected' => $case['expected'], 'source' => $case['source'] ) );
	$state['records'][ $record['id'] ] = $record;
	$invoice = monitor_invoice( array( 'created' => $now - $case['days'] * DAY_IN_SECONDS ) );
	$snapshot = monitor_snapshot( array(), array( $invoice ) );
	SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
	$id = monitor_first_issue_id( $state, 'invoice', 'open' );
	monitor_check( $id && $case['severity'] === $state['issues'][ $id ]['severity'] && $invoice['created'] === $state['issues'][ $id ]['occurred_at'], $case['label'] . ' must retain its historical timestamp and correct priority.' );
}
$state = monitor_empty_state();
$snapshot = monitor_snapshot( array( monitor_record( 'stripe', array( 'status' => 'past_due' ) ) ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
$id = monitor_first_issue_id( $state, 'plan_status', 'open' );
monitor_check( $id && $snapshot['records'][0]['current_period_start'] === $state['issues'][ $id ]['occurred_at'], 'Plan status issues expose the billing-period occurrence date.' );

// Refunds/disputes attach to exact receipts and subscriptions, never customer similarity.
foreach ( array( 'stripe', 'moonclerk' ) as $source ) {
	$state = monitor_empty_state();
	$refunded = monitor_payment( $source, array( 'status' => 'refunded', 'refunded_cents' => 2500, 'url' => 'https://example.test/payments/payment1' ) );
	$snapshot = monitor_snapshot( array( monitor_record( $source ) ), array(), array( $refunded ) );
	SPM_Monitor::merge_snapshot( $state, $source, $snapshot );
	SPM_Monitor::reconcile_state( $state, $source, $snapshot, $now );
	$id = monitor_first_issue_id( $state, 'payment_refund', 'open' );
	monitor_check( $id && $state['issues'][ $id ]['record_id'] === $source . ':plan1' && 2500 === $state['issues'][ $id ]['amount_cents'], $source . ' partial refund needs a receipt-specific review on the exact subscription.' );
	monitor_check( $id && $state['issues'][ $id ]['identity'] === $source . ':payment1', $source . ' refund identity must remain tied to its receipt.' );
	if ( $id ) {
		$state['issues'][ $id ]['status'] = 'resolved'; $state['issues'][ $id ]['resolution'] = 'Intentional partial reimbursement.';
		SPM_Monitor::reconcile_state( $state, $source, $snapshot, $now + 60 );
		monitor_check( 'resolved' === $state['issues'][ $id ]['status'], $source . ' unchanged refund must preserve its explanation.' );
		$snapshot['payments'][0]['refunded_cents'] = 5000;
		SPM_Monitor::reconcile_state( $state, $source, $snapshot, $now + 120 );
		monitor_check( 'open' === $state['issues'][ $id ]['status'] && 5000 === $state['issues'][ $id ]['amount_cents'], $source . ' larger refund reopens the same receipt issue.' );
		$snapshot['payments'] = array();
		SPM_Monitor::reconcile_state( $state, $source, $snapshot, $now + 180 );
		monitor_check( 'open' === $state['issues'][ $id ]['status'], $source . ' refund cannot be cleared merely because its receipt leaves the bounded read window.' );
	}
}
$state = monitor_empty_state();
$dispute = monitor_payment( 'stripe', array( 'disputed' => true ) );
$snapshot = monitor_snapshot( array( monitor_record(), monitor_record( 'stripe', array( 'id' => 'stripe:plan2', 'source_ref' => 'plan2' ) ) ), array(), array( $dispute ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
$dispute_id = monitor_first_issue_id( $state, 'payment_dispute', 'open' );
monitor_check( $dispute_id && 'stripe:plan1' === $state['issues'][ $dispute_id ]['record_id'] && 'action' === $state['issues'][ $dispute_id ]['severity'], 'Dispute must flag the exact subscription despite a shared customer.' );
monitor_check( $dispute_id && null === $state['issues'][ $dispute_id ]['amount_cents'], 'Charge-level disputed flag must not invent a disputed amount.' );
monitor_check( 0 === $state['records']['stripe:plan1']['last_paid'], 'Disputed receipt cannot count as last successful payment.' );
$snapshot['payments'][0]['disputed'] = false;
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now + 60 );
monitor_check( $dispute_id && 'resolved' === $state['issues'][ $dispute_id ]['status'] && $state['issues'][ $dispute_id ]['auto_resolved'], 'An observed receipt no longer marked disputed clears its dispute condition.' );

// Unmatched refunded payments create a separate evidence record, not a customer-based subscription match.
$state = monitor_empty_state();
$unmatched = monitor_payment( 'stripe', array( 'record_id' => '', 'customer_ref' => 'cus_example', 'refunded_cents' => 1000, 'name' => 'Same customer', 'status' => 'refunded' ) );
$snapshot = monitor_snapshot( array( monitor_record() ), array(), array( $unmatched ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
$id = monitor_first_issue_id( $state, 'payment_refund', 'open' );
monitor_check( $id && 'payment:stripe:payment1' === $state['issues'][ $id ]['record_id'] && isset( $state['records']['payment:stripe:payment1'] ), 'Unmatched refunded receipt gets its own review record even when customer matches an active subscription.' );
monitor_check( 'stripe_payment' === $state['records']['payment:stripe:payment1']['source'] && 'review' === $state['records']['payment:stripe:payment1']['expected'], 'Synthetic payment record is clearly evidence requiring review.' );
$snapshot['payments'][] = monitor_payment( 'stripe', array( 'id' => 'stripe:payment2', 'record_id' => '', 'status' => 'refunded', 'refunded_cents' => 500 ) );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now + 60 );
monitor_check( 2 === count( monitor_issues( $state, 'payment_refund', 'open' ) ), 'Two refunded receipts have independent review identities.' );

// An explicit manual receipt allocation links reversal review but never rewinds an expected date.
$state = monitor_empty_state();
$due = strtotime( '2026-10-31 UTC' );
$manual = monitor_record( 'mdm_remittance', array( 'id' => 'mdm_remittance:agreement', 'status' => 'manual', 'expected' => 'active', 'next_due' => $due, 'notes' => array() ) );
$state['records'][ $manual['id'] ] = $manual;
$state['manual_payments']['evidence:one'] = array( 'id' => 'evidence:one', 'record_id' => $manual['id'], 'source_payment_id' => 'stripe:payment1', 'amount_cents' => 10000, 'currency' => 'usd', 'paid_at' => $now, 'period_end' => $due, 'status' => 'paid' );
$before_manual = $state['records'][ $manual['id'] ];
$before_evidence = $state['manual_payments'];
$snapshot = monitor_snapshot( array(), array(), array( monitor_payment( 'stripe', array( 'record_id' => '', 'status' => 'refunded', 'refunded_cents' => 10000, 'disputed' => true ) ) ) );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
$refund_id = monitor_first_issue_id( $state, 'payment_refund', 'open' );
$dispute_id = monitor_first_issue_id( $state, 'payment_dispute', 'open' );
monitor_check( $refund_id && $dispute_id && $manual['id'] === $state['issues'][ $refund_id ]['record_id'] && $manual['id'] === $state['issues'][ $dispute_id ]['record_id'], 'Refund and dispute of an explicitly allocated receipt flag the manual agreement.' );
monitor_check( $before_manual === $state['records'][ $manual['id'] ] && $before_evidence === $state['manual_payments'], 'A reversal review must not silently alter expected dates or rewrite saved manual payment evidence.' );
monitor_check( false !== strpos( $state['issues'][ $refund_id ]['detail'], 'due date has not been changed' ), 'Allocated-receipt review explains that expected date needs human review.' );

// Aged current-period drafts need review; upcoming or newly-created drafts do not.
$state = monitor_empty_state();
$draft = monitor_invoice( array( 'status' => 'draft', 'attempted' => false, 'attempt_count' => 0, 'next_payment_attempt' => 0 ) );
$snapshot = monitor_snapshot( array( monitor_record() ), array( $draft ) );
SPM_Monitor::merge_snapshot( $state, 'stripe', $snapshot );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 1 === count( monitor_issues( $state, 'invoice_draft', 'open' ) ) && 0 === count( monitor_issues( $state, 'invoice_missing', 'open' ) ), 'Current invoice still draft beyond grace is flagged even though invoice existence is known.' );
$draft_id = monitor_first_issue_id( $state, 'invoice_draft', 'open' );
monitor_check( $draft_id && $draft['created'] === $state['issues'][ $draft_id ]['occurred_at'], 'Draft issue keeps the invoice creation date for historical grouping.' );
$snapshot['invoices'][0]['created'] = $now - DAY_IN_SECONDS;
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 0 === count( monitor_issues( $state, 'invoice_draft', 'open' ) ), 'A newly created draft gets its own grace period.' );
$snapshot['invoices'][0] = $draft;
$snapshot['invoices'][0]['period_start'] = strtotime( '2026-10-01 UTC' );
$snapshot['invoices'][0]['period_end'] = strtotime( '2026-11-01 UTC' );
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
monitor_check( 0 === count( monitor_issues( $state, 'invoice_draft', 'open' ) ), 'A future-period draft is not a late current invoice.' );
$snapshot['invoices'][0] = $draft;
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now );
$snapshot['invoices'][0]['status'] = 'paid'; $snapshot['invoices'][0]['amount_remaining'] = 0;
SPM_Monitor::reconcile_state( $state, 'stripe', $snapshot, $now + 60 );
monitor_check( 0 === count( monitor_issues( $state, 'invoice_draft', 'open' ) ), 'Finalization/payment clears the current draft condition.' );

// Legacy ignores include check payers and must not imply the relationship ended.
$GLOBALS['monitor_options'] = array( 'spm_ignore_clients' => array( 'client@example.test' => true ), 'spm_client_notes' => array( 'client@example.test' => 'Pays by check.' ) );
$state = monitor_empty_state();
SPM_Monitor::merge_snapshot( $state, 'stripe', monitor_snapshot( array( monitor_record() ) ) );
monitor_check( 'review' === $state['records']['stripe:plan1']['expected'], 'Legacy ignored customer needs expectation review rather than silently ending a check payer.' );
$legacy_notes = array_column( $state['records']['stripe:plan1']['notes'], 'note' );
monitor_check( in_array( 'Pays by check.', $legacy_notes, true ) && in_array( 'Previously ignored in site matching; confirm expected relationship (may pay by check).', $legacy_notes, true ), 'Legacy ignored migration retains check note and adds explicit review context.' );

// Failed or malformed collection retains prior snapshot and flags via the real refresh path.
$GLOBALS['monitor_options'] = array();
SPM_Stripe_Source::$snapshot = monitor_snapshot( array( monitor_record( 'stripe', array( 'status' => 'past_due' ) ) ), array( monitor_invoice() ) );
$result = SPM_Monitor::refresh( 'stripe' );
monitor_check( true === $result['stripe']['ok'], 'A complete provider snapshot should save successfully.' );
$before = SPM_Monitor::state();
$prior_snapshot = get_option( 'spm_monitor_snapshot_stripe' );
$prior_success = $before['sources']['stripe']['last_success'];
SPM_Stripe_Source::$snapshot = new WP_Error( 'unavailable', 'Stripe unavailable.' );
$result = SPM_Monitor::refresh( 'stripe' );
$after = SPM_Monitor::state();
monitor_check( false === $result['stripe']['ok'] && 'error' === $after['sources']['stripe']['status'], 'Source failures must be visible health failures.' );
monitor_check( $before['issues'] === $after['issues'], 'A failed source refresh must never clear or rewrite prior issue decisions.' );
monitor_check( $before['records'] === $after['records'] && $prior_snapshot === get_option( 'spm_monitor_snapshot_stripe' ), 'A failed source must retain prior records and successful evidence.' );
monitor_check( $prior_success === $after['sources']['stripe']['last_success'], 'Failed attempts must not advance last-success time.' );
SPM_Stripe_Source::$snapshot = array( 'records' => array() );
$result = SPM_Monitor::refresh( 'stripe' );
monitor_check( false === $result['stripe']['ok'] && $before['issues'] === SPM_Monitor::state()['issues'], 'Malformed provider snapshots must retain existing flags.' );
$GLOBALS['wpdb']->busy = true;
$result = SPM_Monitor::mutate( function( &$state ) { $state['records'] = array(); return true; } );
monitor_check( is_wp_error( $result ) && $before['records'] === SPM_Monitor::state()['records'], 'Concurrent lock contention must leave state unchanged.' );
$GLOBALS['wpdb']->busy = false;
monitor_check( $GLOBALS['wpdb']->released > 0, 'Successful mutations must release advisory locks.' );

// Calendar recurrence clamps month ends and preserves the chosen anchor across months.
$january = SPM_Monitor::date( '2028-01-31' );
$february = SPM_Monitor::next_date( $january, 'month', 1, 31 );
monitor_check( '2028-02-29' === gmdate( 'Y-m-d', $february ), 'January 31 must clamp to leap-day February.' );
monitor_check( '2028-03-31' === gmdate( 'Y-m-d', SPM_Monitor::next_date( $february, 'month', 1, 31 ) ), 'A clamped February payment must return to the 31st anchor in March.' );
monitor_check( '2029-02-28' === gmdate( 'Y-m-d', SPM_Monitor::next_date( $february, 'year', 1, 29 ) ), 'Leap-day annual schedules must clamp in non-leap years.' );
monitor_check( '2026-11-01' === gmdate( 'Y-m-d', SPM_Monitor::next_date( SPM_Monitor::date( '2026-09-01' ), 'month', 2 ) ), 'Multi-month recurrence must keep interval count.' );
monitor_check( '2026-09-15' === gmdate( 'Y-m-d', SPM_Monitor::next_date( SPM_Monitor::date( '2026-09-01' ), 'week', 2 ) ), 'Weekly recurrence must keep interval count.' );
monitor_check( false === SPM_Monitor::date( '2026-02-30' ) && false === SPM_Monitor::date( '09/07/2026' ), 'Invalid and ambiguous input dates must be rejected.' );
monitor_check( 10001 === SPM_Monitor::amount( '100.01' ) && 10010 === SPM_Monitor::amount( '100.1' ) && false === SPM_Monitor::amount( '100.001' ), 'Manual amounts must parse decimal input into exact integer cents.' );

if ( $GLOBALS['monitor_failures'] ) {
	fwrite( STDERR, count( $GLOBALS['monitor_failures'] ) . ' of ' . $GLOBALS['monitor_assertions'] . " monitor assertions failed:\n" );
	foreach ( $GLOBALS['monitor_failures'] as $failure ) { fwrite( STDERR, '- ' . $failure . "\n" ); }
	exit( 1 );
}
echo 'Monitor reconciliation: ' . $GLOBALS['monitor_assertions'] . " assertions passed.\n";
