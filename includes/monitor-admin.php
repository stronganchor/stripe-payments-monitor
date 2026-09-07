<?php
/** Private revenue monitor screens. All writes go through the guarded action handler. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SPM_Monitor_Admin {
    private static $field_id = 0;

    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this report.', 'spm' ) );
        }
        nocache_headers();
    }

    private static function url( $page = 'stripe-payments-monitor', $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
    }

    private static function label( $value ) {
        $labels = array( 'stripe' => 'Stripe', 'moonclerk' => 'MoonClerk', 'email' => 'Email review', 'check' => 'Check', 'manual' => 'Manual', 'mdm_remittance' => 'MDM remittance', 'maintenance' => 'Website maintenance', 'sponsorship' => 'Sponsorship', 'other' => 'Other', 'ok' => 'Current', 'error' => 'Check failed', 'unconfigured' => 'Not configured', 'stale' => 'Needs refresh', 'review' => 'Needs verification', 'active' => 'Expected to continue', 'paused' => 'Paused', 'ended' => 'Ended', 'open' => 'Open', 'waiting' => 'Waiting', 'snoozed' => 'Snoozed', 'resolved' => 'Resolved', 'action' => 'Action needed' );
        return isset( $labels[ $value ] ) ? $labels[ $value ] : ucwords( str_replace( '_', ' ', (string) $value ) );
    }

    private static function date( $value, $time = false, $date_only = false ) {
        if ( empty( $value ) ) { return 'Not recorded'; }
        $stamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
        if ( ! $stamp ) { return 'Not recorded'; }
        return $date_only ? gmdate( 'M j, Y', $stamp ) : wp_date( $time ? 'M j, Y H:i' : 'M j, Y', $stamp );
    }

    private static function input_date( $value ) {
        if ( empty( $value ) ) { return ''; }
        if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return $value; }
        $stamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
        return $stamp ? gmdate( 'Y-m-d', $stamp ) : '';
    }

    private static function money( $amount, $currency = 'usd' ) {
        if ( null === $amount || '' === (string) $currency ) { return 'Variable / review'; }
        if ( ! in_array( strtolower( (string) $currency ), [ 'usd', 'eur', 'gbp', 'cad', 'aud', 'nzd', 'chf', 'try' ], true ) ) {
            return strtoupper( (string) $currency ) . ' ' . number_format_i18n( (float) $amount, 0 ) . ' minor units (review in provider)';
        }
        return strtoupper( (string) $currency ) . ' ' . number_format_i18n( (float) $amount / 100, 2 );
    }

    private static function hidden( $name, $value ) {
        echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
    }

    private static function form( $op, $values = array(), $class = '' ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spm-form ' . esc_attr( $class ) . '">';
        self::hidden( 'action', 'spm_monitor_action' );
        self::hidden( 'op', $op );
        self::hidden( '_wpnonce', wp_create_nonce( 'spm_monitor_action' ) );
        foreach ( $values as $key => $value ) { self::hidden( $key, $value ); }
    }

    private static function field( $name, $label, $value = '', $type = 'text', $required = false, $help = '' ) {
        $id = 'spm-field-' . ++self::$field_id;
        echo '<div class="spm-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
        $attributes = $required ? ' required' : '';
        if ( $help ) { $attributes .= ' aria-describedby="' . esc_attr( $id . '-help' ) . '"'; }
        if ( 'textarea' === $type ) {
            echo '<textarea rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $attributes . '>' . esc_textarea( $value ) . '</textarea>';
        } else {
            $extra = 'password' === $type ? ' autocomplete="new-password"' : '';
            if ( 'number' === $type ) { $extra .= ' min="' . ( 'interval_count' === $name ? '1' : '0' ) . '" step="1"'; }
            echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $attributes . $extra . '>';
        }
        if ( $help ) { echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $help ) . '</p>'; }
        echo '</div>';
    }

    private static function select( $name, $label, $value, $options ) {
        $id = 'spm-field-' . ++self::$field_id;
        echo '<div class="spm-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
        foreach ( $options as $key => $text ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>';
        }
        echo '</select></div>';
    }

    private static function badge( $value, $text = '' ) {
        echo '<span class="spm-badge spm-status-' . esc_attr( sanitize_html_class( $value ) ) . '">' . esc_html( $text ? $text : self::label( $value ) ) . '</span>';
    }

    private static function evidence( $items ) {
        if ( ! is_array( $items ) || ! $items ) { return; }
        echo '<ul class="spm-evidence">';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $note = ! empty( $item['note'] ) ? $item['note'] : 'View evidence';
            echo '<li>';
            if ( ! empty( $item['url'] ) && esc_url( $item['url'] ) ) {
                echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( $item['url'] ) . '">' . esc_html( $note ) . '<span class="screen-reader-text"> (opens in a new tab)</span></a>';
            } else { echo esc_html( $note ); }
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function notes( $notes ) {
        if ( empty( $notes ) || ! is_array( $notes ) ) { return; }
        echo '<ol class="spm-notes">';
        foreach ( array_slice( array_reverse( $notes ), 0, 12 ) as $note ) {
            if ( is_string( $note ) ) { $note = array( 'note' => $note ); }
            if ( ! is_array( $note ) ) { continue; }
            echo '<li><p>' . nl2br( esc_html( $note['note'] ?? $note['text'] ?? '' ) ) . '</p>';
            echo '<span class="spm-meta">' . esc_html( self::date( $note['created_at'] ?? 0, true ) );
            if ( ! empty( $note['author'] ) && is_scalar( $note['author'] ) ) { echo ' · ' . esc_html( $note['author'] ); }
            echo '</span>';
            if ( ! empty( $note['evidence_url'] ) ) { self::evidence( array( array( 'url' => $note['evidence_url'], 'note' => 'Source for this note' ) ) ); }
            echo '</li>';
        }
        echo '</ol>';
        if ( count( $notes ) > 12 ) { echo '<p class="description">Showing the 12 most recent notes. Earlier notes remain in the record.</p>'; }
    }

    private static function note_form( $key, $id ) {
        self::form( 'note', array( $key => $id ) );
        self::field( 'note', 'Add a note', '', 'textarea', true );
        self::field( 'evidence_url', 'Evidence link', '', 'url', false, 'A Gmail conversation or provider record link. Store a short summary, without account or card details.' );
        echo '<button class="button" type="submit">Save note</button></form>';
    }

    private static function source_health( $sources ) {
        echo '<section class="spm-source-section" aria-labelledby="spm-sources-title"><h2 id="spm-sources-title">Source freshness</h2><div class="spm-source-grid">';
        foreach ( array( 'stripe', 'moonclerk', 'email' ) as $source ) {
            $health = isset( $sources[ $source ] ) && is_array( $sources[ $source ] ) ? $sources[ $source ] : array();
            $status = $health['status'] ?? 'unconfigured';
            echo '<article class="spm-source-card"><div class="spm-card-heading"><h3>' . esc_html( self::label( $source ) ) . '</h3>';
            self::badge( $status );
            echo '</div><dl class="spm-facts"><div><dt>Last attempt</dt><dd>' . esc_html( self::date( $health['checked_at'] ?? 0, true ) ) . '</dd></div><div><dt>Last successful ' . ( 'email' === $source ? 'review' : 'sync' ) . '</dt><dd>' . esc_html( self::date( $health['last_success'] ?? 0, true ) ) . '</dd></div></dl>';
            if ( ! empty( $health['error'] ) ) { echo '<p class="spm-source-error">' . esc_html( $health['error'] ) . '</p>'; }
            if ( 'email' === $source ) {
                echo '<p class="description">The email review checks payment explanations, Aaron’s check confirmations, and MDM remittance instructions. Missing confirmation means unverified.</p>';
            } else {
                self::form( 'refresh', array( 'source' => $source ), 'spm-inline-form' );
                echo '<button class="button" type="submit">Refresh ' . esc_html( self::label( $source ) ) . '</button></form>';
            }
            echo '</article>';
        }
        echo '</div><p class="spm-meta">A failed or stale source cannot confirm that everything is paid. Dates use the WordPress site timezone.</p></section>';
    }

    private static function historical_issue( $issue, $now ) {
        // A reviewer's due follow-up takes priority over historical categorization.
        if ( ! empty( $issue['follow_up_at'] ) && (int) $issue['follow_up_at'] <= $now ) { return false; }
        if ( 'baseline' === ( $issue['kind'] ?? '' ) ) { return true; }
        // A newly imported issue can describe an old invoice. Unknown dates stay visible.
        $occurred = (int) ( $issue['occurred_at'] ?? 0 );
        return 'invoice' === ( $issue['kind'] ?? '' ) && $occurred > 0 && $occurred < $now - 60 * DAY_IN_SECONDS;
    }

    private static function issue_groups( $issues, $records ) {
        $groups = array();
        foreach ( $issues as $issue ) {
            $rid = (string) ( $issue['record_id'] ?? '' );
            $record = $records[ $rid ] ?? array();
            $source = (string) ( $record['source'] ?? $issue['source'] ?? 'manual' );
            if ( in_array( $source, array( 'stripe_invoice', 'stripe_payment' ), true ) ) { $source = 'stripe'; }
            if ( 'moonclerk_payment' === $source ) { $source = 'moonclerk'; }
            $customer = (string) ( $record['customer_ref'] ?? '' );
            // Provider customer identity groups multiple agreements without matching names.
            // Keep manual remittances and donor payments in their own source groups.
            $key = $source . '|' . ( $customer ? 'customer:' . $customer : 'record:' . ( $rid ?: $issue['id'] ) );
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array( 'name' => $record['name'] ?? 'Unlinked issue', 'source' => $source, 'issues' => array(), 'records' => array(), 'priority' => 1, 'latest' => null, 'latest_at' => 0 );
            }
            $group =& $groups[ $key ];
            $group['issues'][] = $issue;
            $group['records'][ $rid ] = true;
            if ( 'action' === ( $issue['severity'] ?? '' ) ) { $group['priority'] = 0; }
            $occurred = (int) ( $issue['occurred_at'] ?? 0 );
            if ( null === $group['latest'] || $occurred > $group['latest_at'] ) { $group['latest'] = $issue; $group['latest_at'] = $occurred; }
            unset( $group );
        }
        uasort( $groups, static function( $a, $b ) {
            return ( $a['priority'] <=> $b['priority'] ) ?: ( $b['latest_at'] <=> $a['latest_at'] ) ?: strcasecmp( $a['name'], $b['name'] );
        } );
        return $groups;
    }

    private static function client_issues( $groups, $records ) {
        foreach ( $groups as $group ) {
            $count = count( $group['issues'] );
            $latest = $group['latest'];
            echo '<details class="spm-client-group"><summary class="spm-client-summary"><span class="spm-client-label"><strong>' . esc_html( $group['name'] ) . '</strong><span class="spm-meta">' . esc_html( self::label( $group['source'] ) ) . ' · ' . (int) $count . ' issue' . ( 1 === $count ? '' : 's' );
            if ( count( $group['records'] ) > 1 ) { echo ' across ' . (int) count( $group['records'] ) . ' agreements'; }
            echo '</span><span class="spm-client-reason">' . esc_html( $latest['title'] ?? 'Review needed' ) . '</span></span><span class="spm-client-latest">';
            if ( isset( $latest['amount_cents'] ) && $latest['amount_cents'] > 0 ) { echo '<strong>' . esc_html( self::money( $latest['amount_cents'], $latest['currency'] ?? '' ) ) . '</strong><span class="spm-meta">Latest issue amount</span>'; }
            echo '<span class="spm-meta">' . ( $group['latest_at'] ? 'Most recent: ' . esc_html( self::date( $group['latest_at'] ) ) : 'Occurrence date not recorded' ) . '</span></span></summary><div class="spm-client-issues">';
            usort( $group['issues'], static function( $a, $b ) { return ( (int) ( $b['occurred_at'] ?? 0 ) <=> (int) ( $a['occurred_at'] ?? 0 ) ) ?: ( (int) ( $b['first_seen'] ?? 0 ) <=> (int) ( $a['first_seen'] ?? 0 ) ); } );
            foreach ( $group['issues'] as $issue ) {
                $record = $records[ $issue['record_id'] ?? '' ] ?? array();
                echo '<div class="spm-issue-row"><div class="spm-issue-row-main"><a href="' . esc_url( self::url( 'stripe-payments-monitor', array( 'spm_issue' => $issue['id'] ) ) . '#spm-selected-issue' ) . '">' . esc_html( $issue['title'] ?? 'Review issue' ) . '</a><p class="spm-meta">';
                if ( count( $group['records'] ) > 1 ) { echo esc_html( $record['name'] ?? 'Agreement' ) . ' · '; }
                echo ! empty( $issue['occurred_at'] ) ? esc_html( self::date( $issue['occurred_at'] ) ) : 'Occurrence date not recorded';
                if ( ! empty( $issue['follow_up_at'] ) ) { echo ' · Follow up ' . esc_html( self::date( $issue['follow_up_at'], false, true ) ); }
                echo '</p></div><div class="spm-issue-row-meta">';
                if ( isset( $issue['amount_cents'] ) && $issue['amount_cents'] > 0 ) { echo '<strong>' . esc_html( self::money( $issue['amount_cents'], $issue['currency'] ?? '' ) ) . '</strong>'; }
                echo '<div class="spm-badges">'; self::badge( $issue['severity'] ?? 'review' ); self::badge( $issue['status'] ?? 'open' ); echo '</div></div></div>';
            }
            echo '</div></details>';
        }
    }

    private static function issue_card( $issue, $records ) {
        $record = $records[ $issue['record_id'] ?? '' ] ?? array();
        $id = $issue['id'];
        echo '<article class="spm-issue spm-severity-' . esc_attr( $issue['severity'] ?? 'review' ) . '"><div class="spm-card-heading"><div><h3>' . esc_html( $issue['title'] ?? 'Review needed' ) . '</h3><p class="spm-meta">';
        if ( $record ) { echo '<a href="' . esc_url( self::url() . '#spm-record-' . $record['id'] ) . '">' . esc_html( $record['name'] ?? 'Agreement' ) . '</a> · '; }
        echo esc_html( self::label( $issue['source'] ?? $record['source'] ?? 'manual' ) ) . '</p></div><div class="spm-badges">';
        self::badge( $issue['severity'] ?? 'review' );
        self::badge( $issue['status'] ?? 'open' );
        echo '</div></div><p>' . nl2br( esc_html( $issue['detail'] ?? '' ) ) . '</p>';
        if ( isset( $issue['amount_cents'] ) && $issue['amount_cents'] > 0 ) { echo '<p class="spm-amount">' . esc_html( self::money( $issue['amount_cents'], $issue['currency'] ?? 'usd' ) ) . '</p>'; }
        echo '<p class="spm-meta">';
        if ( ! empty( $issue['occurred_at'] ) ) { echo 'Occurred ' . esc_html( self::date( $issue['occurred_at'] ) ) . ' · '; }
        echo 'First seen ' . esc_html( self::date( $issue['first_seen'] ?? 0 ) ) . ' · Last observed ' . esc_html( self::date( $issue['last_seen'] ?? 0 ) );
        if ( ! empty( $issue['follow_up_at'] ) ) { echo ' · Follow up ' . esc_html( self::date( $issue['follow_up_at'], false, true ) ); }
        echo '</p>';
        if ( ! empty( $issue['resolution'] ) ) { echo '<p class="spm-resolution"><strong>Review note:</strong> ' . nl2br( esc_html( $issue['resolution'] ) ) . '</p>'; }
        self::evidence( $issue['evidence'] ?? array() );
        echo '<details class="spm-details"><summary>Review issue and add notes</summary><p class="spm-meta">Issue ID: <code>' . esc_html( $id ) . '</code></p><div class="spm-two-col">';
        self::form( 'issue', array( 'issue_id' => $id ) );
        self::select( 'status', 'Issue status', $issue['status'] ?? 'open', array( 'open' => 'Open — action or review needed', 'waiting' => 'Waiting — someone is already responding', 'snoozed' => 'Snoozed — revisit on the follow-up date', 'resolved' => 'Resolved — explanation or payment verified' ) );
        self::field( 'resolution', 'Explanation / resolution', $issue['resolution'] ?? '', 'textarea', false, 'Record why the issue is waiting or resolved. This does not mark an invoice paid.' );
        self::field( 'follow_up_date', 'Follow-up date', self::input_date( $issue['follow_up_at'] ?? 0 ), 'date' );
        echo '<button class="button button-primary" type="submit">Save review</button></form><div>';
        self::note_form( 'issue_id', $id );
        echo '</div></div>';
        self::notes( $issue['notes'] ?? array() );
        echo '</details></article>';
    }

    private static function record_form( $record = array() ) {
        $existing = ! empty( $record['id'] );
        $source = $record['source'] ?? 'check';
        $provider = in_array( $source, array( 'stripe', 'stripe_invoice', 'moonclerk' ), true );
        self::form( 'record', $existing ? array( 'record_id' => $record['id'] ) : array() );
        echo '<div class="spm-field-grid">';
        if ( $provider ) {
            foreach ( array( 'source', 'name', 'email', 'currency', 'interval', 'interval_count', 'customer_ref' ) as $name ) { self::hidden( $name, $record[ $name ] ?? '' ); }
            self::hidden( 'amount', number_format( (float) ( $record['amount_cents'] ?? 0 ) / 100, 2, '.', '' ) );
        } else {
            self::field( 'name', 'Client or sponsor name', $record['name'] ?? '', 'text', true );
            self::field( 'email', 'Contact email', $record['email'] ?? '', 'email' );
            if ( $existing ) { self::hidden( 'source', $source ); }
            else { self::select( 'source', 'Payment channel', $source, array( 'check' => 'Check — confirmation from Aaron', 'mdm_remittance' => 'MDM combined remittance', 'manual' => 'Other manual payment' ) ); }
            self::field( 'amount', 'Expected amount', isset( $record['amount_cents'] ) ? number_format( (float) $record['amount_cents'] / 100, 2, '.', '' ) : '', 'text', true, 'Decimal amount, for example 50.00. Use 0 for a variable remittance; it still needs verification.' );
            self::field( 'currency', 'Currency', strtoupper( $record['currency'] ?? 'usd' ), 'text', true );
            self::select( 'interval', 'Schedule', $record['interval'] ?? 'month', array( 'day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly' ) );
            self::field( 'interval_count', 'Every how many intervals', $record['interval_count'] ?? 1, 'number', true );
            self::field( 'next_due', 'Next payment / verification due', self::input_date( $record['next_due'] ?? '' ), 'date', true );
            self::field( 'customer_ref', 'Stripe customer ID (if applicable)', $record['customer_ref'] ?? '', 'text', false, 'Useful for linking an MDM remittance to the LLC payment. A shared customer can have multiple agreements.' );
        }
        self::select( 'expected', 'Relationship expectation', $record['expected'] ?? 'active', array( 'active' => 'Expected to continue', 'review' => 'Needs verification', 'paused' => 'Paused', 'ended' => 'Ended' ) );
        self::select( 'category', 'Purpose', $record['category'] ?? 'maintenance', array( 'maintenance' => 'Website maintenance', 'sponsorship' => 'Sponsorship', 'mdm_remittance' => 'MDM combined remittance', 'other' => 'Other' ) );
        self::field( 'grace_days', 'Grace period (days)', $record['grace_days'] ?? 7, 'number', true );
        self::field( 'site_url', 'Website', $record['site_url'] ?? '', 'url' );
        echo '</div><button type="submit" class="button button-primary">' . ( $existing ? 'Save agreement' : 'Add agreement' ) . '</button></form>';
    }

    private static function record_card( $record ) {
        $id = $record['id'];
        echo '<article class="spm-record" id="spm-record-' . esc_attr( $id ) . '"><div class="spm-record-summary"><div><h3>' . esc_html( $record['name'] ?? 'Unnamed agreement' ) . '</h3><p class="spm-meta">' . esc_html( $record['email'] ?? '' ) . '</p><div class="spm-badges">';
        self::badge( $record['source'] ?? 'manual' );
        self::badge( $record['category'] ?? 'other' );
        self::badge( $record['expected'] ?? 'review' );
        $amount = array_key_exists( 'amount_cents', $record ) ? $record['amount_cents'] : null;
        echo '</div></div><div class="spm-record-amount"><strong>' . esc_html( self::money( $amount, $record['currency'] ?? '' ) ) . '</strong>';
        $schedule = ! empty( $record['interval'] ) ? 'Every ' . max( 1, (int) ( $record['interval_count'] ?? 1 ) ) . ' ' . $record['interval'] . ( (int) ( $record['interval_count'] ?? 1 ) > 1 ? 's' : '' ) : ( 'stripe_invoice' === ( $record['source'] ?? '' ) ? 'Individual invoice' : 'Schedule varies' );
        echo '<span>' . esc_html( $schedule ) . '</span></div></div>';
        $manual_date = in_array( $record['source'] ?? '', array( 'manual', 'check', 'mdm_remittance' ), true );
        echo '<dl class="spm-record-facts"><div><dt>Provider status</dt><dd>' . esc_html( str_replace( '_', ' ', $record['status'] ?? 'Manual verification' ) ) . '</dd></div><div><dt>Last paid</dt><dd>' . esc_html( self::date( $record['last_paid'] ?? 0, false, $manual_date ) ) . '</dd></div><div><dt>' . ( ! empty( $record['next_due'] ) ? 'Next due / verification' : 'Current period ends' ) . '</dt><dd>' . esc_html( self::date( ! empty( $record['next_due'] ) ? $record['next_due'] : ( $record['current_period_end'] ?? 0 ), false, ! empty( $record['next_due'] ) ) ) . '</dd></div></dl>';
        $links = array();
        if ( ! empty( $record['url'] ) ) { $links[] = array( 'url' => $record['url'], 'note' => 'Open provider record' ); }
        if ( ! empty( $record['site_url'] ) ) { $links[] = array( 'url' => $record['site_url'], 'note' => 'Open website' ); }
        self::evidence( $links );
        echo '<details class="spm-details"><summary>Agreement, notes and payment evidence</summary><p class="spm-meta">Agreement ID: <code>' . esc_html( $id ) . '</code></p><div class="spm-two-col"><section><h4>Expected agreement</h4>';
        if ( in_array( $record['source'] ?? '', array( 'stripe', 'stripe_invoice', 'moonclerk' ), true ) ) { echo '<p class="description">Amount and schedule come from the provider. The relationship expectation remains independent so unexpected cancellations can be reviewed.</p>'; }
        self::record_form( $record );
        echo '</section><section><h4>Notes</h4>';
        self::notes( $record['notes'] ?? array() );
        self::note_form( 'record_id', $id );
        echo '</section></div><div class="spm-two-col spm-divider"><section><h4>Record payment evidence</h4><p class="description">Use a confirmed receipt, Aaron’s confirmation, or a matched settlement. An email saying a check was sent does not establish that it arrived.</p>';
        self::form( 'evidence', array( 'record_id' => $id ) );
        echo '<div class="spm-field-grid">';
        self::field( 'amount', 'Amount received', '', 'text', true );
        self::field( 'currency', 'Currency', strtoupper( $record['currency'] ?? 'usd' ), 'text', true );
        self::field( 'paid_date', 'Payment / receipt date', '', 'date', true );
        self::field( 'period_end', 'Paid through', '', 'date', true );
        echo '</div>';
        self::field( 'source_payment_id', 'Match an imported payment ID (optional)', '', 'text', false, 'Copy the exact ID from recent payment evidence below. A match uses the verified provider amount and status.' );
        self::field( 'evidence_url', 'Evidence link', '', 'url', true );
        self::field( 'note', 'What this evidence confirms', '', 'textarea', true );
        echo '<button type="submit" class="button">Save payment evidence</button></form></section><section><h4>Flag something for review</h4>';
        self::form( 'flag', array( 'record_id' => $id ) );
        self::field( 'title', 'Issue title', '', 'text', true );
        self::field( 'detail', 'What needs attention', '', 'textarea', true );
        self::field( 'evidence_url', 'Evidence link', '', 'url' );
        echo '<button type="submit" class="button">Flag issue</button></form></section></div></details></article>';
    }

    private static function recent_payments( $payments, $records ) {
        $payments = is_array( $payments ) ? $payments : array();
        usort( $payments, function( $a, $b ) { return (int) ( ! empty( $b['paid_at'] ) ? $b['paid_at'] : ( $b['created'] ?? 0 ) ) <=> (int) ( ! empty( $a['paid_at'] ) ? $a['paid_at'] : ( $a['created'] ?? 0 ) ); } );
        echo '<section class="spm-section"><details class="spm-group"><summary>Recent payment evidence (' . (int) count( $payments ) . ' in the available history)</summary><p>Showing up to 50 recent payments. MoonClerk contributions and MDM payments to the LLC are separate stages and must not be added together as income.</p>';
        if ( ! $payments ) { echo '<div class="spm-empty">No payment evidence is available yet.</div>'; }
        else {
            echo '<div class="spm-table-scroll" role="region" aria-label="Recent payment evidence" tabindex="0"><table class="widefat striped spm-payments-table"><thead><tr><th scope="col">Date</th><th scope="col">Source / customer</th><th scope="col">Payment ID</th><th scope="col">Amount</th><th scope="col">Status</th></tr></thead><tbody>';
            foreach ( array_slice( $payments, 0, 50 ) as $payment ) {
                $record = $records[ $payment['record_id'] ?? '' ] ?? array();
                $name = $record['name'] ?? $payment['name'] ?? $payment['customer_ref'] ?? 'Unmatched payment';
                $source = $payment['source'] ?? $record['source'] ?? 'manual';
                $stamp = ! empty( $payment['paid_at'] ) ? $payment['paid_at'] : ( $payment['created'] ?? 0 );
                echo '<tr><td>' . esc_html( self::date( $stamp, false, in_array( $source, array( 'manual', 'check', 'mdm_remittance' ), true ) ) ) . '</td><td><strong>' . esc_html( self::label( $source ) ) . '</strong><br>' . esc_html( $name );
                if ( ! empty( $payment['customer_ref'] ) && $name !== $payment['customer_ref'] ) { echo '<br><span class="spm-meta">' . esc_html( $payment['customer_ref'] ) . '</span>'; }
                echo '</td><td><code>' . esc_html( $payment['id'] ?? '' ) . '</code>';
                if ( ! empty( $payment['url'] ) ) { echo '<br><a href="' . esc_url( $payment['url'] ) . '" target="_blank" rel="noopener noreferrer">Open source<span class="screen-reader-text"> (opens in a new tab)</span></a>'; }
                echo '</td><td>' . esc_html( self::money( $payment['amount_cents'] ?? null, $payment['currency'] ?? '' ) );
                if ( ! empty( $payment['refunded_cents'] ) ) { echo '<br><span class="spm-meta">Refunded: ' . esc_html( self::money( $payment['refunded_cents'], $payment['currency'] ?? '' ) ) . '</span>'; }
                echo '</td><td>' . esc_html( self::label( $payment['status'] ?? 'unknown' ) ) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</details></section>';
    }

    private static function review_import() {
        echo '<section class="spm-section"><details class="spm-group"><summary>Import review notes</summary><p>Use this for a reviewed batch of notes, flags, agreement changes or payment evidence. All actions are validated together before saving. Include the email review section only after the stated date range has actually been checked.</p>';
        self::form( 'review_import', array(), 'spm-review-import' );
        self::field( 'review_json', 'Review JSON', '', 'textarea', true, 'Maximum 100 actions. Do not include credentials, full email bodies or bank details.' );
        echo '<button type="submit" class="button">Validate and import review</button></form><details class="spm-details"><summary>JSON format</summary><p>The actions list accepts op values note, issue, record, evidence and flag, using the same field names as the forms above. For a note, supply record_id or issue_id. For a status change, supply issue_id, status, resolution and follow_up_date.</p><pre class="spm-json-example">' . esc_html( '{' . "\n" . '  "actions": [' . "\n" . '    {"op": "note", "record_id": "record-id", "note": "Verified explanation", "evidence_url": "https://mail.google.com/mail/#all/message-id"}' . "\n" . '  ],' . "\n" . '  "review": {"summary": "Checked relevant payment correspondence", "from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}' . "\n" . '}' ) . '</pre><p>Omit review for notes that do not represent a completed email review. A completed review updates the email freshness panel.</p></details></details></section>';
    }

    public static function render() {
        self::guard();
        $report = SPM_Monitor::report();
        $records = is_array( $report['records'] ?? null ) ? $report['records'] : array();
        $issues = is_array( $report['issues'] ?? null ) ? $report['issues'] : array();
        foreach ( $records as $id => &$record ) { if ( empty( $record['id'] ) ) { $record['id'] = $id; } } unset( $record );
        foreach ( $issues as $id => &$issue ) { if ( empty( $issue['id'] ) ) { $issue['id'] = $id; } } unset( $issue );
        $open = array_filter( $issues, function( $i ) { return 'open' === ( $i['status'] ?? 'open' ); } );
        $waiting = array_filter( $issues, function( $i ) { return in_array( $i['status'] ?? '', array( 'waiting', 'snoozed' ), true ); } );
        $resolved = array_filter( $issues, function( $i ) { return 'resolved' === ( $i['status'] ?? '' ); } );
        $historical = array(); $current = array(); $now = time();
        foreach ( $open as $id => $issue ) { if ( self::historical_issue( $issue, $now ) ) { $historical[ $id ] = $issue; } else { $current[ $id ] = $issue; } }
        $current_groups = self::issue_groups( $current, $records );
        $historical_groups = self::issue_groups( $historical, $records );
        $active = count( array_filter( $records, static function( $record ) { return 'active' === ( $record['expected'] ?? '' ); } ) );
        $selected_issue = isset( $_GET['spm_issue'] ) && is_string( $_GET['spm_issue'] ) ? sanitize_text_field( wp_unslash( $_GET['spm_issue'] ) ) : '';
        echo '<div class="wrap spm-monitor"><div class="spm-page-heading"><div><h1>Payments Monitor</h1><p>Expected income, payment evidence and follow-ups in one place.</p></div><div class="spm-page-actions"><a class="button" href="' . esc_url( self::url( 'stripe-payments-monitor-settings' ) ) . '">Connections &amp; settings</a><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=spm_monitor_report' ), 'spm_monitor_report' ) ) . '">View report JSON</a>';
        self::form( 'refresh', array( 'source' => 'all' ), 'spm-inline-form' );
        echo '<button type="submit" class="button button-primary">Refresh payment sources</button></form></div></div>';
        if ( isset( $_GET['spm_saved'] ) ) { echo '<div class="notice notice-success"><p>Request processed. Source refresh results appear in the freshness panel below.</p></div>'; }
        if ( isset( $_GET['spm_notice'] ) && is_string( $_GET['spm_notice'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['spm_notice'] ) ) ) . '</p></div>'; }
        echo '<div class="spm-counts"><div><strong>' . (int) count( $current_groups ) . '</strong><span>Clients to review now · ' . (int) count( $current ) . ' issues</span></div><div><strong>' . (int) count( $historical ) . '</strong><span>Historical and baseline issues</span></div><div><strong>' . (int) count( $waiting ) . '</strong><span>Waiting or snoozed issues</span></div><div><strong>' . (int) $active . '</strong><span>Expected to continue · ' . (int) count( $records ) . ' total tracked</span></div></div>';
        self::source_health( $report['sources'] ?? array() );
        if ( $selected_issue ) {
            echo '<section class="spm-section" id="spm-selected-issue" aria-labelledby="spm-selected-title"><div class="spm-section-heading"><h2 id="spm-selected-title">Review selected issue</h2><a href="' . esc_url( self::url() . '#spm-issues-title' ) . '">Back to issue overview</a></div>';
            if ( isset( $issues[ $selected_issue ] ) ) { self::issue_card( $issues[ $selected_issue ], $records ); }
            else { echo '<div class="spm-empty"><p>This issue is not in the current report. Choose an issue from the overview below.</p></div>'; }
            echo '</section>';
        }
        echo '<section class="spm-section" aria-labelledby="spm-issues-title"><div class="spm-section-heading"><h2 id="spm-issues-title">Current follow-ups</h2><span class="spm-meta">Reconciled ' . esc_html( self::date( $report['last_reconciled'] ?? 0, true ) ) . '</span></div><p class="spm-meta">Open a client to see its issues, then select an issue to review the evidence or update its status. Separate payment channels remain separate.</p>';
        if ( ! $current ) { echo '<div class="spm-empty"><strong>No current follow-ups in this view.</strong><p>Check source freshness and the historical review below before treating the report as complete.</p></div>'; }
        self::client_issues( $current_groups, $records );
        if ( $waiting ) { echo '<details class="spm-group" open><summary>Waiting and snoozed (' . (int) count( $waiting ) . ')</summary>'; self::client_issues( self::issue_groups( $waiting, $records ), $records ); echo '</details>'; }
        if ( $historical ) { echo '<details class="spm-group spm-historical"><summary>Historical balances and relationship review (' . (int) count( $historical ) . ')</summary><p class="spm-meta">' . (int) count( $historical_groups ) . ' client groups. Includes invoices created more than 60 days ago and imported relationships whose expected status needs confirmation. These issues are still open; grouping them does not clear a balance or confirm an intentional ending.</p>'; self::client_issues( $historical_groups, $records ); echo '</details>'; }
        if ( $resolved ) { echo '<details class="spm-group"><summary>Resolved history (' . (int) count( $resolved ) . ')</summary>'; self::client_issues( self::issue_groups( $resolved, $records ), $records ); echo '</details>'; }
        echo '</section><section class="spm-section" aria-labelledby="spm-agreements-title"><div class="spm-section-heading"><h2 id="spm-agreements-title">Expected agreements</h2><a href="' . esc_url( self::url( 'stripe-payments-monitor-legacy' ) ) . '">Site matching &amp; legacy report</a></div><p>Keep each relationship on this list until it is intentionally paused or ended. MDM donor contributions and the combined payment to the LLC are separate stages of the same money.</p><details class="spm-add"><summary>Add a check, manual payment or MDM remittance agreement</summary>';
        self::record_form();
        echo '</details>';
        $query = isset( $_GET['spm_search'] ) && is_string( $_GET['spm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['spm_search'] ) ) : '';
        $source = isset( $_GET['spm_source'] ) && is_string( $_GET['spm_source'] ) ? sanitize_key( $_GET['spm_source'] ) : '';
        $category = isset( $_GET['spm_category'] ) && is_string( $_GET['spm_category'] ) ? sanitize_key( $_GET['spm_category'] ) : '';
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#spm-agreements-title" class="spm-filters">';
        self::hidden( 'page', 'stripe-payments-monitor' );
        self::field( 'spm_search', 'Search agreements', $query, 'search' );
        self::select( 'spm_source', 'Source', $source, array( '' => 'All sources', 'stripe' => 'Stripe subscriptions', 'stripe_invoice' => 'Stripe invoices', 'moonclerk' => 'MoonClerk', 'check' => 'Check', 'manual' => 'Manual', 'mdm_remittance' => 'MDM remittance' ) );
        self::select( 'spm_category', 'Purpose', $category, array( '' => 'All purposes', 'maintenance' => 'Website maintenance', 'sponsorship' => 'Sponsorship', 'mdm_remittance' => 'MDM remittance', 'other' => 'Other' ) );
        echo '<button type="submit" class="button">Filter</button><a href="' . esc_url( self::url() ) . '#spm-agreements-title">Reset</a></form>';
        uasort( $records, function( $a, $b ) { return strcasecmp( $a['name'] ?? '', $b['name'] ?? '' ); } );
        $visible = 0;
        foreach ( $records as $record ) {
            if ( $source && $source !== ( $record['source'] ?? '' ) ) { continue; }
            if ( $category && $category !== ( $record['category'] ?? '' ) ) { continue; }
            $haystack = implode( ' ', array( $record['name'] ?? '', $record['email'] ?? '', $record['site_url'] ?? '', $record['customer_ref'] ?? '', $record['source_ref'] ?? '' ) );
            if ( $query && false === stripos( $haystack, $query ) ) { continue; }
            self::record_card( $record ); ++$visible;
        }
        if ( ! $visible ) { echo '<div class="spm-empty"><p>No agreements match these filters. Refresh a configured source or add an expected agreement above.</p></div>'; }
        echo '</section>';
        self::recent_payments( $report['payments'] ?? array(), $records );
        self::review_import();
        echo '</div>';
    }

    public static function settings() {
        self::guard();
        $stripe = (bool) get_option( 'spm_stripe_secret_key', '' );
        $moonclerk = ( defined( 'SPM_MOONCLERK_API_KEY' ) && (bool) constant( 'SPM_MOONCLERK_API_KEY' ) ) || (bool) get_option( 'spm_moonclerk_api_key', '' );
        $partners = get_option( 'spm_moonclerk_partner_allowlist', '' );
        $customers = get_option( 'spm_moonclerk_customer_ids', '' );
        $forms = get_option( 'spm_moonclerk_form_ids', '' );
        if ( is_array( $partners ) ) { $partners = implode( "\n", $partners ); }
        if ( is_array( $customers ) ) { $customers = implode( "\n", $customers ); }
        echo '<div class="wrap spm-monitor"><div class="spm-page-heading"><div><h1>Payments Monitor settings</h1><p>Connect the payment sources and define which MDM sponsorships belong in this report.</p></div><a class="button" href="' . esc_url( self::url() ) . '">Back to report</a></div>';
        if ( isset( $_GET['spm_saved'] ) ) { echo '<div class="notice notice-success"><p>Settings saved. Refresh the payment sources from the report to verify the connections.</p></div>'; }
        if ( isset( $_GET['spm_notice'] ) && is_string( $_GET['spm_notice'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['spm_notice'] ) ) ) . '</p></div>'; }
        self::form( 'settings', array(), 'spm-settings-form' );
        echo '<section class="spm-settings-card"><h2>Stripe</h2><p>Key configured: <strong>' . ( $stripe ? 'Yes' : 'No' ) . '</strong></p>';
        self::field( 'spm_stripe_key', 'Replace Stripe API key', '', 'password', false, 'Leave blank to keep the existing key. Use a key with read access to the customers, subscriptions, invoices and payments needed by the report.' );
        echo '</section><section class="spm-settings-card"><h2>MoonClerk / MDM</h2><p>Key configured: <strong>' . ( $moonclerk ? 'Yes' : 'No' ) . '</strong></p>';
        self::field( 'spm_moonclerk_key', 'Replace MoonClerk API key', '', 'password', false, 'Leave blank to keep the existing key. If a server configuration key is set, it takes precedence.' );
        self::field( 'moonclerk_partner_allowlist', 'Partner / project names to include', $partners, 'textarea', false, 'One exact partner or project name per line. Include only the sponsorships that belong to this monitor.' );
        self::field( 'moonclerk_customer_ids', 'MoonClerk customer IDs to include', $customers, 'textarea', false, 'One customer ID per line, where a donor is explicitly identified. Review the scope before importing unrelated MDM donors.' );
        self::field( 'moonclerk_form_ids', 'Dedicated MoonClerk form IDs to include', $forms, 'textarea', false, 'Numeric form IDs separated by commas or newlines. Use only a form dedicated to the sponsorships tracked here; a shared donation form would include unrelated donors.' );
        echo '</section><button type="submit" class="button button-primary">Save connections and scope</button></form><section class="spm-settings-card"><h2>Email review and follow-ups</h2><p>Recurring email reviews use the connected Gmail account and save short evidence notes into this monitor. The report shows the last successful review separately from payment-source refreshes.</p><p>Follow-up notes and resolved flags belong to this private dashboard. Customer payments, subscriptions and email messages are not changed by these review controls.</p></section></div>';
    }
}
