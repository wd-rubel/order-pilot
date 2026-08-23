<?php
/**
 * WooCommerce Integration
 *
 * Hooks into WooCommerce to add courier status columns
 * and handles order status changes from courier sync.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_WC_Integration
 */
class OP_WC_Integration {

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

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
     * @param OP_Settings        $settings
     * @param OP_Courier_Manager $couriers
     * @param OP_Logger          $logger
     */
    public function __construct(
        OP_Settings $settings,
        OP_Courier_Manager $couriers,
        OP_Logger $logger
    ) {
        $this->settings = $settings;
        $this->couriers = $couriers;
        $this->logger   = $logger;
    }

    // ─── Order List Columns ───────────────────────────────────────────────────

    /**
     * Add the Courier Status column to the WooCommerce orders list.
     *
     * @since 1.0.0
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_courier_column( array $columns ): array {
        // Insert after the 'order_status' column.
        $new_columns = [];
        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( 'order_status' === $key ) {
                $new_columns['op_courier_status'] = __( 'Courier', 'order-pilot' );
            }
        }
        return $new_columns;
    }

    /**
     * Render the Courier Status column cell.
     *
     * @since 1.0.0
     * @param string $column    Current column key.
     * @param mixed  $order_id  Order ID (int for HPOS, WP_Post for legacy).
     */
    public function render_courier_column( string $column, $order_id ): void {
        if ( 'op_courier_status' !== $column ) {
            return;
        }

        // Normalize to int — HPOS passes int, legacy passes WP_Post.
        $oid = is_object( $order_id ) ? $order_id->ID : (int) $order_id;

        $order          = wc_get_order( $oid );
        $courier_slug   = $order ? $order->get_meta( '_op_courier', true ) : '';
        $consignment_id = $order ? $order->get_meta( '_op_consignment_id', true ) : '';

        if ( ! $courier_slug || ! $consignment_id ) {
            echo '<span class="op-badge op-badge--none">—</span>';
            return;
        }

        // Fetch status from DB (not live API to avoid rate limits on list view).
        global $wpdb;
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}op_consignments WHERE order_id = %d AND courier = %s LIMIT 1",
            $oid,
            $courier_slug
        ) );

        $courier     = $this->couriers->get( $courier_slug );
        $courier_name = $courier ? $courier->get_name() : ucfirst( $courier_slug );
        $status       = $status ?: OP_Courier_Manager::STATUS_PENDING;

        printf(
            '<span class="op-badge op-badge--%1$s" title="%2$s">%3$s</span>',
            esc_attr( $status ),
            esc_attr( $courier_name . ' — ' . $consignment_id ),
            esc_html( $this->status_label( $status ) )
        );
    }

    // ─── Order Status Change ──────────────────────────────────────────────────

    /**
     * React to WooCommerce order status changes.
     *
     * This is the hook point where Pro can trigger courier status sync
     * and also the hook point for COD-aware Purchase event tracking.
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
         * @param string    $old_status Old status slug (without wc- prefix).
         * @param string    $new_status New status slug (without wc- prefix).
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
            'content_ids'    => array_column( $items, 'id' ),
            'customer_note'  => $order->get_customer_note(),
            'date_created'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Get a human-readable label for a normalized courier status.
     *
     * @since 1.0.0
     * @param string $status
     * @return string
     */
    private function status_label( string $status ): string {
        $labels = [
            OP_Courier_Manager::STATUS_PENDING         => __( 'Pending', 'order-pilot' ),
            OP_Courier_Manager::STATUS_PICKED_UP       => __( 'Picked Up', 'order-pilot' ),
            OP_Courier_Manager::STATUS_IN_TRANSIT      => __( 'In Transit', 'order-pilot' ),
            OP_Courier_Manager::STATUS_DELIVERED       => __( 'Delivered', 'order-pilot' ),
            OP_Courier_Manager::STATUS_CANCELLED       => __( 'Cancelled', 'order-pilot' ),
            OP_Courier_Manager::STATUS_RETURNED        => __( 'Returned', 'order-pilot' ),
            OP_Courier_Manager::STATUS_FAILED_DELIVERY => __( 'Failed', 'order-pilot' ),
            OP_Courier_Manager::STATUS_UNKNOWN         => __( 'Unknown', 'order-pilot' ),
        ];

        return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
    }
}
