<?php
/** Authenticated HTML report boundary. Uses synthetic data only. */
define( 'ABSPATH', __DIR__ );
class SPM_Monitor {
    public static $reads = 0;
    public static $fixture = array( 'records' => array( 'test:one' => array( 'name' => '</pre><script>alert("synthetic")</script>', 'notes' => array( 'Quotes " and ampersands & stay intact.' ) ) ) );
    public static function report() { self::$reads++; return self::$fixture; }
}
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['allowed']; }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( $message ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value, $domain ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function admin_url( $path ) { return 'https://dashboard.example.invalid/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function check_admin_referer( $action ) {
    $GLOBALS['nonce_action'] = $action;
    if ( ! $GLOBALS['nonce_valid'] ) { throw new RuntimeException( 'Invalid nonce' ); }
}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
require_once __DIR__ . '/../includes/monitor-admin.php';
$checks = 0;
function html_report_check( $condition, $message ) {
    $GLOBALS['checks']++;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$_GET = array( 'spm_report' => '1' );
$allowed = false;
$nonce_valid = true;
$denied = false;
ob_start();
try { SPM_Monitor_Admin::render(); } catch ( RuntimeException $error ) { $denied = true; }
$html = ob_get_clean();
html_report_check( $denied && '' === $html && 0 === SPM_Monitor::$reads, 'A user without manage_options must not receive or read report data.' );
$allowed = true;
$nonce_valid = false;
$denied = false;
ob_start();
try { SPM_Monitor_Admin::render(); } catch ( RuntimeException $error ) { $denied = true; }
$html = ob_get_clean();
html_report_check( $denied && '' === $html && 0 === SPM_Monitor::$reads, 'Invalid nonces must be rejected before reading or rendering the report.' );
html_report_check( 'spm_monitor_report' === $nonce_action, 'The view must verify its own report nonce.' );
$nonce_valid = true;
$nocache = false;
ob_start(); SPM_Monitor_Admin::render(); $html = ob_get_clean();
html_report_check( $nocache && 1 === SPM_Monitor::$reads, 'An authorized view must disable caching and read the current report once.' );
html_report_check( 1 === substr_count( $html, '<pre ' ) && 1 === substr_count( $html, 'id="spm-report-json"' ), 'The report must occupy a single stable pre element for browser DOM reading.' );
html_report_check( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ), 'Untrusted provider text must remain escaped and cannot exit the pre element.' );
preg_match( '/<pre id="spm-report-json"[^>]*>(.*?)<\/pre>/s', $html, $match );
$decoded = json_decode( html_entity_decode( $match[1] ?? '', ENT_QUOTES, 'UTF-8' ), true );
html_report_check( SPM_Monitor::$fixture === $decoded, 'The DOM text must preserve complete parseable JSON including quotes, ampersands and closing tags.' );
html_report_check( false === strpos( $html, '<form' ) && false !== strpos( $html, 'Back to Payments Monitor' ), 'The report view must remain read-only and offer dashboard navigation.' );
echo 'Monitor HTML report: ' . $checks . " checks passed.\n";
