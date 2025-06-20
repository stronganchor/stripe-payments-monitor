<?php
/**
 * Admin-dashboard renderer – outputs the four tables:
 *   1) Matched Clients
 *   2) Unmatched Websites
 *   3) Internal Websites
 *   4) Unmatched Stripe Customers
 *
 * Depends on:
 *   – includes/data-cache.php   → spm_get_cached_report()
 *   – includes/action-handler.php (POST endpoints)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Admin_Dashboard_View {

    /**
     * Main entry invoked from admin-menu callback.
     */
    public static function output() {

        $key = get_option( 'spm_stripe_secret_key', '' );
        if ( empty( $key ) ) {
            echo '<div class="wrap"><h1>Payments Monitor</h1>'
               . '<p>Please add your Stripe secret key in Settings.</p></div>';
            return;
        }

        $force = isset( $_GET['spm_refresh'] ) && wp_verify_nonce( $_GET['spm_refresh'], 'spm_refresh' );
        $data  = spm_get_cached_report( $key, $force );

        if ( is_wp_error( $data ) ) {
            echo '<div class="wrap"><h1>Stripe Payments Monitor</h1>'
               . '<p>Error: ' . esc_html( $data->get_error_message() ) . '</p></div>';
            return;
        }

        extract( $data ); // $customers, $overdue_ids, $sites, $matched_sites, $matched_custs

        // Persistent lists & notes
        $ignore_sites = get_option( 'spm_ignore_sites',   [] );
        $ignore_cids  = get_option( 'spm_ignore_clients', [] );
        $unlinked     = get_option( 'spm_unlinked_sites', [] );
        $site_notes   = get_option( 'spm_site_notes',     [] );
        $client_notes = get_option( 'spm_client_notes',   [] );

        // Remove ignored customers from overdue list
        foreach ( $ignore_cids as $cid => $_ ) {
            unset( $overdue_ids[ $cid ] );
        }
        // Remove internal sites from pools
        $sites = array_diff_key( $sites, $ignore_sites );

        // Helper – inline form button
        $btn = function( $action, $label, $hidden = [], $note = false ) {
            $out  = '<form method="post" style="display:inline">';
            $out .= wp_nonce_field( 'spm_action', 'spm_nonce', true, false );
            $out .= '<input type="hidden" name="spm_action" value="' . esc_attr( $action ) . '">';
            foreach ( $hidden as $k => $v ) {
                $out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
            }
            if ( $note ) {
                $out .= '<input type="text" name="note" placeholder="note…" style="width:120px">';
            }
            return $out . '<button class="button-link">' . esc_html( $label ) . '</button></form>';
        };

        // Header + Refresh button
        echo '<div class="wrap"><h1>Stripe Payments Monitor</h1>';
        echo '<p><a href="' . esc_url( add_query_arg( 'spm_refresh', wp_create_nonce( 'spm_refresh' ) ) )
             . '" class="button">Refresh data</a></p>';

        // 1) Matched Clients
        echo '<h2>Matched Clients</h2><table class="widefat"><thead><tr>'
           . '<th>Website</th><th>Customer</th><th>E-mail</th>'
           . '<th style="text-align:right">Lifetime&nbsp;$</th>'
           . '<th style="text-align:right">MRR&nbsp;$</th>'
           . '<th>Last&nbsp;Pay</th><th>Actions</th>'
           . '</tr></thead><tbody>';
        $rows = [];
        foreach ( $matched_sites as $site_url => $cid ) {
            if ( isset( $ignore_sites[ $site_url ] ) ) {
                continue;
            }
            $c      = $customers[ $cid ];
            $is_red = isset( $overdue_ids[ $cid ] );
            $note   = $client_notes[ $cid ] ?? '';
            $rows[] = [
                'is_red' => $is_red,
                'html'   => sprintf(
                    '<tr class="%s"><td><a target="_blank" href="%s">%s</a></td>'
                  . '<td>%s%s</td><td>%s</td><td style="text-align:right">%0.2f</td>'
                  . '<td style="text-align:right">%0.2f</td><td>%s</td><td>%s %s</td></tr>',
                    $is_red ? 'spm-error' : '',
                    esc_url( $site_url ),
                    esc_html( $site_url ),
                    esc_html( $c['name'] ),
                    $note ? ' <em>(' . esc_html( $note ) . ')</em>' : '',
                    esc_html( $c['email'] ),
                    $c['total'],
                    $c['mrr'],
                    $c['last_paid'] ? date_i18n( 'Y-m-d', $c['last_paid'] ) : '—',
                    $btn( 'unlink', 'Unlink', [ 'site_url' => $site_url ] ),
                    $btn( isset( $ignore_cids[ $cid ] ) ? 'unignore_client' : 'ignore_client',
                          isset( $ignore_cids[ $cid ] ) ? 'Un-ignore' : 'Ignore',
                          [ 'cid' => $cid ], true )
                )
            ];
        }
        usort( $rows, fn( $a, $b ) => $a['is_red'] === $b['is_red'] ? 0 : ( $a['is_red'] ? -1 : 1 ) );
        foreach ( $rows as $r ) {
            echo $r['html'];
        }
        echo '</tbody></table>';

        // 2) Unmatched Websites
        $unmatched = array_diff_key( $sites, $matched_sites, $ignore_sites );
        echo '<h2>Unmatched Websites</h2>';
        if ( $unmatched ) {
            echo '<table class="widefat"><thead><tr>'
               . '<th>Site</th><th>Link to customer</th><th>Actions</th>'
               . '</tr></thead><tbody>';
            foreach ( $unmatched as $site_url => $title ) {
                $is_unlinked = isset( $unlinked[ $site_url ] );
                echo '<tr><td>' . esc_html( $site_url ) . '</td><td>';
                echo '<form method="post" style="margin:0">'
                   . wp_nonce_field( 'spm_action', 'spm_nonce', true, false )
                   . '<input type="hidden" name="spm_action" value="save_mapping">'
                   . '<input type="hidden" name="site_url" value="' . esc_attr( $site_url ) . '">'
                   . '<select name="customer_id">';
                foreach ( $customers as $cid => $c ) {
                    echo '<option value="' . esc_attr( $cid ) . '">'
                       . esc_html( $c['name'] . ' (' . $c['email'] . ')' )
                       . '</option>';
                }
                echo '</select> <button class="button">Save</button></form></td><td>';
                echo $btn( 'ignore_site', 'Mark internal', [ 'site_url' => $site_url ], true );
                if ( $is_unlinked ) {
                    echo ' ' . $btn( 'allow_automatch', 'Allow auto-match', [ 'site_url' => $site_url ] );
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>No unmatched websites (excluding internal).</em></p>';
        }

        // 3) Internal Websites
        echo '<h2>Internal Websites</h2>';
        if ( $ignore_sites ) {
            echo '<table class="widefat"><thead><tr>'
               . '<th>Site</th><th>Note</th><th>Actions</th>'
               . '</tr></thead><tbody>';
            foreach ( $ignore_sites as $site_url => $_ ) {
                $note = $site_notes[ $site_url ] ?? '';
                echo '<tr><td>' . esc_html( $site_url ) . '</td><td>'
                   . esc_html( $note ) . '</td><td>'
                   . $btn( 'unignore_site', 'Un-mark internal', [ 'site_url' => $site_url ] )
                   . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>No sites marked as internal.</em></p>';
        }

        // 4) Unmatched Stripe Customers
        $unmatched_cust = array_diff_key( $customers, $matched_custs, $ignore_cids );
        echo '<h2>Unmatched Stripe Customers</h2>';
        if ( $unmatched_cust ) {
            echo '<table class="widefat"><thead><tr>'
               . '<th>Customer</th><th>E-mail</th><th>Lifetime&nbsp;$</th>'
               . '<th>Link new site</th><th>Actions</th>'
               . '</tr></thead><tbody>';
            foreach ( $unmatched_cust as $cid => $c ) {
                echo '<tr><td>' . esc_html( $c['name'] ) . '</td><td>'
                   . esc_html( $c['email'] ) . '</td><td>'
                   . number_format( $c['total'], 2 ) . '</td><td>'
                   . '<form method="post" style="margin:0">'
                   . wp_nonce_field( 'spm_action', 'spm_nonce', true, false )
                   . '<input type="hidden" name="spm_action" value="save_mapping">'
                   . '<input type="hidden" name="customer_id" value="' . esc_attr( $cid ) . '">'
                   . '<input type="text" name="site_url" placeholder="https://site.com" style="width:200px">'
                   . '<button class="button">Link</button></form></td><td>'
                   . $btn( 'ignore_client', 'Ignore', [ 'cid' => $cid ], true )
                   . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>No unmatched customers (excluding ignored).</em></p>';
        }

        echo '</div>';
    }
}
