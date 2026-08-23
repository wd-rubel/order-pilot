<?php
/**
 * Pathao Courier Adapter
 *
 * API Docs: https://docs.pathao.com
 * Base URL:  https://api-hermes.pathao.com
 * Auth:      OAuth2 client credentials
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Courier_Pathao
 */
class OP_Courier_Pathao implements OP_Courier_Interface {

    /**
     * Pathao API base URL.
     */
    const API_BASE = 'https://api-hermes.pathao.com';

    /**
     * Token cache option key.
     */
    const TOKEN_OPTION = 'order_pilot_pathao_token_cache';

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
        return 'pathao';
    }

    /** @inheritDoc */
    public function get_name(): string {
        return __( 'Pathao Courier', 'order-pilot' );
    }

    /** @inheritDoc */
    public function get_credential_fields(): array {
        return [
            'client_id'     => [
                'label'    => __( 'Client ID', 'order-pilot' ),
                'type'     => 'text',
                'required' => true,
            ],
            'client_secret' => [
                'label'    => __( 'Client Secret', 'order-pilot' ),
                'type'     => 'password',
                'required' => true,
            ],
            'username'      => [
                'label'    => __( 'Account Email', 'order-pilot' ),
                'type'     => 'email',
                'required' => true,
            ],
            'password'      => [
                'label'    => __( 'Account Password', 'order-pilot' ),
                'type'     => 'password',
                'required' => true,
            ],
        ];
    }

    /** @inheritDoc */
    public function is_configured(): bool {
        return ! empty( $this->credentials['client_id'] )
            && ! empty( $this->credentials['client_secret'] )
            && ! empty( $this->credentials['username'] )
            && ! empty( $this->credentials['password'] );
    }

    /**
     * Create an order in Pathao.
     *
     * {@inheritDoc}
     *
     * @param \WC_Order $order
     * @param array     $extra_data city_id, zone_id, area_id, item_type, special_instruction.
     * @return array|\WP_Error
     */
    public function create_order( \WC_Order $order, array $extra_data = [] ) {
        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $payload = $this->build_order_data( $order, $extra_data );

        /**
         * Filter: Pathao order payload before submission.
         *
         * @since 1.0.0
         * @param array     $payload
         * @param \WC_Order  $order
         * @param array     $extra_data
         */
        $payload = (array) apply_filters( 'order_pilot_courier_payload', $payload, $order, $extra_data );
        $payload = (array) apply_filters( 'order_pilot_pathao_payload', $payload, $order, $extra_data );

        $response = $this->request( 'POST', '/aladdin/api/v1/orders', $payload, $token );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( empty( $body['data']['order_id'] ) ) {
            return new \WP_Error(
                'pathao_create_failed',
                $body['message'] ?? __( 'Pathao: Failed to create order.', 'order-pilot' )
            );
        }

        $data = $body['data'];

        return [
            'consignment_id' => (string) $data['order_id'],
            'tracking_id'    => (string) ( $data['order_id'] ),
            'status'         => OP_Courier_Manager::STATUS_PENDING,
            'raw'            => $body,
        ];
    }

    /**
     * Get order status from Pathao.
     *
     * {@inheritDoc}
     */
    public function get_status( string $consignment_id ) {
        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = $this->request( 'GET', "/aladdin/api/v1/orders/{$consignment_id}/info", [], $token );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $raw_status = $body['data']['order_status'] ?? '';
        $manager    = new OP_Courier_Manager( $this->settings, new OP_Logger( new OP_Database() ), new OP_License() );

        return [
            'status'     => $manager->normalize_status( $raw_status, $this->get_slug() ),
            'raw_status' => $raw_status,
            'raw'        => $body,
        ];
    }

    /**
     * Cancel a Pathao order.
     *
     * {@inheritDoc}
     */
    public function cancel_order( string $consignment_id ) {
        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = $this->request(
            'POST',
            "/aladdin/api/v1/orders/cancel",
            [ 'order_id' => $consignment_id ],
            $token
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

    // ─── OAuth2 Token ─────────────────────────────────────────────────────────

    /**
     * Get a valid access token, using cache where possible.
     *
     * @since 1.0.0
     * @return string|\WP_Error
     */
    private function get_access_token() {
        // Check transient cache.
        $cached = get_transient( self::TOKEN_OPTION );
        if ( $cached ) {
            return $cached;
        }

        $payload = [
            'client_id'     => $this->credentials['client_id'] ?? '',
            'client_secret' => $this->credentials['client_secret'] ?? '',
            'username'      => $this->credentials['username'] ?? '',
            'password'      => $this->credentials['password'] ?? '',
            'grant_type'    => 'password',
        ];

        $response = wp_remote_post(
            self::API_BASE . '/aladdin/api/v1/issue-token',
            [
                'timeout'     => 30,
                'headers'     => [ 'Content-Type' => 'application/json' ],
                'body'        => wp_json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            return new \WP_Error(
                'pathao_auth_failed',
                $body['message'] ?? __( 'Pathao: Authentication failed.', 'order-pilot' )
            );
        }

        $token   = $body['access_token'];
        $expires = absint( $body['expires_in'] ?? 3600 ) - 60; // Subtract 60s buffer.
        set_transient( self::TOKEN_OPTION, $token, $expires );

        return $token;
    }

    // ─── Data Builder ─────────────────────────────────────────────────────────

    /**
     * Build the Pathao API payload from a WooCommerce order.
     *
     * Note: city_id, zone_id, and area_id must be supplied via $extra_data
     * because Pathao uses its own location IDs.
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @param array     $extra_data
     * @return array
     */
    private function build_order_data( \WC_Order $order, array $extra_data = [] ): array {
        $items_count = array_sum( array_map( fn( $item ) => $item->get_quantity(), $order->get_items() ) );

        $data = [
            'store_id'              => $extra_data['store_id'] ?? ( $this->credentials['store_id'] ?? 0 ),
            'merchant_order_id'     => (string) $order->get_order_number(),
            'recipient_name'        => $extra_data['recipient_name']
                                       ?? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
                                       ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'recipient_phone'       => $extra_data['recipient_phone'] ?? $order->get_billing_phone(),
            'recipient_address'     => $extra_data['recipient_address']
                                       ?? trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() )
                                       ?: $order->get_billing_address_1(),
            'recipient_city'        => (int) ( $extra_data['city_id'] ?? 1 ),
            'recipient_zone'        => (int) ( $extra_data['zone_id'] ?? 1 ),
            'recipient_area'        => (int) ( $extra_data['area_id'] ?? 0 ),
            'delivery_type'         => (int) ( $extra_data['delivery_type'] ?? 48 ),  // 48 = normal
            'item_type'             => (int) ( $extra_data['item_type'] ?? 2 ),        // 2 = parcel
            'item_quantity'         => $items_count,
            'item_weight'           => (float) ( $extra_data['item_weight'] ?? 0.5 ),
            'amount_to_collect'     => (float) ( $extra_data['cod_amount'] ?? $order->get_total() ),
            'special_instruction'   => $extra_data['note'] ?? $order->get_customer_note(),
        ];

        /** @see order_pilot_order_data */
        return (array) apply_filters( 'order_pilot_order_data', $data, $order );
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Make an authenticated request to the Pathao API.
     *
     * @since 1.0.0
     * @param string $method
     * @param string $endpoint
     * @param array  $body
     * @param string $token   Bearer access token.
     * @return array|\WP_Error
     */
    private function request( string $method, string $endpoint, array $body = [], string $token = '' ) {
        $url  = self::API_BASE . $endpoint;
        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        return wp_remote_request( $url, $args );
    }

    /**
     * Parse and validate an API response.
     *
     * @param array $response
     * @return array|\WP_Error
     */
    private function parse_response( array $response ) {
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = $body['message'] ?? __( 'Pathao API error.', 'order-pilot' );
            return new \WP_Error( 'pathao_api_error', $message, [ 'status' => $code ] );
        }

        return is_array( $body ) ? $body : [];
    }
}
