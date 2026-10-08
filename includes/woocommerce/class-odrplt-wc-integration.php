<?php
/**
 * WooCommerce Integration
 *
 * Hooks into WooCommerce to add OrderPilot columns (Fraud Risk, Courier,
 * Delivery Status, Actions) to the native order listing, registers bulk
 * actions, and handles order status change events.
 *
 * WooCommerce remains the single source of truth for order listing and
 * management. OrderPilot only adds informational columns and quick actions.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_WC_Integration
 */
class ODRPLT_WC_Integration {

    /**
     * @var ODRPLT_Settings
     */
    private ODRPLT_Settings $settings;

    /**
     * @var ODRPLT_Courier_Manager
     */
    private ODRPLT_Courier_Manager $couriers;

    /**
     * @var ODRPLT_Logger
     */
    private ODRPLT_Logger $logger;

    /**
     * Constructor.
     *
     * @param ODRPLT_Settings        $settings
     * @param ODRPLT_Courier_Manager $couriers
     * @param ODRPLT_Logger          $logger
     */
    public function __construct(
        ODRPLT_Settings $settings,
        ODRPLT_Courier_Manager $couriers,
        ODRPLT_Logger $logger
    ) {
        $this->settings = $settings;
        $this->couriers = $couriers;
        $this->logger   = $logger;
    }

    // ─── Order List Columns ───────────────────────────────────────────────────

    /**
     * Add OrderPilot columns to the WooCommerce orders list.
     * Replaces the single legacy courier-status column with four focused columns.
     *
     * Hooked to:
     *   - `manage_woocommerce_page_wc-orders_columns` (HPOS)
     *   - `manage_edit-shop_order_columns` (legacy CPT)
     *
     * @since 1.0.0
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_columns( array $columns ): array {
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            // Remove the old single courier column if it somehow still exists.
            if ( 'odrplt_courier_status' === $key ) {
                continue;
            }

            $new_columns[ $key ] = $label;

            // Inject all four OrderPilot columns after the WC order status column.
            if ( 'order_status' === $key ) {
                $new_columns['odrplt_fraud_risk']       = __( 'Fraud Risk', 'order-pilot' );
                $new_columns['odrplt_courier']          = __( 'Courier', 'order-pilot' );
                $new_columns['odrplt_delivery_status']  = __( 'Delivery Status', 'order-pilot' );
                $new_columns['odrplt_actions']          = __( 'Actions', 'order-pilot' );
            }
        }

        return $new_columns;
    }

    /**
     * Render an OrderPilot column cell.
     *
     * Hooked to:
     *   - `manage_woocommerce_page_wc-orders_custom_column` (HPOS) — args: column, order
     *   - `manage_shop_order_posts_custom_column` (legacy) — args: column, post_id
     *
     * @since 1.0.0
     * @param string         $column   Current column key.
     * @param mixed          $order_or_id  WC_Order (HPOS) or post ID (legacy).
     */
    public function render_column( string $column, $order_or_id ): void {
        if ( 0 !== strpos( $column, 'odrplt_' ) ) {
            return;
        }

        $oid = $this->normalize_order_id( $order_or_id );

        switch ( $column ) {
            case 'odrplt_fraud_risk':
                $this->render_fraud_risk_cell( $oid );
                break;
            case 'odrplt_courier':
                $this->render_courier_cell( $oid );
                break;
            case 'odrplt_delivery_status':
                $this->render_delivery_status_cell( $oid );
                break;
            case 'odrplt_actions':
                $this->render_actions_cell( $oid );
                break;
        }
    }

    // ─── Column Cell Renderers ────────────────────────────────────────────────

    /**
     * Render the Fraud Risk column cell.
     *
     * For free users: shows the risk label only when risk meta is present.
     * For Pro: renders the same colored badge with the fraud score.
     *
     * Pro populates `_odrplt_fraud_risk` (low|medium|high) and optionally
     * `_odrplt_fraud_score` (0–100) via its own fraud checker hooks.
     *
     * @since 1.0.0
     * @param int $order_id
     */
    private function render_fraud_risk_cell( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            echo '<span class="odrplt-badge odrplt-badge--none">—</span>';
            return;
        }

        $risk  = $order->get_meta( '_odrplt_fraud_risk', true );
        $score = $order->get_meta( '_odrplt_fraud_score', true );

        /**
         * Filter: fraud risk display data for a column cell.
         * Pro uses this to ensure fresh data is shown.
         *
         * @since 1.0.0
         * @param array $data  [ 'risk' => string, 'score' => int|null ]
         * @param int   $order_id
         */
        $data = (array) apply_filters( 'order_pilot_fraud_risk_column_data', [
            'risk'  => $risk,
            'score' => $score !== '' ? (int) $score : null,
        ], $order_id );

        $risk  = $data['risk'] ?? '';
        $score = $data['score'] ?? null;

        if ( empty( $risk ) ) {
            echo '<span class="odrplt-badge odrplt-badge--none" title="' . esc_attr__( 'Fraud check not performed', 'order-pilot' ) . '">— ' . esc_html__( 'Not Checked', 'order-pilot' ) . '</span>';
            return;
        }

        $label = ucfirst( $risk );

        // Scores are a Pro-only detail. The free column intentionally stays
        // compact and shows only the calculated risk level.
        $is_pro     = (bool) apply_filters( 'order_pilot_is_pro', false );
        $score_html = ( $is_pro && null !== $score )
            ? '<br><small style="color:var(--odrplt-text-muted,#888);">' . (int) $score . '/100</small>'
            : '';

        printf(
            '<span class="odrplt-badge odrplt-badge--risk odrplt-risk--%s">%s%s</span>',
            esc_attr( $risk ),
            esc_html( $label ),
            $score_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    /**
     * Render the Courier column cell.
     *
     * Shows the courier name or "— Not Sent" if no courier is assigned.
     *
     * @since 1.0.0
     * @param int $order_id
     */
    private function render_courier_cell( int $order_id ): void {
        $order        = wc_get_order( $order_id );
        $courier_slug = $order ? $order->get_meta( '_odrplt_courier', true ) : '';

        if ( ! $courier_slug ) {
            echo '<span class="odrplt-badge odrplt-badge--none">— ' . esc_html__( 'Not Sent', 'order-pilot' ) . '</span>';
            return;
        }

        $courier      = $this->couriers->get( $courier_slug );
        $courier_name = $courier ? $courier->get_name() : ucfirst( $courier_slug );
        $consignment  = $order ? $order->get_meta( '_odrplt_consignment_id', true ) : '';
        $title        = $consignment ? $courier_name . ' — ' . $consignment : $courier_name;

        printf(
            '<span class="odrplt-badge odrplt-badge--courier" title="%s">%s</span>',
            esc_attr( $title ),
            esc_html( $courier_name )
        );
    }

    /**
     * Render the Delivery Status column cell.
     *
     * Reads the normalized status from the consignments DB table.
     *
     * @since 1.0.0
     * @param int $order_id
     */
    private function render_delivery_status_cell( int $order_id ): void {
        $order        = wc_get_order( $order_id );
        $courier_slug = $order ? $order->get_meta( '_odrplt_courier', true ) : '';
        $consignment  = $order ? $order->get_meta( '_odrplt_consignment_id', true ) : '';

        if ( ! $courier_slug || ! $consignment ) {
            echo '<span class="odrplt-badge odrplt-badge--none">— ' . esc_html__( 'Not Sent', 'order-pilot' ) . '</span>';
            return;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom consignment table query.
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}odrplt_consignments WHERE order_id = %d AND courier = %s LIMIT 1",
            $order_id,
            $courier_slug
        ) );

        $status = $status ?: ODRPLT_Courier_Manager::STATUS_PENDING;

        printf(
            '<span class="odrplt-badge odrplt-badge--%s">%s</span>',
            esc_attr( $status ),
            esc_html( $this->status_label( $status ) )
        );
    }

    /**
     * Render the Actions column cell.
     *
     * Shows contextual quick-action buttons based on order state:
     * - Not sent: [Send to Courier]
     * - Already sent: [Sync Status]
     * - High fraud risk: [View Fraud]
     *
     * Vanilla JS picks up the data-* attributes; no React needed here.
     *
     * @since 1.0.0
     * @param int $order_id
     */
    private function render_actions_cell( int $order_id ): void {
        $order        = wc_get_order( $order_id );
        $courier_slug = $order ? $order->get_meta( '_odrplt_courier', true ) : '';
        $consignment  = $order ? $order->get_meta( '_odrplt_consignment_id', true ) : '';
        $risk         = $order ? $order->get_meta( '_odrplt_fraud_risk', true ) : '';
        $rest_url     = rest_url( 'order-pilot/v1/' );
        $rest_nonce   = wp_create_nonce( 'wp_rest' );

        echo '<div class="odrplt-action-btns" style="display:flex;gap:4px;flex-wrap:wrap;">';

        if ( $courier_slug && $consignment ) {
            // Already sent — offer Sync Status.
            printf(
                '<button type="button" class="button button-small odrplt-col-sync-btn"
                    data-order-id="%d"
                    data-rest-url="%s"
                    data-rest-nonce="%s"
                    title="%s">🔄 %s</button>',
                absint( $order_id ),
                esc_url( $rest_url ),
                esc_attr( $rest_nonce ),
                esc_attr__( 'Sync delivery status from courier', 'order-pilot' ),
                esc_html__( 'Sync', 'order-pilot' )
            );
        } else {
            // Not yet sent — offer Send to Courier.
            $connected = $this->couriers->get_connected();
            if ( ! empty( $connected ) ) {
                $auto_info = apply_filters( 'order_pilot_resolve_order_courier', null, $order );
                printf(
                    '<button type="button" class="button button-small button-primary odrplt-col-send-btn"
                        data-order-id="%d"
                        data-rest-url="%s"
                        data-rest-nonce="%s"
                        data-auto-courier="%s"
                        data-auto-source="%s"
                        title="%s">🚚 %s</button>',
                    absint( $order_id ),
                    esc_url( $rest_url ),
                    esc_attr( $rest_nonce ),
                    esc_attr( $auto_info['courier'] ?? '' ),
                    esc_attr( $auto_info['source'] ?? '' ),
                    esc_attr__( 'Send this order to a courier', 'order-pilot' ),
                    esc_html__( 'Send', 'order-pilot' )
                );
            }
        }

        // High fraud risk — offer View Fraud link (Pro will populate the fraud page URL).
        if ( 'high' === $risk ) {
            $fraud_url = apply_filters(
                'order_pilot_fraud_view_url',
                admin_url( 'admin.php?page=order-pilot-fraud&order_id=' . $order_id ),
                $order_id
            );
            printf(
                '<a href="%s" class="button button-small" style="color:#cc0000;" title="%s">🔴 %s</a>',
                esc_url( $fraud_url ),
                esc_attr__( 'This order has a high fraud risk score', 'order-pilot' ),
                esc_html__( 'Fraud', 'order-pilot' )
            );
        }

        /**
         * Action: fires inside the Actions column cell so Pro can add extra buttons.
         *
         * @since 1.0.0
         * @param int           $order_id
         * @param \WC_Order|null $order
         */
        do_action( 'order_pilot_actions_column_buttons', $order_id, $order );

        echo '</div>';
    }

    // ─── Inline JS for Column Actions ─────────────────────────────────────────

    /**
     * Output the inline JavaScript that powers the Send and Sync column buttons.
     * Hooked to `admin_footer` — only outputs on WC order list screens.
     *
     * @since 1.0.0
     */
    public function output_column_js(): void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $is_wc_orders = in_array( $screen->id, [
            'woocommerce_page_wc-orders', // HPOS
            'edit-shop_order',             // Legacy
        ], true );

        if ( ! $is_wc_orders ) {
            return;
        }

        $couriers = [];
        foreach ( $this->couriers->get_connected() as $slug => $courier ) {
            $couriers[] = [ 'slug' => $slug, 'name' => $courier->get_name() ];
        }

        ?>
        <style>
            .odrplt-badge { display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:600; white-space:nowrap; line-height:1.6; }
            .odrplt-badge--none { color:#999; background:transparent; }
            .odrplt-badge--courier { background:#e8f0fe; color:#1a56db; }
            .odrplt-badge--pending { background:#fff3cd; color:#856404; }
            .odrplt-badge--in_transit { background:#cfe2ff; color:#0d6efd; }
            .odrplt-badge--picked_up { background:#d1ecf1; color:#0c5460; }
            .odrplt-badge--delivered { background:#d4edda; color:#155724; }
            .odrplt-badge--cancelled, .odrplt-badge--returned, .odrplt-badge--failed_delivery { background:#f8d7da; color:#721c24; }
            .odrplt-badge--unknown { background:#e2e3e5; color:#383d41; }
            /* Fraud risk badges */
            .odrplt-risk--low { background:#d4edda; color:#155724; }
            .odrplt-risk--medium { background:#fff3cd; color:#856404; }
            .odrplt-risk--high { background:#f8d7da; color:#721c24; }
            .odrplt-badge--risk::before { content:""; display:inline-block; width:7px; height:7px; margin-right:5px; border-radius:50%; vertical-align:middle; background:currentColor; }
            /* Column widths */
            .column-odrplt_fraud_risk { width:100px; }
            .column-odrplt_courier { width:90px; }
            .column-odrplt_delivery_status { width:110px; }
            .column-odrplt_actions { width:130px; }
            /* Courier selector modal */
            #odrplt-send-modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center; }
            #odrplt-send-modal { background:#fff;border-radius:6px;padding:24px;min-width:280px;box-shadow:0 4px 24px rgba(0,0,0,.2); }
            #odrplt-send-modal h3 { margin:0 0 16px;font-size:15px; }
            #odrplt-send-modal .odrplt-modal-actions { display:flex;gap:8px;margin-top:16px;justify-content:flex-end; }
            .odrplt-col-result { font-size:11px;margin-top:3px; }
        </style>

        <!-- OrderPilot courier-selection modal (shared for column Send buttons) -->
        <div id="odrplt-send-modal-overlay" style="display:none;">
            <div id="odrplt-send-modal">
                <h3><?php esc_html_e( 'Select Courier', 'order-pilot' ); ?></h3>
                <div id="odrplt-modal-couriers"></div>
                <div class="odrplt-modal-actions">
                    <button type="button" id="odrplt-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'order-pilot' ); ?></button>
                    <button type="button" id="odrplt-modal-confirm" class="button button-primary"><?php esc_html_e( 'Send', 'order-pilot' ); ?></button>
                </div>
                <p id="odrplt-modal-result" style="font-size:12px;margin:8px 0 0;"></p>
            </div>
        </div>

        <script>
        (function() {
            var couriers = <?php echo wp_json_encode( $couriers ); ?>;
            var pendingBtn = null;

            // ── Build courier radio list ──────────────────────────────────────
            function buildCourierList(autoCourier, autoSource) {
                var container = document.getElementById('odrplt-modal-couriers');
                container.innerHTML = '';
                couriers.forEach(function(c) {
                    var label = document.createElement('label');
                    label.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;font-size:13px;cursor:pointer;padding:8px 12px;border-radius:6px;border:1px solid #dcdcde;background:#fff;';

                    var isSelected = (autoCourier && c.slug === autoCourier) || (!autoCourier && couriers.length === 1);
                    if (isSelected) {
                        label.style.borderColor = '#2271b1';
                        label.style.backgroundColor = '#f0f6fc';
                    }

                    var badgeHtml = '';
                    if (autoCourier && c.slug === autoCourier) {
                        if (autoSource === 'rule') {
                            badgeHtml = '<span style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:600;padding:2px 8px;border-radius:12px;margin-left:8px;white-space:nowrap;">⚡ Recommended</span>';
                        } else if (autoSource === 'default') {
                            badgeHtml = '<span style="background:#f1f5f9;color:#475569;font-size:11px;font-weight:600;padding:2px 8px;border-radius:12px;margin-left:8px;white-space:nowrap;">Default</span>';
                        }
                    }

                    label.innerHTML = '<span style="display:flex;align-items:center;"><input type="radio" name="odrplt_modal_courier" value="' + c.slug + '" ' + (isSelected ? 'checked' : '') + ' style="margin-right:8px;"> <strong>' + c.name + '</strong></span>' + badgeHtml;

                    label.addEventListener('click', function() {
                        container.querySelectorAll('label').forEach(function(l) {
                            l.style.borderColor = '#dcdcde';
                            l.style.backgroundColor = '#fff';
                        });
                        label.style.borderColor = '#2271b1';
                        label.style.backgroundColor = '#f0f6fc';
                    });

                    container.appendChild(label);
                });
            }

            // ── Open modal ────────────────────────────────────────────────────
            function openModal(btn) {
                pendingBtn = btn;
                document.getElementById('odrplt-modal-result').textContent = '';
                var autoCourier = btn.dataset.autoCourier || '';
                var autoSource  = btn.dataset.autoSource || '';
                buildCourierList(autoCourier, autoSource);
                document.getElementById('odrplt-send-modal-overlay').style.display = 'flex';
            }

            // ── Close modal ───────────────────────────────────────────────────
            function closeModal() {
                document.getElementById('odrplt-send-modal-overlay').style.display = 'none';
                pendingBtn = null;
            }

            document.getElementById('odrplt-modal-cancel').addEventListener('click', closeModal);
            document.getElementById('odrplt-send-modal-overlay').addEventListener('click', function(e) {
                if (e.target === this) closeModal();
            });

            // ── REST helper ───────────────────────────────────────────────────
            function restPost(url, nonce, data, cb) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', url);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', nonce);
                xhr.onload = function() {
                    try { cb(null, JSON.parse(xhr.responseText)); }
                    catch(e) { cb('Parse error'); }
                };
                xhr.onerror = function() { cb('<?php echo esc_js( __( 'Network error', 'order-pilot' ) ); ?>'); };
                xhr.send(JSON.stringify(data));
            }

            // ── Send button handler (column) ──────────────────────────────────
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.odrplt-col-send-btn');
                if (!btn) return;

                if (couriers.length === 0) {
                    alert('<?php echo esc_js( __( 'No courier connected. Please connect a courier in Settings.', 'order-pilot' ) ); ?>');
                    return;
                }

                openModal(btn);
            });

            // ── Modal confirm ─────────────────────────────────────────────────
            document.getElementById('odrplt-modal-confirm').addEventListener('click', function() {
                if (!pendingBtn) return;

                var selected = document.querySelector('input[name="odrplt_modal_courier"]:checked');
                if (!selected) {
                    document.getElementById('odrplt-modal-result').textContent = '<?php echo esc_js( __( 'Please select a courier.', 'order-pilot' ) ); ?>';
                    return;
                }

                var btn       = pendingBtn;
                var orderId   = btn.dataset.orderId;
                var restUrl   = btn.dataset.restUrl;
                var restNonce = btn.dataset.restNonce;
                var slug      = selected.value;
                var resultEl  = document.getElementById('odrplt-modal-result');

                document.getElementById('odrplt-modal-confirm').disabled = true;
                resultEl.textContent = '<?php echo esc_js( __( 'Sending…', 'order-pilot' ) ); ?>';

                restPost(
                    restUrl + 'orders/' + orderId + '/send',
                    restNonce,
                    { courier_slug: slug },
                    function(err, body) {
                        document.getElementById('odrplt-modal-confirm').disabled = false;
                        if (err || (body && body.data && body.data.status >= 400)) {
                            resultEl.style.color = '#cc0000';
                            resultEl.textContent = (body && body.message) || err || '<?php echo esc_js( __( 'Failed.', 'order-pilot' ) ); ?>';
                        } else {
                            resultEl.style.color = '#00a32a';
                            resultEl.textContent = '✓ <?php echo esc_js( __( 'Sent!', 'order-pilot' ) ); ?>';
                            setTimeout(function() { closeModal(); location.reload(); }, 1000);
                        }
                    }
                );
            });

            // ── Sync button handler (column) ──────────────────────────────────
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.odrplt-col-sync-btn');
                if (!btn) return;

                btn.disabled = true;
                var original = btn.textContent;
                btn.textContent = '⏳';

                var xhr = new XMLHttpRequest();
                xhr.open('POST', btn.dataset.restUrl + 'orders/' + btn.dataset.orderId + '/sync-status');
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', btn.dataset.restNonce);
                xhr.onload = function() {
                    btn.disabled = false;
                    btn.textContent = original;
                    var body = JSON.parse(xhr.responseText || '{}');
                    if (body.data && body.data.status) {
                        // Briefly show result then reload to refresh the row.
                        setTimeout(function() { location.reload(); }, 800);
                    }
                };
                xhr.onerror = function() {
                    btn.disabled = false;
                    btn.textContent = original;
                };
                xhr.send();
            });
        })();
        </script>
        <?php
    }

    // ─── Bulk Actions ─────────────────────────────────────────────────────────

    /**
     * Register OrderPilot bulk actions in the WooCommerce order list.
     *
     * Hooked to:
     *   - `bulk_actions-woocommerce_page_wc-orders` (HPOS)
     *   - `bulk_actions-edit-shop_order` (legacy)
     *
     * @since 1.0.0
     * @param array $actions Existing bulk actions.
     * @return array
     */
    public function register_bulk_actions( array $actions ): array {
        $actions['odrplt_bulk_send_courier'] = __( 'Order Pilot: Send to Courier', 'order-pilot' );

        /**
         * Filter: bulk actions available in the OrderPilot section.
         * Pro adds "Check Fraud" here.
         *
         * @since 1.0.0
         * @param array $actions
         */
        return (array) apply_filters( 'order_pilot_bulk_actions', $actions );
    }

    /**
     * Handle OrderPilot bulk actions.
     *
     * Hooked to:
     *   - `handle_bulk_actions-woocommerce_page_wc-orders` (HPOS)
     *   - `handle_bulk_actions-edit-shop_order` (legacy)
     *
     * For "Send to Courier" bulk action, we redirect to an intermediate screen
     * where the user selects a courier, then process all orders.
     *
     * @since 1.0.0
     * @param string $redirect_to Redirect URL after action.
     * @param string $action      The bulk action key.
     * @param int[]  $order_ids   Selected order IDs.
     * @return string
     */
    public function handle_bulk_action( string $redirect_to, string $action, array $order_ids ): string {
        if ( 'odrplt_bulk_send_courier' !== $action ) {
            return $redirect_to;
        }

        if ( empty( $order_ids ) ) {
            return $redirect_to;
        }

        $sent   = 0;
        $failed = 0;

        // Use the first connected courier as default (user chose it from the dropdown
        // or it was pre-selected). If multiple couriers are connected, we use whichever
        // is already assigned to each order; for unassigned orders we skip (no blind default).
        foreach ( $order_ids as $order_id ) {
            $order        = wc_get_order( (int) $order_id );
            $courier_slug = $order ? $order->get_meta( '_odrplt_courier', true ) : '';

            if ( ! $courier_slug ) {
                // Order has no assigned courier — skip in bulk mode.
                $failed++;
                continue;
            }

            $result = $this->couriers->send_order( (int) $order_id, $courier_slug );

            if ( is_wp_error( $result ) ) {
                $failed++;
            } else {
                $sent++;
                if ( $order ) {
                    $courier = $this->couriers->get( $courier_slug );
                    $order->add_order_note( sprintf(
                        /* translators: 1: courier name, 2: consignment ID */
                        __( 'Order Pilot (Bulk): Sent to %1$s. Consignment: %2$s', 'order-pilot' ),
                        $courier ? $courier->get_name() : $courier_slug,
                        $result['consignment_id'] ?? '—'
                    ) );
                }
            }
        }

        return add_query_arg(
            [
                'odrplt_bulk_sent'   => $sent,
                'odrplt_bulk_failed' => $failed,
            ],
            $redirect_to
        );
    }

    /**
     * Show admin notice after bulk action completes.
     * Hooked to `admin_notices`.
     *
     * @since 1.0.0
     */
    public function show_bulk_notice(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_REQUEST['odrplt_bulk_sent'] ) && ! isset( $_REQUEST['odrplt_bulk_failed'] ) ) {
            return;
        }

        $sent   = isset( $_REQUEST['odrplt_bulk_sent'] ) ? absint( wp_unslash( $_REQUEST['odrplt_bulk_sent'] ) ) : 0;
        $failed = isset( $_REQUEST['odrplt_bulk_failed'] ) ? absint( wp_unslash( $_REQUEST['odrplt_bulk_failed'] ) ) : 0;
        // phpcs:enable

        if ( $sent > 0 ) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                esc_html( sprintf(
                    /* translators: %d: number of orders sent */
                    _n(
                        'Order Pilot: %d order sent to courier successfully.',
                        'Order Pilot: %d orders sent to courier successfully.',
                        $sent,
                        'order-pilot'
                    ),
                    $sent
                ) ) .
                '</p></div>';
        }

        if ( $failed > 0 ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                esc_html( sprintf(
                    /* translators: %d: number of orders that failed */
                    _n(
                        'Order Pilot: %d order could not be sent (no courier assigned or API error). Check the Logs page.',
                        'Order Pilot: %d orders could not be sent (no courier assigned or API error). Check the Logs page.',
                        $failed,
                        'order-pilot'
                    ),
                    $failed
                ) ) .
                '</p></div>';
        }
    }

    // ─── Order Status Change ──────────────────────────────────────────────────

    /**
     * React to WooCommerce order status changes.
     *
     * @since 1.0.0
     * @param int       $order_id
     * @param string    $old_status
     * @param string    $new_status
     * @param \WC_Order $order
     */
    public function on_order_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
        /**
         * Fires when a WooCommerce order status changes.
         *
         * Pro hooks here to:
         * - Trigger courier status sync
         * - Fire the Purchase pixel event when order is 'completed' or 'delivered'
         *
         * @since 1.0.0
         * @param int       $order_id
         * @param string    $old_status
         * @param string    $new_status
         * @param \WC_Order $order
         */
        do_action( 'order_pilot_order_status_changed', $order_id, $old_status, $new_status, $order );
    }

    // ─── Order Data Extraction ────────────────────────────────────────────────

    /**
     * Extract a standardized order data array from a WooCommerce order.
     * Used by tracking, courier adapters, and fraud checks.
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @return array
     */
    public static function extract_order_data( \WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product  = $item->get_product();
            $items[]  = [
                'id'       => $item->get_product_id(),
                'name'     => $item->get_name(),
                'price'    => $product ? (float) $product->get_price() : 0.0,
                'quantity' => $item->get_quantity(),
                'category' => $product ? implode( ', ', wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ) ) : '',
            ];
        }

        return [
            'order_id'       => $order->get_id(),
            'order_number'   => $order->get_order_number(),
            'total'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
            'status'         => $order->get_status(),
            'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'customer_phone' => $order->get_billing_phone(),
            'customer_email' => $order->get_billing_email(),
            'address'        => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() )
                                ?: $order->get_billing_address_1(),
            'city'           => $order->get_shipping_city() ?: $order->get_billing_city(),
            'items'          => $items,
            'item_count'     => count( $items ),
            'content_ids'    => array_map( 'strval', array_column( $items, 'id' ) ),
            'customer_note'  => $order->get_customer_note(),
            'date_created'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Normalize an order ID from either HPOS (WC_Order object) or legacy (post ID).
     *
     * @since 1.0.0
     * @param mixed $order_or_id
     * @return int
     */
    private function normalize_order_id( $order_or_id ): int {
        if ( $order_or_id instanceof \WC_Order ) {
            return $order_or_id->get_id();
        }
        if ( is_object( $order_or_id ) ) {
            return (int) ( $order_or_id->ID ?? $order_or_id->get_id() );
        }
        return (int) $order_or_id;
    }

    /**
     * Get a human-readable label for a normalized courier status.
     *
     * @since 1.0.0
     * @param string $status
     * @return string
     */
    private function status_label( string $status ): string {
        $labels = [
            ODRPLT_Courier_Manager::STATUS_PENDING         => __( 'Pending', 'order-pilot' ),
            ODRPLT_Courier_Manager::STATUS_PICKED_UP       => __( 'Picked Up', 'order-pilot' ),
            ODRPLT_Courier_Manager::STATUS_IN_TRANSIT      => __( 'In Transit', 'order-pilot' ),
            ODRPLT_Courier_Manager::STATUS_DELIVERED       => __( 'Delivered', 'order-pilot' ),
            ODRPLT_Courier_Manager::STATUS_CANCELLED       => __( 'Cancelled', 'order-pilot' ),
            ODRPLT_Courier_Manager::STATUS_RETURNED        => __( 'Returned', 'order-pilot' ),
            ODRPLT_Courier_Manager::STATUS_FAILED_DELIVERY => __( 'Failed', 'order-pilot' ),
            ODRPLT_Courier_Manager::STATUS_UNKNOWN         => __( 'Unknown', 'order-pilot' ),
        ];

        return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
    }
}
