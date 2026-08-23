<?php
/**
 * Tracking Manager
 *
 * Orchestrates all tracking events (Pixel browser-side and future CAPI server-side).
 * This class is the single point of entry for firing any tracking event.
 * Pro CAPI hooks into the actions fired here.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Tracking_Manager
 */
class OP_Tracking_Manager {

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

    /**
     * @var OP_Pixel
     */
    private OP_Pixel $pixel;

    /**
     * Constructor.
     *
     * @param OP_Settings $settings
     * @param OP_Pixel    $pixel
     */
    public function __construct( OP_Settings $settings, OP_Pixel $pixel ) {
        $this->settings = $settings;
        $this->pixel    = $pixel;
    }

    // ─── WooCommerce Event Handlers ───────────────────────────────────────────

    /**
     * Track ViewContent on single product pages.
     * Hooked to `woocommerce_after_single_product`.
     *
     * @since 1.0.0
     */
    public function track_view_content(): void {
        global $product;

        if ( ! $product instanceof \WC_Product ) {
            $product = wc_get_product( get_the_ID() );
        }

        if ( ! $product ) {
            return;
        }

        $event_id = 'vc_' . $product->get_id() . '_' . time();

        $this->pixel->fire_view_content( $product );

        /**
         * Fires after a ViewContent event is tracked (browser side).
         * Pro CAPI hooks here to send the server-side event.
         *
         * @since 1.0.0
         * @param \WC_Product $product
         * @param string      $event_id Shared event ID for deduplication.
         */
        do_action( 'order_pilot_track_view_content', $product, $event_id );
    }

    /**
     * Track AddToCart.
     * Hooked to `woocommerce_add_to_cart`.
     *
     * @since 1.0.0
     * @param string $cart_item_key
     * @param int    $product_id
     * @param int    $quantity
     * @param int    $variation_id
     * @param array  $variation
     * @param array  $cart_item_data
     */
    public function track_add_to_cart(
        string $cart_item_key,
        int $product_id,
        int $quantity,
        int $variation_id,
        array $variation,
        array $cart_item_data
    ): void {
        $actual_id = $variation_id ?: $product_id;
        $product   = wc_get_product( $actual_id );

        if ( ! $product ) {
            return;
        }

        $event_id = 'atc_' . $product_id . '_' . time();

        $this->pixel->fire_add_to_cart( $product, $quantity );

        /**
         * Fires after AddToCart is tracked.
         *
         * @since 1.0.0
         * @param \WC_Product $product
         * @param int         $quantity
         * @param string      $event_id
         */
        do_action( 'order_pilot_track_add_to_cart', $product, $quantity, $event_id );
    }

    /**
     * Track InitiateCheckout on the checkout page.
     * Hooked to `woocommerce_before_checkout_form`.
     *
     * @since 1.0.0
     */
    public function track_initiate_checkout(): void {
        $event_id = 'ic_' . time();

        $this->pixel->fire_initiate_checkout();

        /**
         * Fires after InitiateCheckout is tracked.
         *
         * @since 1.0.0
         * @param string $event_id
         */
        do_action( 'order_pilot_track_initiate_checkout', $event_id );
    }

    /**
     * Track Purchase on the thank-you page.
     * Hooked to `woocommerce_thankyou`.
     *
     * Respects the `purchase_trigger` setting:
     * - 'order_created'     → fire immediately (default for free).
     * - 'payment_completed' → fire on woocommerce_payment_complete (Pro).
     * - 'order_delivered'   → fire when status becomes delivered (Pro).
     *
     * @since 1.0.0
     * @param int $order_id
     */
    public function track_purchase( int $order_id ): void {
        $trigger = $this->settings->get_purchase_trigger();

        // Free version only handles 'order_created'.
        // Other triggers are handled by the Pro plugin hooking into order_pilot_order_status_changed.
        if ( 'order_created' !== $trigger ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Avoid duplicate fires on the thank-you page refresh.
        if ( $order->get_meta( '_op_purchase_event_fired', true ) ) {
            return;
        }

        $event_id = 'purchase_' . $order_id;

        $this->pixel->fire_purchase( $order, $event_id );

        $order->update_meta_data( '_op_purchase_event_fired', '1' );
        $order->save_meta_data();

        /**
         * Fires after the Purchase event is tracked.
         *
         * @since 1.0.0
         * @param int       $order_id
         * @param \WC_Order $order
         * @param string    $event_id Shared event ID for deduplication.
         */
        do_action( 'order_pilot_track_purchase', $order_id, $order, $event_id );
    }

    // ─── Manual Event Trigger ─────────────────────────────────────────────────

    /**
     * Manually trigger a purchase event for an order.
     * Used by Pro when the purchase trigger is 'order_delivered'.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $event_id Custom event ID (for deduplication).
     */
    public function trigger_purchase_for_order( int $order_id, string $event_id = '' ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        $event_id = $event_id ?: 'purchase_' . $order_id;

        $this->pixel->fire_purchase( $order, $event_id );

        /** @see order_pilot_track_purchase */
        do_action( 'order_pilot_track_purchase', $order_id, $order, $event_id );
    }
}
