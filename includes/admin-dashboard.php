<?php
/**
 * Admin dashboard renderer.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SPM_Admin_Dashboard_View {

    const BATCH_NONCE_ACTION  = 'spm_process_report_batch';
    const ACTION_NONCE_ACTION = 'spm_dashboard_action';

    /**
     * Main entry invoked from admin-menu callback.
     */
    public static function output() {
        $key = get_option( 'spm_stripe_secret_key', '' );
        if ( empty( $key ) ) {
            echo '<div class="wrap"><h1>Payments Monitor</h1><p>Please enter your Stripe secret key in Settings.</p></div>';
            return;
        }

        $refresh_requested = isset( $_GET['spm_refresh'] )
            && wp_verify_nonce( wp_unslash( $_GET['spm_refresh'] ), 'spm_refresh' );

        $config = [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'batchNonce'  => wp_create_nonce( self::BATCH_NONCE_ACTION ),
            'actionNonce' => wp_create_nonce( self::ACTION_NONCE_ACTION ),
            'refresh'     => $refresh_requested ? '1' : '0',
        ];

        echo '<div class="wrap">';
        echo '<h1>Stripe Payments Monitor</h1>';
        echo '<div class="spm-dashboard-actions">';
        echo '<button type="button" class="button" data-spm-refresh-button="1">Refresh data</button>';
        echo '<span class="description" data-spm-dashboard-status>Preparing dashboard...</span>';
        echo '</div>';
        echo '<div class="spm-dashboard-progress" data-spm-loader="1" data-config="' . esc_attr( wp_json_encode( $config ) ) . '">';
        echo '<div class="spm-dashboard-progress-bar"><span class="spm-dashboard-progress-fill" data-spm-progress-fill></span></div>';
        echo '<div class="spm-dashboard-progress-meta"><strong data-spm-progress-percent>0%</strong> <span data-spm-progress-summary>Loading report...</span></div>';
        echo '<div class="description" data-spm-progress-status>Waiting to start...</div>';
        echo '<div class="spm-dashboard-progress-error is-hidden" data-spm-progress-error></div>';
        echo '</div>';
        echo '<div data-spm-dashboard-results></div>';
        echo '<noscript><p>The Payments Monitor dashboard requires JavaScript so it can load in batches.</p></noscript>';
        echo '</div>';

        self::print_bootstrap_script();
    }

    /**
     * Returns the rendered dashboard HTML for AJAX responses.
     *
     * @param array $data Cached report data.
     * @return string
     */
    public static function get_report_html( $data ) {
        ob_start();
        self::render_report( $data );
        return (string) ob_get_clean();
    }

    /**
     * Builds the final report view from cached Stripe/MainWP data plus runtime options.
     *
     * @param array $data Cached report data.
     */
    private static function render_report( $data ) {
        $context = self::prepare_report_context( $data );

        $customers     = $context['customers'];
        $overdue_ids   = $context['overdue_ids'];
        $sites         = $context['sites'];
        $matched_sites = $context['matched_sites'];
        $matched_custs = $context['matched_custs'];
        $ignore_sites  = $context['ignore_sites'];
        $ignore_cids   = $context['ignore_cids'];
        $unlinked      = $context['unlinked'];
        $site_notes    = $context['site_notes'];
        $client_notes  = $context['client_notes'];

        echo '<div class="spm-dashboard-results-shell">';

        echo '<h2>Matched Clients</h2>';
        echo '<table class="widefat"><thead><tr>';
        echo '<th>Website</th><th>Customer</th><th>E-mail</th><th style="text-align:right">Lifetime&nbsp;$</th>';
        echo '<th style="text-align:right">MRR&nbsp;$</th><th>Last&nbsp;Pay</th><th>Actions</th></tr></thead><tbody>';

        $rows = [];
        foreach ( $matched_sites as $site_url => $cid ) {
            if ( isset( $ignore_sites[ $site_url ] ) || ! isset( $customers[ $cid ] ) ) {
                continue;
            }

            $customer = $customers[ $cid ];
            $is_red   = isset( $overdue_ids[ $cid ] );
            $note     = $client_notes[ $cid ] ?? '';

            $rows[] = [
                'is_red' => $is_red,
                'html'   => sprintf(
                    '<tr class="%s"><td><a target="_blank" rel="noopener noreferrer" href="%s">%s</a></td><td>%s%s</td><td>%s</td><td style="text-align:right">%0.2f</td><td style="text-align:right">%0.2f</td><td>%s</td><td>%s %s</td></tr>',
                    $is_red ? 'spm-error' : '',
                    esc_url( $site_url ),
                    esc_html( $site_url ),
                    esc_html( $customer['name'] ),
                    $note ? ' <em>(' . esc_html( $note ) . ')</em>' : '',
                    esc_html( $customer['email'] ),
                    (float) $customer['total'],
                    (float) $customer['mrr'],
                    ! empty( $customer['last_paid'] ) ? date_i18n( 'Y-m-d', (int) $customer['last_paid'] ) : '&mdash;',
                    self::get_action_button( 'unlink', 'Unlink', [ 'site_url' => $site_url ] ),
                    self::get_action_button(
                        isset( $ignore_cids[ $cid ] ) ? 'unignore_client' : 'ignore_client',
                        isset( $ignore_cids[ $cid ] ) ? 'Un-ignore' : 'Ignore',
                        [ 'cid' => $cid ],
                        true
                    )
                ),
            ];
        }

        usort(
            $rows,
            fn( $left, $right ) => $left['is_red'] === $right['is_red'] ? 0 : ( $left['is_red'] ? -1 : 1 )
        );

        foreach ( $rows as $row ) {
            echo $row['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        echo '</tbody></table>';

        $unmatched_sites = array_diff_key( $sites, $matched_sites, $ignore_sites );
        echo '<h2>Unmatched Websites</h2>';
        if ( ! empty( $unmatched_sites ) ) {
            echo '<table class="widefat"><thead><tr><th>Site</th><th>Link to customer</th><th>Actions</th></tr></thead><tbody>';

            $sorted_customers = $customers;
            uasort(
                $sorted_customers,
                static function( $left, $right ) {
                    return strcasecmp( $left['name'], $right['name'] );
                }
            );

            foreach ( $unmatched_sites as $site_url => $title ) {
                $is_unlinked = isset( $unlinked[ $site_url ] );
                echo '<tr><td>' . esc_html( $site_url ) . '</td><td>';
                echo self::get_action_form_open(
                    'save_mapping',
                    [
                        'site_url' => $site_url,
                    ],
                    false,
                    'margin:0'
                );
                echo '<select name="customer_id">';
                foreach ( $sorted_customers as $cid => $customer ) {
                    echo '<option value="' . esc_attr( $cid ) . '">' . esc_html( $customer['name'] . ' (' . $customer['email'] . ')' ) . '</option>';
                }
                echo '</select> <button class="button">Save</button></form>';
                echo '</td><td>' . self::get_action_button( 'ignore_site', 'Mark internal', [ 'site_url' => $site_url ], true );
                if ( $is_unlinked ) {
                    echo ' ' . self::get_action_button( 'allow_automatch', 'Allow auto-match', [ 'site_url' => $site_url ] );
                }
                echo '</td></tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p><em>No unmatched websites (excluding internal).</em></p>';
        }

        echo '<h2>Internal Websites</h2>';
        if ( ! empty( $ignore_sites ) ) {
            echo '<table class="widefat"><thead><tr><th>Site</th><th>Note</th><th>Actions</th></tr></thead><tbody>';
            foreach ( $ignore_sites as $site_url => $ignored ) {
                echo '<tr><td>' . esc_html( $site_url ) . '</td><td>' . esc_html( $site_notes[ $site_url ] ?? '' ) . '</td><td>';
                echo self::get_action_button( 'unignore_site', 'Un-mark internal', [ 'site_url' => $site_url ] );
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>No sites marked as internal.</em></p>';
        }

        $unmatched_customers = array_diff_key( $customers, $matched_custs, $ignore_cids );
        echo '<h2>Unmatched Stripe Customers</h2>';
        if ( ! empty( $unmatched_customers ) ) {
            echo '<table class="widefat"><thead><tr><th>Customer</th><th>E-mail</th><th>Lifetime&nbsp;$</th><th>Link new site</th><th>Actions</th></tr></thead><tbody>';
            foreach ( $unmatched_customers as $cid => $customer ) {
                echo '<tr><td>' . esc_html( $customer['name'] ) . '</td><td>' . esc_html( $customer['email'] ) . '</td><td>' . esc_html( number_format( (float) $customer['total'], 2 ) ) . '</td><td>';
                echo self::get_action_form_open(
                    'save_mapping',
                    [
                        'customer_id' => $cid,
                    ],
                    false,
                    'margin:0'
                );
                echo '<input type="text" name="site_url" placeholder="https://site.com" style="width:200px">';
                echo ' <button class="button">Link</button></form></td><td>';
                echo self::get_action_button( 'ignore_client', 'Ignore', [ 'cid' => $cid ], true );
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>No unmatched customers (excluding ignored).</em></p>';
        }

        echo '</div>';
    }

    /**
     * Applies runtime-only options to cached report data.
     *
     * @param array $data Cached report data.
     * @return array
     */
    private static function prepare_report_context( $data ) {
        $customers = isset( $data['customers'] ) && is_array( $data['customers'] ) ? $data['customers'] : [];
        $overdue_ids = isset( $data['overdue_ids'] ) && is_array( $data['overdue_ids'] ) ? $data['overdue_ids'] : [];
        $sites = isset( $data['sites'] ) && is_array( $data['sites'] ) ? $data['sites'] : [];
        $matched_sites = isset( $data['matched_sites'] ) && is_array( $data['matched_sites'] ) ? $data['matched_sites'] : [];

        $manual_map   = get_option( 'stripe_pm_site_customer_map', [] );
        $ignore_sites = get_option( 'spm_ignore_sites', [] );
        $ignore_cids  = get_option( 'spm_ignore_clients', [] );
        $unlinked     = get_option( 'spm_unlinked_sites', [] );
        $site_notes   = get_option( 'spm_site_notes', [] );
        $client_notes = get_option( 'spm_client_notes', [] );

        if ( ! is_array( $manual_map ) ) {
            $manual_map = [];
        }
        if ( ! is_array( $ignore_sites ) ) {
            $ignore_sites = [];
        }
        if ( ! is_array( $ignore_cids ) ) {
            $ignore_cids = [];
        }
        if ( ! is_array( $unlinked ) ) {
            $unlinked = [];
        }
        if ( ! is_array( $site_notes ) ) {
            $site_notes = [];
        }
        if ( ! is_array( $client_notes ) ) {
            $client_notes = [];
        }

        $matched_sites = array_filter(
            $matched_sites,
            static function( $cid, $site_url ) use ( $customers, $sites ) {
                return isset( $customers[ $cid ], $sites[ $site_url ] );
            },
            ARRAY_FILTER_USE_BOTH
        );

        foreach ( $manual_map as $site_url => $cid ) {
            if ( isset( $customers[ $cid ], $sites[ $site_url ] ) ) {
                $matched_sites[ $site_url ] = $cid;
            }
        }

        foreach ( $unlinked as $site_url => $ignored ) {
            unset( $matched_sites[ $site_url ] );
        }

        foreach ( $ignore_cids as $cid => $ignored ) {
            unset( $overdue_ids[ $cid ] );
        }

        return [
            'customers'     => $customers,
            'overdue_ids'   => $overdue_ids,
            'sites'         => $sites,
            'matched_sites' => $matched_sites,
            'matched_custs' => self::build_matched_customer_index( $matched_sites ),
            'ignore_sites'  => $ignore_sites,
            'ignore_cids'   => $ignore_cids,
            'unlinked'      => $unlinked,
            'site_notes'    => $site_notes,
            'client_notes'  => $client_notes,
        ];
    }

    /**
     * Builds the reverse customer-to-sites index from site matches.
     *
     * @param array $matched_sites Site-to-customer matches.
     * @return array
     */
    private static function build_matched_customer_index( $matched_sites ) {
        $matched_customers = [];

        foreach ( $matched_sites as $site_url => $cid ) {
            if ( ! isset( $matched_customers[ $cid ] ) ) {
                $matched_customers[ $cid ] = [];
            }

            if ( ! in_array( $site_url, $matched_customers[ $cid ], true ) ) {
                $matched_customers[ $cid ][] = $site_url;
            }
        }

        return $matched_customers;
    }

    /**
     * Returns the opening form markup for AJAX-aware action forms.
     *
     * @param string $action Dashboard action.
     * @param array  $hidden Hidden field values.
     * @param bool   $allow_note Whether to render a note field.
     * @param string $style Inline style attribute value.
     * @return string
     */
    private static function get_action_form_open( $action, $hidden = [], $allow_note = false, $style = 'display:inline' ) {
        $markup  = '<form method="post" data-spm-action-form="1" style="' . esc_attr( $style ) . '">';
        $markup .= wp_nonce_field( 'spm_action', 'spm_nonce', true, false );
        $markup .= '<input type="hidden" name="spm_action" value="' . esc_attr( $action ) . '">';

        foreach ( $hidden as $key => $value ) {
            $markup .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
        }

        if ( $allow_note ) {
            $markup .= '<input type="text" name="note" placeholder="note..." style="width:120px">';
        }

        return $markup;
    }

    /**
     * Returns an inline button form for a single action.
     *
     * @param string $action Dashboard action.
     * @param string $label Button label.
     * @param array  $hidden Hidden field values.
     * @param bool   $allow_note Whether to render a note field.
     * @return string
     */
    private static function get_action_button( $action, $label, $hidden = [], $allow_note = false ) {
        $markup  = self::get_action_form_open( $action, $hidden, $allow_note );
        $markup .= '<button class="button-link">' . esc_html( $label ) . '</button></form>';
        return $markup;
    }

    /**
     * Outputs the dashboard bootstrap script.
     */
    private static function print_bootstrap_script() {
        ?>
        <script>
        (function() {
            var loader = document.querySelector('[data-spm-loader="1"]');
            var resultsNode = document.querySelector('[data-spm-dashboard-results]');
            if (!loader || !resultsNode) {
                return;
            }

            var config = {};
            try {
                config = JSON.parse(loader.getAttribute('data-config') || '{}');
            } catch (error) {
                config = {};
            }

            var ajaxUrl = config.ajaxUrl || '';
            var batchNonce = config.batchNonce || '';
            var actionNonce = config.actionNonce || '';
            var refreshMode = config.refresh === '1';
            var refreshButton = document.querySelector('[data-spm-refresh-button="1"]');
            var statusNode = document.querySelector('[data-spm-dashboard-status]');
            var percentNode = document.querySelector('[data-spm-progress-percent]');
            var summaryNode = document.querySelector('[data-spm-progress-summary]');
            var detailNode = document.querySelector('[data-spm-progress-status]');
            var fillNode = document.querySelector('[data-spm-progress-fill]');
            var errorNode = document.querySelector('[data-spm-progress-error]');
            var jobId = '';
            var loading = false;
            var actionBusy = false;
            var pendingScrollY = null;

            var setRefreshDisabled = function(disabled) {
                if (refreshButton) {
                    refreshButton.disabled = !!disabled;
                }
            };

            var setStatus = function(message) {
                if (statusNode) {
                    statusNode.textContent = message || '';
                }
            };

            var setProgress = function(percent, summary, details) {
                var safePercent = Math.max(0, Math.min(100, Number(percent) || 0));
                if (fillNode) {
                    fillNode.style.width = safePercent + '%';
                }
                if (percentNode) {
                    percentNode.textContent = Math.round(safePercent) + '%';
                }
                if (summaryNode) {
                    summaryNode.textContent = summary || '';
                }
                if (detailNode) {
                    detailNode.textContent = details || '';
                }
            };

            var clearError = function() {
                loader.classList.remove('is-error');
                if (errorNode) {
                    errorNode.textContent = '';
                    errorNode.classList.add('is-hidden');
                }
            };

            var restoreScrollPosition = function() {
                if (pendingScrollY === null) {
                    return;
                }

                var targetY = pendingScrollY;
                pendingScrollY = null;

                window.setTimeout(function() {
                    window.scrollTo({
                        top: targetY,
                        behavior: 'auto'
                    });
                }, 20);
            };

            var setFormDisabled = function(form, disabled) {
                if (!form) {
                    return;
                }

                var fields = form.querySelectorAll('button, input, select, textarea');
                fields.forEach(function(field) {
                    if (field.type === 'hidden') {
                        return;
                    }
                    field.disabled = !!disabled;
                });
            };

            var showError = function(message, keepResults) {
                loading = false;
                actionBusy = false;
                loader.classList.remove('is-loading');
                loader.classList.remove('is-complete');
                loader.classList.add('is-error');

                if (errorNode) {
                    errorNode.textContent = message || 'Unable to load the dashboard.';
                    errorNode.classList.remove('is-hidden');
                }

                if (!keepResults) {
                    resultsNode.innerHTML = '';
                }

                setRefreshDisabled(false);
                setStatus(message || 'Request failed.');
            };

            var parseResponse = function(response) {
                return response.text().then(function(text) {
                    var payload = null;
                    if (text) {
                        try {
                            payload = JSON.parse(text);
                        } catch (error) {
                            payload = null;
                        }
                    }

                    if (!response.ok) {
                        var errorMessage = payload && payload.data && payload.data.message ? payload.data.message : 'The server returned an invalid Payments Monitor response.';
                        throw new Error(errorMessage);
                    }

                    if (!payload) {
                        throw new Error('The server returned an empty Payments Monitor response.');
                    }

                    return payload;
                });
            };

            var requestNextBatch = function() {
                if (loading) {
                    return;
                }

                loading = true;
                clearError();
                loader.classList.add('is-loading');
                loader.classList.remove('is-complete');
                setRefreshDisabled(true);
                setStatus(refreshMode ? 'Refreshing Stripe data...' : 'Loading Payments Monitor...');

                var formData = new URLSearchParams();
                formData.append('action', 'spm_process_report_batch');
                formData.append('nonce', batchNonce);
                formData.append('refresh', refreshMode ? '1' : '0');

                if (jobId) {
                    formData.append('job_id', jobId);
                }

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: formData.toString()
                }).then(parseResponse).then(function(payload) {
                    if (!payload || !payload.success || !payload.data) {
                        var message = payload && payload.data && payload.data.message ? payload.data.message : 'The Payments Monitor batch request failed.';
                        throw new Error(message);
                    }

                    var data = payload.data;
                    if (data.job_id) {
                        jobId = String(data.job_id);
                    }

                    setProgress(data.progress_pct, data.status_text, data.counts_text);

                    if (data.done) {
                        if (typeof data.html === 'string') {
                            resultsNode.innerHTML = data.html;
                        }
                        loading = false;
                        refreshMode = false;
                        loader.classList.remove('is-loading');
                        loader.classList.remove('is-error');
                        loader.classList.add('is-complete');
                        setRefreshDisabled(false);
                        setStatus(data.status_text || 'Payments report ready.');
                        restoreScrollPosition();
                        return;
                    }

                    loading = false;
                    window.setTimeout(requestNextBatch, 25);
                }).catch(function(error) {
                    showError(error && error.message ? error.message : 'Unable to load the Payments Monitor dashboard.', false);
                });
            };

            var startBatch = function(forceRefresh, scrollY) {
                if (loading || actionBusy) {
                    return;
                }

                jobId = '';
                refreshMode = !!forceRefresh;
                if (typeof scrollY === 'number') {
                    pendingScrollY = scrollY;
                }

                resultsNode.innerHTML = '';
                setProgress(0, refreshMode ? 'Refreshing Stripe data...' : 'Loading Payments Monitor...', 'Preparing report batches...');
                requestNextBatch();
            };

            var submitActionForm = function(form) {
                if (loading || actionBusy) {
                    return;
                }

                actionBusy = true;
                pendingScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
                clearError();
                setRefreshDisabled(true);
                setStatus('Saving changes...');
                setFormDisabled(form, true);

                var formData = new FormData(form);
                var payload = new URLSearchParams();
                formData.forEach(function(value, key) {
                    payload.append(key, value);
                });
                payload.append('action', 'spm_dashboard_action');
                payload.append('nonce', actionNonce);

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: payload.toString()
                }).then(parseResponse).then(function(responsePayload) {
                    if (!responsePayload || !responsePayload.success || !responsePayload.data) {
                        var message = responsePayload && responsePayload.data && responsePayload.data.message ? responsePayload.data.message : 'The Payments Monitor action failed.';
                        throw new Error(message);
                    }

                    var data = responsePayload.data;
                    actionBusy = false;
                    setFormDisabled(form, false);

                    if (data.reload_report) {
                        setStatus(data.message || 'Changes saved. Reloading the dashboard report...');
                        startBatch(false, pendingScrollY);
                        return;
                    }

                    if (typeof data.html === 'string') {
                        resultsNode.innerHTML = data.html;
                    }

                    loader.classList.remove('is-error');
                    loader.classList.add('is-complete');
                    setRefreshDisabled(false);
                    setStatus(data.message || 'Changes saved.');
                    restoreScrollPosition();
                }).catch(function(error) {
                    setFormDisabled(form, false);
                    showError(error && error.message ? error.message : 'Unable to save Payments Monitor changes.', true);
                });
            };

            if (refreshButton) {
                refreshButton.addEventListener('click', function() {
                    startBatch(true);
                });
            }

            resultsNode.addEventListener('submit', function(event) {
                var form = event.target;
                if (!form || !form.matches('[data-spm-action-form="1"]')) {
                    return;
                }

                event.preventDefault();
                submitActionForm(form);
            });

            startBatch(refreshMode);
        })();
        </script>
        <?php
    }
}
