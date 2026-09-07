<?php
/** Read-only browser report access boundary: php tests/monitor-report-test.php */
define( 'ABSPATH', __DIR__ );
class SPM_Monitor {
	public static $reads = 0;
	public static function report() { self::$reads++; return array( 'records' => array( 'test:one' => array( 'name' => 'Synthetic agreement' ) ) ); }
}
class Monitor_Report_Response extends RuntimeException {
	public $payload;
	public function __construct( $payload ) { parent::__construct( 'JSON sent' ); $this->payload = $payload; }
}
function add_action( $hook, $callback ) { $GLOBALS['report_hooks'][ $hook ][] = $callback; }
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['report_allowed']; }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( $message, $args['response'] ?? 0 ); }
function check_admin_referer( $action ) {
	$GLOBALS['report_nonce_action'] = $action;
	if ( ! $GLOBALS['report_nonce_valid'] ) { throw new RuntimeException( 'Invalid nonce', 403 ); }
}
function nocache_headers() { $GLOBALS['report_nocache'] = true; }
function wp_send_json( $payload, $status = null, $flags = 0 ) {
	$GLOBALS['report_status'] = $status;
	throw new Monitor_Report_Response( $payload );
}
require_once __DIR__ . '/../includes/monitor-actions.php';
$checks = 0;
function report_check( $condition, $message ) {
	$GLOBALS['checks']++;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
report_check( isset( $GLOBALS['report_hooks']['admin_post_spm_monitor_report'] ), 'The authenticated report hook must be registered.' );
report_check( ! isset( $GLOBALS['report_hooks']['admin_post_nopriv_spm_monitor_report'] ), 'The private report must not register an anonymous endpoint.' );
$callback = $GLOBALS['report_hooks']['admin_post_spm_monitor_report'][0];
$GLOBALS['report_allowed'] = false;
$GLOBALS['report_nonce_valid'] = true;
try { $callback(); report_check( false, 'An unauthorized user reached the report.' ); }
catch ( RuntimeException $error ) { report_check( 403 === $error->getCode(), 'Unauthorized access must return 403.' ); }
report_check( 0 === SPM_Monitor::$reads, 'Unauthorized users must not read financial records.' );
$GLOBALS['report_allowed'] = true;
$GLOBALS['report_nonce_valid'] = false;
try { $callback(); report_check( false, 'An invalid nonce reached the report.' ); }
catch ( RuntimeException $error ) { report_check( 403 === $error->getCode(), 'An invalid nonce must be rejected.' ); }
report_check( 0 === SPM_Monitor::$reads && 'spm_monitor_report' === $GLOBALS['report_nonce_action'], 'The report must verify its own nonce before reading data.' );
$GLOBALS['report_nonce_valid'] = true;
$GLOBALS['report_nocache'] = false;
try { $callback(); report_check( false, 'The successful endpoint failed to send JSON.' ); }
catch ( Monitor_Report_Response $response ) {
	report_check( 'Synthetic agreement' === $response->payload['records']['test:one']['name'], 'An authorized browser must receive the structured current report.' );
}
report_check( true === $GLOBALS['report_nocache'] && 200 === $GLOBALS['report_status'], 'The authorized response must be successful and noncacheable.' );
report_check( 1 === SPM_Monitor::$reads, 'The read-only endpoint must retrieve the report exactly once.' );
echo 'Monitor browser report: ' . $checks . " checks passed.\n";
