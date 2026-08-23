<?php
/**
 * RedX Courier Adapter
 *
 * API Docs: https://redx.com.bd/api-doc
 * Base URL:  https://openapi.redx.com.bd/v1.0.0-beta
 * Auth:      Static API key in header
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Courier_RedX
 */
class OP_Courier_RedX implements OP_Courier_Interface {

    /**
     * RedX API base URL.
     */
    const API_BASE = 'https://openapi.redx.com.bd/v1.0.0-beta';

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

    /**
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
        return 'redx';
    }

    /** @inheritDoc */
    public function get_name(): string {
        return __( 'RedX Courier', 'order-pilot' );
    }

    /** @inheritDoc */
    public function get_credential_fields(): array {
        return [
            'api_token' => [
                'label'    => __( 'API Token', 'order-pilot' ),
                'type'     => 'password',
                'required' => true,
            ],
        ];
    }

    /** @inheritDoc */
    public function is_configured(): bool {
        return ! empty( $this->credentials['api_token'] );
    }

    /**
     * Create a parcel in RedX.
     *
     * {@inheritDoc}
     *
     * @param \WC_Order $order
     * @param array     $extra_data Override fields: customer_name, customer_phone,
     *                              delivery_area, delivery_area_id, cash_collection_amount, instruction.
     * @return array|\WP_Error
     */
    public function create_order( \WC_Order $order, array $extra_data = [] ) {
        $payload = $this->build_order_data( $order, $extra_data );

        /**
         * Filter: RedX parcel payload before submission.
         *
         * @since 1.0.0
         * @param array     $payload
         * @param \WC_Order  $order
         * @param array     $extra_data
         */
        $payload = (array) apply_filters( 'order_pilot_courier_payload', $payload, $order, $extra_data );
        $payload = (array) apply_filters( 'order_pilot_redx_payload', $payload, $order, $extra_data );

        $response = $this->request( 'POST', '/parcel', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( empty( $body['tracking_id'] ) ) {
            return new \WP_Error(
                'redx_create_failed',
                $body['message'] ?? __( 'RedX: Failed to create parcel.', 'order-pilot' )
            );
        }

        return [
            'consignment_id' => (string) $body['tracking_id'],
            'tracking_id'    => (string) $body['tracking_id'],
            'status'         => OP_Courier_Manager::STATUS_PENDING,
            'raw'            => $body,
        ];
    }

    /**
     * Get parcel tracking status.
     *
     * {@inheritDoc}
     */
    public function get_status( string $consignment_id ) {
        $response = $this->request( 'GET', "/parcel/track/{$consignment_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // RedX returns an array of tracking events; latest is the current status.
        $events     = $body['trackings'] ?? [];
        $raw_status = ! empty( $events ) ? end( $events )['status'] ?? '' : '';
        $manager    = new OP_Courier_Manager( $this->settings, new OP_Logger( new OP_Database() ), new OP_License() );

        return [
            'status'     => $manager->normalize_status( $raw_status, $this->get_slug() ),
            'raw_status' => $raw_status,
            'raw'        => $body,
        ];
    }

    /**
     * Cancel a parcel.
     *
     * RedX supports cancellation via the parcel update endpoint.
     *
     * {@inheritDoc}
     */
    public function cancel_order( string $consignment_id ) {
        $response = $this->request(
            'PATCH',
            "/parcel/{$consignment_id}",
            [ 'status' => 'cancelled' ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        return true;
    }

    // ─── Data Builder ─────────────────────────────────────────────────────────

    /**
     * Build the RedX API payload.
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @param array     $extra_data
     * @return array
     */
    private function build_order_data( \WC_Order $order, array $extra_data = [] ): array {
        $data = [
            'customer_name'           => $extra_data['customer_name']
                                         ?? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
                                         ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_phone'          => $extra_data['customer_phone'] ?? $order->get_billing_phone(),
            'delivery_area'           => $extra_data['delivery_area']
                                         ?? trim( $order->get_shipping_city() ?: $order->get_billing_city() ),
            'delivery_area_id'        => (int) ( $extra_data['delivery_area_id'] ?? 1 ),
            'merchant_invoice_id'     => (string) $order->get_order_number(),
            'cash_collection_amount'  => (float) ( $extra_data['cod_amount'] ?? $order->get_total() ),
            'parcel_weight'           => (float) ( $extra_data['parcel_weight'] ?? 0.5 ),
            'instruction'             => $extra_data['note'] ?? $order->get_customer_note(),
            'value'                   => (float) $order->get_total(),
        ];

        /** @see order_pilot_order_data */
        return (array) apply_filters( 'order_pilot_order_data', $data, $order );
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Make an authenticated request to the RedX API.
     *
     * @since 1.0.0
     * @param string $method
     * @param string $endpoint
     * @param array  $body
     * @return array|\WP_Error
     */
    private function request( string $method, string $endpoint, array $body = [] ) {
        $url  = self::API_BASE . $endpoint;
        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'API-ACCESS-TOKEN' => 'Bearer ' . ( $this->credentials['api_token'] ?? '' ),
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
            ],
        ];

        if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) && $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        return wp_remote_request( $url, $args );
    }

    /**
     * Parse and validate a RedX API response.
     *
     * @param array $response
     * @return array|\WP_Error
     */
    private function parse_response( array $response ) {
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = $body['message'] ?? __( 'RedX API error.', 'order-pilot' );
            return new \WP_Error( 'redx_api_error', $message, [ 'status' => $code ] );
        }

        return is_array( $body ) ? $body : [];
    }
}
