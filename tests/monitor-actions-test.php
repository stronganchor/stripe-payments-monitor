<?php
/** Review mutation regressions. Run: php tests/monitor-actions-test.php */
define( 'ABSPATH', __DIR__ );
define( 'DB_NAME', 'monitor_actions_tests' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class Actions_Test_DB {
    public $prefix = 'test_';
    public $busy = false;
    public function prepare( $sql, $name ) { return str_replace( '%s', "'" . $name . "'", $sql ); }
    public function get_var( $sql ) { return false !== strpos( $sql, 'RELEASE_LOCK' ) || ! $this->busy ? '1' : '0'; }
}
class SPM_Stripe_Source {
    public static $snapshot;
    public static $during_read;
    public static function collect() {
        if ( self::$during_read ) { call_user_func( self::$during_read ); }
        return self::$snapshot;
    }
}
class SPM_MoonClerk_Source {
    public static $snapshot;
    public static function collect() { return self::$snapshot; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ); }
function esc_url_raw( $url, $protocols = null ) {
    $scheme = parse_url( $url, PHP_URL_SCHEME );
    return $scheme && in_array( $scheme, $protocols ?: array( 'http', 'https' ), true ) ? $url : '';
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_attr( esc_url_raw( $value ) ); }
function wp_get_current_user() { return (object) array( 'display_name' => 'Test reviewer' ); }
function wp_generate_uuid4() { return 'test-' . ++$GLOBALS['uuid']; }
function add_action( $name, $callback ) { $GLOBALS['hooks'][ $name ][] = $callback; }
function current_user_can( $capability ) { return true; }
function nocache_headers() {}
function admin_url( $path ) { return 'https://dashboard.example.invalid/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_create_nonce( $action ) { return 'test-nonce'; }
function selected( $value, $expected, $echo = true ) { return (string) $value === (string) $expected ? ' selected="selected"' : ''; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function number_format_i18n( $amount, $places = 0 ) { return number_format( $amount, $places ); }
function wp_unslash( $value ) { return $value; }
require dirname( __DIR__ ) . '/includes/monitor.php';
require dirname( __DIR__ ) . '/includes/monitor-actions.php';
require dirname( __DIR__ ) . '/includes/monitor-admin.php';

$wpdb = new Actions_Test_DB();
$checks = 0;
$failures = array();
$uuid = 0;
function action_check( $condition, $message ) {
    ++$GLOBALS['checks'];
    if ( ! $condition ) { $GLOBALS['failures'][] = $message; }
}
function fresh_state() {
    $GLOBALS['options'] = array();
    $GLOBALS['wpdb']->busy = false;
    SPM_Stripe_Source::$during_read = null;
    return SPM_Monitor::state();
}
function save_state( $state ) { update_option( SPM_Monitor::STATE, $state ); }
function agreement( $id = 'check:one', $overrides = array() ) {
    return array_replace( array( 'id' => $id, 'name' => 'Example agreement', 'email' => '', 'source' => 'check',
        'source_ref' => '', 'customer_ref' => '', 'status' => 'manual', 'expected' => 'active', 'category' => 'sponsorship',
        'amount_cents' => 10000, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1,
        'next_due' => strtotime( '2026-01-31 UTC' ), 'anchor_day' => 31, 'grace_days' => 7,
        'last_paid' => 0, 'notes' => array(), 'url' => '', 'site_url' => '' ), $overrides );
}
function evidence_action( $overrides = array() ) {
    return array_replace( array( 'op' => 'evidence', 'record_id' => 'check:one', 'amount' => '100', 'currency' => 'usd',
        'paid_date' => '2026-01-31', 'period_end' => '2026-01-31', 'note' => 'Aaron confirmed the January installment.',
        'evidence_url' => 'https://mail.google.com/mail/u/0/#all/thread_january' ), $overrides );
}
function attach_payment( &$state, $overrides = array() ) {
    $payment = array_replace( array( 'id' => 'stripe:ch_one', 'source' => 'stripe', 'record_id' => '', 'customer_ref' => 'cus_one',
        'status' => 'paid', 'amount_cents' => 10000, 'captured_cents' => 10000, 'refunded_cents' => 0,
        'currency' => 'usd', 'paid_at' => strtotime( '2026-01-31 UTC' ), 'url' => 'https://dashboard.stripe.com/payments/ch_one' ), $overrides );
    $source = $payment['source'];
    $state['sources'][ $source ] = array( 'status' => 'ok', 'last_success' => time(), 'checked_at' => time(), 'error' => '' );
    update_option( 'spm_monitor_snapshot_' . $source, array( 'source' => $source, 'checked_at' => time(), 'records' => array(), 'invoices' => array(), 'payments' => array( $payment ) ) );
    return $payment;
}
function fixture() { $state = fresh_state(); $state['records']['check:one'] = agreement(); save_state( $state ); return $state; }

// Whole import succeeds or fails as a unit, including review coverage metadata.
$state = fixture();
$before = SPM_Monitor::state();
$result = SPM_Monitor_Actions::apply( array( 'op' => 'review_import', 'review_json' => json_encode( array(
    'actions' => array( array( 'op' => 'note', 'record_id' => 'check:one', 'note' => 'Must roll back.' ), array( 'op' => 'note', 'record_id' => 'missing', 'note' => 'Invalid.' ) ),
) ) ) );
action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), 'failed second action leaves no partial note or state mutation' );
$result = SPM_Monitor_Actions::apply( array( 'op' => 'review_import', 'review_json' => json_encode( array(
    'actions' => array( array( 'op' => 'note', 'record_id' => 'check:one', 'note' => 'Must also roll back.' ) ),
    'review' => array( 'summary' => 'Review completed.', 'from' => '2026-02-30', 'to' => '2026-03-02' ),
) ) ) );
action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), 'invalid email coverage rolls back earlier valid mutations' );
$result = SPM_Monitor_Actions::apply( array( 'op' => 'review_import', 'review_json' => json_encode( array(
    'actions' => array( array( 'op' => 'note', 'record_id' => 'check:one', 'note' => 'Reviewed exact message.' ) ),
    'review' => array( 'summary' => 'Aaron confirmed the installment.', 'from' => '2026-01-01', 'to' => '2026-01-31' ),
) ) ) );
action_check( ! is_wp_error( $result ) && 'ok' === SPM_Monitor::state()['sources']['email']['status'], 'valid batch saves evidence and covered email dates together' );
$GLOBALS['wpdb']->busy = true;
$before = SPM_Monitor::state();
$result = SPM_Monitor_Actions::apply( array( 'op' => 'note', 'record_id' => 'check:one', 'note' => 'Must not save while locked.' ) );
action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), 'busy lock prevents concurrent review changes' );
$GLOBALS['wpdb']->busy = false;

// Notes dedupe by content plus evidence; clearing and waiting require a reason.
$state = fixture();
$note = array( 'op' => 'note', 'record_id' => 'check:one', 'note' => 'Aaron confirmed.', 'evidence_url' => 'https://mail.google.com/mail/u/0/#all/one' );
SPM_Monitor_Actions::apply( $note );
SPM_Monitor_Actions::apply( $note );
action_check( 1 === count( SPM_Monitor::state()['records']['check:one']['notes'] ), 'repeat review notes are idempotent' );
$result = SPM_Monitor_Actions::apply( array( 'op' => 'flag', 'record_id' => 'check:one', 'title' => 'Check verification', 'detail' => 'Check still needs confirmation.' ) );
$issue_id = $result['results'][0]['id'];
foreach ( array( 'resolved', 'waiting', 'snoozed' ) as $status ) {
    $before = SPM_Monitor::state();
    $result = SPM_Monitor_Actions::apply( array( 'op' => 'issue', 'issue_id' => $issue_id, 'status' => $status ) );
    action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), $status . ' requires an explanation' );
}
$result = SPM_Monitor_Actions::apply( array( 'op' => 'issue', 'issue_id' => $issue_id, 'status' => 'waiting', 'resolution' => 'Waiting for Aaron.', 'follow_up_date' => '2020-01-01' ) );
action_check( is_wp_error( $result ), 'waiting cannot use a past follow-up date' );
$result = SPM_Monitor_Actions::apply( array( 'op' => 'issue', 'issue_id' => $issue_id, 'status' => 'resolved', 'resolution' => 'Aaron confirmed it in the linked message.' ) );
action_check( ! is_wp_error( $result ) && 'resolved' === SPM_Monitor::state()['issues'][ $issue_id ]['status'], 'explained resolution is saved' );

// Date and manual money entry reject rollover, exponent, negative and unknown currency.
foreach ( array( '2026-02-30', '2025-02-29', '2026-13-01', 'tomorrow', '2026-1-01', '2026-01-01T00:00:00Z' ) as $date ) {
    action_check( false === SPM_Monitor::date( $date ), 'reject invalid date ' . $date );
}
action_check( strtotime( '2024-02-29 UTC' ) === SPM_Monitor::date( '2024-02-29' ), 'leap day is accepted exactly' );
foreach ( array( '-1', '1e3', '1.999', 'USD 10', '1,000', '', 'NaN' ) as $amount ) {
    action_check( false === SPM_Monitor::amount( $amount ), 'reject ambiguous amount ' . $amount );
}
action_check( 12345 === SPM_Monitor::amount( '123.45' ) && 10 === SPM_Monitor::amount( '0.1' ), 'decimal entry has exact cent precision' );
$state = fixture();
$before = SPM_Monitor::state();
$result = SPM_Monitor_Actions::apply( array( 'op' => 'record', 'record_id' => 'check:one', 'currency' => 'jpy' ) );
action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), 'unsupported manual currency cannot use two-decimal conversion' );
$result = SPM_Monitor_Actions::apply( array( 'op' => 'record', 'record_id' => 'check:one', 'next_due' => '2026-02-30' ) );
action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), 'manual schedule rejects impossible dates atomically' );

// Partial installments do not advance; complete evidence advances one anchored month.
$state = fixture();
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'amount' => '40' ) ) );
action_check( ! is_wp_error( $result ) && strtotime( '2026-01-31 UTC' ) === SPM_Monitor::state()['records']['check:one']['next_due'], 'partial installment does not advance expected date' );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'amount' => '60', 'evidence_url' => 'https://mail.google.com/mail/u/0/#all/second_receipt' ) ) );
action_check( ! is_wp_error( $result ) && strtotime( '2026-02-28 UTC' ) === SPM_Monitor::state()['records']['check:one']['next_due'], 'combined sufficient evidence advances Jan31 to Feb28' );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'amount' => '1000', 'paid_date' => '2026-02-28', 'period_end' => '2027-12-31', 'evidence_url' => 'https://mail.google.com/mail/u/0/#all/lump_receipt' ) ) );
action_check( ! is_wp_error( $result ) && strtotime( '2026-03-31 UTC' ) === SPM_Monitor::state()['records']['check:one']['next_due'], 'lump sum advances exactly one month and restores original day31 anchor' );
$before = SPM_Monitor::state();
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'amount' => '1000', 'paid_date' => '2026-02-28', 'period_end' => '2027-12-31', 'evidence_url' => 'https://mail.google.com/mail/u/0/#all/lump_receipt' ) ) );
action_check( ! is_wp_error( $result ) && $before['manual_payments'] === SPM_Monitor::state()['manual_payments'] && $before['records'] === SPM_Monitor::state()['records'], 'identical receipt import does not advance twice' );

// Provider evidence is verified against fresh payment status and exact association.
foreach ( array(
    array( 'label' => 'different subscription', 'payment' => array( 'record_id' => 'stripe:other' ) ),
    array( 'label' => 'wrong currency', 'payment' => array( 'currency' => 'eur' ) ),
    array( 'label' => 'failed payment', 'payment' => array( 'status' => 'failed' ) ),
    array( 'label' => 'pending payment', 'payment' => array( 'status' => 'pending' ) ),
    array( 'label' => 'disputed payment', 'payment' => array( 'disputed' => true ) ),
    array( 'label' => 'fully refunded payment', 'payment' => array( 'status' => 'refunded', 'refunded_cents' => 10000 ) ),
) as $case ) {
    $state = fixture();
    attach_payment( $state, $case['payment'] ); save_state( $state );
    $before = SPM_Monitor::state();
    $result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one' ) ) );
    action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), 'cannot allocate ' . $case['label'] );
}
$state = fixture();
$state['records']['check:one']['customer_ref'] = 'cus_different';
attach_payment( $state ); save_state( $state );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one' ) ) );
action_check( is_wp_error( $result ), 'receipt for another exact customer is rejected' );
$state = fixture();
attach_payment( $state ); $state['sources']['stripe']['last_success'] = time() - 2 * DAY_IN_SECONDS; save_state( $state );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one' ) ) );
action_check( is_wp_error( $result ), 'stale source payment cannot be newly allocated' );
$state = fixture();
$state['records']['check:one']['source'] = 'mdm_remittance';
attach_payment( $state, array( 'id' => 'moonclerk:payment1', 'source' => 'moonclerk' ) ); save_state( $state );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'moonclerk:payment1' ) ) );
action_check( is_wp_error( $result ), 'donor contribution cannot verify the separate LLC remittance stage' );
$state = fixture();
attach_payment( $state, array( 'status' => 'refunded', 'refunded_cents' => 2000 ) ); save_state( $state );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one', 'amount' => '999999' ) ) );
$payment = array_values( SPM_Monitor::state()['manual_payments'] )[0] ?? array();
action_check( ! is_wp_error( $result ) && 8000 === ( $payment['amount_cents'] ?? 0 ) && strtotime( '2026-01-31 UTC' ) === SPM_Monitor::state()['records']['check:one']['next_due'], 'allocated provider amount uses captured less refunds, ignoring claimed form amount' );

// A receipt cannot be spent twice on another agreement OR another billing period.
$state = fixture();
$state['records']['check:two'] = agreement( 'check:two' );
attach_payment( $state ); save_state( $state );
$first = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one' ) ) );
action_check( ! is_wp_error( $first ), 'fresh unallocated standalone receipt can verify manual agreement' );
$before = SPM_Monitor::state();
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one', 'record_id' => 'check:two' ) ) );
action_check( is_wp_error( $result ) && $before === SPM_Monitor::state(), 'same provider receipt cannot be allocated across agreements' );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one', 'period_end' => '2026-02-28' ) ) );
action_check( 1 === count( SPM_Monitor::state()['manual_payments'] ) && strtotime( '2026-02-28 UTC' ) === SPM_Monitor::state()['records']['check:one']['next_due'], 'same provider receipt cannot be counted again under a later period' );

// Saved connection secrets are never part of report JSON, settings HTML or notes.
$state = fixture();
$stripe_key = 'rk_' . 'live_' . str_repeat( 'SyntheticFixture', 2 );
$moon_key = 'moonKeyExampleOpaque987654321';
update_option( 'spm_stripe_secret_key', $stripe_key );
update_option( 'spm_moonclerk_api_key', $moon_key );
SPM_Monitor_Actions::apply( array( 'op' => 'note', 'record_id' => 'check:one', 'note' => 'Accidental credential paste: ' . $stripe_key . ' and ' . $moon_key ) );
$json = json_encode( SPM_Monitor::report() );
action_check( false === strpos( $json, $stripe_key ), 'Stripe credentials are redacted from stored notes and report' );
action_check( false === strpos( $json, $moon_key ), 'configured opaque MoonClerk credentials are redacted from stored notes and report' );
ob_start(); SPM_Monitor_Admin::settings(); $settings_html = ob_get_clean();
action_check( false === strpos( $settings_html, $stripe_key ) && false === strpos( $settings_html, $moon_key ), 'settings renders only empty credential controls' );
ob_start(); SPM_Monitor_Admin::render(); $report_html = ob_get_clean();
action_check( false === strpos( $report_html, $stripe_key ) && false === strpos( $report_html, $moon_key ), 'report HTML does not expose configured credentials' );
action_check( false !== strpos( $report_html, 'Test reviewer' ), 'report HTML test renders persisted note authors' );

// A key change invalidates cached evidence; an old in-flight refresh cannot undo that.
$state = fixture();
attach_payment( $state ); save_state( $state );
update_option( 'spm_stripe_secret_key', 'rk_live_OriginalTestKey' );
$result = SPM_Monitor_Actions::apply( array( 'op' => 'settings', 'spm_stripe_key' => 'rk_live_ReplacementTestKey' ) );
action_check( ! is_wp_error( $result ) && 'ok' !== ( SPM_Monitor::state()['sources']['stripe']['status'] ?? '' ), 'key change invalidates current source health' );
$result = SPM_Monitor_Actions::apply( evidence_action( array( 'source_payment_id' => 'stripe:ch_one' ) ) );
action_check( is_wp_error( $result ), 'old cached receipt is unavailable after source key change' );
$old_snapshot = get_option( 'spm_monitor_snapshot_stripe' );
SPM_Stripe_Source::$snapshot = array( 'source' => 'stripe', 'checked_at' => time(), 'records' => array(), 'payments' => array(), 'invoices' => array() );
SPM_Stripe_Source::$during_read = static function() {
    SPM_Monitor_Actions::apply( array( 'op' => 'settings', 'spm_stripe_key' => 'rk_live_ChangedDuringRefresh' ) );
};
$result = SPM_Monitor::refresh( 'stripe' );
action_check( empty( $result['stripe']['ok'] ) && 'ok' !== ( SPM_Monitor::state()['sources']['stripe']['status'] ?? '' ), 'refresh collected with previous credentials cannot claim current after concurrent key change' );
action_check( $old_snapshot === get_option( 'spm_monitor_snapshot_stripe' ), 'configuration race does not replace prior snapshot with old-account read' );

if ( $failures ) {
    echo count( $failures ) . " failures in {$checks} monitor action checks:\n";
    foreach ( $failures as $failure ) { echo '- ' . $failure . "\n"; }
    exit( 1 );
}
echo "Monitor actions: {$checks} checks passed.\n";
