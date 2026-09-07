<?php
/**
 * Read-only, explicitly scoped MoonClerk payment evidence.
 *
 * API: https://github.com/moonclerk/developer/tree/main/api
 * No raw responses, unrelated donor records, or management bearer URLs persist.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SPM_MoonClerk_Source {

	/** @return array|WP_Error A complete snapshot, or an error; never partial success. */
	public static function collect() {
		$key = defined( 'SPM_MOONCLERK_API_KEY' ) && is_string( SPM_MOONCLERK_API_KEY )
			? trim( SPM_MOONCLERK_API_KEY ) : '';
		if ( '' === $key ) {
			$key = trim( (string) get_option( 'spm_moonclerk_api_key', '' ) );
		}
		if ( '' === $key ) {
			return new WP_Error( 'spm_moonclerk_key_missing', 'MoonClerk has not been connected. Configure its read-only API key.' );
		}
		$partners = array();
		foreach ( preg_split( '/\R/u', (string) get_option( 'spm_moonclerk_partner_allowlist', '' ) ) as $partner ) {
			$partner = self::fold( trim( $partner ) );
			if ( '' !== $partner && 'other' !== $partner ) {
				$partners[ $partner ] = true;
			}
		}
		$ids = array();
		foreach ( preg_split( '/[\s,]+/', trim( (string) get_option( 'spm_moonclerk_customer_ids', '' ) ) ) as $id ) {
			if ( '' !== $id ) {
				if ( ! preg_match( '/^[1-9][0-9]*$/D', $id ) ) {
					return new WP_Error( 'spm_moonclerk_scope_invalid', 'MoonClerk customer scope must contain numeric customer IDs separated by commas or newlines.' );
				}
				$ids[ $id ] = true;
			}
		}
		$forms = array();
		foreach ( preg_split( '/[\s,]+/', trim( (string) get_option( 'spm_moonclerk_form_ids', '' ) ) ) as $id ) {
			if ( '' === $id ) { continue; }
			if ( ! preg_match( '/^[1-9][0-9]*$/D', $id ) ) {
				return new WP_Error( 'spm_moonclerk_scope_invalid', 'MoonClerk form scope must contain numeric dedicated form IDs separated by commas or newlines.' );
			}
			$forms[ $id ] = true;
		}
		if ( ! $partners && ! $ids && ! $forms ) {
			return new WP_Error( 'spm_moonclerk_scope_missing', 'MoonClerk needs an exact partner designation, customer ID, or dedicated form ID scope before it can monitor sponsors. No MDM donors were imported.' );
		}

		$context = array( 'key' => $key, 'requests' => 0, 'started' => microtime( true ) );
		// The API exposes no account endpoint. A keyed fingerprint detects key changes,
		// while globally distinctive Stripe customer references verify account continuity.
		$fingerprint = hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
		$prior_snapshot = get_option( 'spm_monitor_snapshot_moonclerk', array() );
		$prior_state = get_option( 'spm_monitor_state', array() );
		$prior_fingerprint = is_array( $prior_snapshot ) ? ( $prior_snapshot['account_binding']['key_fingerprint'] ?? '' ) : '';
		$unchanged_key = is_string( $prior_fingerprint ) && '' !== $prior_fingerprint && hash_equals( $prior_fingerprint, $fingerprint );
		$identities = array();
		foreach ( array_merge( is_array( $prior_snapshot ) ? ( $prior_snapshot['records'] ?? array() ) : array(), is_array( $prior_state ) ? ( $prior_state['records'] ?? array() ) : array() ) as $prior ) {
			if ( ! is_array( $prior ) || 'moonclerk' !== ( $prior['source'] ?? '' ) ) { continue; }
			$id = (string) ( $prior['source_ref'] ?? '' );
			if ( ! preg_match( '/^[1-9][0-9]*$/D', $id ) ) { continue; }
			$identities[ $id ] = is_string( $prior['customer_ref'] ?? null ) ? $prior['customer_ref'] : '';
		}
		$identity_matches = 0;
		$records = array();
		$payments = array();
		try {
			// Do not filter to active: unexpected cancellations must stay observable.
			$result = self::pages( 'customers', array(), $context, function ( $customer ) use ( &$records, $partners, $ids, $forms, $identities, &$identity_matches ) {
				$id = (string) $customer['id'];
				if ( isset( $identities[ $id ] ) && '' !== $identities[ $id ] ) {
					$current_ref = $customer['customer_reference'] ?? '';
					if ( ! is_string( $current_ref ) || ! hash_equals( $identities[ $id ], $current_ref ) ) {
						throw new UnexpectedValueException( 'MoonClerk customer IDs no longer match the saved customer identities. The existing register has been protected; verify the original account before importing.' );
					}
					$identity_matches++;
				}
				$designations = self::designations( $customer );
				$form_id = is_int( $customer['form_id'] ?? null ) || is_string( $customer['form_id'] ?? null ) ? (string) $customer['form_id'] : '';
				$matches = isset( $ids[ (string) $customer['id'] ] ) || isset( $forms[ $form_id ] );
				foreach ( $designations as $designation ) {
					$matches = $matches || isset( $partners[ self::fold( $designation ) ] );
				}
				$matches = $matches || isset( $partners[ self::fold( implode( ' | ', $designations ) ) ] );
				if ( $matches ) {
					$records[] = self::record( $customer, implode( ' | ', $designations ) );
				}
			} );
			if ( is_wp_error( $result ) ) { return $result; }
			$customer_count = $result;
			if ( $identities && ! $unchanged_key && 0 === $identity_matches ) {
				return new WP_Error( 'spm_moonclerk_account_unverified', 'The MoonClerk key changed or has no saved account binding, and the existing monitored customer identities could not verify the same account. No records or notes were replaced. Reconnect the original account or review an explicit account migration.' );
			}
			if ( ! $records && ! $identities ) {
				return new WP_Error( 'spm_moonclerk_scope_empty', 'No MoonClerk customers match the configured scope. Verify the exact partner names, customer IDs, or dedicated form IDs; this has not been recorded as an all-clear check.' );
			}
			$seen_payments = array();
			foreach ( $records as $record ) {
				$result = self::pages( 'payments', array(
					'customer_id' => $record['source_ref'],
					'date_from' => gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ),
				), $context, function ( $payment ) use ( &$payments, &$seen_payments, $record ) {
					// Treat an ignored customer filter as a failure, never import another donor.
					if ( (string) ( $payment['customer_id'] ?? '' ) !== $record['source_ref'] ) {
						throw new UnexpectedValueException( 'MoonClerk returned a payment outside the requested customer scope.' );
					}
					$id = (string) $payment['id'];
					if ( isset( $seen_payments[ $id ] ) ) {
						throw new UnexpectedValueException( 'MoonClerk returned a duplicate payment across customer queries.' );
					}
					$seen_payments[ $id ] = true;
					$payments[] = self::payment( $payment );
				} );
				if ( is_wp_error( $result ) ) { return $result; }
			}
		} catch ( UnexpectedValueException $error ) {
			return new WP_Error( 'spm_moonclerk_invalid_data', $error->getMessage() );
		}

		return array(
			'source' => 'moonclerk', 'checked_at' => time(),
			'records' => $records, 'payments' => $payments,
			'account_binding' => array( 'key_fingerprint' => $fingerprint, 'verified_at' => time(),
				'method' => $identity_matches > 0 ? 'customer_reference' : ( $unchanged_key ? 'unchanged_key' : 'initial_setup' ) ),
			'health' => array( 'complete' => true, 'customers_scanned' => $customer_count,
				'records_retained' => count( $records ), 'payments_retained' => count( $payments ),
				'payment_history_days' => 400, 'requests' => $context['requests'] ),
		);
	}

	/** Stream list pages so unrelated donor payloads are not retained in the snapshot. */
	private static function pages( $endpoint, $query, &$context, $accept ) {
		$max_pages = defined( 'SPM_MOONCLERK_MAX_PAGES' ) ? max( 1, min( 1000, (int) SPM_MOONCLERK_MAX_PAGES ) ) : 100;
		$seen = array();
		$total = 0;
		for ( $page = 0; $page < $max_pages; $page++ ) {
			$data = self::request( $endpoint, array_merge( $query, array( 'count' => 100, 'offset' => $page * 100 ) ), $context );
			if ( is_wp_error( $data ) ) { return $data; }
			if ( ! isset( $data[ $endpoint ] ) || ! is_array( $data[ $endpoint ] ) ) {
				return new WP_Error( 'spm_moonclerk_shape', 'MoonClerk returned an invalid ' . $endpoint . ' list. The previous snapshot was retained.' );
			}
			$rows = $data[ $endpoint ];
			if ( count( $rows ) > 100 || array_values( $rows ) !== $rows ) {
				return new WP_Error( 'spm_moonclerk_shape', 'MoonClerk returned an invalid page shape. The previous snapshot was retained.' );
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! ( is_int( $row['id'] ) || is_string( $row['id'] ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $row['id'] ) ) {
					return new WP_Error( 'spm_moonclerk_shape', 'MoonClerk returned a record without a valid numeric ID. The previous snapshot was retained.' );
				}
				$id = (string) $row['id'];
				if ( isset( $seen[ $id ] ) ) {
					return new WP_Error( 'spm_moonclerk_repeated_page', 'MoonClerk pagination repeated a record. Refresh is incomplete; the previous snapshot was retained.' );
				}
				$seen[ $id ] = true;
				$accept( $row );
				$total++;
			}
			if ( count( $rows ) < 100 ) { return $total; }
		}
		return new WP_Error( 'spm_moonclerk_page_limit', 'MoonClerk reached the pagination safety limit before confirming the end of the list. Refresh is incomplete.' );
	}

	private static function request( $endpoint, $query, &$context ) {
		$url = 'https://api.moonclerk.com/' . $endpoint . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			if ( $context['requests'] >= 250 || microtime( true ) - $context['started'] > 120 ) {
				return new WP_Error( 'spm_moonclerk_request_limit', 'MoonClerk exceeded the refresh request or time budget. Refresh is incomplete.' );
			}
			$context['requests']++;
			$response = wp_remote_get( $url, array(
				'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 4 * 1024 * 1024,
				'headers' => array( 'Authorization' => 'Token token=' . $context['key'], 'Accept' => 'application/vnd.moonclerk+json;version=1' ),
			) );
			$http = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$retryable = 0 === $http || 429 === $http || $http >= 500;
			if ( $retryable && $attempt < 2 ) {
				// Bound retry delay; a later scheduled refresh handles longer outages.
				usleep( 200000 * ( $attempt + 1 ) );
				continue;
			}
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'spm_moonclerk_transport', 'MoonClerk could not be reached after bounded retries. The previous snapshot was retained.' );
			}
			if ( 200 !== $http ) {
				return new WP_Error( 'spm_moonclerk_http', 'MoonClerk returned HTTP ' . $http . ' for ' . $endpoint . '. Refresh failed; the previous snapshot was retained.' );
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				return new WP_Error( 'spm_moonclerk_json', 'MoonClerk returned invalid JSON. Refresh failed; the previous snapshot was retained.' );
			}
			return $data;
		}
		return new WP_Error( 'spm_moonclerk_retry_limit', 'MoonClerk exhausted its refresh retries.' );
	}

	private static function designations( $customer ) {
		$values = array();
		$fields = isset( $customer['custom_fields'] ) && is_array( $customer['custom_fields'] ) ? $customer['custom_fields'] : array();
		foreach ( array( 'choose_the_partner_youre_supporting', 'which_mdm_partner_are_you_supporting' ) as $key ) {
			$value = $fields[ $key ]['response'] ?? '';
			if ( is_string( $value ) && '' !== trim( $value ) && 'other' !== self::fold( trim( $value ) ) ) {
				$values[] = trim( $value );
			}
		}
		return array_values( array_unique( $values ) );
	}

	private static function record( $customer, $designation ) {
		$subscription = $customer['subscription'] ?? null;
		$plan = is_array( $subscription ) ? ( $subscription['plan'] ?? null ) : null;
		if ( ! is_array( $subscription ) || ! is_array( $plan ) || ! isset( $subscription['status'] ) || ! is_string( $subscription['status'] ) || '' === $subscription['status'] ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk customer is missing subscription or plan details.' );
		}
		$interval = (string) ( $plan['interval'] ?? '' );
		if ( ! in_array( $interval, array( 'day', 'week', 'month', 'year' ), true ) ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk plan has an unsupported billing interval.' );
		}
		$count = self::money( $plan, 'interval_count' );
		if ( $count < 1 ) { throw new UnexpectedValueException( 'A scoped MoonClerk plan has an invalid billing interval count.' ); }
		return array(
			'id' => 'moonclerk:' . $customer['id'], 'source' => 'moonclerk', 'source_ref' => (string) $customer['id'],
			'name' => sanitize_text_field( (string) ( $customer['name'] ?? '' ) ),
			'email' => sanitize_email( (string) ( $customer['email'] ?? '' ) ),
			'status' => sanitize_key( (string) $subscription['status'] ),
			'amount_cents' => self::money( $plan, 'amount' ), 'currency' => self::currency( $plan ),
			'interval' => $interval, 'interval_count' => $count,
			'current_period_start' => self::timestamp( $subscription['current_period_start'] ?? null ),
			'current_period_end' => self::timestamp( $subscription['current_period_end'] ?? null ),
			'next_payment_attempt' => self::timestamp( $subscription['next_payment_attempt'] ?? null ),
			'cancel_at_period_end' => true === ( $subscription['cancel_at_period_end'] ?? false ),
			'canceled_at' => self::timestamp( $subscription['canceled_at'] ?? null ),
			'customer_ref' => sanitize_text_field( (string) ( $customer['customer_reference'] ?? '' ) ),
			'url' => '', 'designation' => sanitize_text_field( $designation ),
			'form_id' => is_int( $customer['form_id'] ?? null ) || is_string( $customer['form_id'] ?? null ) ? (string) $customer['form_id'] : '',
		);
	}

	private static function payment( $payment ) {
		$statuses = array( 'successful' => 'paid', 'failed' => 'failed', 'refunded' => 'refunded' );
		if ( ! isset( $payment['status'] ) || ! is_string( $payment['status'] ) || ! isset( $statuses[ $payment['status'] ] ) ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk payment has an unsupported payment status.' );
		}
		$date = self::timestamp( $payment['date'] ?? null );
		if ( ! $date ) { throw new UnexpectedValueException( 'A scoped MoonClerk payment is missing its payment date.' ); }
		$amount = self::money( $payment, 'amount' );
		$refunded = self::money( $payment, 'amount_refunded', 'refunded' === $payment['status'] ? null : 0 );
		if ( $refunded > $amount ) { throw new UnexpectedValueException( 'A scoped MoonClerk payment has an invalid refund amount.' ); }
		return array(
			'id' => 'moonclerk:' . $payment['id'], 'record_id' => 'moonclerk:' . $payment['customer_id'],
			'source_ref' => (string) $payment['id'], 'source' => 'moonclerk',
			'status' => $statuses[ $payment['status'] ], 'amount_cents' => $amount,
			'refunded_cents' => $refunded, 'fee_cents' => self::money( $payment, 'fee', 'failed' === $payment['status'] ? 0 : null ),
			'currency' => self::currency( $payment ), 'paid_at' => $date,
			'period_start' => 0, 'period_end' => 0, 'url' => '',
		);
	}

	private static function money( $row, $field, $default = null ) {
		$value = $row[ $field ] ?? $default;
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk record has a missing or invalid integer amount.' );
		}
		if ( $value < 0 || (float) $value >= (float) PHP_INT_MAX ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk record has an out-of-range amount.' );
		}
		return (int) $value;
	}

	private static function currency( $row ) {
		$value = $row['currency'] ?? '';
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z]{3}$/D', $value ) ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk record has a missing or invalid currency.' );
		}
		return strtolower( $value );
	}

	private static function timestamp( $value ) {
		if ( null === $value || '' === $value || 0 === $value ) { return 0; }
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T/', $value ) ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk record has an invalid date.' );
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp || $timestamp < 0 ) {
			throw new UnexpectedValueException( 'A scoped MoonClerk record has an invalid date.' );
		}
		return $timestamp;
	}

	private static function fold( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
