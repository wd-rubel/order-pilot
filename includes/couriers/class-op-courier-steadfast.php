<?php
/**
 * Steadfast Courier Adapter
 *
 * API Docs: https://steadfast.com.bd/user/api
 * Base URL:  https://portal.steadfast.com.bd/api/v1
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Courier_Steadfast
 */
class OP_Courier_Steadfast implements OP_Courier_Interface {

    /**
     * Steadfast API base URL.
     */
    const API_BASE = 'https://portal.steadfast.com.bd/api/v1';

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

    /**
     * Cached credentials.
     *
     * @var array
     */
    private array $credentials = [];

    /**
     * Constructor.
     *
     * @param OP_Settings $settings
     */
    public function __construct( OP_Settings $settings ) {
        $this->settings    = $settings;
        $this->credentials = $settings->get_courier_credentials( $this->get_slug() );
    }

    // ─── Interface Methods ────────────────────────────────────────────────────

    /** @inheritDoc */
    public function get_slug(): string {
        return 'steadfast';
    }

    /** @inheritDoc */
    public function get_name(): string {
        return __( 'Steadfast Courier', 'order-pilot' );
    }

    /** @inheritDoc */
    public function get_credential_fields(): array {
        return [
            'api_key'    => [
                'label'    => __( 'API Key', 'order-pilot' ),
                'type'     => 'text',
                'required' => true,
            ],
            'secret_key' => [
                'label'    => __( 'Secret Key', 'order-pilot' ),
                'type'     => 'password',
                'required' => true,
            ],
        ];
    }

    /** @inheritDoc */
    public function is_configured(): bool {
        return ! empty( $this->credentials['api_key'] )
            && ! empty( $this->credentials['secret_key'] );
    }

    /**
     * Create a consignment order in Steadfast.
     *
     * {@inheritDoc}
     *
     * @param \WC_Order $order
     * @param array     $extra_data Override fields: recipient_name, recipient_phone,
     *                              recipient_address, cod_amount, note.
     * @return array|\WP_Error
     */
    public function create_order( \WC_Order $order, array $extra_data = [] ) {
        $order_data = $this->build_order_data( $order, $extra_data );

        /**
         * Filter: Steadfast order payload before it is sent.
         *
         * @since 1.0.0
         * @param array     $order_data Request payload.
         * @param \WC_Order  $order      WooCommerce order.
         * @param array     $extra_data Additional data.
         */
        $order_data = (array) apply_filters( 'order_pilot_courier_payload', $order_data, $order, $extra_data );
        $order_data = (array) apply_filters( 'order_pilot_steadfast_payload', $order_data, $order, $extra_data );

        $response = $this->request( 'POST', '/create_order', $order_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Steadfast returns status 200 with consignment data on success.
        if ( empty( $body['consignment']['tracking_code'] ) ) {
            return new \WP_Error(
                'steadfast_create_failed',
                $body['message'] ?? __( 'Steadfast: Failed to create consignment.', 'order-pilot' )
            );
        }

        $consignment = $body['consignment'];

        return [
            'consignment_id' => (string) $consignment['tracking_code'],
            'tracking_id'    => (string) $consignment['tracking_code'],
            'status'         => OP_Courier_Manager::STATUS_PENDING,
            'raw'            => $body,
        ];
    }

    /**
     * Get consignment status by tracking code.
     *
     * {@inheritDoc}
     *
     * @param string $consignment_id Steadfast tracking code.
     * @return array|\WP_Error
     */
    public function get_status( string $consignment_id ) {
        $response = $this->request( 'GET', "/status_by_cid/{$consignment_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $raw_status = $body['delivery_status'] ?? '';
        $manager    = new OP_Courier_Manager( $this->settings, new OP_Logger( new OP_Database() ), new OP_License() );

        return [
            'status'     => $manager->normalize_status( $raw_status, $this->get_slug() ),
            'raw_status' => $raw_status,
            'raw'        => $body,
        ];
    }

    /**
     * Cancel a consignment. (Steadfast does not support API cancellation — returns unsupported.)
     *
     * {@inheritDoc}
     */
    public function cancel_order( string $consignment_id ) {
        return new \WP_Error(
            'steadfast_cancel_unsupported',
            __( 'Steadfast does not support consignment cancellation via API. Please cancel from the Steadfast portal.', 'order-pilot' )
        );
    }

    // ─── Data Builders ────────────────────────────────────────────────────────

    /**
     * Build the Steadfast API payload from a WooCommerce order.
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @param array     $extra_data
     * @return array
     */
    private function build_order_data( \WC_Order $order, array $extra_data = [] ): array {
        $items_summary = $this->get_items_summary( $order );

        $data = [
            'invoice'            => $order->get_order_number(),
            'recipient_name'     => $extra_data['recipient_name']
                                    ?? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
                                    ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'recipient_phone'    => $extra_data['recipient_phone']
                                    ?? $order->get_billing_phone(),
            'recipient_address'  => $extra_data['recipient_address']
                                    ?? trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() )
                                    ?: $order->get_billing_address_1(),
            'cod_amount'         => $extra_data['cod_amount']
                                    ?? (float) $order->get_total(),
            'note'               => $extra_data['note']
                                    ?? $order->get_customer_note(),
            'item_description'   => $items_summary,
        ];

        /**
         * Filter: the final order data before it becomes the Steadfast payload.
         *
         * @since 1.0.0
         * @param array     $data
         * @param \WC_Order  $order
         */
        return (array) apply_filters( 'order_pilot_order_data', $data, $order );
    }

    /**
     * Generate a brief summary of order line items.
     *
     * @param \WC_Order $order
     * @return string
     */
    private function get_items_summary( \WC_Order $order ): string {
        $parts = [];
        foreach ( $order->get_items() as $item ) {
            $parts[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        return implode( ', ', $parts );
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Make an authenticated request to the Steadfast API.
     *
     * @since 1.0.0
     * @param string $method  'GET' | 'POST'.
     * @param string $endpoint Relative endpoint, e.g. '/create_order'.
     * @param array  $body    Request body (for POST requests).
     * @return array|\WP_Error Raw wp_remote_*() response array.
     */
    private function request( string $method, string $endpoint, array $body = [] ) {
        $url  = self::API_BASE . $endpoint;
        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'Api-Key'      => $this->credentials['api_key'] ?? '',
                'Secret-Key'   => $this->credentials['secret_key'] ?? '',
                'Content-Type' => 'application/json',
            ],
        ];

        if ( 'POST' === strtoupper( $method ) && $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Parse and validate an API response.
     *
     * @param array $response wp_remote_*() response.
     * @return array|\WP_Error Decoded response body, or WP_Error on HTTP failure.
     */
    private function parse_response( array $response ) {
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = $body['message'] ?? __( 'Steadfast API error.', 'order-pilot' );
            return new \WP_Error( 'steadfast_api_error', $message, [ 'status' => $code ] );
        }

        return is_array( $body ) ? $body : [];
    }
}
