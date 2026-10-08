<?php
/**
 * Fraud Checker Orchestrator
 *
 * Coordinates fraud checks: runs the score calculator, saves results to DB,
 * performs automatic checkout verification when customer enters phone number,
 * enforces configurable risk-level actions (Approve, Manual Review, Block),
 * renders WooCommerce meta boxes and order list columns, provides AJAX + REST endpoints.
 *
 * Cleanly decoupled from IP Blocking (which is an Order Pilot Pro feature).
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Fraud_Checker
 */
class ODRPLT_Fraud_Checker {

    /**
     * @var ODRPLT_Fraud_Score|null Lazy-loaded scorer.
     */
    private ?ODRPLT_Fraud_Score $scorer = null;

    /**
     * Constructor.
     */
    public function __construct() {
        // Order Detail Meta Box
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

        // Automatic Checkout Phone Pre-Check & Validation (Classic & AJAX Checkouts)
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_script' ] );
        add_action( 'wp_ajax_odrplt_checkout_fraud_check', [ $this, 'ajax_check_phone' ] );
        add_action( 'wp_ajax_nopriv_odrplt_checkout_fraud_check', [ $this, 'ajax_check_phone' ] );
        add_action( 'woocommerce_checkout_process', [ $this, 'process_checkout_validation' ] );
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 10, 2 );

        // Modern WooCommerce Blocks / Store API Validation
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_blocks_checkout' ], 10, 2 );

        // Order Creation Interception & Note Persistence
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_order_result' ], 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'check_on_order' ], 10, 1 );

        // Column Data Filter for WooCommerce Orders List
        add_filter( 'order_pilot_fraud_risk_column_data', [ $this, 'filter_fraud_risk_column_data' ], 10, 2 );
    }

    /**
     * Get (or create) the fraud scorer instance.
     *
     * @return ODRPLT_Fraud_Score
     */
    public function get_scorer(): ODRPLT_Fraud_Score {
        if ( null === $this->scorer ) {
            $this->scorer = new ODRPLT_Fraud_Score(
                new ODRPLT_Fraud_Steadfast(),
                new ODRPLT_Fraud_Pathao()
            );
        }
        return $this->scorer;
    }

    /**
     * Check whether automatic fraud checking is enabled in Settings.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_auto_check_enabled(): bool {
        $fraud      = function_exists( 'order_pilot' ) ? order_pilot()->settings->get_fraud() : [];
        $enabled    = ! empty( $fraud['enable_fraud'] );
        $auto_check = isset( $fraud['auto_check'] ) ? ! empty( $fraud['auto_check'] ) : true;
        return $enabled && $auto_check;
    }

    /**
     * Build the block message string from the merchant's configured template.
     *
     * Supports two placeholders:
     *   {score}     — the customer's evaluated fraud score (integer %)
     *   {threshold} — the configured auto-block threshold (integer %)
     *
     * @since 1.0.0
     * @param int $score           Fraud score (0–100).
     * @param int $block_threshold Configured threshold.
     * @return string Ready-to-display message.
     */
    public function get_block_message( int $score, int $block_threshold ): string {
        $fraud    = function_exists( 'order_pilot' ) ? order_pilot()->settings->get_fraud() : [];
        $template = isset( $fraud['block_message'] ) && '' !== trim( $fraud['block_message'] )
            ? $fraud['block_message']
            : __( 'Sorry, we’re unable to process your order at this time. Please contact our support team for assistance.', 'order-pilot' );

        return str_replace(
            [ '{score}', '{threshold}' ],
            [ (string) $score, (string) $block_threshold ],
            $template
        );
    }

    /**
     * Determine the policy action for a given fraud score.
     *
     * If the customer's fraud risk score reaches or exceeds the configured
     * block_score_threshold (e.g. 80%), the order is blocked; otherwise approved.
     *
     * @since 1.0.0
     * @param int    $score Fraud score (0–100).
     * @param string $risk  Optional risk level ('low' | 'medium' | 'high').
     * @return string 'block' | 'approve'
     */
    public function get_action_for_result( int $score, string $risk = 'low' ): string {
        $fraud           = function_exists( 'order_pilot' ) ? order_pilot()->settings->get_fraud() : [];
        $block_threshold = isset( $fraud['block_score_threshold'] ) && '' !== $fraud['block_score_threshold'] ? (int) $fraud['block_score_threshold'] : 80;

        if ( $block_threshold > 0 && $score >= $block_threshold ) {
            return 'block';
        }

        return 'approve';
    }

    /**
     * Get the configured policy action for a given risk level or score.
     *
     * @since 1.0.0
     * @param string $risk  'low' | 'medium' | 'high'
     * @param int    $score Optional score (0–100).
     * @return string 'block' | 'approve'
     */
    public function get_action_for_risk( string $risk, int $score = -1 ): string {
        if ( $score >= 0 ) {
            return $this->get_action_for_result( $score, $risk );
        }
        return ( 'high' === $risk ) ? 'block' : 'approve';
    }

    /**
     * Helper to extract billing phone across all checkout request types.
     *
     * @param array $data Posted checkout data.
     * @return string Clean digits-only phone.
     */
    private function extract_phone( array $data = [] ): string {
        $phone = '';

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Extracted during WooCommerce checkout pipeline where WC validates nonces.
        if ( ! empty( $data['billing_phone'] ) ) {
            $phone = sanitize_text_field( (string) $data['billing_phone'] );
        } elseif ( ! empty( $_POST['billing_phone'] ) ) {
            $phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
        } elseif ( ! empty( $_POST['phone'] ) ) {
            $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ) );
        } elseif ( ! empty( $_POST['post_data'] ) ) {
            $parsed = [];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw query string is parsed then individual values are sanitized.
            wp_parse_str( (string) wp_unslash( $_POST['post_data'] ), $parsed );
            if ( ! empty( $parsed['billing_phone'] ) ) {
                $phone = sanitize_text_field( (string) $parsed['billing_phone'] );
            } elseif ( ! empty( $parsed['phone'] ) ) {
                $phone = sanitize_text_field( (string) $parsed['phone'] );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( empty( $phone ) && function_exists( 'WC' ) && WC()->customer ) {
            $phone = (string) WC()->customer->get_billing_phone();
        }

        return preg_replace( '/[^0-9]/', '', $phone );
    }

    // ─── Checkout Automatic Fraud Check ───────────────────────────────────────

    /**
     * Load the checkout phone watcher script when automatic checking is enabled.
     *
     * @since 1.0.0
     */
    public function enqueue_checkout_script(): void {
        if ( ! $this->is_auto_check_enabled() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }

        wp_register_script( 'order-pilot-fraud-checkout', false, [ 'jquery' ], ORDER_PILOT_VERSION, true );
        wp_enqueue_script( 'order-pilot-fraud-checkout' );
        wp_localize_script( 'order-pilot-fraud-checkout', 'orderPilotFraud', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'odrplt_fraud_checkout' ),
        ] );

        wp_add_inline_script( 'order-pilot-fraud-checkout', <<<'JS'
jQuery( function( $ ) {
    var timer, lastPhone = '';
    $( document.body ).on( 'input change blur', '#billing_phone', function() {
        var phone = String( $( this ).val() || '' ).replace( /\s+/g, '' );
        if ( phone.length < 6 || phone === lastPhone ) return;
        clearTimeout( timer );
        timer = setTimeout( function() {
            $.post( orderPilotFraud.ajaxUrl, { action: 'odrplt_checkout_fraud_check', nonce: orderPilotFraud.nonce, phone: phone } )
                .done( function( response ) { if ( response && response.success ) lastPhone = phone; } );
        }, 600 );
    } );
} );
JS
        );
    }

    /**
     * AJAX handler for the live checkout phone check.
     *
     * @since 1.0.0
     */
    public function ajax_check_phone(): void {
        check_ajax_referer( 'odrplt_fraud_checkout', 'nonce' );

        $phone       = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $clean_phone = preg_replace( '/[^0-9]/', '', $phone );

        if ( empty( $clean_phone ) || strlen( $clean_phone ) < 6 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid phone number.', 'order-pilot' ) ], 400 );
        }

        $result = $this->get_scorer()->calculate( $clean_phone, 0 );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
        }

        $this->store_session_result( $clean_phone, $result );

        $risk   = $result['risk_level'] ?? 'low';
        $score  = (int) ( $result['score'] ?? 0 );
        $action = $this->get_action_for_result( $score, $risk );

        // Merge computed policy action into result data so the log table displays it correctly
        $result['action'] = $action;

        // Log to database table
        order_pilot()->db->insert_fraud_check( [
            'order_id'   => 0,
            'phone'      => $clean_phone,
            'score'      => $score,
            'risk_level' => $risk,
            'data'       => $result,
        ] );

        /**
         * Extension hook: fires after a fraud check is executed.
         * Pro IP Blocker hooks here for auto-blocking.
         *
         * @since 1.0.0
         * @param int   $order_id Order ID (0 for pre-checkout).
         * @param array $result   Fraud evaluation results.
         */
        do_action( 'order_pilot_after_fraud_check', 0, $result );

        wp_send_json_success( [
            'risk_level' => $risk,
            'score'      => $score,
            'action'     => $action,
        ] );
    }

    /**
     * Intercept classic WooCommerce checkout submission before validation.
     *
     * @since 1.0.0
     */
    public function process_checkout_validation(): void {
        if ( ! $this->is_auto_check_enabled() ) {
            return;
        }

        // ── Step 1: Pro IP check extension point (fast, skips phone API) ──────
        if ( apply_filters( 'order_pilot_is_checkout_ip_blocked', false ) ) {
            return;
        }

        // ── Step 2: Phone-based fraud score check ─────────────────────────────
        $phone = $this->extract_phone();
        if ( '' === $phone || strlen( $phone ) < 6 ) {
            return;
        }

        $result = $this->get_or_check_phone( $phone );
        if ( is_wp_error( $result ) || ! is_array( $result ) ) {
            return;
        }

        $risk   = $result['risk_level'] ?? 'low';
        $score  = (int) ( $result['score'] ?? 0 );
        $action = $this->get_action_for_result( $score, $risk );

        $result['action'] = $action;

        do_action( 'order_pilot_after_fraud_check', 0, $result );

        if ( 'block' === $action ) {
            $fraud           = function_exists( 'order_pilot' ) ? order_pilot()->settings->get_fraud() : [];
            $block_threshold = isset( $fraud['block_score_threshold'] ) ? (int) $fraud['block_score_threshold'] : 80;
            $msg             = $this->get_block_message( $score, $block_threshold );
            if ( function_exists( 'wc_add_notice' ) && ( ! function_exists( 'wc_has_notice' ) || ! wc_has_notice( $msg, 'error' ) ) ) {
                wc_add_notice( $msg, 'error' );
            }
        }
    }

    /**
     * Validate the customer's fraud risk during checkout submission.
     * If the configured action for this risk level or score ratio threshold is 'block', halt checkout.
     *
     * @since 1.0.0
     * @param array     $data   Posted checkout data.
     * @param \WP_Error $errors Checkout validation errors object.
     */
    public function validate_checkout( array $data, \WP_Error $errors ): void {
        if ( ! $this->is_auto_check_enabled() ) {
            return;
        }

        // ── Step 1: Pro IP check extension point ──────────────────────────────
        if ( apply_filters( 'order_pilot_is_checkout_ip_blocked', false, $errors ) ) {
            return;
        }

        // ── Step 2: Phone-based fraud score check ─────────────────────────────
        $phone = $this->extract_phone( $data );
        if ( '' === $phone || strlen( $phone ) < 6 ) {
            return;
        }

        $result = $this->get_or_check_phone( $phone );
        if ( is_wp_error( $result ) || ! is_array( $result ) ) {
            return;
        }

        $risk   = $result['risk_level'] ?? 'low';
        $score  = (int) ( $result['score'] ?? 0 );
        $action = $this->get_action_for_result( $score, $risk );

        // Merge computed policy action into result data so the log table displays it correctly
        $result['action'] = $action;

        // Always log check in DB
        order_pilot()->db->insert_fraud_check( [
            'order_id'   => 0,
            'phone'      => $phone,
            'score'      => $score,
            'risk_level' => $risk,
            'data'       => $result,
        ] );

        do_action( 'order_pilot_after_fraud_check', 0, $result );

        if ( 'block' === $action ) {
            $fraud           = function_exists( 'order_pilot' ) ? order_pilot()->settings->get_fraud() : [];
            $block_threshold = isset( $fraud['block_score_threshold'] ) ? (int) $fraud['block_score_threshold'] : 80;
            $msg             = $this->get_block_message( $score, $block_threshold );
            if ( ! function_exists( 'wc_has_notice' ) || ! wc_has_notice( $msg, 'error' ) ) {
                if ( ! in_array( $msg, $errors->get_error_messages(), true ) ) {
                    $errors->add( 'odrplt_fraud_blocked', $msg );
                }
            }
        }
    }

    /**
     * Validate fraud risk for WooCommerce Blocks / Store API checkout.
     *
     * @since 1.0.0
     * @param \WC_Order        $order
     * @param \WP_REST_Request $request
     * @throws \Exception
     */
    public function validate_blocks_checkout( \WC_Order $order, $request = null ): void {
        if ( ! $this->is_auto_check_enabled() ) {
            return;
        }

        // ── Step 1: Pro IP check extension point ──────────────────────────────
        if ( apply_filters( 'order_pilot_blocks_is_checkout_ip_blocked', false, $order, $request ) ) {
            return;
        }

        // ── Step 2: Phone-based fraud score check ─────────────────────────────
        $phone = preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() );
        if ( empty( $phone ) && $request instanceof \WP_REST_Request ) {
            $billing = $request->get_param( 'billing_address' );
            if ( ! empty( $billing['phone'] ) ) {
                $phone = preg_replace( '/[^0-9]/', '', (string) $billing['phone'] );
            }
        }

        if ( '' === $phone || strlen( $phone ) < 6 ) {
            return;
        }

        $result = $this->get_or_check_phone( $phone, $order->get_id() );
        if ( is_wp_error( $result ) || ! is_array( $result ) ) {
            return;
        }

        $risk   = $result['risk_level'] ?? 'low';
        $score  = (int) ( $result['score'] ?? 0 );
        $action = $this->get_action_for_result( $score, $risk );

        // Merge computed policy action into result data so the log table displays it correctly
        $result['action'] = $action;

        order_pilot()->db->insert_fraud_check( [
            'order_id'   => $order->get_id(),
            'phone'      => $phone,
            'score'      => $score,
            'risk_level' => $risk,
            'data'       => $result,
        ] );

        do_action( 'order_pilot_after_fraud_check', $order->get_id(), $result );

        if ( 'block' === $action ) {
            $fraud           = function_exists( 'order_pilot' ) ? order_pilot()->settings->get_fraud() : [];
            $block_threshold = isset( $fraud['block_score_threshold'] ) ? (int) $fraud['block_score_threshold'] : 80;
            $msg             = esc_html( $this->get_block_message( $score, $block_threshold ) );
            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'odrplt_fraud_blocked', esc_html( $msg ), 400 );
            } else {
                throw new \Exception( esc_html( $msg ) );
            }
        }
    }

    /**
     * Save the fraud result, score, risk level, and policy action to the newly created WC order.
     * If blocked, abort order creation immediately.
     *
     * @since 1.0.0
     * @param \WC_Order $order Newly created WooCommerce order.
     * @param array     $data  Posted checkout data.
     * @throws \Exception When action is 'block'.
     */
    public function save_order_result( \WC_Order $order, array $data = [] ): void {
        if ( ! $this->is_auto_check_enabled() ) {
            return;
        }

        $phone = preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() );
        if ( '' === $phone ) {
            $phone = $this->extract_phone( $data );
        }
        if ( '' === $phone ) {
            return;
        }

        $result = $this->get_or_check_phone( $phone, $order->get_id() );
        if ( is_wp_error( $result ) || empty( $result ) || ! is_array( $result ) ) {
            return;
        }

        $risk   = $result['risk_level'] ?? 'low';
        $score  = (int) ( $result['score'] ?? 0 );
        $action = $this->get_action_for_result( $score, $risk );

        $order->update_meta_data( '_odrplt_fraud_score', $score );
        $order->update_meta_data( '_odrplt_fraud_risk', $risk );
        $order->update_meta_data( '_odrplt_fraud_action', $action );
        $order->update_meta_data( '_odrplt_fraud_result', $result );
        $order->update_meta_data( '_odrplt_fraud_checked', '1' );

        $action_labels = [
            'approve'       => __( 'Approved', 'order-pilot' ),
            'manual_review' => __( 'Manual Review', 'order-pilot' ),
            'block'         => __( 'Blocked (Do Not Send)', 'order-pilot' ),
        ];
        $action_label = $action_labels[ $action ] ?? ucfirst( $action );

        $order->add_order_note(
            sprintf(
                /* translators: 1: risk level, 2: score, 3: action */
                __( 'Order Pilot: Automatic Fraud Check — Risk: %1$s (%2$d/100). Policy Action: %3$s.', 'order-pilot' ),
                ucfirst( $risk ),
                (int) $score,
                $action_label
            )
        );

        // Merge computed policy action into result data so the log table displays it correctly
        $result['action'] = $action;

        order_pilot()->db->insert_fraud_check( [
            'order_id'   => $order->get_id(),
            'phone'      => $phone,
            'score'      => $score,
            'risk_level' => $risk,
            'data'       => $result,
        ] );

        do_action( 'order_pilot_after_fraud_check', $order->get_id(), $result );

        // If policy action is block, prevent order from ever completing
        if ( 'block' === $action ) {
            $fraud           = function_exists( 'order_pilot' ) ? order_pilot()->settings->get_fraud() : [];
            $block_threshold = isset( $fraud['block_score_threshold'] ) ? (int) $fraud['block_score_threshold'] : 80;
            $block_msg       = esc_html( $this->get_block_message( $score, $block_threshold ) );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \Exception( $block_msg );
        }
    }

    /**
     * Filter fraud risk column display data for WooCommerce orders list.
     *
     * @since 1.0.0
     * @param array $data     [ 'risk' => string, 'score' => int|null ]
     * @param int   $order_id
     * @return array
     */
    public function filter_fraud_risk_column_data( array $data, int $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return $data;
        }

        $risk  = $order->get_meta( '_odrplt_fraud_risk', true );
        $score = $order->get_meta( '_odrplt_fraud_score', true );

        if ( ! empty( $risk ) ) {
            return [
                'risk'  => $risk,
                'score' => ( '' !== $score ) ? (int) $score : null,
            ];
        }

        return $data;
    }

    // ─── Session Helpers ──────────────────────────────────────────────────────

    /**
     * Store calculated result in WooCommerce customer session.
     *
     * @param string $phone
     * @param array  $result
     */
    private function store_session_result( string $phone, array $result ): void {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'odrplt_fraud_' . md5( $phone ), $result );
        }
    }

    /**
     * Get calculated result from WooCommerce customer session.
     *
     * @param string $phone
     * @return array|null
     */
    private function get_session_result( string $phone ) {
        return ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( 'odrplt_fraud_' . md5( $phone ) ) : null;
    }

    /**
     * Retrieve cached session result or calculate live.
     *
     * @param string $phone
     * @param int    $order_id
     * @return array|\WP_Error
     */
    private function get_or_check_phone( string $phone, int $order_id = 0 ) {
        $cached = $this->get_session_result( $phone );
        if ( is_array( $cached ) && isset( $cached['score'] ) ) {
            return $cached;
        }
        $result = $this->get_scorer()->calculate( $phone, $order_id );
        if ( ! is_wp_error( $result ) && is_array( $result ) ) {
            $this->store_session_result( $phone, $result );
        }
        return $result;
    }

    // ─── Meta Box ─────────────────────────────────────────────────────────────

    /**
     * Register Fraud Risk Assessment meta box on WooCommerce order edit screen.
     */
    public function register_meta_box(): void {
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'odrplt_fraud_panel',
                __( '🛡️ Order Pilot — Fraud Risk Assessment', 'order-pilot' ),
                [ $this, 'render_meta_box' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * Render the Fraud Risk Assessment widget on WC order edit screen.
     *
     * @param \WP_Post|\WC_Order $post_or_order
     */
    public function render_meta_box( $post_or_order ): void {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
        if ( ! $order instanceof \WC_Order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'order-pilot' ) . '</p>';
            return;
        }

        $order_id = $order->get_id();
        $phone    = $order->get_billing_phone();

        if ( empty( $phone ) ) {
            echo '<p style="color:#888;font-size:13px;">' . esc_html__( 'No billing phone provided for this order.', 'order-pilot' ) . '</p>';
            return;
        }

        // Check if cached/stored fraud data exists
        $score      = $order->get_meta( '_odrplt_fraud_score', true );
        $risk_level = $order->get_meta( '_odrplt_fraud_risk', true );
        $history    = order_pilot()->db->get_fraud_check_by_order( $order_id );

        $nonce      = wp_create_nonce( 'order_pilot_nonce' );
        $ajax_url   = admin_url( 'admin-ajax.php' );

        $risk_colors = [
            'low'    => [ 'bg' => '#e6f7ec', 'color' => '#008a2e', 'badge' => '🟢 ' . __( 'Low Risk', 'order-pilot' ) ],
            'medium' => [ 'bg' => '#fff8e6', 'color' => '#b27200', 'badge' => '🟡 ' . __( 'Medium Risk', 'order-pilot' ) ],
            'high'   => [ 'bg' => '#fde8e8', 'color' => '#d60000', 'badge' => '🔴 ' . __( 'High Risk', 'order-pilot' ) ],
        ];

        $current_risk = $risk_colors[ $risk_level ] ?? null;
        ?>
        <div id="odrplt-fraud-box" style="font-size:13px;">
            <?php if ( '' !== $score && $current_risk ) : ?>
                <div style="background:<?php echo esc_attr( $current_risk['bg'] ); ?>;color:<?php echo esc_attr( $current_risk['color'] ); ?>;padding:10px 12px;border-radius:6px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-weight:700;font-size:14px;"><?php echo esc_html( $current_risk['badge'] ); ?></span>
                    <span style="font-weight:700;font-size:14px;"><?php echo esc_html( $score ); ?>/100</span>
                </div>

                <?php 
                $bdc_summary = $history['data']['summary'] ?? [];
                $reports     = $history['data']['reports'] ?? [];
                ?>

                <?php if ( ! empty( $reports ) ) : ?>
                    <div style="background:#fff0f0;border:1px solid #ffcccc;color:#d60000;padding:8px 10px;border-radius:6px;margin-bottom:10px;font-size:12px;">
                        <strong>⚠️ <?php
                        /* translators: %d: Number of fraud reports. */
                        printf( esc_html__( '%d Fraud Report(s) Found!', 'order-pilot' ), count( $reports ) );
                        ?></strong>
                        <?php foreach ( $reports as $rep ) : ?>
                            <div style="margin-top:4px;font-size:11px;border-top:1px solid #ffe0e0;padding-top:3px;">
                                • <?php echo esc_html( $rep['courierName'] ?? 'Courier' ); ?>: <?php echo esc_html( $rep['details'] ?? $rep['name'] ?? '' ); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $bdc_summary['total_parcel'] ) ) : ?>
                    <div style="background:#f8f9fa;border:1px solid #eee;border-radius:6px;padding:8px 10px;margin-bottom:10px;font-size:12px;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span><?php esc_html_e( 'Total Deliveries:', 'order-pilot' ); ?></span>
                            <strong><?php echo esc_html( $bdc_summary['total_parcel'] ); ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span><?php esc_html_e( 'Success Rate:', 'order-pilot' ); ?></span>
                            <strong style="color:<?php echo ( ( $bdc_summary['success_ratio'] ?? 0 ) >= 75 ) ? '#008a2e' : ( ( ( $bdc_summary['success_ratio'] ?? 0 ) >= 50 ) ? '#b27200' : '#d60000' ); ?>">
                                <?php echo esc_html( $bdc_summary['success_ratio'] ?? 0 ); ?>%
                            </strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;color:#777;font-size:11px;">
                            <span><?php
                            /* translators: %d: Number of successfully delivered parcels. */
                            printf( esc_html__( 'Delivered: %d', 'order-pilot' ), (int) ( $bdc_summary['success_parcel'] ?? 0 ) );
                            ?></span>
                            <span><?php
                            /* translators: %d: Number of cancelled parcels. */
                            printf( esc_html__( 'Cancelled: %d', 'order-pilot' ), (int) ( $bdc_summary['cancelled_parcel'] ?? 0 ) );
                            ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $history['data']['breakdown'] ) ) : ?>
                    <div style="margin-bottom:12px;">
                        <strong style="font-size:11px;text-transform:uppercase;color:#777;display:block;margin-bottom:6px;">
                            <?php esc_html_e( 'Signal Breakdown', 'order-pilot' ); ?>
                        </strong>
                        <?php foreach ( $history['data']['breakdown'] as $key => $signal ) : ?>
                            <div style="display:flex;justify-content:space-between;padding:3px 0;font-size:12px;border-bottom:1px dashed #eee;">
                                <span><?php echo esc_html( $signal['label'] ?? ucfirst( $key ) ); ?></span>
                                <span style="font-weight:600;"><?php echo esc_html( $signal['score'] ); ?>% <?php esc_html_e( 'risk', 'order-pilot' ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php else : ?>
                <p style="color:#666;margin:0 0 10px;">
                    <?php esc_html_e( 'Fraud risk has not been calculated for this order yet.', 'order-pilot' ); ?>
                </p>
            <?php endif; ?>

            <div style="display:flex;gap:6px;align-items:center;margin-top:10px;">
                <button
                    type="button"
                    class="button button-secondary odrplt-run-fraud-btn"
                    data-order-id="<?php echo esc_attr( $order_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                >
                    🔄 <?php echo ( '' !== $score ) ? esc_html__( 'Re-Check Risk', 'order-pilot' ) : esc_html__( 'Check Fraud Risk', 'order-pilot' ); ?>
                </button>
                <span class="odrplt-fraud-status-msg" style="font-size:12px;color:#666;"></span>
            </div>
        </div>

        <script>
        (function() {
            var btn = document.querySelector('.odrplt-run-fraud-btn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var msg = document.querySelector('.odrplt-fraud-status-msg');
                btn.disabled = true;
                btn.textContent = '⏳ Checking…';
                if (msg) msg.textContent = '';

                var formData = new FormData();
                formData.append('action', 'odrplt_fraud_check');
                formData.append('order_id', btn.dataset.orderId);
                formData.append('nonce', btn.dataset.nonce);

                fetch(btn.dataset.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    btn.textContent = '🔄 Re-Check Risk';
                    if (res.success) {
                        location.reload();
                    } else {
                        if (msg) {
                            msg.style.color = '#c00';
                            msg.textContent = res.data && res.data.message ? res.data.message : 'Check failed';
                        }
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = '🔄 Re-Check Risk';
                    if (msg) {
                        msg.style.color = '#c00';
                        msg.textContent = 'Network error';
                    }
                });
            });
        })();
        </script>
        <?php
    }

    // ─── Auto-Check Hooks ─────────────────────────────────────────────────────

    /**
     * Run a fraud check when a new WooCommerce order is placed (if not already checked at checkout).
     *
     * @since 1.0.0
     * @param int $order_id
     */
    public function check_on_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $phone = $order->get_billing_phone();
        if ( ! $phone ) return;

        if ( $order->get_meta( '_odrplt_fraud_checked', true ) ) {
            return;
        }

        $this->run_check( $order_id, $phone );
    }

    /**
     * Run fraud check before a courier submission.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $courier_slug
     */
    public function check_before_courier( int $order_id, string $courier_slug ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $phone = $order->get_billing_phone();
        if ( ! $phone ) return;

        $result = $this->run_check( $order_id, $phone );

        if ( ! is_wp_error( $result ) && isset( $result['risk_level'] ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: risk level, 2: score */
                    __( 'Order Pilot: Fraud assessment before courier send — Risk: %1$s (Score: %2$d/100)', 'order-pilot' ),
                    strtoupper( $result['risk_level'] ),
                    $result['score']
                )
            );
        }
    }

    /**
     * Provide a fraud result for an order via the free plugin's filter.
     *
     * @since 1.0.0
     * @param mixed $current
     * @param int   $order_id
     * @return array|null
     */
    public function get_fraud_result( $current, int $order_id ): ?array {
        return order_pilot()->db->get_fraud_check_by_order( $order_id ) ?: $current;
    }

    // ─── Core Check ───────────────────────────────────────────────────────────

    /**
     * Run a fraud check and persist the result.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $phone
     * @return array|\WP_Error
     */
    public function run_check( int $order_id, string $phone ) {
        do_action( 'order_pilot_before_fraud_check', $order_id, $phone );

        $clean_phone = preg_replace( '/[^0-9]/', '', $phone );
        $result = $this->get_scorer()->calculate( $clean_phone, $order_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $risk   = $result['risk_level'] ?? 'low';
        $score  = (int) ( $result['score'] ?? 0 );
        $action = $this->get_action_for_result( $score, $risk );

        // Save to Database table
        order_pilot()->db->insert_fraud_check( [
            'order_id'   => $order_id,
            'phone'      => $result['phone'] ?? $clean_phone,
            'score'      => $score,
            'risk_level' => $risk,
            'data'       => $result,
        ] );

        // Store meta on the WC order object
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_odrplt_fraud_score', $score );
                $order->update_meta_data( '_odrplt_fraud_risk', $risk );
                $order->update_meta_data( '_odrplt_fraud_action', $action );
                $order->update_meta_data( '_odrplt_fraud_checked', '1' );
                $order->save_meta_data();
            }
        }

        do_action( 'order_pilot_after_fraud_check', $order_id, $result );

        return $result;
    }

    // ─── AJAX Handlers ────────────────────────────────────────────────────────

    /**
     * AJAX: Check a single order.
     */
    public function handle_ajax(): void {
        check_ajax_referer( 'order_pilot_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'order-pilot' ) ], 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'order-pilot' ) ], 404 );
        }

        $result = $this->run_check( $order_id, $order->get_billing_phone() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 422 );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Bulk fraud check.
     */
    public function handle_bulk_ajax(): void {
        check_ajax_referer( 'order_pilot_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'order-pilot' ) ], 403 );
        }

        $order_ids = array_map( 'absint', (array) ( $_POST['order_ids'] ?? [] ) );
        $results   = [];

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;

            $result = $this->run_check( $order_id, $order->get_billing_phone() );
            $results[ $order_id ] = is_wp_error( $result )
                ? [ 'error' => $result->get_error_message() ]
                : $result;
        }

        wp_send_json_success( $results );
    }

    // ─── REST Handlers ────────────────────────────────────────────────────────

    /**
     * REST: Check fraud for an order or arbitrary phone number.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_check_order( \WP_REST_Request $request ) {
        $order_id = (int) $request->get_param( 'order_id' );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'not_found', __( 'Order not found.', 'order-pilot' ), [ 'status' => 404 ] );
        }

        $result = $this->run_check( $order_id, $order->get_billing_phone() );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    /**
     * REST: Manual phone lookup.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_lookup_phone( \WP_REST_Request $request ) {
        $phone = sanitize_text_field( $request->get_param( 'phone' ) ?? '' );
        if ( empty( $phone ) ) {
            return new \WP_Error( 'invalid_phone', __( 'Phone number is required.', 'order-pilot' ), [ 'status' => 422 ] );
        }

        $clean_phone = preg_replace( '/[^0-9]/', '', $phone );
        $result = $this->get_scorer()->calculate( $clean_phone, 0 );

        if ( ! is_wp_error( $result ) && is_array( $result ) ) {
            $score  = (int) ( $result['score'] ?? 0 );
            $risk   = $result['risk_level'] ?? 'low';
            $action = $this->get_action_for_result( $score, $risk );

            // Merge computed policy action into result data for log display
            $result['action'] = $action;

            order_pilot()->db->insert_fraud_check( [
                'order_id'   => 0,
                'phone'      => $clean_phone,
                'score'      => $score,
                'risk_level' => $risk,
                'data'       => $result,
            ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * REST: Get fraud check history.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function rest_get_history( \WP_REST_Request $request ): \WP_REST_Response {
        $phone = sanitize_text_field( $request->get_param( 'phone' ) ?? '' );
        $limit = min( (int) ( $request->get_param( 'limit' ) ?? 50 ), 100 );

        $clean_phone = preg_replace( '/[^0-9]/', '', $phone );
        $history     = order_pilot()->db->get_fraud_history_by_phone( $clean_phone, $limit );

        return rest_ensure_response( $history );
    }

    /**
     * REST: DELETE /fraud/history
     * Delete all fraud check history records.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_delete_all_history( \WP_REST_Request $request ) {
        global $wpdb;
        $table = order_pilot()->db->table( ODRPLT_Database::TABLE_FRAUD_CHECKS );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $result = $wpdb->query( "TRUNCATE TABLE {$table}" );
        if ( false === $result ) {
            $result = $wpdb->query( "DELETE FROM {$table}" );
        }
        // phpcs:enable

        if ( false === $result ) {
            return new \WP_Error(
                'delete_failed',
                __( 'Failed to delete fraud check logs.', 'order-pilot' ),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [
            'deleted' => true,
            'count'   => $count,
            /* translators: %d: Number of deleted fraud check logs. */
            'message' => sprintf( __( 'Successfully deleted %d fraud check log(s).', 'order-pilot' ), $count ),
        ] );
    }

    /**
     * REST: DELETE /fraud/history/{id}
     * Delete a single fraud check log entry.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_delete_history_item( \WP_REST_Request $request ) {
        $id = absint( $request->get_param( 'id' ) );
        if ( $id <= 0 ) {
            return new \WP_Error( 'invalid_id', __( 'Invalid ID.', 'order-pilot' ), [ 'status' => 400 ] );
        }

        global $wpdb;
        $table = order_pilot()->db->table( ODRPLT_Database::TABLE_FRAUD_CHECKS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        if ( ! $deleted ) {
            return new \WP_Error( 'not_found', __( 'Fraud check log not found.', 'order-pilot' ), [ 'status' => 404 ] );
        }

        return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
    }
}
