<?php
/** Read-only, identity-preserving Stripe evidence for the revenue monitor. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SPM_Stripe_Source {
    const LOOKBACK_DAYS = 400;
    const MAX_PAGES = 250;
    const MAX_REQUESTS = 1500;
    const MAX_CUSTOMERS = 1000;
    const MAX_COLLECTION_SECONDS = 180;

    private $stripe;
    private $requests = 0;
    private $customers = array();
    private $customer_reads = 0;
    private $invoices = array();
    private $invoice_rows = array();
    private $payment_links = array();
    private $stage = 'initialization';
    private $started_at = 0;

    /** A failed or incomplete read must never replace the last successful snapshot. */
    public static function collect() {
        $key = get_option( 'spm_stripe_secret_key', '' );
        if ( ! is_string( $key ) || '' === trim( $key ) ) {
            return new WP_Error( 'spm_stripe_not_configured', 'Stripe is not connected.' );
        }
        $collector = new self();
        try {
            $collector->stripe = spm_get_stripe_client( $key );
            if ( is_wp_error( $collector->stripe ) ) {
                // The old client factory may return raw API messages. Do not relay them.
                return new WP_Error( 'spm_stripe_client', 'The Stripe client could not be initialized. Check the SDK and connection settings.' );
            }
            return $collector->read();
        } catch ( \Throwable $error ) {
            $incomplete = 1001 === $error->getCode();
            return new WP_Error(
                $incomplete ? 'spm_stripe_incomplete' : 'spm_stripe_read_failed',
                $incomplete
                    ? 'Stripe returned incomplete data or the collection limit was reached while reading ' . $collector->stage . '. The refresh was not accepted.'
                    : 'Stripe could not be fully read while reading ' . $collector->stage . '. Check the connection and read permissions, then refresh again.'
            );
        }
    }

    private function read() {
        $this->started_at = microtime( true );
        $started = time();
        $since = $started - self::LOOKBACK_DAYS * 86400;
        $this->stage = 'subscriptions';
        $subscriptions = $this->pages( function( $params ) { return $this->stripe->subscriptions->all( $params ); }, array(
            'status' => 'all', 'expand' => array( 'data.customer', 'data.latest_invoice' ),
        ) );
        $records = array();
        foreach ( $subscriptions as $subscription ) {
            $records[] = $this->subscription( $subscription );
        }
        $latest_invoice_ids = array_fill_keys( array_column( $records, 'latest_invoice_ref' ), true );

        $this->stage = 'invoices';
        // Read full invoice history: an old open/uncollectible invoice must not
        // disappear at the lookback boundary, and a recent charge can pay an old invoice.
        $all_invoices = $this->pages( function( $params ) { return $this->stripe->invoices->all( $params ); }, array(
            'expand' => array( 'data.payments' ),
        ) );
        foreach ( $all_invoices as $invoice ) {
            $id = self::id( $invoice );
            $this->invoices[ $id ] = $invoice;
            $this->link( 'charge', self::id( self::get( $invoice, 'charge' ) ), $id );
            $this->link( 'payment_intent', self::id( self::get( $invoice, 'payment_intent' ) ), $id );
            $embedded = self::get( $invoice, 'payments' );
            $links = null === $embedded ? array() : $this->embedded( $embedded, function( $params ) use ( $id ) {
                return $this->stripe->invoicePayments->all( $params );
            }, array( 'invoice' => $id ) );
            foreach ( $links as $link ) {
                $payment = self::get( $link, 'payment' );
                $this->link( 'charge', self::id( self::get( $payment, 'charge' ) ), $id );
                $this->link( 'payment_intent', self::id( self::get( $payment, 'payment_intent' ) ), $id );
            }
        }
        $invoices = array();
        foreach ( $this->invoices as $id => $invoice ) {
            if ( isset( $latest_invoice_ids[ $id ] ) || self::integer( $invoice, 'created' ) >= $since || in_array( self::string( $invoice, 'status' ), array( 'open', 'uncollectible' ), true ) ) {
                $invoices[] = $this->invoice( $id );
            }
        }
        foreach ( $records as &$record ) {
            $latest = $record['latest_invoice_ref'];
            if ( isset( $this->invoices[ $latest ] ) ) {
                $record['next_payment_attempt'] = self::integer( $this->invoices[ $latest ], 'next_payment_attempt' );
            }
        }
        unset( $record );

        $this->stage = 'payments';
        $charges = $this->pages( function( $params ) { return $this->stripe->charges->all( $params ); }, array(
            'created' => array( 'gte' => $since ), 'expand' => array( 'data.customer' ),
        ) );
        $payments = array();
        foreach ( $charges as $charge ) {
            $payments[] = $this->charge( $charge );
        }
        if ( microtime( true ) - $this->started_at > self::MAX_COLLECTION_SECONDS ) { $this->incomplete(); }
        return array(
            'source' => 'stripe', 'checked_at' => time(), 'started_at' => $started,
            'records' => $records, 'payments' => $payments, 'invoices' => $invoices,
            'payment_history_since' => $since, 'complete' => true,
        );
    }

    private function subscription( $subscription ) {
        $id = self::id( $subscription );
        $customer = $this->customer( self::get( $subscription, 'customer' ) );
        $items = $this->embedded( self::get( $subscription, 'items' ), function( $params ) {
            return $this->stripe->subscriptionItems->all( $params );
        }, array( 'subscription' => $id ) );
        $rows = array();
        $schedules = $currencies = $starts = $ends = array();
        $amount = 0;
        $variable = false;
        foreach ( $items as $item ) {
            $price = self::get( $item, 'price', self::get( $item, 'plan' ) );
            $recurring = self::get( $price, 'recurring', $price );
            $interval = self::string( $recurring, 'interval' );
            $interval_count = self::integer( $recurring, 'interval_count' );
            $currency = strtolower( self::string( $price, 'currency' ) );
            $unit = self::get( $price, 'unit_amount', self::get( $price, 'amount' ) );
            $quantity = self::get( $item, 'quantity' );
            $usage = self::string( $recurring, 'usage_type' );
            $billing_scheme = self::string( $price, 'billing_scheme' );
            $start = self::integer( $item, 'current_period_start', self::integer( $subscription, 'current_period_start' ) );
            $end = self::integer( $item, 'current_period_end', self::integer( $subscription, 'current_period_end' ) );
            $fixed = is_numeric( $unit ) && is_numeric( $quantity ) && 'metered' !== $usage && 'tiered' !== $billing_scheme
                && null === self::get( $price, 'transform_quantity' ) && in_array( $interval, array( 'day', 'week', 'month', 'year' ), true ) && $interval_count > 0;
            if ( $fixed ) { $amount += (int) round( (float) $unit * (float) $quantity ); } else { $variable = true; }
            $rows[] = array(
                'id' => self::id( $item ), 'price_ref' => self::id( $price ), 'unit_amount_cents' => is_numeric( $unit ) ? (int) $unit : null,
                'quantity' => is_numeric( $quantity ) ? (int) $quantity : null, 'currency' => $currency,
                'interval' => $interval, 'interval_count' => $interval_count, 'usage_type' => $usage, 'billing_scheme' => $billing_scheme,
                'current_period_start' => $start, 'current_period_end' => $end,
            );
            $schedules[ $interval . ':' . $interval_count ] = array( $interval, $interval_count );
            $currencies[ $currency ] = true;
            $starts[ $start ] = true;
            $ends[ $end ] = true;
        }
        $mixed = count( $schedules ) !== 1 || count( $currencies ) !== 1 || count( $starts ) !== 1 || count( $ends ) !== 1;
        $schedule = count( $schedules ) === 1 ? reset( $schedules ) : array( '', 0 );
        $currency = count( $currencies ) === 1 ? (string) key( $currencies ) : '';
        $latest = self::get( $subscription, 'latest_invoice' );
        return array(
            'id' => 'stripe:' . $id, 'source' => 'stripe', 'source_ref' => $id,
            'name' => $customer['name'] ?: ( $customer['email'] ?: $id ), 'email' => $customer['email'],
            'customer_ref' => $customer['id'], 'status' => self::string( $subscription, 'status' ),
            'amount_cents' => $mixed || $variable || '' === $currency ? null : $amount, 'currency' => $currency,
            'interval' => $schedule[0], 'interval_count' => $schedule[1],
            'current_period_start' => count( $starts ) === 1 ? (int) key( $starts ) : 0,
            'current_period_end' => count( $ends ) === 1 ? (int) key( $ends ) : 0,
            'next_payment_attempt' => self::integer( $latest, 'next_payment_attempt' ),
            'cancel_at_period_end' => (bool) self::get( $subscription, 'cancel_at_period_end', false ),
            'cancel_at' => self::integer( $subscription, 'cancel_at' ), 'canceled_at' => self::integer( $subscription, 'canceled_at' ),
            'ended_at' => self::integer( $subscription, 'ended_at' ), 'trial_end' => self::integer( $subscription, 'trial_end' ),
            'collection_paused' => null !== self::get( $subscription, 'pause_collection' ),
            'collection_method' => self::string( $subscription, 'collection_method' ),
            'created' => self::integer( $subscription, 'created' ), 'latest_invoice_ref' => self::id( $latest ),
            'mixed_schedule' => $mixed, 'variable_amount' => $variable, 'items' => $rows,
            'schedule_note' => $mixed ? 'Items have different or missing schedules, currencies, or billing periods. Review the individual items.'
                : ( $variable ? 'The recurring amount requires review because pricing is usage based, tiered, transformed, or incomplete.' : 'Listed recurring amount is before invoice discounts, credits, and tax.' ),
            'url' => self::dashboard( $subscription, 'subscriptions', $id ),
        );
    }

    private function invoice( $id ) {
        if ( isset( $this->invoice_rows[ $id ] ) ) { return $this->invoice_rows[ $id ]; }
        if ( ! isset( $this->invoices[ $id ] ) ) {
            $this->request_guard();
            $invoice = $this->stripe->invoices->retrieve( $id, array() );
            if ( self::id( $invoice ) !== $id ) { $this->incomplete(); }
            $this->invoices[ $id ] = $invoice;
        }
        $invoice = $this->invoices[ $id ];
        $subscription = self::id( self::get( self::get( self::get( $invoice, 'parent' ), 'subscription_details' ), 'subscription' ) );
        if ( '' === $subscription ) { $subscription = self::id( self::get( $invoice, 'subscription' ) ); }
        $periods = array();
        $embedded = self::get( $invoice, 'lines' );
        $lines = null === $embedded ? array() : $this->embedded( $embedded, function( $params ) use ( $id ) {
            return $this->stripe->invoices->allLines( $id, $params );
        }, array() );
        foreach ( $lines as $line ) {
            $details = self::get( self::get( $line, 'parent' ), 'subscription_item_details' );
            $line_subscription = self::id( self::get( $details, 'subscription', self::get( $line, 'subscription' ) ) );
            $proration = (bool) self::get( $details, 'proration', self::get( $line, 'proration', false ) );
            if ( '' === $subscription || $line_subscription !== $subscription || $proration ) { continue; }
            $period = self::get( $line, 'period' );
            $start = self::integer( $period, 'start' );
            $end = self::integer( $period, 'end' );
            if ( $start > 0 && $end > $start ) { $periods[ $start . ':' . $end ] = array( $start, $end ); }
        }
        $period = count( $periods ) === 1 ? reset( $periods ) : array( 0, 0 );
        $customer = self::get( $invoice, 'customer' );
        $row = array(
            'id' => 'stripe:' . $id, 'source' => 'stripe', 'source_ref' => $id,
            'record_id' => $subscription ? 'stripe:' . $subscription : '', 'customer_ref' => self::id( $customer ),
            'name' => self::string( $invoice, 'customer_name', self::string( $customer, 'name' ) ),
            'email' => self::string( $invoice, 'customer_email', self::string( $customer, 'email' ) ),
            'status' => self::string( $invoice, 'status' ), 'amount_remaining' => self::integer( $invoice, 'amount_remaining' ),
            'amount_paid' => self::integer( $invoice, 'amount_paid' ), 'currency' => strtolower( self::string( $invoice, 'currency' ) ),
            // Automatic collection invoices legitimately have no due date.
            'due_at' => self::integer( $invoice, 'due_date' ), 'created' => self::integer( $invoice, 'created' ),
            'attempted' => (bool) self::get( $invoice, 'attempted', false ), 'attempt_count' => self::integer( $invoice, 'attempt_count' ),
            'next_payment_attempt' => self::integer( $invoice, 'next_payment_attempt' ),
            'period_start' => $period[0], 'period_end' => $period[1],
            'collection_method' => self::string( $invoice, 'collection_method' ), 'url' => self::dashboard( $invoice, 'invoices', $id ),
        );
        $this->invoice_rows[ $id ] = $row;
        return $row;
    }

    private function charge( $charge ) {
        $id = self::id( $charge );
        $customer = $this->customer( self::get( $charge, 'customer' ) );
        $billing = self::get( $charge, 'billing_details' );
        $invoice_ids = $this->payment_links[ 'charge:' . $id ] ?? array();
        $intent = self::id( self::get( $charge, 'payment_intent' ) );
        if ( isset( $this->payment_links[ 'payment_intent:' . $intent ] ) ) { $invoice_ids += $this->payment_links[ 'payment_intent:' . $intent ]; }
        $legacy_invoice = self::id( self::get( $charge, 'invoice' ) );
        if ( '' !== $legacy_invoice ) { $invoice_ids[ $legacy_invoice ] = true; }
        $record_ids = $periods = array();
        foreach ( array_keys( $invoice_ids ) as $invoice_id ) {
            $invoice = $this->invoice( $invoice_id );
            $record_ids[ $invoice['record_id'] ] = true;
            $periods[ $invoice['period_start'] . ':' . $invoice['period_end'] ] = array( $invoice['period_start'], $invoice['period_end'] );
        }
        $record_id = count( $record_ids ) === 1 ? (string) key( $record_ids ) : '';
        $period = count( $periods ) === 1 ? reset( $periods ) : array( 0, 0 );
        $refunded = self::integer( $charge, 'amount_refunded' );
        $status = self::string( $charge, 'status' );
        $paid = 'succeeded' === $status && (bool) self::get( $charge, 'paid', false ) && (bool) self::get( $charge, 'captured', false );
        $status = 'failed' === $status ? 'failed' : ( $paid ? ( $refunded > 0 ? 'refunded' : 'paid' ) : 'pending' );
        return array(
            'id' => 'stripe:' . $id, 'source' => 'stripe', 'source_ref' => $id,
            'record_id' => $record_id, 'customer_ref' => $customer['id'],
            'name' => $customer['name'] ?: self::string( $billing, 'name' ),
            'email' => $customer['email'] ?: self::string( $billing, 'email', self::string( $charge, 'receipt_email' ) ),
            'status' => $status,
            // A partial capture must not be counted at its original authorization amount.
            'amount_cents' => $paid ? self::integer( $charge, 'amount_captured', self::integer( $charge, 'amount' ) ) : self::integer( $charge, 'amount' ),
            'authorized_cents' => self::integer( $charge, 'amount' ),
            'captured_cents' => self::integer( $charge, 'amount_captured' ), 'refunded_cents' => $refunded,
            'currency' => strtolower( self::string( $charge, 'currency' ) ),
            'paid_at' => $paid ? self::integer( $charge, 'created' ) : 0, 'created' => self::integer( $charge, 'created' ),
            'period_start' => $period[0], 'period_end' => $period[1], 'invoice_refs' => array_keys( $invoice_ids ),
            'payment_intent_ref' => $intent, 'disputed' => (bool) self::get( $charge, 'disputed', false ),
            'url' => self::dashboard( $charge, 'payments', $intent ?: $id ),
        );
    }

    private function customer( $customer ) {
        $id = self::id( $customer );
        if ( '' === $id ) { return array( 'id' => '', 'name' => '', 'email' => '' ); }
        if ( isset( $this->customers[ $id ] ) ) { return $this->customers[ $id ]; }
        if ( is_string( $customer ) ) {
            if ( ++$this->customer_reads > self::MAX_CUSTOMERS ) { $this->incomplete(); }
            $this->request_guard();
            $customer = $this->stripe->customers->retrieve( $id, array() );
            if ( self::id( $customer ) !== $id ) { $this->incomplete(); }
        }
        $this->customers[ $id ] = array( 'id' => $id, 'name' => self::string( $customer, 'name' ), 'email' => self::string( $customer, 'email' ) );
        return $this->customers[ $id ];
    }

    private function link( $type, $payment_id, $invoice_id ) {
        if ( '' !== $payment_id ) { $this->payment_links[ $type . ':' . $payment_id ][ $invoice_id ] = true; }
    }

    /** Explicit cursors make truncation and non-advancing/duplicate pages fatal. */
    private function pages( $fetch, $params, $first = null ) {
        $rows = array();
        $seen = array();
        for ( $page_number = 0; $page_number < self::MAX_PAGES; ++$page_number ) {
            if ( 0 === $page_number && null !== $first ) {
                $page = $first;
            } else {
                $this->request_guard();
                $page = $fetch( array_merge( $params, array( 'limit' => 100 ) ) );
            }
            $data = self::get( $page, 'data' );
            $has_more = self::get( $page, 'has_more' );
            if ( ! is_array( $data ) || ! is_bool( $has_more ) ) { $this->incomplete(); }
            foreach ( $data as $row ) {
                $id = self::id( $row );
                if ( '' === $id || isset( $seen[ $id ] ) ) { $this->incomplete(); }
                $seen[ $id ] = true;
                $rows[] = $row;
            }
            if ( ! $has_more ) { return $rows; }
            if ( ! count( $data ) ) { $this->incomplete(); }
            $params['starting_after'] = self::id( $data[ count( $data ) - 1 ] );
        }
        $this->incomplete();
    }

    private function embedded( $first, $fetch, $params ) {
        if ( null === $first ) { $this->incomplete(); }
        return $this->pages( $fetch, $params, $first );
    }

    private function request_guard() {
        if ( ++$this->requests > self::MAX_REQUESTS || microtime( true ) - $this->started_at > self::MAX_COLLECTION_SECONDS ) { $this->incomplete(); }
    }

    private function incomplete() { throw new \RuntimeException( 'Incomplete collection.', 1001 ); }

    private static function get( $object, $key, $default = null ) {
        if ( is_array( $object ) ) { return array_key_exists( $key, $object ) ? $object[ $key ] : $default; }
        if ( is_object( $object ) ) { return isset( $object->$key ) ? $object->$key : $default; }
        return $default;
    }

    private static function id( $object ) {
        $value = is_string( $object ) ? $object : self::get( $object, 'id', '' );
        return is_string( $value ) ? $value : '';
    }

    private static function string( $object, $key, $default = '' ) {
        $value = self::get( $object, $key, $default );
        return is_scalar( $value ) ? (string) $value : $default;
    }

    private static function integer( $object, $key, $default = 0 ) {
        $value = self::get( $object, $key, $default );
        return is_numeric( $value ) ? (int) $value : $default;
    }

    private static function dashboard( $object, $resource, $id ) {
        return 'https://dashboard.stripe.com/' . ( false === self::get( $object, 'livemode', true ) ? 'test/' : '' ) . $resource . '/' . rawurlencode( $id );
    }
}
