<?php
/** Durable expectations, evidence and issue decisions. No payment-provider writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SPM_Monitor {
    const STATE = 'spm_monitor_state';

    public static function state() {
        $state = get_option( self::STATE, [] );
        return array_replace( [ 'version' => 1, 'records' => [], 'issues' => [], 'sources' => [], 'manual_payments' => [], 'last_reconciled' => 0 ], is_array( $state ) ? $state : [] );
    }

    /** MySQL advisory locks serialize cron, administrator and agent updates. */
    private static function locked( $scope, $callback ) {
        global $wpdb;
        $name = 'spm_' . md5( $wpdb->prefix . DB_NAME . $scope );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
            return new WP_Error( 'spm_busy', 'Another Payments Monitor operation is running. Try again shortly.' );
        }
        try {
            wp_cache_delete( self::STATE, 'options' );
            return $callback();
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
        }
    }

    public static function mutate( $callback ) {
        return self::locked( 'state', static function() use ( $callback ) {
            $state = self::state();
            $result = $callback( $state );
            if ( is_wp_error( $result ) ) { return $result; }
            update_option( self::STATE, $state, false );
            return $result;
        } );
    }

    public static function refresh( $source = 'all' ) {
        if ( ! in_array( $source, [ 'all', 'stripe', 'moonclerk' ], true ) ) {
            return new WP_Error( 'spm_source', 'Unknown payment source.' );
        }
        $results = [];
        foreach ( 'all' === $source ? [ 'stripe', 'moonclerk' ] : [ $source ] as $name ) {
            $results[ $name ] = self::locked( 'refresh_' . $name, static function() use ( $name ) {
                $config_hash = self::configuration_hash( $name );
                try {
                    $snapshot = 'stripe' === $name ? SPM_Stripe_Source::collect() : SPM_MoonClerk_Source::collect();
                } catch ( Throwable $error ) {
                    $snapshot = new WP_Error( 'spm_source_failed', 'The source could not be checked. Previous evidence has been retained.' );
                }
                return self::mutate( static function( &$state ) use ( $name, $snapshot, $config_hash ) {
                    $old = $state['sources'][ $name ] ?? [];
                    if ( ! hash_equals( $config_hash, self::configuration_hash( $name ) ) ) {
                        $state['sources'][ $name ] = [ 'status' => 'stale', 'checked_at' => time(), 'last_success' => $old['last_success'] ?? 0, 'error' => 'Source settings changed during refresh. Run a fresh check.' ];
                        return [ 'ok' => false, 'error' => 'Source configuration changed.' ];
                    }
                    if ( is_wp_error( $snapshot ) ) {
                        $message = self::safe_text( $snapshot->get_error_message() );
                        $state['sources'][ $name ] = [ 'status' => 'error', 'checked_at' => time(), 'last_success' => $old['last_success'] ?? 0, 'error' => $message ];
                        return [ 'ok' => false, 'error' => $message ];
                    }
                    if ( ! is_array( $snapshot ) || ! isset( $snapshot['records'], $snapshot['payments'] ) || ! is_array( $snapshot['records'] ) || ! is_array( $snapshot['payments'] ) ) {
                        $state['sources'][ $name ] = [ 'status' => 'error', 'checked_at' => time(), 'last_success' => $old['last_success'] ?? 0, 'error' => 'The source returned an incomplete report. Previous evidence retained.' ];
                        return [ 'ok' => false, 'error' => 'Incomplete source response.' ];
                    }
                    self::merge_snapshot( $state, $name, $snapshot );
                    update_option( 'spm_monitor_snapshot_' . $name, $snapshot, false );
                    $state['sources'][ $name ] = [ 'status' => 'ok', 'checked_at' => time(), 'last_success' => time(), 'error' => '', 'records' => count( $snapshot['records'] ), 'payments' => count( $snapshot['payments'] ) ];
                    self::reconcile_state( $state, $name, $snapshot );
                    $state['last_reconciled'] = time();
                    return [ 'ok' => true, 'records' => count( $snapshot['records'] ), 'payments' => count( $snapshot['payments'] ) ];
                } );
            } );
            if ( is_wp_error( $results[ $name ] ) ) { $results[ $name ] = [ 'ok' => false, 'error' => $results[ $name ]->get_error_message() ]; }
        }
        self::reconcile();
        return $results;
    }

    public static function merge_snapshot( &$state, $source, $snapshot ) {
        $seen = [];
        foreach ( $snapshot['records'] as $record ) {
            $id = (string) ( $record['id'] ?? '' );
            if ( '' === $id ) { continue; }
            $seen[ $id ] = true;
            $old = $state['records'][ $id ] ?? [];
            $initial_expected = in_array( $record['status'] ?? '', [ 'canceled', 'expired', 'incomplete_expired' ], true ) ? 'review' : 'active';
            $record = array_replace( [ 'expected' => $initial_expected, 'category' => 'moonclerk' === $source ? 'sponsorship' : 'other', 'grace_days' => 7, 'site_url' => '', 'notes' => [], 'created_at' => time(), 'next_due' => 0 ], $record );
            foreach ( [ 'expected', 'category', 'grace_days', 'site_url', 'notes', 'created_at', 'next_due' ] as $field ) {
                if ( array_key_exists( $field, $old ) ) { $record[ $field ] = $old[ $field ]; }
            }
            $record['source'] = $source;
            $record['missing'] = false;
            $record['last_seen'] = time();
            $record['last_paid'] = 0;
            foreach ( $snapshot['payments'] as $payment ) {
                if ( ( $payment['record_id'] ?? '' ) === $id && in_array( $payment['status'] ?? '', [ 'paid', 'refunded' ], true ) && empty( $payment['disputed'] ) && ( $payment['captured_cents'] ?? $payment['amount_cents'] ?? 0 ) > ( $payment['refunded_cents'] ?? 0 ) ) {
                    $record['last_paid'] = max( $record['last_paid'], (int) ( $payment['paid_at'] ?? 0 ) );
                }
            }
            // Preserve the existing customer's explanatory notes and manual site links.
            if ( ! $old && 'stripe' === $source ) {
                $alias = strtolower( trim( (string) ( $record['email'] ?? '' ) ) );
                $notes = get_option( 'spm_client_notes', [] );
                if ( ! empty( $notes[ $alias ] ) ) { self::append_note( $record, $notes[ $alias ], '', 'Legacy monitor' ); }
                $ignored = get_option( 'spm_ignore_clients', [] );
                if ( ! empty( $ignored[ $alias ] ) ) {
                    $record['expected'] = 'review';
                    self::append_note( $record, 'Previously ignored in site matching; confirm expected relationship (may pay by check).', '', 'Legacy monitor' );
                }
                foreach ( (array) get_option( 'stripe_pm_site_customer_map', [] ) as $site => $customer ) {
                    if ( $customer === $alias || $customer === ( $record['customer_ref'] ?? '' ) ) { $record['site_url'] = $site; $record['category'] = 'maintenance'; break; }
                }
            }
            $state['records'][ $id ] = $record;
        }
        foreach ( $state['records'] as $id => &$record ) {
            if ( ( $record['source'] ?? '' ) === $source && ! isset( $seen[ $id ] ) ) { $record['missing'] = true; }
        }
        unset( $record );
    }

    private static function candidate( $record, $kind, $title, $detail, $identity = '', $extra = [] ) {
        $id = 'issue_' . substr( hash( 'sha256', $record['id'] . '|' . $kind . '|' . $identity ), 0, 24 );
        return array_replace( [ 'id' => $id, 'record_id' => $record['id'], 'source' => $record['source'], 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'severity' => 'review', 'amount_cents' => $record['amount_cents'] ?? 0, 'currency' => $record['currency'] ?? 'usd', 'evidence' => empty( $record['url'] ) ? [] : [ [ 'url' => $record['url'], 'note' => 'Source record' ] ], 'identity' => $identity ], $extra );
    }

    public static function reconcile_state( &$state, $source, $snapshot, $now = null ) {
        $now = $now ?? time();
        $candidates = [];
        $invoices = $snapshot['invoices'] ?? [];
        $invoice_records = [];
        foreach ( $invoices as $invoice ) {
            $rid = $invoice['record_id'] ?? '';
            if ( $rid ) { $invoice_records[ $rid ][] = $invoice; }
            if ( 'draft' === ( $invoice['status'] ?? '' ) && isset( $state['records'][ $rid ] ) ) {
                $record = $state['records'][ $rid ];
                $start = (int) ( $record['current_period_start'] ?? 0 );
                $created = (int) ( $invoice['created'] ?? 0 );
                $grace = max( 0, (int) ( $record['grace_days'] ?? 7 ) ) * DAY_IN_SECONDS;
                $invoice_start = (int) ( $invoice['period_start'] ?? 0 );
                $invoice_end = (int) ( $invoice['period_end'] ?? 0 );
                $current = $invoice_end > $start && ( ! $invoice_start || $invoice_start <= $start );
                if ( ! $invoice_end ) { $current = $created >= $start - DAY_IN_SECONDS; }
                if ( 'active' === ( $record['expected'] ?? '' ) && 'active' === ( $record['status'] ?? '' ) && $start > 0 && $created > 0 && $current && max( $start, $created ) + $grace < $now ) {
                    $candidates[] = self::candidate( $record, 'invoice_draft', 'Current invoice is still a draft', 'The invoice for this expected billing period is still a draft after the agreement grace period. Review finalization and collection settings; a draft does not prove payment.', $invoice['id'], [ 'source' => $source, 'amount_cents' => (int) ( $invoice['amount_remaining'] ?? 0 ), 'currency' => $invoice['currency'] ?? $record['currency'], 'evidence' => [ [ 'url' => $invoice['url'] ?? '', 'note' => 'Draft invoice' ] ] ] );
                }
            }
            if ( (int) ( $invoice['amount_remaining'] ?? 0 ) <= 0 || ! in_array( $invoice['status'] ?? '', [ 'open', 'uncollectible' ], true ) ) { continue; }
            $failed = ! empty( $invoice['attempted'] ) || (int) ( $invoice['attempt_count'] ?? 0 ) > 0;
            $overdue = ! empty( $invoice['due_at'] ) && (int) $invoice['due_at'] < $now;
            if ( ! $failed && ! $overdue && 'uncollectible' !== $invoice['status'] ) { continue; }
            if ( ! isset( $state['records'][ $rid ] ) ) {
                // One-off invoices remain separate from that customer's subscriptions.
                $rid = 'stripe:invoice:' . $invoice['id'];
                if ( ! isset( $state['records'][ $rid ] ) ) {
                    $state['records'][ $rid ] = [ 'id' => $rid, 'source' => 'stripe_invoice', 'source_ref' => $invoice['id'], 'name' => $invoice['name'] ?? ( $invoice['customer_ref'] ?? 'Stripe invoice' ), 'email' => $invoice['email'] ?? '', 'status' => $invoice['status'], 'expected' => 'review', 'category' => 'other', 'amount_cents' => $invoice['amount_remaining'], 'currency' => $invoice['currency'], 'customer_ref' => $invoice['customer_ref'] ?? '', 'url' => $invoice['url'] ?? '', 'notes' => [], 'grace_days' => 0 ];
                }
            }
            $record = $state['records'][ $rid ];
            $title = $failed ? 'Invoice payment needs attention' : 'Outstanding invoice needs review';
            $detail = 'Invoice ' . $invoice['id'] . ' is ' . $invoice['status'] . ' with a remaining balance.';
            if ( ! empty( $invoice['next_payment_attempt'] ) ) { $detail .= ' Next retry: ' . gmdate( 'Y-m-d H:i', (int) $invoice['next_payment_attempt'] ) . ' UTC.'; }
            $candidates[] = self::candidate( $record, 'invoice', $title, $detail, $invoice['id'], [ 'source' => 'stripe', 'amount_cents' => (int) $invoice['amount_remaining'], 'currency' => $invoice['currency'], 'severity' => 'uncollectible' === $invoice['status'] ? 'review' : 'action', 'evidence' => [ [ 'url' => $invoice['url'] ?? '', 'note' => 'Invoice' ] ] ] );
        }
        $observed_payments = [];
        $allocations = [];
        foreach ( $state['manual_payments'] as $evidence ) {
            if ( ! empty( $evidence['source_payment_id'] ) && isset( $state['records'][ $evidence['record_id'] ] ) ) {
                $allocations[ $evidence['source_payment_id'] ][ $evidence['record_id'] ] = true;
            }
        }
        foreach ( $snapshot['payments'] ?? [] as $payment ) {
            $pid = (string) ( $payment['id'] ?? '' );
            if ( '' === $pid || ( isset( $payment['source'] ) && $payment['source'] !== $source ) ) { continue; }
            $observed_payments[ $pid ] = true;
            $refunded = max( 0, (int) ( $payment['refunded_cents'] ?? 0 ) );
            $has_refund = $refunded > 0 || 'refunded' === ( $payment['status'] ?? '' );
            $disputed = ! empty( $payment['disputed'] );
            if ( ! $has_refund && ! $disputed ) { continue; }
            $rid = (string) ( $payment['record_id'] ?? '' );
            if ( ! isset( $state['records'][ $rid ] ) ) {
                // Only an explicit source receipt allocation can connect a one-off
                // payment to an agreement. Customer/name/email similarity is insufficient.
                $linked = $allocations[ $pid ] ?? [];
                $rid = '' === $rid && 1 === count( $linked ) ? (string) key( $linked ) : '';
            }
            if ( '' === $rid || ! isset( $state['records'][ $rid ] ) ) {
                $rid = 'payment:' . $pid;
                if ( ! isset( $state['records'][ $rid ] ) ) {
                    $state['records'][ $rid ] = [ 'id' => $rid, 'source' => $source . '_payment', 'source_ref' => $payment['source_ref'] ?? $pid, 'name' => self::safe_text( $payment['name'] ?? '' ) ?: ( self::safe_text( $payment['email'] ?? '' ) ?: 'Payment ' . $pid ), 'email' => self::safe_text( $payment['email'] ?? '' ), 'status' => $payment['status'] ?? 'unknown', 'expected' => 'review', 'category' => 'other', 'amount_cents' => (int) ( $payment['captured_cents'] ?? $payment['amount_cents'] ?? 0 ), 'currency' => $payment['currency'] ?? '', 'customer_ref' => $payment['customer_ref'] ?? '', 'url' => self::safe_url( $payment['url'] ?? '' ), 'notes' => [], 'grace_days' => 0 ];
                }
            }
            $record = $state['records'][ $rid ];
            $allocated = isset( $allocations[ $pid ][ $rid ] );
            $context = $allocated ? ' This receipt was used as agreement payment evidence. Review its coverage and the expected date; the recorded due date has not been changed.' : ' Review whether the agreement or payment evidence needs updating.';
            $extra = [ 'source' => $source, 'currency' => $payment['currency'] ?? '', 'evidence' => [ [ 'url' => self::safe_url( $payment['url'] ?? '' ), 'note' => 'Source payment' ] ], 'source_payment_id' => $pid ];
            if ( $has_refund ) {
                $candidates[] = self::candidate( $record, 'payment_refund', 'Payment refund needs review', 'Payment ' . $pid . ' has a recorded refund.' . $context, $pid, array_replace( $extra, [ 'amount_cents' => $refunded ] ) );
            }
            if ( $disputed ) {
                $candidates[] = self::candidate( $record, 'payment_dispute', 'Payment dispute needs review', 'Payment ' . $pid . ' is marked disputed. Check the provider for its current outcome and affected amount.' . $context, $pid, array_replace( $extra, [ 'amount_cents' => null, 'severity' => 'action' ] ) );
            }
        }
        foreach ( $state['records'] as $record ) {
            if ( ( $record['source'] ?? '' ) !== $source ) { continue; }
            $expected = $record['expected'] ?? 'review';
            if ( in_array( $expected, [ 'ended', 'paused' ], true ) ) { continue; }
            if ( 'review' === $expected ) {
                $candidates[] = self::candidate( $record, 'baseline', 'Confirm whether this relationship should continue', 'The imported history cannot establish whether this agreement ended intentionally. Set the expected status after review.', $record['source_ref'] ?? '' );
                continue;
            }
            if ( ! empty( $record['missing'] ) || in_array( $record['status'] ?? '', [ 'canceled', 'expired', 'incomplete_expired' ], true ) ) {
                $candidates[] = self::candidate( $record, 'unexpected_end', 'Expected relationship is missing or ended', 'This agreement remains expected in the monitor, but its payment plan is missing or ended. Confirm the reason or arrange the next payment.', (string) ( $record['canceled_at'] ?? 0 ), [ 'severity' => 'action' ] );
                continue;
            }
            if ( ! empty( $record['cancel_at_period_end'] ) ) {
                $candidates[] = self::candidate( $record, 'scheduled_end', 'Subscription is scheduled to end', 'Confirm whether the scheduled cancellation is intentional.', (string) ( $record['current_period_end'] ?? 0 ), [ 'severity' => 'action' ] );
            }
            if ( in_array( $record['status'] ?? '', [ 'past_due', 'unpaid', 'incomplete', 'paused', 'pending' ], true ) || ! empty( $record['collection_paused'] ) ) {
                $candidates[] = self::candidate( $record, 'plan_status', 'Payment plan needs attention', 'Provider status: ' . ( $record['status'] ?? 'unknown' ) . ( ! empty( $record['collection_paused'] ) ? '; payment collection is paused.' : '.' ), (string) ( $record['current_period_start'] ?? 0 ), [ 'severity' => 'action', 'semantic_status' => ( $record['status'] ?? '' ) . ':' . (int) ! empty( $record['collection_paused'] ) ] );
            }
            if ( ! empty( $record['mixed_schedule'] ) ) {
                $candidates[] = self::candidate( $record, 'schedule_review', 'Review a subscription with multiple billing schedules', $record['schedule_note'] ?? 'Check the individual subscription items; no single amount or renewal date has been assumed.' );
            }
            $start = (int) ( $record['current_period_start'] ?? 0 );
            $grace = max( 0, (int) ( $record['grace_days'] ?? 7 ) ) * DAY_IN_SECONDS;
            if ( 'moonclerk' === $source && $start > 0 && $start + $grace < $now && (int) ( $record['last_paid'] ?? 0 ) < $start - DAY_IN_SECONDS && 'active' === ( $record['status'] ?? '' ) ) {
                $candidates[] = self::candidate( $record, 'period_unverified', 'No confirmed payment found for this plan period', 'Review the current MoonClerk period and payment evidence. An active plan alone does not prove the installment was received.', (string) $start );
            }
            if ( 'stripe' === $source && $start > 0 && $start + $grace < $now && empty( $record['mixed_schedule'] ) && 'active' === ( $record['status'] ?? '' ) ) {
                $found = false;
                foreach ( $invoice_records[ $record['id'] ] ?? [] as $invoice ) {
                    $end = (int) ( $invoice['period_end'] ?? 0 );
                    if ( $end > $start || ( ! $end && (int) ( $invoice['created'] ?? 0 ) >= $start - DAY_IN_SECONDS ) ) { $found = true; break; }
                }
                if ( ! $found ) { $candidates[] = self::candidate( $record, 'invoice_missing', 'Current billing period needs verification', 'No invoice was found for the expected current period. Check for paused collection, billing changes or a missing invoice.', (string) $start ); }
            }
        }
        self::apply_candidates( $state, $source, $candidates, $now, $observed_payments );
    }

    public static function apply_candidates( &$state, $source, $candidates, $now, $observed_payments = null ) {
        $seen = [];
        foreach ( $candidates as $candidate ) {
            $id = $candidate['id'];
            $seen[ $id ] = true;
            // Retry timestamps and prose do not reopen an explained issue. Material balances do.
            $fingerprint = hash( 'sha256', wp_json_encode( [ $candidate['kind'], $candidate['identity'], $candidate['amount_cents'], $candidate['currency'], $candidate['semantic_status'] ?? '' ] ) );
            $old = $state['issues'][ $id ] ?? [];
            $issue = array_replace( [ 'status' => 'open', 'first_seen' => $now, 'notes' => [], 'resolution' => '', 'follow_up_at' => 0 ], $old, $candidate );
            if ( $old && ( ( $old['fingerprint'] ?? '' ) !== $fingerprint || ! empty( $old['auto_resolved'] ) ) ) {
                $issue['status'] = 'open'; $issue['resolution'] = ''; $issue['follow_up_at'] = 0;
            }
            if ( in_array( $issue['status'], [ 'waiting', 'snoozed' ], true ) && ! empty( $issue['follow_up_at'] ) && $issue['follow_up_at'] <= $now ) { $issue['status'] = 'open'; }
            $issue['auto_resolved'] = false;
            $issue['fingerprint'] = $fingerprint;
            $issue['last_seen'] = $now;
            $state['issues'][ $id ] = $issue;
        }
        foreach ( $state['issues'] as $id => &$issue ) {
            if ( ( $issue['source'] ?? '' ) === $source && 'manual' !== ( $issue['kind'] ?? '' ) && ! isset( $seen[ $id ] ) && 'resolved' !== $issue['status'] ) {
                // The bounded payment window can stop containing an old receipt.
                // Its absence is not proof that a refund or dispute was reversed.
                if ( in_array( $issue['kind'] ?? '', [ 'payment_refund', 'payment_dispute' ], true ) && ( null === $observed_payments || ! isset( $observed_payments[ $issue['identity'] ?? '' ] ) ) ) { continue; }
                $issue['status'] = 'resolved';
                $issue['resolution'] = 'The condition no longer appears in a successful source check.';
                $issue['auto_resolved'] = true;
                $issue['resolved_at'] = $now;
            }
        }
        unset( $issue );
    }

    public static function reconcile() {
        return self::mutate( static function( &$state ) {
            foreach ( [ 'stripe', 'moonclerk' ] as $source ) {
                $health = $state['sources'][ $source ] ?? [];
                if ( 'ok' === ( $health['status'] ?? '' ) && ( $health['last_success'] ?? 0 ) > time() - 2 * DAY_IN_SECONDS ) {
                    $snapshot = get_option( 'spm_monitor_snapshot_' . $source, [] );
                    if ( ! empty( $snapshot ) ) { self::reconcile_state( $state, $source, $snapshot ); }
                }
            }
            $candidates = [];
            foreach ( $state['records'] as $record ) {
                if ( ! in_array( $record['source'], [ 'manual', 'check', 'mdm_remittance' ], true ) || 'active' !== $record['expected'] ) { continue; }
                if ( empty( $record['next_due'] ) ) {
                    $candidates[] = self::candidate( $record, 'manual_schedule', 'Set the next expected payment date', 'A date is needed before this relationship can be checked.', '', [ 'source' => 'manual' ] );
                } elseif ( $record['next_due'] + ( $record['grace_days'] ?? 7 ) * DAY_IN_SECONDS < time() ) {
                    $candidates[] = self::candidate( $record, 'manual_unverified', 'Expected payment needs confirmation', 'No sufficient confirmed evidence has been recorded for the expected date ' . gmdate( 'Y-m-d', $record['next_due'] ) . '. This does not establish nonpayment.', (string) $record['next_due'], [ 'source' => 'manual' ] );
                }
            }
            self::apply_candidates( $state, 'manual', $candidates, time() );
            $state['last_reconciled'] = time();
            return true;
        } );
    }

    public static function report() {
        $state = self::state();
        $payments = array_values( $state['manual_payments'] );
        foreach ( [ 'stripe', 'moonclerk', 'email' ] as $source ) {
            $health = $state['sources'][ $source ] ?? [ 'status' => 'unconfigured', 'checked_at' => 0, 'last_success' => 0, 'error' => 'This source has not been checked yet.' ];
            $threshold = 'email' === $source ? 2 * DAY_IN_SECONDS : 6 * HOUR_IN_SECONDS;
            if ( 'ok' === $health['status'] && (int) ( $health['last_success'] ?? 0 ) < time() - $threshold ) { $health['status'] = 'stale'; $health['error'] = 'The last successful check is overdue.'; }
            $state['sources'][ $source ] = $health;
            if ( 'email' !== $source ) {
                $snapshot = get_option( 'spm_monitor_snapshot_' . $source, [] );
                $payments = array_merge( $payments, $snapshot['payments'] ?? [] );
            }
        }
        foreach ( $state['issues'] as &$issue ) {
            if ( in_array( $issue['status'], [ 'waiting', 'snoozed' ], true ) && ! empty( $issue['follow_up_at'] ) && $issue['follow_up_at'] <= time() ) { $issue['status'] = 'open'; }
        }
        unset( $issue );
        $state['payments'] = $payments;
        unset( $state['manual_payments'] );
        return $state;
    }

    public static function safe_text( $value ) {
        $text = sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
        $text = preg_replace( '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9_]+\b/', '[redacted credential]', $text );
        foreach ( [ get_option( 'spm_stripe_secret_key', '' ), get_option( 'spm_moonclerk_api_key', '' ), defined( 'SPM_MOONCLERK_API_KEY' ) ? SPM_MOONCLERK_API_KEY : '' ] as $secret ) {
            if ( is_string( $secret ) && strlen( $secret ) >= 8 ) { $text = str_replace( [ $secret, rawurlencode( $secret ) ], '[redacted credential]', $text ); }
        }
        return substr( $text, 0, 5000 );
    }

    public static function configuration_hash( $source ) {
        foreach ( [ 'spm_stripe_secret_key', 'spm_moonclerk_api_key', 'spm_moonclerk_partner_allowlist', 'spm_moonclerk_customer_ids', 'spm_moonclerk_form_ids' ] as $option ) { wp_cache_delete( $option, 'options' ); }
        $values = 'stripe' === $source ? [ get_option( 'spm_stripe_secret_key', '' ) ] : [ defined( 'SPM_MOONCLERK_API_KEY' ) ? SPM_MOONCLERK_API_KEY : get_option( 'spm_moonclerk_api_key', '' ), get_option( 'spm_moonclerk_partner_allowlist', '' ), get_option( 'spm_moonclerk_customer_ids', '' ), get_option( 'spm_moonclerk_form_ids', '' ) ];
        return hash( 'sha256', wp_json_encode( $values ) );
    }

    public static function append_note( &$target, $note, $url = '', $author = '' ) {
        $note = self::safe_text( $note );
        $url = self::safe_url( $url );
        if ( '' === $note ) { return false; }
        $id = hash( 'sha256', $note . '|' . $url );
        foreach ( $target['notes'] ?? [] as $existing ) { if ( ( $existing['id'] ?? '' ) === $id ) { return false; } }
        $target['notes'][] = [ 'id' => $id, 'note' => $note, 'evidence_url' => $url, 'created_at' => time(), 'author' => $author ?: ( wp_get_current_user()->display_name ?: 'Scheduled review' ) ];
        return true;
    }

    public static function date( $value ) {
        if ( '' === (string) $value ) { return 0; }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) { return false; }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
        return $date && $date->format( 'Y-m-d' ) === $value ? $date->getTimestamp() : false;
    }

    public static function safe_url( $url ) {
        $clean = self::safe_text( $url );
        if ( false !== strpos( $clean, '[redacted credential]' ) || preg_match( '/[?&](?:token|key|secret|password|api_key|access_token)=/i', $clean ) ) { return ''; }
        return esc_url_raw( $clean, [ 'https' ] );
    }

    /** Calendar-aware recurrence, clamping Jan 31 to Feb's last day. */
    public static function next_date( $timestamp, $interval, $count = 1, $anchor_day = null ) {
        $date = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( new DateTimeZone( 'UTC' ) );
        $count = max( 1, min( 120, (int) $count ) );
        if ( in_array( $interval, [ 'day', 'week' ], true ) ) { return $date->modify( '+' . $count . ' ' . $interval )->getTimestamp(); }
        $months = 'year' === $interval ? 12 * $count : $count;
        $day = $anchor_day ?? (int) $date->format( 'j' );
        $target = $date->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
        return $target->setDate( (int) $target->format( 'Y' ), (int) $target->format( 'n' ), min( $day, (int) $target->format( 't' ) ) )->getTimestamp();
    }

    /** All currency entry is explicit; non-two-decimal currencies need provider-derived records. */
    public static function amount( $value ) {
        if ( ! preg_match( '/^\d{1,9}(?:\.\d{1,2})?$/', (string) $value ) ) { return false; }
        $parts = explode( '.', (string) $value, 2 );
        return (int) $parts[0] * 100 + (int) str_pad( $parts[1] ?? '', 2, '0' );
    }
}
