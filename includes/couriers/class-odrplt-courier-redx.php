<?php
/**
 * RedX Courier Adapter
 *
 * API Docs: https://redx.com.bd/developer-api/
 * Live Base URL:    https://openapi.redx.com.bd/v1.0.0-beta
 * Sandbox Base URL: https://sandbox.redx.com.bd/v1.0.0-beta
 * Auth:             Bearer token in API-ACCESS-TOKEN header
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Courier_RedX
 */
class ODRPLT_Courier_RedX implements ODRPLT_Courier_Interface {

    /**
     * RedX live API base URL.
     */
    const API_BASE = 'https://openapi.redx.com.bd/v1.0.0-beta';

    /**
     * RedX sandbox API base URL.
     */
    const API_BASE_SANDBOX = 'https://sandbox.redx.com.bd/v1.0.0-beta';

    /**
     * @var ODRPLT_Settings
     */
    private ODRPLT_Settings $settings;

    /**
     * @var array
     */
    private array $credentials = [];

    /**
     * Constructor.
     *
     * @param ODRPLT_Settings $settings
     */
    public function __construct( ODRPLT_Settings $settings ) {
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

    /**
     * Get API base URL depending on environment setting.
     *
     * @return string
     */
    private function get_api_base(): string {
        $env  = $this->credentials['environment'] ?? 'live';
        $base = 'sandbox' === $env ? self::API_BASE_SANDBOX : self::API_BASE;
        return (string) apply_filters( 'order_pilot_redx_api_base', $base, $env );
    }

    /** @inheritDoc */
    public function get_credential_fields(): array {
        return [
            'environment' => [
                'label'    => __( 'Environment', 'order-pilot' ),
                'type'     => 'select',
                'options'  => [
                    'live'    => __( 'Live (Production)', 'order-pilot' ),
                    'sandbox' => __( 'Sandbox (Testing)', 'order-pilot' ),
                ],
                'default'  => 'live',
                'required' => true,
                'help'     => __( 'Select Sandbox to test RedX parcel dispatch without creating live shipments.', 'order-pilot' ),
            ],
            'api_token' => [
                'label'    => __( 'API Token', 'order-pilot' ),
                'type'     => 'password',
                'required' => true,
                'help'     => __( 'Enter your API Access Token from RedX Merchant Dashboard.', 'order-pilot' ),
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
     *                              customer_address, delivery_area, delivery_area_id, cash_collection_amount, instruction.
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

        $tracking_id = (string) ( $body['tracking_id'] ?? $body['tracking_code'] ?? $body['parcel_id'] ?? '' );

        if ( empty( $tracking_id ) ) {
            return new \WP_Error(
                'redx_create_failed',
                $body['message'] ?? __( 'RedX: Failed to create parcel. No tracking ID returned.', 'order-pilot' )
            );
        }

        return [
            'consignment_id' => $tracking_id,
            'tracking_id'    => $tracking_id,
            'status'         => ODRPLT_Courier_Manager::STATUS_PENDING,
            'raw'            => $body,
        ];
    }

    /**
     * Get parcel tracking status.
     *
     * {@inheritDoc}
     *
     * @param string $consignment_id
     * @return array|\WP_Error
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
        $raw_status = ! empty( $events ) ? end( $events )['status'] ?? '' : ( $body['status'] ?? '' );

        return [
            'status'     => $this->map_status( $raw_status ),
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
     *
     * @param string $consignment_id
     * @return bool|\WP_Error
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

    /**
     * Normalize RedX parcel status strings to standard Order Pilot statuses.
     *
     * @param string $raw
     * @return string
     */
    public function map_status( string $raw ): string {
        $raw = strtolower( trim( $raw ) );

        $map = [
            'pickup_pending'     => ODRPLT_Courier_Manager::STATUS_PENDING,
            'ready-for-pickup'   => ODRPLT_Courier_Manager::STATUS_PENDING,
            'pickup-pending'     => ODRPLT_Courier_Manager::STATUS_PENDING,
            'picked'             => ODRPLT_Courier_Manager::STATUS_PICKED_UP,
            'picked_up'          => ODRPLT_Courier_Manager::STATUS_PICKED_UP,
            'in_sorting_hub'     => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            'in_transit'         => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            'out_for_delivery'   => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            'delivered'          => ODRPLT_Courier_Manager::STATUS_DELIVERED,
            'partial_delivered'  => ODRPLT_Courier_Manager::STATUS_DELIVERED,
            'cancelled'          => ODRPLT_Courier_Manager::STATUS_CANCELLED,
            'merchant_cancelled' => ODRPLT_Courier_Manager::STATUS_CANCELLED,
            'returned'           => ODRPLT_Courier_Manager::STATUS_RETURNED,
            'return_in_transit'  => ODRPLT_Courier_Manager::STATUS_RETURNED,
            'failed_delivery'    => ODRPLT_Courier_Manager::STATUS_FAILED,
            'delivery_failed'    => ODRPLT_Courier_Manager::STATUS_FAILED,
        ];

        return $map[ $raw ] ?? ODRPLT_Courier_Manager::STATUS_UNKNOWN;
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
        $phone = $extra_data['customer_phone'] ?? $order->get_billing_phone();
        $clean_phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( str_starts_with( $clean_phone, '880' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        } elseif ( str_starts_with( $clean_phone, '88' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        }

        // Build full delivery address from shipping or billing fields
        $address_parts = array_filter( [
            $extra_data['customer_address'] ?? null,
            $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            $order->get_shipping_city()       ?: $order->get_billing_city(),
        ] );
        $customer_address = implode( ', ', $address_parts );

        $data = [
            'customer_name'          => $extra_data['customer_name']
                                         ?? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
                                         ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_phone'         => $clean_phone,
            'customer_address'       => $customer_address,
            'delivery_area'          => $extra_data['delivery_area']
                                         ?? trim( $order->get_shipping_city() ?: $order->get_billing_city() ),
            'delivery_area_id'       => (int) ( $extra_data['delivery_area_id'] ?? 1 ),
            'merchant_invoice_id'    => (string) $order->get_order_number(),
            'cash_collection_amount' => (float) ( $extra_data['cod_amount'] ?? $order->get_total() ),
            'parcel_weight'          => (float) ( $extra_data['parcel_weight'] ?? 0.5 ),
            'instruction'            => $extra_data['note'] ?? $order->get_customer_note(),
            'value'                  => (float) $order->get_total(),
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
        $url  = $this->get_api_base() . $endpoint;
        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'API-ACCESS-TOKEN' => 'Bearer ' . trim( $this->credentials['api_token'] ?? '' ),
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
            ],
        ];

        if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        // Physical file logging for diagnostics
        if ( class_exists( 'ODRPLT_Logger' ) ) {
            $code      = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
            $resp_body = is_wp_error( $response ) ? [ 'error' => $response->get_error_message() ] : json_decode( wp_remote_retrieve_body( $response ), true );
            ODRPLT_Logger::log_courier_call( 'redx', $endpoint, $body, $resp_body, (int) $code );
        }

        return $response;
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
            $message = is_array( $body ) && isset( $body['message'] )
                ? $body['message']
                /* translators: %d: HTTP status code. */
                : sprintf( __( 'RedX API error (HTTP %d).', 'order-pilot' ), $code );

            return new \WP_Error( 'redx_api_error', $message, [ 'status' => $code, 'body' => $body ] );
        }

        return is_array( $body ) ? $body : [];
    }
}
