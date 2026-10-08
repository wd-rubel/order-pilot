<?php
/**
 * Fraud Data — Steadfast
 *
 * Fetches order delivery history from the Steadfast API
 * for a customer phone number, used as one component of the fraud score.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Fraud_Steadfast
 */
class ODRPLT_Fraud_Steadfast {

    /**
     * Steadfast API base URL.
     */
    const API_BASE = 'https://portal.packzy.com/api/v1';

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
     * Get delivery success/failure data for a phone number from Steadfast.
     *
     * @since 1.0.0
     * @param string $phone Customer phone number.
     * @return array {
     *     @type int $total     Total consignments found.
     *     @type int $delivered Delivered consignments.
     *     @type int $cancelled Cancelled consignments.
     *     @type int $returned  Returned consignments.
     * }
     */
    public function get_delivery_data( string $phone ): array {
        $creds = $this->settings->get_courier_credentials( 'steadfast' );

        if ( empty( $creds['api_key'] ) || empty( $creds['secret_key'] ) ) {
            return [];
        }

        // Normalize phone number (digits only)
        $clean_phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( str_starts_with( $clean_phone, '880' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        }

        if ( empty( $clean_phone ) ) {
            return [];
        }

        // Cache by phone for 10 minutes to avoid redundant API hits.
        $cache_key = 'odrplt_sf_fraud_' . md5( $clean_phone );
        $cached    = wp_cache_get( $cache_key, 'order_pilot' );

        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        // Steadfast: search by tracking/phone code
        $response = wp_remote_get(
            self::API_BASE . '/status_by_trackingcode/' . urlencode( $clean_phone ),
            [
                'timeout' => 15,
                'headers' => [
                    'Api-Key'    => $creds['api_key'],
                    'Secret-Key' => $creds['secret_key'],
                    'Accept'     => 'application/json',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || ! is_array( $body ) ) {
            return [];
        }

        $data = $this->aggregate_statuses( $body );

        wp_cache_set( $cache_key, $data, 'order_pilot', 600 );

        return $data;
    }

    /**
     * Aggregate raw Steadfast status data into totals.
     *
     * @since 1.0.0
     * @param array $body API response body.
     * @return array
     */
    private function aggregate_statuses( array $body ): array {
        $statuses = [];

        if ( isset( $body['delivery_status'] ) ) {
            $statuses = [ $body ];
        } elseif ( isset( $body['consignment'] ) && is_array( $body['consignment'] ) ) {
            $statuses = isset( $body['consignment'][0] ) ? $body['consignment'] : [ $body['consignment'] ];
        } elseif ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
            $statuses = isset( $body['data'][0] ) ? $body['data'] : [ $body['data'] ];
        } elseif ( isset( $body[0] ) && is_array( $body[0] ) ) {
            $statuses = $body;
        }

        $total     = count( $statuses );
        $delivered = 0;
        $cancelled = 0;
        $returned  = 0;

        foreach ( $statuses as $consignment ) {
            $status = strtolower( (string) ( $consignment['delivery_status'] ?? $consignment['status'] ?? '' ) );
            if ( in_array( $status, [ 'delivered', 'delivery_success', 'partial_delivered' ], true ) ) {
                $delivered++;
            } elseif ( in_array( $status, [ 'cancelled', 'cancel' ], true ) ) {
                $cancelled++;
            } elseif ( in_array( $status, [ 'returned', 'return', 'partial_return', 'return_in_transit' ], true ) ) {
                $returned++;
            }
        }

        return compact( 'total', 'delivered', 'cancelled', 'returned' );
    }
}
