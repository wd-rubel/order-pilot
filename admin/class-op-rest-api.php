<?php
/**
 * REST API Controller
 *
 * Registers all WP REST API endpoints under the `order-pilot/v1` namespace.
 * Pro endpoints are added via the `order_pilot_rest_routes` filter.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Rest_Api
 */
class OP_Rest_Api {

    /**
     * REST API namespace.
     */
    const NAMESPACE = 'order-pilot/v1';

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

    /**
     * @var OP_License
     */
    private OP_License $license;

    /**
     * @var OP_Courier_Manager
     */
    private OP_Courier_Manager $couriers;

    /**
     * @var OP_Logger
     */
    private OP_Logger $logger;

    /**
     * Constructor.
     *
     * @param OP_Settings        $settings
     * @param OP_License         $license
     * @param OP_Courier_Manager $couriers
     * @param OP_Logger          $logger
     */
    public function __construct(
        OP_Settings $settings,
        OP_License $license,
        OP_Courier_Manager $couriers,
        OP_Logger $logger
    ) {
        $this->settings = $settings;
        $this->license  = $license;
        $this->couriers = $couriers;
        $this->logger   = $logger;
    }

    // ─── Route Registration ───────────────────────────────────────────────────

    /**
     * Register all REST routes.
     * Hooked to `rest_api_init`.
     *
     * @since 1.0.0
     */
    public function register_routes(): void {
        $routes = $this->get_core_routes();

        /**
         * Filter: REST API routes for Order Pilot.
         *
         * Pro adds its own route definitions here.
         *
         * @since 1.0.0
         * @param array $routes Array of route definitions.
         */
        $routes = (array) apply_filters( 'order_pilot_rest_routes', $routes );

        foreach ( $routes as $route ) {
            if ( empty( $route['path'] ) || empty( $route['args'] ) ) {
                continue;
            }
            register_rest_route( self::NAMESPACE, $route['path'], $route['args'] );
        }
    }

    /**
     * Define the core (free) REST routes.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_core_routes(): array {
        return [

            // ── Status ──────────────────────────────────────────────────────
            [
                'path' => '/status',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_status' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],

            // ── Settings ────────────────────────────────────────────────────
            [
                'path' => '/settings',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_settings' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/settings',
                'args' => [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_settings' ],
                    'permission_callback' => [ $this, 'require_manage_options' ],
                ],
            ],

            // ── Couriers ─────────────────────────────────────────────────────
            [
                'path' => '/couriers',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_couriers' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/couriers/(?P<slug>[a-z0-9_-]+)/connect',
                'args' => [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'connect_courier' ],
                    'permission_callback' => [ $this, 'require_manage_options' ],
                ],
            ],
            [
                'path' => '/couriers/(?P<slug>[a-z0-9_-]+)/disconnect',
                'args' => [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'disconnect_courier' ],
                    'permission_callback' => [ $this, 'require_manage_options' ],
                ],
            ],
            [
                'path' => '/couriers/(?P<slug>[a-z0-9_-]+)/credentials',
                'args' => [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'save_courier_credentials' ],
                    'permission_callback' => [ $this, 'require_manage_options' ],
                ],
            ],

            // ── Orders ───────────────────────────────────────────────────────
            [
                'path' => '/orders/(?P<order_id>\\d+)/send',
                'args' => [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'send_order' ],
                    'permission_callback' => [ $this, 'require_shop_manager' ],
                    'args'                => [
                        'courier_slug' => [
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'extra_data' => [
                            'required'          => false,
                            'default'           => [],
                        ],
                    ],
                ],
            ],
            [
                'path' => '/orders/(?P<order_id>\\d+)/consignment',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_consignment' ],
                    'permission_callback' => [ $this, 'require_shop_manager' ],
                ],
            ],

            // ── Logs ─────────────────────────────────────────────────────────
            [
                'path' => '/logs/courier',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_courier_logs' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/logs/tracking',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_tracking_logs' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],

            // ── Dashboard ─────────────────────────────────────────────────────
            [
                'path' => '/dashboard',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_dashboard_data' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
        ];
    }

    // ─── Endpoint Handlers ────────────────────────────────────────────────────

    /**
     * GET /status — Plugin status overview.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
        return rest_ensure_response( [
            'version'           => ORDER_PILOT_VERSION,
            'is_pro'            => $this->license->is_pro(),
            'license_status'    => $this->license->get_status(),
            'wc_version'        => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
            'connected_couriers' => count( $this->couriers->get_connected() ),
            'max_couriers'      => $this->license->max_couriers(),
        ] );
    }

    /**
     * GET /settings
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $data = [
            'general' => $this->settings->get_general(),
            'pixel'   => $this->settings->get_pixel(),
        ];

        if ( $this->license->is_pro() ) {
            $data['capi'] = $this->settings->get_capi();
        }

        /**
         * Filter: settings data returned by the REST API.
         *
         * @since 1.0.0
         * @param array $data
         */
        return rest_ensure_response( apply_filters( 'order_pilot_rest_settings', $data ) );
    }

    /**
     * POST/PUT /settings
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();
        $tab  = sanitize_key( $body['tab'] ?? 'general' );

        switch ( $tab ) {
            case 'pixel':
                $pixel = $body['pixel'] ?? [];
                $this->settings->update_pixel( $this->sanitize_pixel_settings( $pixel ) );
                break;

            case 'general':
            default:
                $general = $body['general'] ?? [];
                $this->settings->update_general( $this->sanitize_general_settings( $general ) );
                break;
        }

        /**
         * Fires after settings are updated via REST.
         *
         * @since 1.0.0
         * @param string $tab
         * @param array  $body Request body.
         */
        do_action( 'order_pilot_settings_saved', $tab, $body );

        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * GET /couriers
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_couriers( \WP_REST_Request $request ): \WP_REST_Response {
        $all_couriers     = $this->couriers->get_all();
        $connected_slugs  = $this->settings->get_connected_couriers();
        $data             = [];

        foreach ( $all_couriers as $slug => $courier ) {
            $data[] = [
                'slug'        => $slug,
                'name'        => $courier->get_name(),
                'connected'   => in_array( $slug, $connected_slugs, true ),
                'configured'  => $courier->is_configured(),
                'fields'      => $courier->get_credential_fields(),
            ];
        }

        return rest_ensure_response( $data );
    }

    /**
     * POST /couriers/{slug}/connect
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function connect_courier( \WP_REST_Request $request ) {
        $slug   = $request->get_param( 'slug' );
        $result = $this->couriers->connect( $slug );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( [ 'success' => true, 'slug' => $slug ] );
    }

    /**
     * DELETE /couriers/{slug}/disconnect
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function disconnect_courier( \WP_REST_Request $request ): \WP_REST_Response {
        $slug = $request->get_param( 'slug' );
        $this->couriers->disconnect( $slug );
        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * POST /couriers/{slug}/credentials
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function save_courier_credentials( \WP_REST_Request $request ): \WP_REST_Response {
        $slug  = $request->get_param( 'slug' );
        $creds = $request->get_json_params();
        $creds = array_map( 'sanitize_text_field', (array) $creds );

        $this->settings->save_courier_credentials( $slug, $creds );

        // Reload credentials on the courier instance.
        $this->couriers->reset();

        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * POST /orders/{order_id}/send
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function send_order( \WP_REST_Request $request ) {
        $order_id     = (int) $request->get_param( 'order_id' );
        $courier_slug = $request->get_param( 'courier_slug' );
        $extra_data   = (array) ( $request->get_param( 'extra_data' ) ?? [] );

        $result = $this->couriers->send_order( $order_id, $courier_slug, $extra_data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    /**
     * GET /orders/{order_id}/consignment
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_consignment( \WP_REST_Request $request ) {
        $order_id = (int) $request->get_param( 'order_id' );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'not_found', __( 'Order not found.', 'order-pilot' ), [ 'status' => 404 ] );
        }

        return rest_ensure_response( [
            'order_id'       => $order_id,
            'courier'        => $order->get_meta( '_op_courier', true ),
            'consignment_id' => $order->get_meta( '_op_consignment_id', true ),
            'tracking_id'    => $order->get_meta( '_op_tracking_id', true ),
        ] );
    }

    /**
     * GET /logs/courier
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_courier_logs( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'order_id' => (int) $request->get_param( 'order_id' ),
            'courier'  => sanitize_key( $request->get_param( 'courier' ) ?? '' ),
            'status'   => sanitize_text_field( $request->get_param( 'status' ) ?? '' ),
            'limit'    => min( (int) ( $request->get_param( 'limit' ) ?? 50 ), 200 ),
            'offset'   => (int) ( $request->get_param( 'offset' ) ?? 0 ),
        ];

        return rest_ensure_response( $this->logger->get_courier_logs( array_filter( $args ) ) );
    }

    /**
     * GET /logs/tracking
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_tracking_logs( \WP_REST_Request $request ): \WP_REST_Response {
        $args = [
            'order_id'   => (int) $request->get_param( 'order_id' ),
            'event_name' => sanitize_text_field( $request->get_param( 'event_name' ) ?? '' ),
            'channel'    => sanitize_text_field( $request->get_param( 'channel' ) ?? '' ),
            'limit'      => min( (int) ( $request->get_param( 'limit' ) ?? 50 ), 200 ),
            'offset'     => (int) ( $request->get_param( 'offset' ) ?? 0 ),
        ];

        return rest_ensure_response( $this->logger->get_tracking_logs( array_filter( $args ) ) );
    }

    /**
     * GET /dashboard — Summary stats for the React dashboard.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_dashboard_data( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $today      = current_time( 'Y-m-d' );
        $this_month = current_time( 'Y-m' ) . '%';

        // Orders this month (WC).
        $total_orders = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE date_created_gmt LIKE %s",
            $this_month
        ) );

        // Delivered this month.
        $delivered = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}op_consignments WHERE status = %s AND created_at LIKE %s",
            OP_Courier_Manager::STATUS_DELIVERED,
            $this_month
        ) );

        // Total consignments this month.
        $total_consignments = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}op_consignments WHERE created_at LIKE %s",
            $this_month
        ) );

        $delivery_rate = $total_consignments > 0
            ? round( ( $delivered / $total_consignments ) * 100, 1 )
            : 0;

        $data = [
            'total_orders'      => $total_orders,
            'total_consignments' => $total_consignments,
            'delivered'         => $delivered,
            'delivery_rate'     => $delivery_rate,
            'connected_couriers' => count( $this->couriers->get_connected() ),
        ];

        /**
         * Filter: dashboard summary data.
         *
         * Pro adds: fraud_orders, meta_events, revenue.
         *
         * @since 1.0.0
         * @param array $data
         */
        return rest_ensure_response( apply_filters( 'order_pilot_dashboard_data', $data ) );
    }

    // ─── Permission Callbacks ─────────────────────────────────────────────────

    /**
     * Require the user to be a WooCommerce admin.
     *
     * @return bool|\WP_Error
     */
    public function require_admin() {
        return current_user_can( 'manage_woocommerce' )
            ? true
            : new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'order-pilot' ), [ 'status' => 403 ] );
    }

    /**
     * Require the user to have manage_options capability.
     *
     * @return bool|\WP_Error
     */
    public function require_manage_options() {
        return current_user_can( 'manage_options' )
            ? true
            : new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'order-pilot' ), [ 'status' => 403 ] );
    }

    /**
     * Require shop manager or higher.
     *
     * @return bool|\WP_Error
     */
    public function require_shop_manager() {
        return current_user_can( 'edit_shop_orders' )
            ? true
            : new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'order-pilot' ), [ 'status' => 403 ] );
    }

    // ─── Sanitizers ───────────────────────────────────────────────────────────

    /**
     * Sanitize general settings from REST input.
     *
     * @param array $data
     * @return array
     */
    private function sanitize_general_settings( array $data ): array {
        return [
            'currency'        => sanitize_text_field( $data['currency'] ?? '' ),
            'enable_courier'  => (bool) ( $data['enable_courier'] ?? true ),
            'enable_pixel'    => (bool) ( $data['enable_pixel'] ?? false ),
            'enable_fraud'    => (bool) ( $data['enable_fraud'] ?? false ),
            'log_retention'   => absint( $data['log_retention'] ?? 30 ),
        ];
    }

    /**
     * Sanitize pixel settings from REST input.
     *
     * @param array $data
     * @return array
     */
    private function sanitize_pixel_settings( array $data ): array {
        return [
            'pixel_id'        => sanitize_text_field( $data['pixel_id'] ?? '' ),
            'purchase_trigger' => sanitize_key( $data['purchase_trigger'] ?? 'order_created' ),
            'events'          => array_map( 'boolval', (array) ( $data['events'] ?? [] ) ),
        ];
    }
}
