<?php
/**
 * Order Actions
 *
 * Adds "Send to Courier" action to WooCommerce order detail pages
 * and handles the AJAX/action dispatch for individual orders.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Order_Actions
 */
class ODRPLT_Order_Actions {

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
     * @param ODRPLT_Courier_Manager $couriers
     * @param ODRPLT_Logger          $logger
     */
    public function __construct( ODRPLT_Courier_Manager $couriers, ODRPLT_Logger $logger ) {
        $this->couriers = $couriers;
        $this->logger   = $logger;

        // AJAX handler for the React modal's courier submission.
        add_action( 'wp_ajax_odrplt_send_to_courier', [ $this, 'ajax_send_to_courier' ] );
        add_action( 'wp_ajax_odrplt_get_couriers', [ $this, 'ajax_get_couriers' ] );
        add_action( 'wp_ajax_odrplt_get_consignment', [ $this, 'ajax_get_consignment' ] );

        // Order detail meta box (one-click send widget).
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
    }

    // ─── Meta Box ─────────────────────────────────────────────────────────────

    /**
     * Register the Order Pilot courier meta box on the WC order edit screen.
     * Supports both HPOS (woocommerce_page_wc-orders) and legacy (shop_order).
     *
     * @since 1.0.0
     */
    public function register_meta_box(): void {
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'odrplt_order_panel',
                __( '📦 Order Pilot', 'order-pilot' ),
                [ $this, 'render_courier_meta_box' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * Render the courier meta box HTML.
     * This is a lightweight PHP-rendered widget — no React needed here.
     *
     * @since 1.0.0
     * @param \WP_Post|\WC_Order $post_or_order
     */
    public function render_courier_meta_box( $post_or_order ): void {
        // Normalise to WC_Order regardless of HPOS or legacy.
        $order = $post_or_order instanceof \WC_Order
            ? $post_or_order
            : wc_get_order( $post_or_order->ID );

        if ( ! $order instanceof \WC_Order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'order-pilot' ) . '</p>';
            return;
        }

        $order_id       = $order->get_id();
        $courier_slug   = $order->get_meta( '_odrplt_courier', true );
        $consignment_id = $order->get_meta( '_odrplt_consignment_id', true );
        $tracking_id    = $order->get_meta( '_odrplt_tracking_id', true );
        $fraud_risk     = $order->get_meta( '_odrplt_fraud_risk', true );
        $fraud_score    = $order->get_meta( '_odrplt_fraud_score', true );
        $connected      = $this->couriers->get_connected();
        $rest_nonce     = wp_create_nonce( 'wp_rest' );
        $rest_url       = rest_url( 'order-pilot/v1/' );

        // Delivery status from DB.
        $delivery_status = '';
        if ( $courier_slug && $consignment_id ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query.
            $delivery_status = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}odrplt_consignments WHERE order_id = %d AND courier = %s LIMIT 1",
                $order_id,
                $courier_slug
            ) );
        }

        // Courier name.
        $courier_name = '';
        if ( $courier_slug ) {
            $courier_obj  = $this->couriers->get( $courier_slug );
            $courier_name = $courier_obj ? $courier_obj->get_name() : ucfirst( $courier_slug );
        }

        // Fraud risk display.
        $risk_icons   = [ 'low' => '🟢', 'medium' => '🟡', 'high' => '🔴' ];
        $risk_display = $fraud_risk
            ? ( ( $risk_icons[ $fraud_risk ] ?? '⚪' ) . ' ' . ucfirst( $fraud_risk ) . ( $fraud_score !== '' ? ' (' . (int) $fraud_score . '/100)' : '' ) )
            : __( '— Not Checked', 'order-pilot' );

        // Status label map.
        $status_labels = [
            'pending'        => __( 'Pending', 'order-pilot' ),
            'picked_up'      => __( 'Picked Up', 'order-pilot' ),
            'in_transit'     => __( 'In Transit', 'order-pilot' ),
            'delivered'      => __( 'Delivered', 'order-pilot' ),
            'cancelled'      => __( 'Cancelled', 'order-pilot' ),
            'returned'       => __( 'Returned', 'order-pilot' ),
            'failed_delivery' => __( 'Failed', 'order-pilot' ),
            'unknown'        => __( 'Unknown', 'order-pilot' ),
        ];
        $delivery_label = $delivery_status
            ? ( $status_labels[ $delivery_status ] ?? ucfirst( str_replace( '_', ' ', $delivery_status ) ) )
            : __( '— Not Sent', 'order-pilot' );
        ?>

        <div id="odrplt-order-panel" style="font-size:13px;">

            <!-- ─── Info table ─────────────────────────────────── -->
            <table style="width:100%;border-collapse:collapse;margin-bottom:12px;">
                <tr>
                    <td style="padding:3px 0;color:#666;width:50%;"><?php esc_html_e( 'Fraud Risk', 'order-pilot' ); ?></td>
                    <td style="padding:3px 0;font-weight:600;"><?php echo esc_html( $risk_display ); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 0;color:#666;"><?php esc_html_e( 'Courier', 'order-pilot' ); ?></td>
                    <td style="padding:3px 0;font-weight:600;"><?php echo $courier_name ? esc_html( $courier_name ) : '<span style="color:#999">—</span>'; ?></td>
                </tr>
                <?php if ( $consignment_id ) : ?>
                <tr>
                    <td style="padding:3px 0;color:#666;"><?php esc_html_e( 'Consignment', 'order-pilot' ); ?></td>
                    <td style="padding:3px 0;"><code style="font-size:11px;"><?php echo esc_html( $consignment_id ); ?></code></td>
                </tr>
                <?php endif; ?>
                <?php if ( $tracking_id && $tracking_id !== $consignment_id ) : ?>
                <tr>
                    <td style="padding:3px 0;color:#666;"><?php esc_html_e( 'Tracking ID', 'order-pilot' ); ?></td>
                    <td style="padding:3px 0;"><code style="font-size:11px;"><?php echo esc_html( $tracking_id ); ?></code></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td style="padding:3px 0;color:#666;"><?php esc_html_e( 'Delivery Status', 'order-pilot' ); ?></td>
                    <td style="padding:3px 0;font-weight:600;"><?php echo esc_html( $delivery_label ); ?></td>
                </tr>
            </table>

            <hr style="margin:0 0 10px;border-color:#eee;">

            <!-- ─── Action buttons ─────────────────────────────── -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;">

                <?php if ( $courier_slug && $consignment_id ) : ?>
                    <!-- Already sent — Sync & Re-send -->
                    <button
                        type="button"
                        class="button button-secondary odrplt-sync-btn"
                        data-order-id="<?php echo esc_attr( $order_id ); ?>"
                        data-rest-url="<?php echo esc_url( $rest_url ); ?>"
                        data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
                    >
                        🔄 <?php esc_html_e( 'Sync Status', 'order-pilot' ); ?>
                    </button>
                    <button
                        type="button"
                        class="button odrplt-resend-btn"
                        data-order-id="<?php echo esc_attr( $order_id ); ?>"
                    >
                        ↩ <?php esc_html_e( 'Re-send', 'order-pilot' ); ?>
                    </button>

                <?php elseif ( empty( $connected ) ) : ?>
                    <p style="color:#888;margin:0;">
                        <?php printf(
                            /* translators: %s: settings URL */
                            esc_html__( 'No courier connected. %s', 'order-pilot' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=order-pilot-settings&tab=couriers' ) ) . '">' . esc_html__( 'Connect one →', 'order-pilot' ) . '</a>'
                        ); ?>
                    </p>

                <?php else : ?>
                    <!-- Not yet sent — courier selector + send button -->
                    <div style="width:100%;margin-bottom:8px;">
                        <?php foreach ( $connected as $slug => $c ) : ?>
                        <label style="display:block;margin-bottom:4px;">
                            <input type="radio" name="odrplt_courier_slug" value="<?php echo esc_attr( $slug ); ?>" />
                            <?php echo esc_html( $c->get_name() ); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button
                        type="button"
                        class="button button-primary odrplt-send-btn"
                        data-order-id="<?php echo esc_attr( $order_id ); ?>"
                        data-rest-url="<?php echo esc_url( $rest_url ); ?>"
                        data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
                    >
                        🚚 <?php esc_html_e( 'Send to Courier', 'order-pilot' ); ?>
                    </button>

                <?php endif; ?>

            </div>

            <p class="odrplt-panel-result" style="margin:8px 0 0;font-size:12px;"></p>

            <?php
            /**
             * Action: fires at the bottom of the OrderPilot order detail panel.
             * Pro uses this to inject fraud checker controls.
             *
             * @since 1.0.0
             * @param int      $order_id
             * @param \WC_Order $order
             */
            do_action( 'order_pilot_order_detail_panel', $order_id, $order );
            ?>
        </div>

        <script>
        (function() {
            var panel = document.getElementById('odrplt-order-panel');
            if (!panel) return;

            function restPost(url, nonce, data, cb) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', url);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', nonce);
                xhr.onload = function() { try { cb(null, JSON.parse(xhr.responseText)); } catch(e) { cb('Parse error'); } };
                xhr.onerror = function() { cb('<?php echo esc_js( __( 'Network error', 'order-pilot' ) ); ?>');};
                xhr.send(JSON.stringify(data));
            }

            var result = panel.querySelector('.odrplt-panel-result');

            // ── Send to Courier ──────────────────────────────────────────
            var sendBtn = panel.querySelector('.odrplt-send-btn');
            if (sendBtn) {
                sendBtn.addEventListener('click', function() {
                    var slug = (panel.querySelector('input[name="odrplt_courier_slug"]:checked') || {}).value;
                    if (!slug) { alert('<?php echo esc_js( __( 'Please select a courier.', 'order-pilot' ) ); ?>'); return; }
                    sendBtn.disabled = true;
                    sendBtn.textContent = '<?php echo esc_js( __( 'Sending…', 'order-pilot' ) ); ?>';
                    restPost(
                        sendBtn.dataset.restUrl + 'orders/' + sendBtn.dataset.orderId + '/send',
                        sendBtn.dataset.restNonce,
                        { courier_slug: slug },
                        function(err, body) {
                            sendBtn.disabled = false;
                            sendBtn.textContent = '🚚 <?php echo esc_js( __( 'Send to Courier', 'order-pilot' ) ); ?>';
                            if (err || (body && body.data)) {
                                result.style.color = '#cc0000';
                                result.textContent = (body && body.message) || err || '<?php echo esc_js( __( 'Failed.', 'order-pilot' ) ); ?>';
                            } else {
                                result.style.color = '#00a32a';
                                result.textContent = '✓ <?php echo esc_js( __( 'Sent!', 'order-pilot' ) ); ?> ID: ' + (body.data && body.data.consignment_id ? body.data.consignment_id : (body.consignment_id || '—'));
                                setTimeout(function() { location.reload(); }, 1500);
                            }
                        }
                    );
                });
            }

            // ── Sync Status ──────────────────────────────────────────────
            var syncBtn = panel.querySelector('.odrplt-sync-btn');
            if (syncBtn) {
                syncBtn.addEventListener('click', function() {
                    syncBtn.disabled = true;
                    syncBtn.textContent = '⏳ <?php echo esc_js( __( 'Syncing…', 'order-pilot' ) ); ?>';
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', syncBtn.dataset.restUrl + 'orders/' + syncBtn.dataset.orderId + '/sync-status');
                    xhr.setRequestHeader('Content-Type', 'application/json');
                    xhr.setRequestHeader('X-WP-Nonce', syncBtn.dataset.restNonce);
                    xhr.onload = function() {
                        syncBtn.disabled = false;
                        syncBtn.textContent = '🔄 <?php echo esc_js( __( 'Sync Status', 'order-pilot' ) ); ?>';
                        var body = JSON.parse(xhr.responseText || '{}');
                        var status = body.data && body.data.status ? body.data.status : (body.status || '');
                        if (status) {
                            result.style.color = '#00a32a';
                            result.textContent = '✓ <?php echo esc_js( __( 'Status:', 'order-pilot' ) ); ?> ' + status;
                        } else {
                            result.style.color = '#cc0000';
                            result.textContent = (body.message || '<?php echo esc_js( __( 'Sync failed.', 'order-pilot' ) ); ?>');
                        }
                    };
                    xhr.onerror = function() {
                        syncBtn.disabled = false;
                        syncBtn.textContent = '🔄 <?php echo esc_js( __( 'Sync Status', 'order-pilot' ) ); ?>';
                        result.style.color = '#cc0000';
                        result.textContent = '<?php echo esc_js( __( 'Network error.', 'order-pilot' ) ); ?>';
                    };
                    xhr.send();
                });
            }
        })();
        </script>
        <?php
    }

    // ─── WC Order Action ─────────────────────────────────────────────────────

    /**
     * Register our custom action in the WooCommerce order actions dropdown.
     *
     * @since 1.0.0
     * @param array $actions Existing order actions.
     * @return array
     */
    public function add_order_actions( array $actions ): array {
        global $theorder;

        if ( ! $theorder instanceof \WC_Order ) {
            return $actions;
        }

        $courier_slug = $theorder->get_meta( '_odrplt_courier', true );

        if ( $courier_slug ) {
            $actions['odrplt_send_to_courier'] = __( '↩ Re-send to Courier', 'order-pilot' );
        } else {
            $actions['odrplt_send_to_courier'] = __( '🚚 Send to Courier', 'order-pilot' );
        }

        return $actions;
    }

    /**
     * Handle the WC order action (fires when the action is selected from the dropdown).
     *
     * @since 1.0.0
     * @param \WC_Order $order
     */
    public function handle_send_to_courier( \WC_Order $order ): void {
        // This action is intercepted by the React UI in the admin.
        // The actual submission goes through ajax_send_to_courier().
        // Here we just add a note so the UI knows to open the modal.
        $order->add_order_note( __( 'Order Pilot: Courier send requested. Please use the Order Pilot courier modal.', 'order-pilot' ) );
    }

    // ─── AJAX Handlers ────────────────────────────────────────────────────────

    /**
     * AJAX: Send an order to a selected courier.
     *
     * Expected POST params:
     *   nonce, order_id, courier_slug, [extra_data...]
     *
     * @since 1.0.0
     */
    public function ajax_send_to_courier(): void {
        check_ajax_referer( 'order_pilot_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'order-pilot' ) ], 403 );
        }

        $order_id     = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
        $courier_slug = sanitize_key( wp_unslash( $_POST['courier_slug'] ?? '' ) );
        $extra_data   = isset( $_POST['extra_data'] ) && is_array( $_POST['extra_data'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['extra_data'] ) )
            : [];

        if ( ! $order_id || ! $courier_slug ) {
            wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'order-pilot' ) ], 400 );
        }

        /**
         * Fires before the courier send AJAX action is processed.
         * Pro can hook here to run fraud checks first.
         *
         * @since 1.0.0
         * @param int    $order_id
         * @param string $courier_slug
         */
        do_action( 'order_pilot_before_courier_submission', $order_id, $courier_slug );

        $result = $this->couriers->send_order( $order_id, $courier_slug, $extra_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                [
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ],
                422
            );
        }

        // Add success note to the WC order.
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $courier = $this->couriers->get( $courier_slug );
            $order->add_order_note(
                sprintf(
                    /* translators: 1: courier name, 2: consignment ID */
                    __( 'Order Pilot: Order sent to %1$s. Consignment ID: %2$s', 'order-pilot' ),
                    $courier ? $courier->get_name() : $courier_slug,
                    $result['consignment_id'] ?? '—'
                )
            );
        }

        wp_send_json_success( [
            'message'        => __( 'Order sent successfully.', 'order-pilot' ),
            'consignment_id' => $result['consignment_id'] ?? '',
            'tracking_id'    => $result['tracking_id'] ?? '',
            'courier'        => $courier_slug,
            'status'         => $result['status'] ?? '',
        ] );
    }

    /**
     * AJAX: Get list of connected couriers (for the React send modal).
     *
     * @since 1.0.0
     */
    public function ajax_get_couriers(): void {
        check_ajax_referer( 'order_pilot_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [], 403 );
        }

        $connected = $this->couriers->get_connected();
        $data      = [];

        foreach ( $connected as $slug => $courier ) {
            $data[] = [
                'slug'  => $slug,
                'name'  => $courier->get_name(),
            ];
        }

        wp_send_json_success( $data );
    }

    /**
     * AJAX: Get the consignment info for a specific order.
     *
     * @since 1.0.0
     */
    public function ajax_get_consignment(): void {
        check_ajax_referer( 'order_pilot_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [], 403 );
        }

        $order_id = absint( wp_unslash( $_GET['order_id'] ?? 0 ) );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'order-pilot' ) ], 404 );
        }

        wp_send_json_success( [
            'courier'        => $order->get_meta( '_odrplt_courier', true ),
            'consignment_id' => $order->get_meta( '_odrplt_consignment_id', true ),
            'tracking_id'    => $order->get_meta( '_odrplt_tracking_id', true ),
        ] );
    }
}
