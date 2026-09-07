<?php
/** Authenticated review interface shared by WordPress forms, REST and WP-CLI. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SPM_Monitor_Actions {
    private static function error( $text ) { return new WP_Error( 'spm_validation', $text ); }
    private static function text( $data, $key, $default = '' ) { return SPM_Monitor::safe_text( $data[ $key ] ?? $default ); }

    public static function apply( $data ) {
        if ( ! is_array( $data ) ) { return self::error( 'Expected an action object.' ); }
        $op = self::text( $data, 'op' );
        if ( 'refresh' === $op ) { return SPM_Monitor::refresh( self::text( $data, 'source', 'all' ) ); }
        if ( 'settings' === $op ) { return self::settings( $data ); }
        if ( 'review_import' === $op ) {
            $batch = json_decode( $data['review_json'] ?? '', true );
            if ( ! is_array( $batch ) || ! isset( $batch['actions'] ) || ! is_array( $batch['actions'] ) || count( $batch['actions'] ) > 100 ) { return self::error( 'Provide valid review JSON with an actions array of at most 100 entries.' ); }
        } else { $batch = [ 'actions' => [ $data ] ]; }
        $result = SPM_Monitor::mutate( static function( &$state ) use ( $batch ) {
            $results = [];
            foreach ( $batch['actions'] as $action ) {
                if ( ! is_array( $action ) ) { return self::error( 'Each review action must be an object.' ); }
                $result = self::apply_state( $state, $action );
                if ( is_wp_error( $result ) ) { return $result; }
                $results[] = $result;
            }
            if ( isset( $batch['review'] ) ) {
                $review = $batch['review'];
                if ( ! is_array( $review ) || '' === self::text( $review, 'summary' ) ) { return self::error( 'Email review requires a factual summary.' ); }
                $from = SPM_Monitor::date( self::text( $review, 'from' ) );
                $to = SPM_Monitor::date( self::text( $review, 'to' ) );
                if ( ! $from || ! $to || $from > $to || $to > time() + DAY_IN_SECONDS ) { return self::error( 'Email review requires valid covered dates.' ); }
                $state['sources']['email'] = [ 'status' => 'ok', 'checked_at' => time(), 'last_success' => time(), 'error' => '', 'from' => $from, 'to' => $to, 'summary' => self::text( $review, 'summary' ) ];
            }
            return [ 'message' => 'Review saved.', 'results' => $results ];
        } );
        if ( ! is_wp_error( $result ) ) { SPM_Monitor::reconcile(); }
        return $result;
    }

    public static function apply_state( &$state, $data ) {
        $op = self::text( $data, 'op' );
        $record_id = self::text( $data, 'record_id' );
        $issue_id = self::text( $data, 'issue_id' );
        if ( 'record' === $op ) { return self::record( $state, $data ); }
        if ( $issue_id && ! isset( $state['issues'][ $issue_id ] ) ) { return self::error( 'Issue not found.' ); }
        if ( $record_id && ! isset( $state['records'][ $record_id ] ) ) { return self::error( 'Agreement not found.' ); }
        if ( 'note' === $op ) {
            if ( '' === self::text( $data, 'note' ) || ( ! $issue_id && ! $record_id ) ) { return self::error( 'Choose an agreement or issue and enter a note.' ); }
            if ( $issue_id ) { $target =& $state['issues'][ $issue_id ]; } else { $target =& $state['records'][ $record_id ]; }
            SPM_Monitor::append_note( $target, self::text( $data, 'note' ), self::text( $data, 'evidence_url' ) );
            return [ 'message' => 'Note saved.', 'id' => $issue_id ?: $record_id ];
        }
        if ( 'issue' === $op ) {
            if ( ! $issue_id ) { return self::error( 'Choose an issue.' ); }
            $status = self::text( $data, 'status' );
            $reason = self::text( $data, 'resolution' );
            $follow = SPM_Monitor::date( self::text( $data, 'follow_up_date' ) );
            if ( ! in_array( $status, [ 'open', 'waiting', 'snoozed', 'resolved' ], true ) ) { return self::error( 'Unknown issue status.' ); }
            if ( 'open' !== $status && '' === $reason ) { return self::error( 'Add an explanation before waiting, snoozing or resolving an issue.' ); }
            if ( false === $follow || ( in_array( $status, [ 'waiting', 'snoozed' ], true ) && $follow < strtotime( 'today UTC' ) ) ) { return self::error( 'Waiting and snoozed issues require a current or future follow-up date.' ); }
            $issue =& $state['issues'][ $issue_id ];
            $issue['status'] = $status; $issue['resolution'] = $reason; $issue['follow_up_at'] = $follow; $issue['auto_resolved'] = false;
            $issue['updated_at'] = time();
            if ( 'resolved' === $status ) { $issue['resolved_at'] = time(); }
            SPM_Monitor::append_note( $issue, ucfirst( $status ) . ': ' . $reason, self::text( $data, 'evidence_url' ) );
            return [ 'message' => 'Issue updated.', 'id' => $issue_id ];
        }
        if ( 'flag' === $op ) {
            $title = self::text( $data, 'title' ); $detail = self::text( $data, 'detail' );
            if ( ! $record_id || ! $title || ! $detail ) { return self::error( 'Choose an agreement and provide an issue title and details.' ); }
            $record = $state['records'][ $record_id ];
            $id = 'manual_' . substr( hash( 'sha256', $record_id . '|' . $title ), 0, 24 );
            $existing = $state['issues'][ $id ] ?? [];
            $state['issues'][ $id ] = array_replace( [ 'id' => $id, 'record_id' => $record_id, 'source' => $record['source'], 'kind' => 'manual', 'severity' => 'review', 'status' => 'open', 'first_seen' => time(), 'notes' => [], 'follow_up_at' => 0, 'resolution' => '', 'amount_cents' => $record['amount_cents'], 'currency' => $record['currency'] ], $existing, [ 'title' => $title, 'detail' => $detail, 'last_seen' => time(), 'evidence' => [ [ 'url' => esc_url_raw( self::text( $data, 'evidence_url' ), [ 'https' ] ), 'note' => 'Supporting evidence' ] ] ] );
            return [ 'message' => 'Issue flagged.', 'id' => $id ];
        }
        if ( 'evidence' === $op ) { return self::evidence( $state, $data ); }
        return self::error( 'Unsupported review action.' );
    }

    private static function record( &$state, $data ) {
        $id = self::text( $data, 'record_id' );
        $existing = $id ? ( $state['records'][ $id ] ?? null ) : null;
        if ( $id && ! $existing ) { return self::error( 'Agreement not found.' ); }
        $source = $existing['source'] ?? self::text( $data, 'source', 'check' );
        if ( ! $existing && ! in_array( $source, [ 'manual', 'check', 'mdm_remittance' ], true ) ) { return self::error( 'New agreements must use check, manual or MDM remittance.' ); }
        $expected = self::text( $data, 'expected', $existing['expected'] ?? 'active' );
        $category = self::text( $data, 'category', $existing['category'] ?? 'other' );
        if ( ! in_array( $expected, [ 'active', 'paused', 'ended', 'review' ], true ) || ! in_array( $category, [ 'maintenance', 'sponsorship', 'mdm_remittance', 'other' ], true ) ) { return self::error( 'Invalid expectation or category.' ); }
        $grace = filter_var( $data['grace_days'] ?? $existing['grace_days'] ?? 7, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0, 'max_range' => 120 ] ] );
        if ( false === $grace ) { return self::error( 'Grace period must be between 0 and 120 days.' ); }
        $record = $existing ?: [ 'id' => $source . ':' . wp_generate_uuid4(), 'source' => $source, 'source_ref' => '', 'notes' => [], 'created_at' => time(), 'last_paid' => 0, 'status' => 'manual', 'url' => '' ];
        $record['expected'] = $expected; $record['category'] = $category; $record['grace_days'] = $grace;
        $record['site_url'] = esc_url_raw( self::text( $data, 'site_url', $record['site_url'] ?? '' ), [ 'https', 'http' ] );
        if ( in_array( $source, [ 'manual', 'check', 'mdm_remittance' ], true ) ) {
            $name = self::text( $data, 'name', $record['name'] ?? '' );
            $currency = strtolower( self::text( $data, 'currency', $record['currency'] ?? 'usd' ) );
            $amount = SPM_Monitor::amount( $data['amount'] ?? ( ( $record['amount_cents'] ?? 0 ) / 100 ) );
            $interval = self::text( $data, 'interval', $record['interval'] ?? 'month' );
            $count = filter_var( $data['interval_count'] ?? $record['interval_count'] ?? 1, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1, 'max_range' => 120 ] ] );
            $next = SPM_Monitor::date( self::text( $data, 'next_due', empty( $record['next_due'] ) ? '' : gmdate( 'Y-m-d', $record['next_due'] ) ) );
            if ( ! $name || false === $amount || ! in_array( $currency, [ 'usd', 'eur', 'gbp', 'cad', 'aud', 'nzd', 'chf', 'try' ], true ) || ! in_array( $interval, [ 'day', 'week', 'month', 'year' ], true ) || false === $count || false === $next ) { return self::error( 'Check the name, amount, supported currency, schedule and next date.' ); }
            $record = array_replace( $record, [ 'name' => $name, 'email' => sanitize_email( self::text( $data, 'email', $record['email'] ?? '' ) ), 'amount_cents' => $amount, 'currency' => $currency, 'interval' => $interval, 'interval_count' => $count, 'next_due' => $next, 'customer_ref' => self::text( $data, 'customer_ref', $record['customer_ref'] ?? '' ) ] );
            if ( $next && ( ! $existing || $next !== ( $existing['next_due'] ?? 0 ) ) ) { $record['anchor_day'] = (int) gmdate( 'j', $next ); }
        }
        $record['updated_at'] = time();
        if ( $existing && ( $existing['expected'] ?? '' ) !== $expected ) { SPM_Monitor::append_note( $record, 'Expected status changed to ' . $expected . '.', self::text( $data, 'evidence_url' ) ); }
        $state['records'][ $record['id'] ] = $record;
        return [ 'message' => 'Agreement saved.', 'id' => $record['id'] ];
    }

    private static function evidence( &$state, $data ) {
        $id = self::text( $data, 'record_id' );
        if ( ! isset( $state['records'][ $id ] ) ) { return self::error( 'Choose an agreement.' ); }
        $record =& $state['records'][ $id ];
        $amount = SPM_Monitor::amount( $data['amount'] ?? '' );
        $currency = strtolower( self::text( $data, 'currency', $record['currency'] ?? 'usd' ) );
        $paid = SPM_Monitor::date( self::text( $data, 'paid_date' ) );
        $end = SPM_Monitor::date( self::text( $data, 'period_end' ) );
        $note = self::text( $data, 'note' );
        $url = SPM_Monitor::safe_url( $data['evidence_url'] ?? '' );
        $payment_id = self::text( $data, 'source_payment_id' );
        if ( $payment_id ) {
            $matched = null;
            foreach ( [ 'stripe', 'moonclerk' ] as $source ) {
                $health = $state['sources'][ $source ] ?? [];
                if ( 'ok' !== ( $health['status'] ?? '' ) || ( $health['last_success'] ?? 0 ) < time() - DAY_IN_SECONDS ) { continue; }
                foreach ( get_option( 'spm_monitor_snapshot_' . $source, [] )['payments'] ?? [] as $payment ) { if ( ( $payment['id'] ?? '' ) === $payment_id ) { $matched = $payment; break 2; } }
            }
            if ( ! $matched || ! in_array( $matched['status'], [ 'paid', 'refunded' ], true ) || ! empty( $matched['disputed'] ) ) { return self::error( 'That payment is not confirmed in a fresh successful source check.' ); }
            if ( ! empty( $matched['record_id'] ) && $matched['record_id'] !== $id ) { return self::error( 'That payment already belongs to a different subscription.' ); }
            if ( ! empty( $record['customer_ref'] ) && ! empty( $matched['customer_ref'] ) && $record['customer_ref'] !== $matched['customer_ref'] ) { return self::error( 'That payment belongs to a different customer.' ); }
            if ( 'mdm_remittance' === $record['source'] && 'stripe' !== $matched['source'] ) { return self::error( 'An LLC remittance requires the LLC Stripe receipt; a donor payment to MDM is a separate stage.' ); }
            foreach ( $state['manual_payments'] as $existing ) {
                if ( ( $existing['source_payment_id'] ?? '' ) !== $payment_id ) { continue; }
                if ( $existing['record_id'] === $id && $existing['period_end'] === $end ) { return [ 'message' => 'This source payment was already recorded.', 'id' => $existing['id'] ]; }
                return self::error( 'That source payment has already been allocated. It cannot pay another agreement or period.' );
            }
            $amount = (int) ( $matched['captured_cents'] ?? $matched['amount_cents'] ) - (int) $matched['refunded_cents'];
            $currency = $matched['currency']; $paid = $matched['paid_at']; $url = $matched['url'] ?? $url;
        }
        if ( false === $amount || $amount <= 0 || ! $paid || ! $end || $paid > time() + DAY_IN_SECONDS || $currency !== ( $record['currency'] ?? '' ) || ( ! $url && ! $note ) ) { return self::error( 'Provide a positive confirmed amount, matching currency, payment date, covered-through date and supporting note or link.' ); }
        $evidence_id = 'evidence:' . hash( 'sha256', $id . '|' . ( $payment_id ?: $url . '|' . $amount . '|' . $paid ) );
        if ( isset( $state['manual_payments'][ $evidence_id ] ) ) {
            if ( $state['manual_payments'][ $evidence_id ]['period_end'] !== $end ) { return self::error( 'This receipt is already recorded for another covered period.' ); }
            return [ 'message' => 'This evidence was already recorded.', 'id' => $evidence_id ];
        }
        $due = (int) ( $record['next_due'] ?? 0 );
        $state['manual_payments'][ $evidence_id ] = [ 'id' => $evidence_id, 'record_id' => $id, 'source' => 'evidence', 'source_payment_id' => $payment_id, 'amount_cents' => $amount, 'refunded_cents' => 0, 'currency' => $currency, 'paid_at' => $paid, 'period_end' => $end, 'due_at' => $due, 'status' => 'paid', 'url' => $url, 'note' => $note, 'created_at' => time() ];
        $total = 0;
        foreach ( $state['manual_payments'] as $evidence ) { if ( $evidence['record_id'] === $id && $evidence['currency'] === $currency && $evidence['period_end'] === $end && ( $evidence['due_at'] ?? 0 ) === $due ) { $total += $evidence['amount_cents']; } }
        $record['last_paid'] = max( (int) ( $record['last_paid'] ?? 0 ), $paid );
        if ( in_array( $record['source'], [ 'manual', 'check', 'mdm_remittance' ], true ) && $due && $end >= $due && $total >= (int) $record['amount_cents'] ) {
            // Advance a single documented installment, never infer months of coverage from a lump sum.
            $record['next_due'] = SPM_Monitor::next_date( $due, $record['interval'], $record['interval_count'], $record['anchor_day'] ?? null );
        }
        SPM_Monitor::append_note( $record, 'Payment evidence recorded: ' . strtoupper( $currency ) . ' ' . number_format( $amount / 100, 2, '.', '' ) . ', covered through ' . gmdate( 'Y-m-d', $end ) . '. ' . $note, $url );
        return [ 'message' => 'Evidence saved. Provider payment records were not changed.', 'id' => $evidence_id ];
    }

    private static function settings( $data ) {
        // Keys must not pass through the note redactor.
        $stripe = isset( $data['spm_stripe_key'] ) && is_string( $data['spm_stripe_key'] ) ? trim( $data['spm_stripe_key'] ) : '';
        $moon = isset( $data['spm_moonclerk_key'] ) && is_string( $data['spm_moonclerk_key'] ) ? trim( $data['spm_moonclerk_key'] ) : '';
        if ( $stripe && ! preg_match( '/^(?:rk|sk)_(?:live|test)_[A-Za-z0-9]+$/', $stripe ) ) { return self::error( 'Enter a valid Stripe restricted or secret key, or leave blank to keep the saved key.' ); }
        if ( strlen( $moon ) > 512 || ( $moon && preg_match( '/\s/', $moon ) ) ) { return self::error( 'The MoonClerk key format is invalid.' ); }
        if ( $moon && defined( 'SPM_MOONCLERK_API_KEY' ) && is_string( SPM_MOONCLERK_API_KEY ) && '' !== trim( SPM_MOONCLERK_API_KEY ) && ! hash_equals( trim( SPM_MOONCLERK_API_KEY ), $moon ) ) { return self::error( 'A server constant supplies the MoonClerk key. Update that server setting, or leave this key field blank to preserve it.' ); }
        $scope = self::text( $data, 'moonclerk_partner_allowlist' );
        $ids = self::text( $data, 'moonclerk_customer_ids' );
        $forms = self::text( $data, 'moonclerk_form_ids', get_option( 'spm_moonclerk_form_ids', '' ) );
        if ( $ids && ! preg_match( '/^[0-9,\s]+$/', $ids ) ) { return self::error( 'MoonClerk customer IDs must contain only numbers separated by commas or newlines.' ); }
        if ( $forms && ! preg_match( '/^[0-9,\s]+$/', $forms ) ) { return self::error( 'MoonClerk dedicated form IDs must contain only numbers separated by commas or newlines.' ); }
        return SPM_Monitor::mutate( static function( &$state ) use ( $stripe, $moon, $scope, $ids, $forms ) {
            if ( $stripe ) { update_option( 'spm_stripe_secret_key', $stripe, false ); $state['sources']['stripe']['status'] = 'stale'; }
            if ( $moon ) { update_option( 'spm_moonclerk_api_key', $moon, false ); }
            if ( get_option( 'spm_moonclerk_partner_allowlist', '' ) !== $scope || get_option( 'spm_moonclerk_customer_ids', '' ) !== $ids || get_option( 'spm_moonclerk_form_ids', '' ) !== $forms || $moon ) { $state['sources']['moonclerk']['status'] = 'stale'; }
            update_option( 'spm_moonclerk_partner_allowlist', $scope, false );
            update_option( 'spm_moonclerk_customer_ids', $ids, false );
            update_option( 'spm_moonclerk_form_ids', $forms, false );
            return [ 'message' => $moon ? 'Settings saved. Refresh MoonClerk to verify the connection and existing account identity before replacing payment evidence.' : 'Settings saved. Refresh the sources to verify the connection.' ];
        } );
    }
}

add_action( 'admin_post_spm_monitor_action', static function() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You cannot manage the Payments Monitor.', '', [ 'response' => 403 ] ); }
    check_admin_referer( 'spm_monitor_action' );
    $result = SPM_Monitor_Actions::apply( wp_unslash( $_POST ) );
    if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Payments Monitor', [ 'response' => 400, 'back_link' => true ] ); }
    $page = isset( $_POST['op'] ) && 'settings' === $_POST['op'] ? 'stripe-payments-monitor-settings' : 'stripe-payments-monitor';
    wp_safe_redirect( admin_url( 'admin.php?page=' . $page . '&spm_saved=1' ) );
    exit;
} );

add_action( 'rest_api_init', static function() {
    $permission = static function() { return current_user_can( 'manage_options' ); };
    register_rest_route( 'spm/v1', '/report', [ 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function() { $response = new WP_REST_Response( SPM_Monitor::report() ); $response->header( 'Cache-Control', 'private, no-store' ); return $response; } ] );
    register_rest_route( 'spm/v1', '/review', [ 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => static function( $request ) { $data = $request->get_json_params(); if ( ! is_array( $data ) || ! in_array( $data['op'] ?? '', [ 'note', 'issue', 'record', 'evidence', 'flag', 'review_import' ], true ) ) { return new WP_Error( 'spm_action', 'Unsupported review action.', [ 'status' => 400 ] ); } $result = SPM_Monitor_Actions::apply( $data ); if ( is_wp_error( $result ) ) { $result->add_data( [ 'status' => 400 ] ); return $result; } $response = new WP_REST_Response( $result ); $response->header( 'Cache-Control', 'private, no-store' ); return $response; } ] );
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'spm report', static function() { WP_CLI::line( wp_json_encode( SPM_Monitor::report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); } );
    WP_CLI::add_command( 'spm refresh', static function( $args, $assoc ) { $result = SPM_Monitor::refresh( $assoc['source'] ?? 'all' ); if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); } WP_CLI::line( wp_json_encode( $result ) ); foreach ( $result as $status ) { if ( empty( $status['ok'] ) ) { WP_CLI::halt( 1 ); } } } );
    WP_CLI::add_command( 'spm review', static function( $args, $assoc ) { $file = $assoc['file'] ?? ''; if ( ! $file || ! is_readable( $file ) || filesize( $file ) > 1048576 ) { WP_CLI::error( 'Provide --file with a readable review JSON file under 1 MB.' ); } $result = SPM_Monitor_Actions::apply( [ 'op' => 'review_import', 'review_json' => file_get_contents( $file ) ] ); if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); } WP_CLI::line( wp_json_encode( $result ) ); } );
}
