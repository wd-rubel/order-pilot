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
 * Class OP_Order_Actions
 */
class OP_Order_Actions {

    /**
     * @var OP_Courier_Manager
     */
    private OP_Courier_Manager $couriers;

    /**
     * @var OP_Logger
     */
    private OP_Logger $logger;

    /**
     * Constructor.
     *
     * @param OP_Courier_Manager $couriers
     * @param OP_Logger          $logger
     */
    public function __construct( OP_Courier_Manager $couriers, OP_Logger $logger ) {
        $this->couriers = $couriers;
        $this->logger   = $logger;

        // AJAX handler for the React modal's courier submission.
        add_action( 'wp_ajax_op_send_to_courier', [ $this, 'ajax_send_to_courier' ] );
        add_action( 'wp_ajax_op_get_couriers', [ $this, 'ajax_get_couriers' ] );
        add_action( 'wp_ajax_op_get_consignment', [ $this, 'ajax_get_consignment' ] );
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

        $courier_slug = $theorder->get_meta( '_op_courier', true );

        if ( $courier_slug ) {
            $actions['op_send_to_courier'] = __( '↩ Re-send to Courier', 'order-pilot' );
        } else {
            $actions['op_send_to_courier'] = __( '🚚 Send to Courier', 'order-pilot' );
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

        $order_id     = absint( $_POST['order_id'] ?? 0 );
        $courier_slug = sanitize_key( $_POST['courier_slug'] ?? '' );
        $extra_data   = isset( $_POST['extra_data'] ) && is_array( $_POST['extra_data'] )
            ? array_map( 'sanitize_text_field', $_POST['extra_data'] )
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

        $order_id = absint( $_GET['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'order-pilot' ) ], 404 );
        }

        wp_send_json_success( [
            'courier'        => $order->get_meta( '_op_courier', true ),
            'consignment_id' => $order->get_meta( '_op_consignment_id', true ),
            'tracking_id'    => $order->get_meta( '_op_tracking_id', true ),
        ] );
    }
}
