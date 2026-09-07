<?php
/** Standalone boundary tests: php tests/moonclerk-source-test.php */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SPM_MOONCLERK_MAX_PAGES', 2 );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function wp_salt( $scheme = 'auth' ) { return 'test-only-salt-not-for-production'; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_get( $url, $args ) {
	$GLOBALS['calls'][] = array( $url, $args );
	return ( $GLOBALS['mock_http'] )( $url, $args );
}
require_once __DIR__ . '/../includes/moonclerk-source.php';

function check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$GLOBALS['assertions']++;
}
function response( $endpoint, $rows, $http = 200 ) {
	return array( 'response' => array( 'code' => $http ), 'body' => json_encode( array( $endpoint => $rows ) ) );
}
function reset_case( $mock, $scope = 'Our exact project', $ids = '' ) {
	$GLOBALS['options'] = array( 'spm_moonclerk_api_key' => 'not-a-real-key', 'spm_moonclerk_partner_allowlist' => $scope, 'spm_moonclerk_customer_ids' => $ids );
	$GLOBALS['calls'] = array();
	$GLOBALS['mock_http'] = $mock;
}
function customer( $id, $designation = 'Our exact project', $status = 'active' ) {
	return array( 'id' => $id, 'name' => 'Donor ' . $id, 'email' => 'donor' . $id . '@example.test',
		'customer_reference' => 'cus_example_' . $id,
		'custom_fields' => array( 'choose_the_partner_youre_supporting' => array( 'response' => $designation ) ),
		'subscription' => array( 'status' => $status, 'current_period_start' => '2026-08-05T00:00:00Z',
			'current_period_end' => '2026-09-05T00:00:00Z', 'next_payment_attempt' => null,
			'canceled_at' => 'canceled' === $status ? '2026-08-30T00:00:00Z' : null,
			'plan' => array( 'amount' => 10000, 'currency' => 'USD', 'interval' => 'month', 'interval_count' => 1 ) ) );
}
function payment( $id, $customer_id, $status = 'successful', $refunded = 0 ) {
	return array( 'id' => $id, 'customer_id' => $customer_id, 'status' => $status,
		'amount' => 10000, 'amount_refunded' => $refunded, 'fee' => 320, 'currency' => 'USD', 'date' => '2026-08-05T00:00:00Z' );
}
function parsed_request( $url ) {
	parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
	return array( trim( parse_url( $url, PHP_URL_PATH ), '/' ), $query );
}
function expect_error( $result, $code ) {
	check( is_wp_error( $result ), 'Expected an error instead of partial success.' );
	check( $code === $result->get_error_code(), 'Unexpected error: ' . $result->get_error_code() );
}

$GLOBALS['assertions'] = 0;

// Explicit scope is mandatory; no account-wide donor import or network request.
reset_case( function () { throw new RuntimeException( 'No HTTP request was expected.' ); }, '' );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_scope_missing' );
check( 0 === count( $GLOBALS['calls'] ), 'Missing scope must not fetch account data.' );
$GLOBALS['options']['spm_moonclerk_api_key'] = '';
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_key_missing' );
reset_case( function () {}, '', '12,not-an-id' );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_scope_invalid' );

// Case-insensitive exact designation, explicit ID inclusion, ended plans, refunds.
reset_case( function ( $url ) {
	list( $endpoint, $query ) = parsed_request( $url );
	if ( 'customers' === $endpoint ) {
		check( ! isset( $query['status'] ), 'Canceled plans must not be excluded from discovery.' );
		return response( $endpoint, array( customer( 1, ' OUR EXACT PROJECT ' ), customer( 2, 'Another charity' ),
			customer( 3, 'Our exact project - unrelated suffix' ), customer( 4, 'Legacy designation', 'canceled' ) ) );
	}
	check( isset( $query['date_from'] ), 'Payment reads must carry the bounded history date.' );
	if ( '1' === $query['customer_id'] ) {
		return response( $endpoint, array( payment( 101, 1, 'successful', 2500 ), payment( 102, 1, 'failed' ) ) );
	}
	check( '4' === $query['customer_id'], 'Unrelated donors must never have payments fetched.' );
	return response( $endpoint, array( payment( 104, 4, 'refunded', 10000 ) ) );
}, 'our exact project', '4' );
$result = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $result ), 'Scoped source should collect successfully.' );
check( array( 'moonclerk:1', 'moonclerk:4' ) === array_column( $result['records'], 'id' ), 'Only scoped donor records should be retained.' );
check( 4 === $result['health']['customers_scanned'] && 2 === $result['health']['records_retained'], 'Health counts should describe complete scope.' );
check( 'canceled' === $result['records'][1]['status'] && $result['records'][1]['canceled_at'] > 0, 'Canceled subscriptions must remain visible.' );
check( 'paid' === $result['payments'][0]['status'] && 2500 === $result['payments'][0]['refunded_cents'], 'Partial refunds must preserve refunded cents.' );
check( 'failed' === $result['payments'][1]['status'], 'Failed payments must not become paid evidence.' );
check( 'refunded' === $result['payments'][2]['status'] && 10000 === $result['payments'][2]['refunded_cents'], 'Full refunds must remain explicit.' );
check( is_int( $result['payments'][0]['amount_cents'] ) && 320 === $result['payments'][0]['fee_cents'], 'Money must use integer cents with fees.' );
check( 'usd' === $result['records'][0]['currency'] && 0 === $result['payments'][0]['period_end'], 'Do not invent payment coverage dates.' );
check( false === strpos( json_encode( $result ), 'not-a-real-key' ), 'Snapshots must not contain the API key.' );
check( false === strpos( json_encode( $result ), 'donor2@' ), 'Snapshots must not retain unrelated personal data.' );
check( 0 === $GLOBALS['calls'][0][1]['redirection'], 'Authenticated calls must not follow redirects.' );
check( 'application/vnd.moonclerk+json;version=1' === $GLOBALS['calls'][0][1]['headers']['Accept'], 'The API version must be pinned.' );

// The other-partner field works, while generic Other alone cannot grant scope.
$other = customer( 9, 'Other' );
$other['custom_fields']['which_mdm_partner_are_you_supporting'] = array( 'response' => 'Our exact project' );
reset_case( function ( $url ) use ( $other ) {
	list( $endpoint ) = parsed_request( $url );
	return response( $endpoint, 'customers' === $endpoint ? array( $other ) : array() );
} );
$result = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $result ) && 'Our exact project' === $result['records'][0]['designation'], 'An exact other-partner response should match.' );
reset_case( function () {}, 'Other' );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_scope_missing' );

// Non-retryable HTTP failure must never be interpreted as an empty account.
reset_case( function () { return response( 'customers', array(), 401 ); } );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_http' );
check( 1 === count( $GLOBALS['calls'] ), 'Authorization failures should not retry.' );

// Rate limit and service errors retry at most three requests.
reset_case( function () { return response( 'customers', array(), 429 ); } );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_http' );
check( 3 === count( $GLOBALS['calls'] ), '429 retry count must be bounded.' );
reset_case( function () { return count( $GLOBALS['calls'] ) < 3 ? response( 'customers', array(), 503 ) : response( 'customers', array() ); } );
$result = SPM_MoonClerk_Source::collect();
check( is_wp_error( $result ) && 'spm_moonclerk_scope_empty' === $result->get_error_code() && 3 === count( $GLOBALS['calls'] ), 'A recovered 503 with no matching customers should report scope uncertainty rather than an outage or all-clear.' );

// Malformed/truncated JSON and malformed list shapes are source failures.
reset_case( function () { return array( 'response' => array( 'code' => 200 ), 'body' => '{"customers":[' ); } );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_json' );
reset_case( function () { return response( 'wrong-key', array() ); } );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_shape' );

// Repeated full pages and exhausted caps cannot look like complete snapshots.
$hundred = array();
for ( $id = 1; $id <= 100; $id++ ) { $hundred[] = customer( $id, 'Out of scope' ); }
reset_case( function () use ( $hundred ) { return response( 'customers', $hundred ); } );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_repeated_page' );
reset_case( function ( $url ) {
	list( $endpoint, $query ) = parsed_request( $url );
	$rows = array();
	for ( $id = 1 + (int) $query['offset']; $id <= 100 + (int) $query['offset']; $id++ ) { $rows[] = customer( $id, 'Out of scope' ); }
	return response( $endpoint, $rows );
} );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_page_limit' );

// A full page followed by empty is complete; offset, not undocumented page, is used.
reset_case( function ( $url ) use ( $hundred ) {
	list( $endpoint, $query ) = parsed_request( $url );
	check( ! isset( $query['page'] ), 'Only documented offset pagination should be used.' );
	if ( 'payments' === $endpoint ) { return response( $endpoint, array() ); }
	$hundred[0] = customer( 1 );
	return response( $endpoint, '0' === $query['offset'] ? $hundred : array() );
} );
$result = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $result ) && 100 === $result['health']['customers_scanned'], 'An explicit final empty page proves completeness.' );

// Payment filter violations and later payment-page failures discard all new data.
reset_case( function ( $url ) {
	list( $endpoint ) = parsed_request( $url );
	return response( $endpoint, 'customers' === $endpoint ? array( customer( 1 ) ) : array( payment( 101, 2 ) ) );
} );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_invalid_data' );
reset_case( function ( $url ) {
	list( $endpoint, $query ) = parsed_request( $url );
	if ( 'customers' === $endpoint ) { return response( $endpoint, array( customer( 1 ) ) ); }
	if ( '0' !== $query['offset'] ) { return response( $endpoint, array(), 403 ); }
	$rows = array();
	for ( $id = 101; $id <= 200; $id++ ) { $rows[] = payment( $id, 1 ); }
	return response( $endpoint, $rows );
} );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_http' );

// Missing money or invalid refund amounts cannot silently become zero.
reset_case( function ( $url ) {
	list( $endpoint ) = parsed_request( $url );
	$row = payment( 101, 1, 'refunded', 10001 );
	return response( $endpoint, 'customers' === $endpoint ? array( customer( 1 ) ) : array( $row ) );
} );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_invalid_data' );
reset_case( function () { $row = customer( 1 ); unset( $row['subscription']['plan']['amount'] ); return response( 'customers', array( $row ) ); } );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_invalid_data' );

// Numeric MoonClerk IDs must never inherit another account's saved relationship.
$identity_mock = function ( $url ) {
	list( $endpoint ) = parsed_request( $url );
	return response( $endpoint, 'customers' === $endpoint ? array( customer( 1 ) ) : array() );
};
reset_case( $identity_mock );
$bound = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $bound ) && 'initial_setup' === $bound['account_binding']['method'], 'Initial scoped setup must not require a prior account binding.' );
check( ! empty( $bound['account_binding']['key_fingerprint'] ) && false === strpos( json_encode( $bound ), 'not-a-real-key' ), 'The account binding must use an opaque fingerprint, never the key.' );
$GLOBALS['options']['spm_monitor_snapshot_moonclerk'] = $bound;
$GLOBALS['options']['spm_monitor_state'] = array( 'records' => array( 'moonclerk:1' => array_replace( $bound['records'][0], array( 'notes' => array( 'Private existing note.' ) ) ) ) );
$GLOBALS['options']['spm_moonclerk_api_key'] = 'a-rotated-key';
$result = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $result ) && 'customer_reference' === $result['account_binding']['method'], 'Same-account key rotation should verify the saved Stripe customer reference automatically.' );
check( $result['account_binding']['key_fingerprint'] !== $bound['account_binding']['key_fingerprint'], 'Successful rotation should produce a new key binding.' );
check( false === strpos( json_encode( $result ), 'Private existing note.' ), 'The collector must not export prior notes while checking account identity.' );

// A key change without any known identities is not a verified empty account.
$GLOBALS['mock_http'] = function ( $url ) {
	list( $endpoint ) = parsed_request( $url );
	return response( $endpoint, 'customers' === $endpoint ? array( customer( 999 ) ) : array() );
};
$GLOBALS['calls'] = array();
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_account_unverified' );
check( 1 === count( $GLOBALS['calls'] ), 'An unverified account switch must stop before reading any new donor payments.' );
check( $bound === $GLOBALS['options']['spm_monitor_snapshot_moonclerk'], 'An account verification failure must not replace the prior snapshot.' );

// A colliding numeric ID with a different Stripe identity always fails, even with the same key.
$GLOBALS['options']['spm_moonclerk_api_key'] = 'not-a-real-key';
$GLOBALS['mock_http'] = function ( $url ) {
	list( $endpoint ) = parsed_request( $url );
	$row = customer( 1 ); $row['customer_reference'] = 'cus_different_account';
	return response( $endpoint, 'customers' === $endpoint ? array( $row ) : array() );
};
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_invalid_data' );

// Same bound account may legitimately lose a plan; retain that signal for the master register.
$GLOBALS['mock_http'] = function () { return response( 'customers', array() ); };
$result = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $result ) && array() === $result['records'], 'An unchanged bound key may report a genuinely missing plan.' );

// Upgrade from snapshots predating this guard verifies identity without user action.
unset( $GLOBALS['options']['spm_monitor_snapshot_moonclerk']['account_binding'] );
$GLOBALS['mock_http'] = $identity_mock;
$result = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $result ) && 'customer_reference' === $result['account_binding']['method'], 'Existing unbound snapshots should bootstrap through matching customer identity.' );
$GLOBALS['options']['spm_monitor_snapshot_moonclerk']['records'][0]['customer_ref'] = '';
$GLOBALS['options']['spm_monitor_state']['records']['moonclerk:1']['customer_ref'] = '';
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_account_unverified' );

// Dedicated forms include new sponsors without importing donors from shared forms.
reset_case( function ( $url ) {
	list( $endpoint, $query ) = parsed_request( $url );
	if ( 'customers' === $endpoint ) {
		$dedicated = customer( 55, '' ); $dedicated['custom_fields'] = array(); $dedicated['form_id'] = 777;
		$shared = customer( 56, '' ); $shared['custom_fields'] = array(); $shared['form_id'] = 999;
		return response( $endpoint, array( $dedicated, $shared ) );
	}
	check( '55' === $query['customer_id'], 'Only a configured dedicated form may include a donor without partner fields.' );
	return response( $endpoint, array( payment( 555, 55 ) ) );
}, '' );
$GLOBALS['options']['spm_moonclerk_form_ids'] = '777';
$result = SPM_MoonClerk_Source::collect();
check( ! is_wp_error( $result ) && array( 'moonclerk:55' ) === array_column( $result['records'], 'id' ), 'Dedicated form IDs must scope otherwise undesignated sponsors precisely.' );
check( '777' === $result['records'][0]['form_id'], 'Normalized records should retain the dedicated form identity.' );
check( false === strpos( json_encode( $result ), 'donor56@' ), 'Shared-form donor details must not enter the dedicated-form snapshot.' );
$GLOBALS['options']['spm_moonclerk_form_ids'] = 'public-checkout-token';
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_scope_invalid' );
reset_case( function () { return response( 'customers', array( customer( 1, 'Some other project' ) ) ); } );
expect_error( SPM_MoonClerk_Source::collect(), 'spm_moonclerk_scope_empty' );

echo 'MoonClerk source: ' . $GLOBALS['assertions'] . " assertions passed.\n";
