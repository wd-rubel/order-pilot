<?php
/**
 * Tracking Manager
 *
 * Orchestrates all tracking events. Fires browser Pixel events
 * and triggers action hooks (with shared event_id) for Pro CAPI.
 * All events are logged to wp_odrplt_tracking_logs.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Tracking_Manager
 */
class ODRPLT_Tracking_Manager {

    /** @var ODRPLT_Settings */
    private ODRPLT_Settings $settings;

    /** @var ODRPLT_Pixel */
    private ODRPLT_Pixel $pixel;

    /** @var ODRPLT_Logger|null */
    private ?ODRPLT_Logger $logger;

    /**
     * Whether InitiateCheckout has already fired during this request.
     * Prevents double-fire when both the classic hook and the footer fallback run.
     *
     * @var bool
     */
    private bool $initiate_checkout_fired = false;

    /**
     * Constructor.
     *
     * @param ODRPLT_Settings  $settings
     * @param ODRPLT_Pixel     $pixel
     * @param ODRPLT_Logger|null $logger
     */
    public function __construct( ODRPLT_Settings $settings, ODRPLT_Pixel $pixel, ?ODRPLT_Logger $logger = null ) {
        $this->settings = $settings;
        $this->pixel    = $pixel;
        $this->logger   = $logger;
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
        $wc_product = $product instanceof \WC_Product ? $product : wc_get_product( get_the_ID() );
        if ( ! $wc_product ) {
            return;
        }

        $event_id = 'vc_' . $wc_product->get_id() . '_' . time();

        $payload = [
            'content_ids'  => [ $wc_product->get_id() ],
            'content_name' => $wc_product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $wc_product->get_price(),
            'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
        ];

        // Browser Pixel
        $this->pixel->fire_view_content( $wc_product, $event_id );
        $this->log_pixel_event( 'ViewContent', $event_id, 0, $payload );

        /**
         * Fires after ViewContent is tracked (browser side).
         * Pro CAPI hooks here to send the server-side event.
         *
         * @since 1.0.0
         * @param \WC_Product $wc_product
         * @param string      $event_id Shared event ID for deduplication.
         */
        do_action( 'order_pilot_track_view_content', $wc_product, $event_id );
    }

    /**
     * Track AddToCart.
     * Hooked to `woocommerce_add_to_cart`.
     *
     * @since 1.0.0
     */
    public function track_add_to_cart(
        string $cart_item_key,
        int    $product_id,
        int    $quantity,
        int    $variation_id,
        array  $variation,
        array  $cart_item_data
    ): void {
        $actual_id = $variation_id ?: $product_id;
        $product   = wc_get_product( $actual_id );
        if ( ! $product ) return;

        $event_id = 'atc_' . $product_id . '_' . time();

        $payload = [
            'content_ids'  => [ $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price() * $quantity,
            'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
        ];

        // Browser Pixel
        $this->pixel->fire_add_to_cart( $product, $quantity, $event_id );
        $this->log_pixel_event( 'AddToCart', $event_id, 0, $payload );

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
        // Fire only once per request (classic hook + footer fallback can both reach here).
        if ( $this->initiate_checkout_fired ) {
            return;
        }
        $this->initiate_checkout_fired = true;

        $event_id = 'ic_' . time();

        $cart      = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
        $item_ids  = [];
        $num_items = 0;

        if ( $cart ) {
            foreach ( $cart->get_cart() as $item ) {
                $item_ids[] = $item['product_id'];
                $num_items += $item['quantity'];
            }
        }

        $payload = [
            'content_ids'  => $item_ids,
            'content_type' => 'product',
            'num_items'    => $num_items,
            'value'        => $cart ? (float) $cart->get_cart_contents_total() : 0.0,
            'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
        ];

        // Browser Pixel
        $this->pixel->fire_initiate_checkout( $event_id );
        $this->log_pixel_event( 'InitiateCheckout', $event_id, 0, $payload );

        /**
         * Fires after InitiateCheckout is tracked.
         *
         * @since 1.0.0
         * @param string $event_id
         */
        do_action( 'order_pilot_track_initiate_checkout', $event_id );
    }

    /**
     * Fallback InitiateCheckout trigger for checkouts that never fire
     * `woocommerce_before_checkout_form` (WooCommerce Checkout Block, FSE/block
     * themes, custom or funnel checkout templates).
     *
     * Hooked to `wp_footer` at priority 1 — before the Pixel prints its queued
     * events (priority 20), so the browser event is output on the same page.
     * No-op if the classic hook already fired.
     *
     * @since 1.0.0
     */
    public function maybe_track_initiate_checkout(): void {
        if ( $this->initiate_checkout_fired ) {
            return;
        }
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }
        // Never on the thank-you page or the pay-for-order page.
        if ( ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
            || ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) ) {
            return;
        }
        // Nothing to check out — don't report an empty InitiateCheckout.
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $this->track_initiate_checkout();
    }

    /**
     * Capture tracking cookies to order on checkout completion.
     *
     * @since 1.0.0
     * @param int|mixed $order_id
     */
    public function capture_order_cookies( $order_id = 0 ): void {
        if ( $order_id instanceof \WC_Order ) {
            $order_id = $order_id->get_id();
        }
        $order_id = is_numeric( $order_id ) ? absint( $order_id ) : 0;
        if ( ! $order_id ) {
            return;
        }

        if ( class_exists( 'ODRPLT_Cookie_Helper' ) ) {
            ODRPLT_Cookie_Helper::capture_to_order( $order_id );
        }
    }

    /**
     * Track Purchase on the thank-you page.
     * Hooked to `woocommerce_thankyou`.
     *
     * @since 1.0.0
     * @param int $order_id
     */
    public function track_purchase( $order_id = 0 ): void {
        if ( ! $order_id ) {
            global $wp;
            if ( ! empty( $wp->query_vars['order-received'] ) ) {
                $order_id = absint( $wp->query_vars['order-received'] );
            }
        }
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( class_exists( 'ODRPLT_Cookie_Helper' ) ) {
            ODRPLT_Cookie_Helper::capture_to_order( $order );
        }

        $trigger = $this->settings->get_purchase_trigger();

        // Free: only 'order_created'. Pro handles other triggers separately.
        if ( 'order_created' !== $trigger ) {
            return;
        }

        // Prevent double-fire on thank-you page refresh.
        $already_fired = $order->get_meta( '_odrplt_purchase_event_fired', true );
        if ( $already_fired ) {
            return;
        }

        $event_id = 'purchase_' . $order_id;

        $data    = ODRPLT_WC_Integration::extract_order_data( $order );
        $payload = [
            'value'        => $data['total'],
            'currency'     => $data['currency'],
            'content_ids'  => $data['content_ids'],
            'content_type' => 'product',
            'num_items'    => $data['item_count'],
            'order_id'     => $data['order_id'],
        ];

        // Browser Pixel
        $this->pixel->fire_purchase( $order, $event_id );
        $this->log_pixel_event( 'Purchase', $event_id, $order_id, $payload );

        $order->update_meta_data( '_odrplt_purchase_event_fired', '1' );
        $order->save_meta_data();

        /**
         * Fires after the Purchase event is tracked on both channels.
         *
         * @since 1.0.0
         * @param int       $order_id
         * @param \WC_Order $order
         * @param string    $event_id
         */
        do_action( 'order_pilot_track_purchase', $order_id, $order, $event_id );
    }

    // ─── Manual Event Trigger ─────────────────────────────────────────────────

    /**
     * Manually trigger a Purchase event for an order.
     * Used by Pro when the purchase trigger is 'order_delivered'.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $event_id
     */
    public function trigger_purchase_for_order( int $order_id, string $event_id = '' ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $event_id = $event_id ?: 'purchase_' . $order_id;

        $data    = ODRPLT_WC_Integration::extract_order_data( $order );
        $payload = [
            'value'        => $data['total'],
            'currency'     => $data['currency'],
            'content_ids'  => $data['content_ids'],
            'content_type' => 'product',
            'num_items'    => $data['item_count'],
            'order_id'     => $data['order_id'],
        ];

        $this->pixel->fire_purchase( $order, $event_id );
        $this->log_pixel_event( 'Purchase', $event_id, $order_id, $payload );

        do_action( 'order_pilot_track_purchase', $order_id, $order, $event_id );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Log a browser Pixel event to wp_odrplt_tracking_logs.
     *
     * @since 1.0.0
     * @param string     $event_name
     * @param string     $event_id
     * @param int        $order_id
     * @param array|null $payload Event data payload.
     */
    private function log_pixel_event( string $event_name, string $event_id, int $order_id, ?array $payload = null ): void {
        if ( $this->logger ) {
            $this->logger->pixel_event( $event_name, $event_id, $order_id ?: null, $payload );
            return;
        }
        // Fallback: write directly if no logger injected.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tracking log table.
        $wpdb->insert(
            $wpdb->prefix . 'odrplt_tracking_logs',
            [
                'order_id'   => $order_id ?: null,
                'event_name' => $event_name,
                'event_id'   => $event_id ?: null,
                'channel'    => 'pixel',
                'payload'    => isset( $payload ) ? wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) : null,
                'http_code'  => 200,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );
    }
}
