<?php
/**
 * Pathao Courier Adapter
 *
 * Implements the Pathao Courier Merchant API v1.
 *
 * API Docs: https://merchant.pathao.com/courier/developer-api
 * Live Base URL:    https://api-hermes.pathao.com
 * Sandbox Base URL: https://courier-api-sandbox.pathao.com
 * Auth:             OAuth2 password grant (client credentials)
 *
 * Authentication (Bearer token for requests):
 *   POST /aladdin/api/v1/issue-token
 *
 * Endpoints used:
 *   POST /aladdin/api/v1/orders              — Create a delivery order
 *   GET  /aladdin/api/v1/orders/{id}/info    — Get order tracking & delivery status
 *   POST /aladdin/api/v1/orders/cancel       — Cancel order
 *   GET  /aladdin/api/v1/stores              — List merchant stores
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Courier_Pathao
 */
class ODRPLT_Courier_Pathao implements ODRPLT_Courier_Interface {

    /**
     * Pathao live API base URL.
     */
    const API_BASE = 'https://api-hermes.pathao.com';

    /**
     * Pathao sandbox API base URL.
     */
    const API_BASE_SANDBOX = 'https://courier-api-sandbox.pathao.com';

    /**
     * Token cache transient key prefix.
     */
    const TOKEN_OPTION = 'order_pilot_pathao_token_cache';

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
        return 'pathao';
    }

    /** @inheritDoc */
    public function get_name(): string {
        return __( 'Pathao Courier', 'order-pilot' );
    }

    /** @inheritDoc */
    public function get_credential_fields(): array {
        return [
            'environment'   => [
                'label'    => __( 'Environment', 'order-pilot' ),
                'type'     => 'select',
                'required' => true,
                'options'  => [
                    'live'    => __( 'Live (Production)', 'order-pilot' ),
                    'sandbox' => __( 'Sandbox (Testing)', 'order-pilot' ),
                ],
                'default'  => 'live',
                'help'     => __( 'Use Sandbox for testing. Switch to Live before fulfilling actual customer orders.', 'order-pilot' ),
            ],
            'client_id'     => [
                'label'       => __( 'Client ID', 'order-pilot' ),
                'type'        => 'text',
                'required'    => true,
                'placeholder' => __( 'From Pathao Developer API settings', 'order-pilot' ),
            ],
            'client_secret' => [
                'label'       => __( 'Client Secret', 'order-pilot' ),
                'type'        => 'password',
                'required'    => true,
                'placeholder' => __( 'From Pathao Developer API settings', 'order-pilot' ),
            ],
            'username'      => [
                'label'       => __( 'Pathao Account Email', 'order-pilot' ),
                'type'        => 'email',
                'required'    => true,
                'placeholder' => __( 'your-email@example.com', 'order-pilot' ),
            ],
            'password'      => [
                'label'       => __( 'Pathao Account Password', 'order-pilot' ),
                'type'        => 'password',
                'required'    => true,
                'placeholder' => __( 'Your Pathao account password', 'order-pilot' ),
            ],
            'store_id'      => [
                'label'       => __( 'Store ID (Optional)', 'order-pilot' ),
                'type'        => 'text',
                'required'    => false,
                'placeholder' => __( 'Leave blank to auto-detect your primary store', 'order-pilot' ),
                'help'        => __( 'Your pickup Store ID from Pathao. If left empty, Order Pilot automatically detects your primary store.', 'order-pilot' ),
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
     * Return the correct API base URL for the current environment.
     *
     * @since 1.0.0
     * @return string
     */
    private function get_api_base(): string {
        $env = $this->credentials['environment'] ?? 'live';
        return 'sandbox' === $env ? self::API_BASE_SANDBOX : self::API_BASE;
    }

    /**
     * Return the token transient key scoped to the current environment.
     *
     * @since 1.0.0
     * @return string
     */
    private function get_token_key(): string {
        $env = $this->credentials['environment'] ?? 'live';
        return self::TOKEN_OPTION . '_' . $env;
    }

    // ─── Core Courier Methods ─────────────────────────────────────────────────

    /**
     * Create an order in Pathao.
     *
     * {@inheritDoc}
     *
     * @param \WC_Order $order
     * @param array     $extra_data city_id, zone_id, area_id, item_type, special_instruction, etc.
     * @return array|\WP_Error
     */
    public function create_order( \WC_Order $order, array $extra_data = [] ) {
        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $payload = $this->build_order_data( $order, $extra_data );

        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        /**
         * Filter: Pathao order payload before submission.
         *
         * @since 1.0.0
         * @param array     $payload
         * @param \WC_Order $order
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

        $data = $body['data'] ?? [];

        // Pathao API returns consignment_id or order_id
        $consignment_id = (string) ( $data['consignment_id'] ?? $data['order_id'] ?? '' );

        if ( empty( $consignment_id ) ) {
            return new \WP_Error(
                'pathao_create_failed',
                $body['message'] ?? __( 'Pathao: Failed to create order. No consignment ID returned.', 'order-pilot' )
            );
        }

        $tracking_id = (string) ( $data['tracking_code'] ?? $consignment_id );

        return [
            'consignment_id' => $consignment_id,
            'tracking_id'    => $tracking_id,
            'status'         => ODRPLT_Courier_Manager::STATUS_PENDING,
            'raw'            => $body,
        ];
    }

    /**
     * Get order status from Pathao.
     *
     * {@inheritDoc}
     *
     * Endpoint: GET /aladdin/api/v1/orders/{consignment_id}/info
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

        $data       = $body['data'] ?? [];
        $raw_status = $data['order_status'] ?? $data['status'] ?? '';

        return [
            'status'     => $this->map_delivery_status( $raw_status ),
            'raw_status' => (string) $raw_status,
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
            '/aladdin/api/v1/orders/cancel',
            [ 'consignment_id' => $consignment_id, 'order_id' => $consignment_id ],
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

    /**
     * Fetch merchant stores from Pathao.
     *
     * Endpoint: GET /aladdin/api/v1/stores
     *
     * @since 1.0.0
     * @return array|\WP_Error
     */
    public function get_stores() {
        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = $this->request( 'GET', '/aladdin/api/v1/stores', [], $token );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_response( $response );
    }

    /**
     * Get the store ID to use for order creation.
     *
     * Uses the configured store_id if set; otherwise automatically queries
     * the merchant's first active store from Pathao and caches it.
     *
     * @since 1.0.0
     * @return int
     */
    private function resolve_store_id(): int {
        if ( ! empty( $this->credentials['store_id'] ) ) {
            return (int) $this->credentials['store_id'];
        }

        $env = $this->credentials['environment'] ?? 'live';
        $cache_key = 'order_pilot_pathao_store_' . $env;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached && is_numeric( $cached ) && (int) $cached > 0 ) {
            return (int) $cached;
        }

        $stores_data = $this->get_stores();

        if ( ! is_wp_error( $stores_data ) && ! empty( $stores_data['data']['data'] ) ) {
            $stores = $stores_data['data']['data'];
            if ( ! empty( $stores[0]['store_id'] ) ) {
                $store_id = (int) $stores[0]['store_id'];
                set_transient( $cache_key, $store_id, DAY_IN_SECONDS );
                return $store_id;
            }
        }

        return 1; // Fallback default
    }

    /**
     * Map a Pathao order_status string to an ODRPLT_Courier_Manager status constant.
     *
     * Pathao statuses (from docs):
     *   Pending, Pickup_Requested, Picked_Up, In_Transit, En_Route,
     *   Assigned_for_Delivery, Delivered, Partial_Delivered, Payment_Invoice,
     *   Cancelled, On_Hold, Return_In_Transit, Returned, Delivery_Failed
     *
     * @since 1.0.0
     * @param string $raw_status Raw status string from Pathao API.
     * @return string ODRPLT_Courier_Manager status constant.
     */
    public function map_delivery_status( string $raw_status ): string {
        $status_clean = strtolower( trim( str_replace( [ ' ', '-' ], '_', $raw_status ) ) );

        $map = [
            'pending'               => ODRPLT_Courier_Manager::STATUS_PENDING,
            'pickup_requested'      => ODRPLT_Courier_Manager::STATUS_PENDING,
            'picked_up'             => ODRPLT_Courier_Manager::STATUS_PICKED_UP,
            'assigned_for_delivery' => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            'in_transit'            => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            'en_route'              => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            'on_hold'               => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            'delivered'             => ODRPLT_Courier_Manager::STATUS_DELIVERED,
            'partial_delivered'     => ODRPLT_Courier_Manager::STATUS_DELIVERED,
            'payment_invoice'       => ODRPLT_Courier_Manager::STATUS_DELIVERED,
            'cancelled'             => ODRPLT_Courier_Manager::STATUS_CANCELLED,
            'delivery_failed'       => ODRPLT_Courier_Manager::STATUS_FAILED_DELIVERY,
            'return_in_transit'     => ODRPLT_Courier_Manager::STATUS_RETURNED,
            'returned'              => ODRPLT_Courier_Manager::STATUS_RETURNED,
        ];

        /**
         * Filter: Pathao delivery status to ODRPLT_Courier_Manager status mapping.
         *
         * @since 1.0.0
         * @param array  $map
         * @param string $raw_status
         */
        $map = (array) apply_filters( 'order_pilot_pathao_status_map', $map, $raw_status );

        return $map[ $status_clean ] ?? ODRPLT_Courier_Manager::STATUS_UNKNOWN;
    }

    // ─── OAuth2 Token ─────────────────────────────────────────────────────────

    /**
     * Get a valid access token, using a per-environment transient cache.
     *
     * @since 1.0.0
     * @return string|\WP_Error
     */
    private function get_access_token() {
        $cache_key = $this->get_token_key();
        $cached    = get_transient( $cache_key );

        if ( $cached ) {
            return $cached;
        }

        $payload = [
            'client_id'     => $this->credentials['client_id']     ?? '',
            'client_secret' => $this->credentials['client_secret'] ?? '',
            'username'      => $this->credentials['username']      ?? '',
            'password'      => $this->credentials['password']      ?? '',
            'grant_type'    => 'password',
        ];

        $response = wp_remote_post(
            $this->get_api_base() . '/aladdin/api/v1/issue-token',
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'    => wp_json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $message = $body['message'] ?? ( isset( $body['error_description'] ) ? $body['error_description'] : __( 'Pathao: Authentication failed. Please check your credentials.', 'order-pilot' ) );
            return new \WP_Error(
                'pathao_auth_failed',
                $message,
                [ 'status' => $code, 'body' => $body ]
            );
        }

        $token   = $body['access_token'];
        $expires = absint( $body['expires_in'] ?? 3600 ) - 60; // 60s safety buffer.
        set_transient( $cache_key, $token, $expires );

        return $token;
    }

    // ─── Data Builder ─────────────────────────────────────────────────────────

    /**
     * Build the Pathao API payload from a WooCommerce order.
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @param array     $extra_data
     * @return array|\WP_Error
     */
    private function build_order_data( \WC_Order $order, array $extra_data = [] ) {
        $items_count = array_sum( array_map( fn( $item ) => $item->get_quantity(), $order->get_items() ) );
        if ( $items_count < 1 ) {
            $items_count = 1;
        }

        // Recipient name
        $name = $extra_data['recipient_name']
            ?? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        if ( '' === $name ) {
            $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        }

        // Phone normalization (Pathao requires 11-digit mobile: 01XXXXXXXXX)
        $raw_phone = $extra_data['recipient_phone'] ?? $order->get_billing_phone();
        $phone     = preg_replace( '/[^0-9]/', '', (string) $raw_phone );
        if ( str_starts_with( $phone, '880' ) ) {
            $phone = substr( $phone, 2 );
        } elseif ( str_starts_with( $phone, '88' ) ) {
            $phone = substr( $phone, 2 );
        }

        // Address (Pathao requires minimum 10 characters)
        $address = $extra_data['recipient_address']
            ?? trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        if ( '' === $address ) {
            $address = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
        }
        $city = $order->get_shipping_city() ?: $order->get_billing_city();
        if ( $city && ! str_contains( strtolower( $address ), strtolower( $city ) ) ) {
            $address .= ', ' . $city;
        }

        $store_id = isset( $extra_data['store_id'] ) ? (int) $extra_data['store_id'] : $this->resolve_store_id();

        $data = [
            'store_id'            => $store_id,
            'merchant_order_id'   => (string) $order->get_order_number(),
            'recipient_name'      => $name,
            'recipient_phone'     => $phone,
            'recipient_address'   => $address,
            'delivery_type'       => (int) ( $extra_data['delivery_type'] ?? 48 ), // 48 = Normal (standard), 12 = On-Demand
            'item_type'           => (int) ( $extra_data['item_type'] ?? 2 ),      // 1 = Document, 2 = Parcel
            'item_quantity'       => (int) $items_count,
            'item_weight'         => (float) ( $extra_data['item_weight'] ?? 0.5 ),
            'amount_to_collect'   => (float) ( $extra_data['cod_amount'] ?? $order->get_total() ),
            'item_description'    => $extra_data['item_description'] ?? $this->get_items_summary( $order ),
        ];

        // Optional special instructions
        $note = $extra_data['note'] ?? $order->get_customer_note();
        if ( ! empty( $note ) ) {
            $data['special_instruction'] = (string) $note;
        }

        // Optional location IDs if explicitly provided
        if ( ! empty( $extra_data['city_id'] ) ) {
            $data['recipient_city'] = (int) $extra_data['city_id'];
        }
        if ( ! empty( $extra_data['zone_id'] ) ) {
            $data['recipient_zone'] = (int) $extra_data['zone_id'];
        }
        if ( ! empty( $extra_data['area_id'] ) ) {
            $data['recipient_area'] = (int) $extra_data['area_id'];
        }

        /** @see order_pilot_order_data */
        return (array) apply_filters( 'order_pilot_order_data', $data, $order );
    }

    /**
     * Generate a summary of order items.
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
        $url  = $this->get_api_base() . $endpoint;
        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        // Encode body for mutating requests
        if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        // Physical file logging for diagnostics
        if ( class_exists( 'ODRPLT_Logger' ) ) {
            $code      = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
            $resp_body = is_wp_error( $response ) ? [ 'error' => $response->get_error_message() ] : json_decode( wp_remote_retrieve_body( $response ), true );
            ODRPLT_Logger::log_courier_call( 'pathao', $endpoint, $body, $resp_body, (int) $code );
        }

        return $response;
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
            $message = is_array( $body ) && isset( $body['message'] )
                ? $body['message']
                /* translators: %d: HTTP status code. */
                : sprintf( __( 'Pathao API error (HTTP %d).', 'order-pilot' ), $code );

            // If Pathao returns field validation errors in 'errors'
            if ( is_array( $body ) && ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
                $err_messages = [];
                foreach ( $body['errors'] as $field => $messages ) {
                    $err_messages[] = is_array( $messages ) ? implode( '; ', $messages ) : (string) $messages;
                }
                $message .= ' — ' . implode( ' | ', $err_messages );
            }

            return new \WP_Error(
                'pathao_api_error',
                $message,
                [ 'status' => $code, 'body' => $body ]
            );
        }

        return is_array( $body ) ? $body : [];
    }
}
