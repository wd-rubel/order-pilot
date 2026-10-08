<?php
/**
 * Fraud Score Calculator
 *
 * Aggregates data from BDCourier Fraud Checker API (Pathao, SteadFast, RedX,
 * PaperFly, CarryBee, CourrierFast, ParcelDex + Merchant Reports) alongside
 * WooCommerce store order history to produce a precision fraud score (0–100)
 * and risk level.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Fraud_Score
 */
class ODRPLT_Fraud_Score {

    /**
     * Risk level thresholds. Configurable via filter.
     */
    const DEFAULT_THRESHOLDS = [
        'low'    => 40,  // 0–40 = Low Risk
        'medium' => 70,  // 41–70 = Medium Risk
                         // 71–100 = High Risk
    ];

    /**
     * @var ODRPLT_Fraud_BDCourier
     */
    private ODRPLT_Fraud_BDCourier $bdcourier;

    /**
     * @var ODRPLT_Fraud_Steadfast
     */
    private ODRPLT_Fraud_Steadfast $steadfast;

    /**
     * @var ODRPLT_Fraud_Pathao
     */
    private ODRPLT_Fraud_Pathao $pathao;

    /**
     * Constructor.
     */
    public function __construct(
        ?ODRPLT_Fraud_Steadfast $steadfast = null,
        ?ODRPLT_Fraud_Pathao $pathao = null,
        ?ODRPLT_Fraud_BDCourier $bdcourier = null
    ) {
        $this->bdcourier = $bdcourier ?? new ODRPLT_Fraud_BDCourier();
        $this->steadfast = $steadfast ?? new ODRPLT_Fraud_Steadfast();
        $this->pathao    = $pathao ?? new ODRPLT_Fraud_Pathao();
    }

    /**
     * Calculate a fraud score for a customer phone number.
     *
     * @since 1.0.0
     * @param string $phone Customer phone number.
     * @param int    $order_id Current WC order ID (excluded from history lookup).
     * @return array
     */
    public function calculate( string $phone, int $order_id = 0 ): array {
        // Clean phone number (11 digits)
        $clean_phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        if ( str_starts_with( $clean_phone, '880' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        } elseif ( str_starts_with( $clean_phone, '88' ) ) {
            $clean_phone = substr( $clean_phone, 2 );
        }

        $wc_data = $this->get_wc_order_history( $clean_phone, $order_id );

        // Check if BDCourier is configured
        if ( $this->bdcourier->is_configured() ) {
            return $this->calculate_with_bdcourier( $clean_phone, $wc_data );
        }

        // Fallback calculation using direct courier endpoints & store history
        return $this->calculate_fallback( $clean_phone, $wc_data );
    }

    /**
     * Calculate fraud score using BDCourier unified API.
     *
     * @param string $phone
     * @param array  $wc_data
     * @return array
     */
    private function calculate_with_bdcourier( string $phone, array $wc_data ): array {
        $bdc_result = $this->bdcourier->check( $phone );

        $breakdown = [];
        $history   = [
            'woocommerce' => $wc_data,
        ];

        // Store History Score (up to 30 points)
        $wc_score = $this->score_from_wc_history( $wc_data );
        $breakdown['wc'] = [
            'label'  => __( 'Store Purchase History', 'order-pilot' ),
            'score'  => $wc_score,
            'weight' => 30,
            'data'   => $wc_data,
        ];

        $courier_score   = 0;
        $reports_penalty = 0;
        $reports         = [];
        $couriers_data   = [];
        $summary         = [
            'total_parcel'     => 0,
            'success_parcel'   => 0,
            'cancelled_parcel' => 0,
            'success_ratio'    => 0,
        ];

        if ( ! is_wp_error( $bdc_result ) && is_array( $bdc_result ) ) {
            $summary       = $bdc_result['summary'] ?? $summary;
            $couriers_data = $bdc_result['couriers'] ?? [];
            $reports       = $bdc_result['reports'] ?? [];
            $history['bdcourier'] = $bdc_result;

            $total_parcel     = (int) ( $summary['total_parcel'] ?? 0 );
            $success_ratio    = (float) ( $summary['success_ratio'] ?? 0 );
            $cancelled_parcel = (int) ( $summary['cancelled_parcel'] ?? 0 );

            // Courier delivery rate risk (up to 50 points)
            if ( $total_parcel > 0 ) {
                $cancel_rate   = 100 - $success_ratio;
                $courier_score = ( $cancel_rate / 100 ) * 50;
            } else {
                // First-time buyer with 0 courier history: neutral low baseline
                $courier_score = 5;
            }

            // Reports penalty: Each merchant report adds 25 points penalty (up to 40pts)
            $report_count    = count( $reports );
            $reports_penalty = min( 40, $report_count * 25 );
        }

        $breakdown['bdcourier'] = [
            'label'           => __( 'BDCourier All-Courier Summary', 'order-pilot' ),
            'score'           => (int) round( $courier_score ),
            'weight'          => 50,
            'summary'         => $summary,
            'couriers'        => $couriers_data,
            'reports'         => $reports,
            'reports_penalty' => $reports_penalty,
        ];

        // Total weighted score (0–100)
        $store_contribution = ( $wc_score / 100 ) * 30;
        $total_score        = (int) round( min( 100, max( 0, $store_contribution + $courier_score + $reports_penalty ) ) );

        // Determine risk level
        if ( ! empty( $reports ) || $total_score >= 70 || ( $summary['total_parcel'] >= 3 && $summary['success_ratio'] < 50 ) ) {
            $risk_level = 'high';
        } elseif ( $total_score >= 40 || ( $summary['total_parcel'] >= 3 && $summary['success_ratio'] < 75 ) ) {
            $risk_level = 'medium';
        } else {
            $risk_level = 'low';
        }

        return [
            'score'      => $total_score,
            'risk_level' => $risk_level,
            'phone'      => $phone,
            'summary'    => $summary,
            'couriers'   => $couriers_data,
            'reports'    => $reports,
            'breakdown'  => $breakdown,
            'history'    => $history,
        ];
    }

    /**
     * Fallback calculation when BDCourier key is not provided.
     *
     * @param string $phone
     * @param array  $wc_data
     * @return array
     */
    private function calculate_fallback( string $phone, array $wc_data ): array {
        $breakdown = [];
        $history   = [ 'woocommerce' => $wc_data ];

        $wc_score        = $this->score_from_wc_history( $wc_data );
        $breakdown['wc'] = [
            'label'  => __( 'Store Purchase History', 'order-pilot' ),
            'score'  => $wc_score,
            'weight' => 40,
            'data'   => $wc_data,
        ];

        $sf_data = $this->steadfast->get_delivery_data( $phone );
        $sf_score = $this->score_from_courier_data( $sf_data );
        $breakdown['steadfast'] = [
            'label'  => __( 'Steadfast Delivery History', 'order-pilot' ),
            'score'  => $sf_score,
            'weight' => 30,
            'data'   => $sf_data,
        ];
        $history['steadfast'] = $sf_data;

        $pa_data = $this->pathao->get_delivery_data( $phone );
        $pa_score = $this->score_from_courier_data( $pa_data );
        $breakdown['pathao'] = [
            'label'  => __( 'Pathao Delivery History', 'order-pilot' ),
            'score'  => $pa_score,
            'weight' => 30,
            'data'   => $pa_data,
        ];
        $history['pathao'] = $pa_data;

        $total = 0;
        $total += ( $wc_score / 100 ) * 40;
        $total += ( $sf_score / 100 ) * 30;
        $total += ( $pa_score / 100 ) * 30;
        $total  = (int) round( min( 100, max( 0, $total ) ) );

        $risk_level = $this->get_risk_level( $total );

        return [
            'score'      => $total,
            'risk_level' => $risk_level,
            'phone'      => $phone,
            'summary'    => [
                'total_parcel'     => ( $sf_data['total'] ?? 0 ) + ( $pa_data['total'] ?? 0 ),
                'success_parcel'   => ( $sf_data['delivered'] ?? 0 ) + ( $pa_data['delivered'] ?? 0 ),
                'cancelled_parcel' => ( $sf_data['cancelled'] ?? 0 ) + ( $pa_data['cancelled'] ?? 0 ),
                'success_ratio'    => 0,
            ],
            'couriers'   => [],
            'reports'    => [],
            'breakdown'  => $breakdown,
            'history'    => $history,
        ];
    }

    // ─── WooCommerce History ──────────────────────────────────────────────────

    /**
     * Get order history for a phone number from WooCommerce.
     *
     * @param string $phone
     * @param int    $exclude_order_id
     * @return array
     */
    private function get_wc_order_history( string $phone, int $exclude_order_id = 0 ): array {
        if ( empty( $phone ) || ! function_exists( 'wc_get_orders' ) ) {
            return [ 'total' => 0, 'delivered' => 0, 'cancelled' => 0, 'returned' => 0, 'cod' => 0 ];
        }

        $orders = wc_get_orders( [
            'billing_phone' => $phone,
            'limit'         => 100,
            'return'        => 'ids',
        ] );

        if ( $exclude_order_id ) {
            $orders = array_filter( $orders, fn( $id ) => (int) $id !== $exclude_order_id );
        }

        $total     = count( $orders );
        $delivered = 0;
        $cancelled = 0;
        $returned  = 0;
        $cod       = 0;

        foreach ( $orders as $oid ) {
            $order = wc_get_order( $oid );
            if ( ! $order ) continue;

            $status = $order->get_status();

            if ( in_array( $status, [ 'completed', 'processing' ], true ) ) {
                $delivered++;
            } elseif ( 'cancelled' === $status ) {
                $cancelled++;
            } elseif ( in_array( $status, [ 'refunded', 'failed' ], true ) ) {
                $returned++;
            }

            if ( $order->get_payment_method() === 'cod' ) {
                $cod++;
            }
        }

        return compact( 'total', 'delivered', 'cancelled', 'returned', 'cod' );
    }

    /**
     * Convert WooCommerce order history to a risk score component (0–100).
     *
     * @param array $data
     * @return int
     */
    private function score_from_wc_history( array $data ): int {
        $total     = $data['total'] ?? 0;
        $delivered = $data['delivered'] ?? 0;
        $cancelled = $data['cancelled'] ?? 0;
        $returned  = $data['returned'] ?? 0;

        if ( $total === 0 ) {
            return 10; // First-time buyer clean baseline
        }

        if ( $delivered > 0 && ( $cancelled + $returned ) === 0 ) {
            return 0; // Trusted buyer
        }

        $bad_rate = ( $cancelled + $returned ) / $total;

        return (int) round( $bad_rate * 100 );
    }

    /**
     * Convert courier delivery data to a risk score component (0–100).
     *
     * @param array $data
     * @return int
     */
    private function score_from_courier_data( array $data ): int {
        if ( empty( $data ) || ! isset( $data['total'] ) || $data['total'] === 0 ) {
            return 0;
        }

        $total     = $data['total'];
        $cancelled = ( $data['cancelled'] ?? 0 ) + ( $data['returned'] ?? 0 );
        $bad_rate  = $cancelled / $total;

        return (int) round( $bad_rate * 100 );
    }

    /**
     * Determine risk level label.
     *
     * @param int $score
     * @return string
     */
    private function get_risk_level( int $score ): string {
        $thresholds = (array) apply_filters( 'order_pilot_fraud_thresholds', self::DEFAULT_THRESHOLDS );

        if ( $score <= $thresholds['low'] ) {
            return 'low';
        }
        if ( $score <= $thresholds['medium'] ) {
            return 'medium';
        }
        return 'high';
    }
}
