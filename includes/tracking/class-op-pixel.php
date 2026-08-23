<?php
/**
 * Meta Pixel (Browser-Side)
 *
 * Injects the Facebook/Meta Pixel base code and fires
 * standard WooCommerce events (PageView, ViewContent, AddToCart,
 * InitiateCheckout, Purchase) on the appropriate pages.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Pixel
 */
class OP_Pixel {

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

    /**
     * Whether the pixel script has already been injected.
     *
     * @var bool
     */
    private bool $injected = false;

    /**
     * Constructor.
     *
     * @param OP_Settings $settings
     */
    public function __construct( OP_Settings $settings ) {
        $this->settings = $settings;
    }

    // ─── Script Injection ─────────────────────────────────────────────────────

    /**
     * Inject the Meta Pixel base code into <head>.
     * Hooked to `wp_head` at priority 1.
     *
     * @since 1.0.0
     */
    public function inject_pixel_script(): void {
        $pixel_id = $this->settings->get_pixel_id();

        if ( ! $pixel_id || $this->injected ) {
            return;
        }

        $this->injected = true;

        echo $this->get_pixel_base_code( $pixel_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Build the pixel base code HTML.
     *
     * @since 1.0.0
     * @param string $pixel_id
     * @return string
     */
    private function get_pixel_base_code( string $pixel_id ): string {
        $pixel_id = esc_js( $pixel_id );

        return <<<HTML
<!-- Meta Pixel Code — Order Pilot -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{$pixel_id}');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id={$pixel_id}&ev=PageView&noscript=1" /></noscript>
<!-- End Meta Pixel Code -->

HTML;
    }

    // ─── Event Firing ─────────────────────────────────────────────────────────

    /**
     * Fire a pixel event by injecting inline JavaScript.
     *
     * @since 1.0.0
     * @param string $event_name Meta standard event name e.g. 'Purchase'.
     * @param array  $params     Event parameters.
     * @param string $event_id   Deduplication event ID.
     */
    public function fire_event( string $event_name, array $params = [], string $event_id = '' ): void {
        if ( ! $this->settings->get_pixel_id() ) {
            return;
        }

        /**
         * Filter: event parameters before a Pixel event fires.
         *
         * @since 1.0.0
         * @param array  $params     Event parameters.
         * @param string $event_name The event name.
         */
        $params = (array) apply_filters( 'order_pilot_pixel_event_data', $params, $event_name );

        $params_json   = wp_json_encode( $params, JSON_UNESCAPED_UNICODE );
        $event_id_str  = $event_id ? ', { eventID: ' . wp_json_encode( $event_id ) . ' }' : '';
        $event_name_js = esc_js( $event_name );

        echo "<script>if(typeof fbq !== 'undefined'){ fbq('track', '{$event_name_js}', {$params_json}{$event_id_str}); }</script>\n"; // phpcs:ignore

        /**
         * Fires after a Meta Pixel browser event is fired.
         *
         * @since 1.0.0
         * @param string $event_name
         * @param array  $params
         */
        do_action( 'order_pilot_pixel_event_fired', $event_name, $params );
    }

    /**
     * Fire the ViewContent event for a product.
     *
     * @since 1.0.0
     * @param \WC_Product $product
     */
    public function fire_view_content( \WC_Product $product ): void {
        if ( ! $this->settings->is_pixel_event_enabled( 'view_content' ) ) {
            return;
        }

        $this->fire_event( 'ViewContent', [
            'content_ids'  => [ $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
        ] );
    }

    /**
     * Fire the AddToCart event.
     *
     * @since 1.0.0
     * @param \WC_Product $product
     * @param int         $quantity
     */
    public function fire_add_to_cart( \WC_Product $product, int $quantity = 1 ): void {
        if ( ! $this->settings->is_pixel_event_enabled( 'add_to_cart' ) ) {
            return;
        }

        $this->fire_event( 'AddToCart', [
            'content_ids'  => [ $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'value'        => (float) $product->get_price() * $quantity,
            'currency'     => get_woocommerce_currency(),
        ] );
    }

    /**
     * Fire the InitiateCheckout event.
     *
     * @since 1.0.0
     */
    public function fire_initiate_checkout(): void {
        if ( ! $this->settings->is_pixel_event_enabled( 'initiate_checkout' ) || ! WC()->cart ) {
            return;
        }

        $cart      = WC()->cart;
        $item_ids  = [];
        $num_items = 0;

        foreach ( $cart->get_cart() as $item ) {
            $item_ids[] = $item['product_id'];
            $num_items += $item['quantity'];
        }

        $this->fire_event( 'InitiateCheckout', [
            'content_ids' => $item_ids,
            'content_type' => 'product',
            'num_items'   => $num_items,
            'value'       => (float) $cart->get_cart_contents_total(),
            'currency'    => get_woocommerce_currency(),
        ] );
    }

    /**
     * Fire the Purchase event for a completed order.
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @param string    $event_id Deduplication event ID.
     */
    public function fire_purchase( \WC_Order $order, string $event_id = '' ): void {
        if ( ! $this->settings->is_pixel_event_enabled( 'purchase' ) ) {
            return;
        }

        $data = OP_WC_Integration::extract_order_data( $order );

        $this->fire_event( 'Purchase', [
            'value'        => $data['total'],
            'currency'     => $data['currency'],
            'content_ids'  => $data['content_ids'],
            'content_type' => 'product',
            'num_items'    => $data['item_count'],
            'order_id'     => $data['order_id'],
        ], $event_id );
    }
}
