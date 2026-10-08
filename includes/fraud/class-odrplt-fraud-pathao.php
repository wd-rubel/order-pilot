<?php
/**
 * Fraud Data — Pathao
 *
 * Fetches order delivery history from the Pathao API
 * for a customer phone number, used as one component of the fraud score.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Fraud_Pathao
 */
class ODRPLT_Fraud_Pathao {

    /**
     * Pathao live API base URL.
     */
    const API_BASE = 'https://api-hermes.pathao.com';

    /**
     * Pathao sandbox API base URL.
     */
    const API_BASE_SANDBOX = 'https://courier-api-sandbox.pathao.com';

    /**
     * @var ODRPLT_Settings
     */
    private ODRPLT_Settings $settings;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->settings = order_pilot()->settings;
    }

    /**
     * Get API base URL depending on configured environment.
     *
     * @return string
     */
    private function get_api_base(): string {
        $creds = $this->settings->get_courier_credentials( 'pathao' );
        $env   = $creds['environment'] ?? 'live';
        return 'sandbox' === $env ? self::API_BASE_SANDBOX : self::API_BASE;
    }

    /**
     * Get delivery success/failure data for a phone number from Pathao.
     *
     * @since 1.0.0
     * @param string $phone Customer phone number.
     * @return array {
     *     @type int $total
     *     @type int $delivered
     *     @type int $cancelled
     *     @type int $returned
     * }
     */
    public function get_delivery_data( string $phone ): array {
        $creds = $this->settings->get_courier_credentials( 'pathao' );

        if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
            return [];
        }

        // Normalize phone number (11 digits: 01XXXXXXXXX)
        $clean_phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( str_starts_with( $clean_phone, '880' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        }

        if ( empty( $clean_phone ) ) {
            return [];
        }

        $cache_key = 'odrplt_pa_fraud_' . md5( $clean_phone );
        $cached    = wp_cache_get( $cache_key, 'order_pilot' );

        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $token = $this->get_token( $creds );

        if ( ! $token ) {
            return [];
        }

        // Search Pathao orders by recipient phone
        $response = wp_remote_get(
            $this->get_api_base() . '/aladdin/api/v1/orders?recipient_phone=' . urlencode( $clean_phone ) . '&page_size=100',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || ! isset( $body['data']['data'] ) || ! is_array( $body['data']['data'] ) ) {
            return [];
        }

        $data = $this->aggregate_statuses( $body['data']['data'] );

        wp_cache_set( $cache_key, $data, 'order_pilot', 600 );

        return $data;
    }

    /**
     * Aggregate Pathao order statuses.
     *
     * @param array $orders
     * @return array
     */
    private function aggregate_statuses( array $orders ): array {
        $total     = count( $orders );
        $delivered = 0;
        $cancelled = 0;
        $returned  = 0;

        foreach ( $orders as $order ) {
            $status = strtolower( (string) ( $order['order_status'] ?? $order['status'] ?? '' ) );

            if ( in_array( $status, [ 'delivered', 'delivery_success', 'completed', 'partial_delivered', 'payment_invoice' ], true ) ) {
                $delivered++;
            } elseif ( in_array( $status, [ 'cancelled', 'cancel', 'merchant_cancelled' ], true ) ) {
                $cancelled++;
            } elseif ( in_array( $status, [ 'returned', 'return', 'partial_return', 'exchange_return', 'return_in_transit', 'delivery_failed' ], true ) ) {
                $returned++;
            }
        }

        return compact( 'total', 'delivered', 'cancelled', 'returned' );
    }

    /**
     * Get a cached Pathao access token.
     *
     * @param array $creds
     * @return string|null
     */
    private function get_token( array $creds ): ?string {
        $env        = $creds['environment'] ?? 'live';
        $trans_key  = 'odrplt_pa_fraud_token_' . $env;
        $cached     = get_transient( $trans_key );

        if ( $cached ) {
            return $cached;
        }

        $base = 'sandbox' === $env ? self::API_BASE_SANDBOX : self::API_BASE;

        $response = wp_remote_post(
            $base . '/aladdin/api/v1/issue-token',
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'client_id'     => $creds['client_id'],
                    'client_secret' => $creds['client_secret'],
                    'username'      => $creds['username'],
                    'password'      => $creds['password'],
                    'grant_type'    => 'password',
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            return null;
        }

        $ttl = (int) ( $body['expires_in'] ?? 3600 ) - 300;
        set_transient( $trans_key, $body['access_token'], max( 60, $ttl ) );

        return $body['access_token'];
    }
}
