<?php
/**
 * BDCourier Fraud Checker API Adapter
 *
 * Integrates with the BDCourier API (https://api.bdcourier.com/courier-check)
 * to aggregate courier delivery history across all major Bangladesh couriers
 * (Pathao, SteadFast, RedX, PaperFly, CarryBee, CourrierFast, ParcelDex)
 * and check merchant fraud complaint reports.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Fraud_BDCourier
 */
class ODRPLT_Fraud_BDCourier {

    /**
     * BDCourier API Endpoint.
     */
    const API_ENDPOINT = 'https://api.bdcourier.com/courier-check';

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
     * Check if BDCourier API is configured with an API key.
     *
     * @return bool
     */
    public function is_configured(): bool {
        $fraud_settings = $this->settings->get_fraud();
        return ! empty( $fraud_settings['api_key'] );
    }

    /**
     * Check customer phone delivery record & fraud reports on BDCourier.
     *
     * @param string $phone Customer phone number.
     * @return array|\WP_Error
     */
    public function check( string $phone ) {
        $fraud_settings = $this->settings->get_fraud();
        $api_key        = trim( $fraud_settings['api_key'] ?? '' );

        if ( empty( $api_key ) ) {
            return new \WP_Error(
                'bdcourier_missing_key',
                __( 'BDCourier API key is not configured in Settings.', 'order-pilot' )
            );
        }

        // Clean and format phone number (11 digits: e.g. 017XXXXXXXX)
        $clean_phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( str_starts_with( $clean_phone, '880' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        } elseif ( str_starts_with( $clean_phone, '88' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        }

        if ( strlen( $clean_phone ) < 11 ) {
            return new \WP_Error(
                'invalid_phone',
                __( 'Please provide a valid 11-digit mobile phone number.', 'order-pilot' )
            );
        }

        // Cache response for 10 minutes to prevent redundant API quota consumption
        $cache_key = 'odrplt_bdcourier_' . md5( $clean_phone );
        $cached    = wp_cache_get( $cache_key, 'order_pilot' );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $transient_key = 'odrplt_bdc_' . md5( $clean_phone );
        $trans_cached  = get_transient( $transient_key );
        if ( false !== $trans_cached && is_array( $trans_cached ) ) {
            wp_cache_set( $cache_key, $trans_cached, 'order_pilot', 600 );
            return $trans_cached;
        }

        // Make POST request to BDCourier API
        $response = wp_remote_post(
            self::API_ENDPOINT,
            [
                'timeout' => 20,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                ],
                'body'    => wp_json_encode( [ 'phone' => $clean_phone ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( $code !== 200 || empty( $body ) || ! is_array( $body ) ) {
            $msg = is_array( $body ) && ! empty( $body['message'] )
                ? $body['message']
                /* translators: %d: HTTP status code. */
                : sprintf( __( 'BDCourier API error (HTTP %d).', 'order-pilot' ), $code );

            return new \WP_Error( 'bdcourier_error', $msg, [ 'status' => $code, 'body' => $body ] );
        }

        $parsed = $this->parse_response( $body, $clean_phone );

        // Cache result for 15 minutes
        set_transient( $transient_key, $parsed, 900 );
        wp_cache_set( $cache_key, $parsed, 'order_pilot', 900 );

        return $parsed;
    }

    /**
     * Parse BDCourier API response into normalized data structure.
     *
     * @param array  $body
     * @param string $phone
     * @return array
     */
    private function parse_response( array $body, string $phone ): array {
        $raw_data = $body['data'] ?? [];
        $reports  = $body['reports'] ?? [];

        // Extract summary
        $summary = $raw_data['summary'] ?? [
            'total_parcel'     => 0,
            'success_parcel'   => 0,
            'cancelled_parcel' => 0,
            'success_ratio'    => 0,
        ];

        // Extract courier breakdown list (excluding summary)
        $couriers = [];
        foreach ( $raw_data as $key => $courier_data ) {
            if ( 'summary' === $key || ! is_array( $courier_data ) ) {
                continue;
            }
            $couriers[ $key ] = [
                'slug'             => $key,
                'name'             => $courier_data['name'] ?? ucfirst( $key ),
                'logo'             => $courier_data['logo'] ?? '',
                'total_parcel'     => (int) ( $courier_data['total_parcel'] ?? 0 ),
                'success_parcel'   => (int) ( $courier_data['success_parcel'] ?? 0 ),
                'cancelled_parcel' => (int) ( $courier_data['cancelled_parcel'] ?? 0 ),
                'success_ratio'    => (float) ( $courier_data['success_ratio'] ?? 0 ),
            ];
        }

        return [
            'success'  => true,
            'phone'    => $phone,
            'summary'  => [
                'total_parcel'     => (int) ( $summary['total_parcel'] ?? 0 ),
                'success_parcel'   => (int) ( $summary['success_parcel'] ?? 0 ),
                'cancelled_parcel' => (int) ( $summary['cancelled_parcel'] ?? 0 ),
                'success_ratio'    => (float) ( $summary['success_ratio'] ?? 0 ),
            ],
            'couriers' => $couriers,
            'reports'  => is_array( $reports ) ? $reports : [],
            'raw'      => $body,
        ];
    }
}
