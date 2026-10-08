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
 * Class ODRPLT_Pixel
 */
class ODRPLT_Pixel {

    /**
     * @var ODRPLT_Settings
     */
    private ODRPLT_Settings $settings;

    /**
     * Whether the pixel script has already been injected.
     *
     * @var bool
     */
    private bool $injected = false;

    /**
     * Standard page events collected during this request, output safely in wp_footer.
     *
     * @var array
     */
    private array $page_events = [];

    /**
     * Constructor.
     *
     * @param ODRPLT_Settings $settings
     */
    public function __construct( ODRPLT_Settings $settings ) {
        $this->settings = $settings;

        // Hook to inject queued pixel events into WooCommerce AJAX fragments.
        add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'inject_pixel_fragments' ] );
    }

    // ─── Script Injection ─────────────────────────────────────────────────────

    /**
     * Inject the Meta Pixel base code into <head>.
     * Hooked to `wp_head` at priority 1.
     *
     * @since 1.0.0
     */
    public function inject_pixel_script(): void {
        // Never output HTML during AJAX or REST requests.
        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        if ( ! $this->settings->is_pixel_enabled() ) {
            return;
        }

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

        $output  = "<!-- Meta Pixel Code — Order Pilot -->\n";
        $output .= "<script>\n";
        $output .= "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n";
        $output .= "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;\n";
        $output .= "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;\n";
        $output .= "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,\n";
        $output .= "document,'script','https://connect.facebook.net/en_US/fbevents.js');\n";
        // Persist ?fbclid= into _fbc (fb.1.{ms}.{fbclid}) — works on cached pages too.
        $output .= "(function(){try{var m=location.search.match(/[?&]fbclid=([^&#]+)/);if(!m)return;var id=decodeURIComponent(m[1]);var c=document.cookie.match(/(?:^|;\\s*)_fbc=([^;]+)/);if(c&&c[1].split('.').slice(3).join('.')===id)return;document.cookie='_fbc=fb.1.'+Date.now()+'.'+id+';path=/;max-age=7776000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'');}catch(e){}})();\n";
        $am = $this->get_advanced_matching();
        if ( ! empty( $am ) ) {
            $output .= "fbq('init', '" . $pixel_id . "', " . wp_json_encode( $am ) . ");\n";
        } else {
            $output .= "fbq('init', '" . $pixel_id . "');\n";
        }
        $output .= "fbq('track', 'PageView');\n";
        $output .= "</script>\n";
        $output .= "<noscript><img height=\"1\" width=\"1\" style=\"display:none\"\n";
        $output .= "src=\"https://www.facebook.com/tr?id=" . $pixel_id . "&ev=PageView&noscript=1\" /></noscript>\n";
        $output .= "<!-- End Meta Pixel Code -->\n";
        // NOTE: No <div> here — a <div> inside <head> is invalid HTML.
        // The AJAX dispatcher script is injected via inject_ajax_dispatcher() in wp_footer.

        return $output;
    }

    /**
     * Pixel Advanced Matching data for the order-received (thank-you) page only.
     *
     * Uses only data the customer entered at checkout, hashed with SHA-256
     * and normalized exactly like the CAPI payload so both channels match.
     * The order key in the URL is verified so another visitor can't see it.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_advanced_matching(): array {
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
            return [];
        }
        $order_id = absint( get_query_var( 'order-received' ) );
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce order key check.
        $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( ! $order instanceof \WC_Order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
            return [];
        }

        $h = static function ( string $v ): string {
            $v = strtolower( trim( $v ) );
            return '' !== $v ? hash( 'sha256', $v ) : '';
        };

        $country = (string) $order->get_billing_country();
        $phone   = (string) $order->get_billing_phone();
        if ( '' === $phone && method_exists( $order, 'get_shipping_phone' ) ) {
            $phone = (string) $order->get_shipping_phone();
        }
        $customer_id = (int) $order->get_customer_id();
        $addr        = class_exists( 'ODRPLT_Cookie_Helper' )
            ? ODRPLT_Cookie_Helper::get_order_address( $order )
            : [ 'ct' => '', 'st' => '', 'zp' => '', 'country' => strtolower( $country ) ];
        $hx = static function ( string $v ): string {
            return '' !== $v ? hash( 'sha256', $v ) : '';
        };

        return array_filter( [
            'em'          => $h( (string) $order->get_billing_email() ),
            'ph'          => class_exists( 'ODRPLT_Cookie_Helper' ) ? ODRPLT_Cookie_Helper::hash_phone( $phone, strtoupper( $addr['country'] ) ) : '',
            'fn'          => $h( (string) $order->get_billing_first_name() ),
            'ln'          => $h( (string) $order->get_billing_last_name() ),
            'ct'          => $hx( $addr['ct'] ),
            'st'          => $hx( $addr['st'] ),
            'zp'          => $hx( $addr['zp'] ),
            'country'     => $hx( $addr['country'] ),
            'external_id' => hash( 'sha256', (string) ( $customer_id > 0 ? $customer_id : $order->get_id() ) ),
        ] );
    }

    // ─── Event Firing ─────────────────────────────────────────────────────────

    /**
     * Fire a pixel event.
     * Collects for wp_footer output, or queues for AJAX requests.
     *
     * @since 1.0.0
     * @param string $event_name Meta standard event name e.g. 'Purchase'.
     * @param array  $params     Event parameters.
     * @param string $event_id   Deduplication event ID.
     */
    public function fire_event( string $event_name, array $params = [], string $event_id = '' ): void {
        if ( ! $this->settings->is_pixel_enabled() || ! $this->settings->get_pixel_id() ) {
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

        // If AJAX/REST, queue the event in the WC session to inject via fragments or next load.
        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            if ( function_exists( 'WC' ) && WC()->session ) {
                $queued   = (array) WC()->session->get( 'odrplt_queued_pixel_events', [] );
                $queued[] = [
                    'event_name' => $event_name,
                    'params'     => $params,
                    'event_id'   => $event_id,
                ];
                WC()->session->set( 'odrplt_queued_pixel_events', $queued );
            }
            return;
        }

        // Store for output in wp_footer.
        $this->page_events[] = [
            'event_name' => $event_name,
            'params'     => $params,
            'event_id'   => $event_id,
        ];

        /**
         * Fires after a Meta Pixel browser event is processed.
         *
         * @since 1.0.0
         * @param string $event_name
         * @param array  $params
         */
        do_action( 'order_pilot_pixel_event_fired', $event_name, $params );
    }

    /**
     * Inject the AJAX dispatcher script in wp_footer (priority 5 — before print_page_events).
     *
     * Uses jQuery ajaxComplete to intercept WooCommerce cart AJAX responses and
     * reads the `odrplt_pixel_events` JSON key we inject via inject_pixel_fragments().
     * Fires fbq('track',...) for each event in the live page context where
     * fbevents.js is already loaded — completely reliable, no timing issues.
     *
     * NOTE: jQuery.replaceWith() does NOT execute <script> tags in swapped HTML.
     * This ajaxComplete approach is the correct solution for AJAX pixel tracking.
     *
     * @since 1.0.0
     */
    public function inject_ajax_dispatcher(): void {
        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        if ( ! $this->settings->is_pixel_enabled() || ! $this->settings->get_pixel_id() ) {
            return;
        }
        ?>
<script id="odrplt-pixel-ajax-dispatcher">
(function ($) {
    if (!$) return;
    $(document).ajaxComplete(function (event, xhr, settings) {
        /* Only handle WooCommerce ?wc-ajax= requests */
        if (!settings.url || settings.url.indexOf('wc-ajax=') === -1) return;
        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch (e) { return; }
        var events = resp && resp.odrplt_pixel_events;
        if (!events || !events.length) return;
        if (typeof fbq === 'undefined') return;
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev || !ev.event_name) continue;
            var opts = ev.event_id ? { eventID: ev.event_id } : {};
            fbq('track', ev.event_name, ev.params || {}, opts);
        }
    });
}(window.jQuery));
</script>
        <?php
    }

    /**
     * Output all collected standard events at the bottom of the page.
     * Hooked to `wp_footer` at priority 20.
     *
     * Uses plain `fbq(...)` calls — no custom stubs, no IIFE wrapper.
     * The base code in <head> already installed the official Pixel queue stub,
     * so these calls are safely queued if fbevents.js hasn't fully loaded yet
     * and are replayed automatically when the SDK arrives.
     *
     * Also merges any session-queued events not sent via AJAX (e.g. block checkout).
     *
     * @since 1.0.0
     */
    public function print_page_events(): void {
        if ( ! $this->settings->is_pixel_enabled() || ! $this->settings->get_pixel_id() ) {
            return;
        }
        // Merge session-queued events not sent via AJAX (e.g. block checkout REST).
        if ( function_exists( 'WC' ) && WC()->session ) {
            $queued = (array) WC()->session->get( 'odrplt_queued_pixel_events', [] );
            if ( ! empty( $queued ) ) {
                $this->page_events = array_merge( $this->page_events, $queued );
                WC()->session->set( 'odrplt_queued_pixel_events', [] );
            }
        }

        if ( empty( $this->page_events ) ) {
            return;
        }

        $lines = [];
        foreach ( $this->page_events as $item ) {
            $event_name = $item['event_name'] ?? '';
            if ( ! $event_name ) {
                continue;
            }
            $params_json  = wp_json_encode( $item['params'] ?? [], JSON_UNESCAPED_UNICODE );
            $event_id     = $item['event_id'] ?? '';
            $event_id_str = $event_id ? ', { eventID: ' . wp_json_encode( $event_id ) . ' }' : '';
            $lines[]      = "    fbq('track', '" . esc_js( $event_name ) . "', {$params_json}{$event_id_str});";
        }

        if ( empty( $lines ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "<script>\nif (typeof fbq !== 'undefined') {\n" . implode( "\n", $lines ) . "\n}\n</script>\n";
    }

    /**
     * Append queued pixel events to WooCommerce AJAX fragments JSON response.
     *
     * The WooCommerce fragments mechanism sends a JSON object where keys are normally
     * jQuery DOM selectors and values are HTML. jQuery does NOT execute <script> tags
     * inside .replaceWith() HTML — so putting a <script> in the fragment HTML is broken.
     *
     * Instead we inject a custom JSON key `odrplt_pixel_events` containing the raw event
     * data. The AJAX dispatcher script (inject_ajax_dispatcher, hooked to wp_footer)
     * reads this key via ajaxComplete and calls fbq() in the live page context where
     * the real Pixel SDK is already initialized.
     *
     * Hooked to `woocommerce_add_to_cart_fragments`.
     *
     * @since 1.0.0
     * @param array $fragments
     * @return array
     */
    public function inject_pixel_fragments( array $fragments ): array {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return $fragments;
        }

        $queued = (array) WC()->session->get( 'odrplt_queued_pixel_events', [] );
        if ( empty( $queued ) ) {
            return $fragments;
        }

        WC()->session->set( 'odrplt_queued_pixel_events', [] );

        $events = [];
        foreach ( $queued as $item ) {
            if ( empty( $item['event_name'] ) ) {
                continue;
            }
            $events[] = [
                'event_name' => $item['event_name'],
                'params'     => $item['params'] ?? [],
                'event_id'   => $item['event_id'] ?? '',
            ];
        }

        if ( ! empty( $events ) ) {
            // Non-selector JSON key — read by the JS dispatcher, NOT used as a DOM selector.
            $fragments['odrplt_pixel_events'] = $events;
        }

        return $fragments;
    }

    /**
     * Fire the ViewContent event for a product.
     *
     * @since 1.0.0
     * @param \WC_Product $product
     */
    public function fire_view_content( \WC_Product $product, string $event_id = '' ): void {
        if ( ! $this->settings->is_pixel_event_enabled( 'view_content' ) ) {
            return;
        }

        $price    = (float) $product->get_price();
        $currency = get_woocommerce_currency();

        $params = [
            'content_ids'  => [ (string) $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'contents'     => [
                [
                    'id'         => (string) $product->get_id(),
                    'quantity'   => 1,
                    'item_price' => $price,
                ],
            ],
            'value'        => $price,
            'currency'     => $currency,
            'num_items'    => 1,
        ];

        $category = self::get_product_category( $product );
        if ( $category ) {
            $params['content_category'] = $category;
        }

        $this->fire_event( 'ViewContent', $params, $event_id );
    }

    /**
     * Fire the AddToCart event.
     *
     * @since 1.0.0
     * @param \WC_Product $product
     * @param int         $quantity
     * @param string      $event_id
     */
    public function fire_add_to_cart( \WC_Product $product, int $quantity = 1, string $event_id = '' ): void {
        if ( ! $this->settings->is_pixel_event_enabled( 'add_to_cart' ) ) {
            return;
        }

        $price    = (float) $product->get_price();
        $currency = get_woocommerce_currency();
        $value    = $price * $quantity;

        $params = [
            'content_ids'  => [ (string) $product->get_id() ],
            'content_name' => $product->get_name(),
            'content_type' => 'product',
            'contents'     => [
                [
                    'id'         => (string) $product->get_id(),
                    'quantity'   => $quantity,
                    'item_price' => $price,
                ],
            ],
            'value'        => $value,
            'currency'     => $currency,
            'num_items'    => $quantity,
        ];

        $category = self::get_product_category( $product );
        if ( $category ) {
            $params['content_category'] = $category;
        }

        $this->fire_event( 'AddToCart', $params, $event_id );
    }

    /**
     * Fire the InitiateCheckout event.
     *
     * @since 1.0.0
     * @param string $event_id
     */
    public function fire_initiate_checkout( string $event_id = '' ): void {
        if ( ! $this->settings->is_pixel_event_enabled( 'initiate_checkout' ) || ! WC()->cart ) {
            return;
        }

        $cart      = WC()->cart;
        $currency  = get_woocommerce_currency();
        $item_ids  = [];
        $contents  = [];
        $num_items = 0;

        foreach ( $cart->get_cart() as $item ) {
            $product    = $item['data'] ?? null;
            $item_id    = (string) $item['product_id'];
            $qty        = (int) $item['quantity'];
            $item_price = $product instanceof \WC_Product ? (float) $product->get_price() : 0.0;

            $item_ids[] = $item_id;
            $num_items += $qty;
            $contents[] = [
                'id'         => $item_id,
                'quantity'   => $qty,
                'item_price' => $item_price,
            ];
        }

        $this->fire_event( 'InitiateCheckout', [
            'content_ids'  => $item_ids,
            'content_type' => 'product',
            'contents'     => $contents,
            'num_items'    => $num_items,
            'value'        => (float) $cart->get_cart_contents_total(),
            'currency'     => $currency,
        ], $event_id );
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

        $data     = ODRPLT_WC_Integration::extract_order_data( $order );
        $contents = [];

        foreach ( $data['items'] as $item ) {
            $contents[] = [
                'id'         => (string) $item['id'],
                'quantity'   => (int) $item['quantity'],
                'item_price' => (float) $item['price'],
            ];
        }

        $this->fire_event( 'Purchase', [
            'value'        => $data['total'],
            'currency'     => $data['currency'],
            'content_ids'  => $data['content_ids'],
            'content_type' => 'product',
            'contents'     => $contents,
            'num_items'    => (int) $data['item_count'],
        ], $event_id );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Get primary product category name(s) as a comma-separated string.
     *
     * @since 1.0.0
     * @param \WC_Product $product
     * @return string
     */
    private static function get_product_category( \WC_Product $product ): string {
        $terms = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        if ( is_array( $terms ) && ! empty( $terms ) ) {
            return implode( ', ', $terms );
        }
        return '';
    }
}
