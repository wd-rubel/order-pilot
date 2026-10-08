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
 * Class ODRPLT_Rest_Api
 */
class ODRPLT_Rest_Api {

    /**
     * REST API namespace.
     */
    const NAMESPACE = 'order-pilot/v1';

    /**
     * @var ODRPLT_Settings
     */
    private ODRPLT_Settings $settings;

    /**
     * @var ODRPLT_License
     */
    private ODRPLT_License $license;

    /**
     * @var ODRPLT_Courier_Manager
     */
    private ODRPLT_Courier_Manager $couriers;

    /**
     * @var ODRPLT_Logger
     */
    private ODRPLT_Logger $logger;

    /**
     * @var ODRPLT_Fraud_Checker
     */
    private ODRPLT_Fraud_Checker $fraud;

    /**
     * Constructor.
     *
     * @param ODRPLT_Settings           $settings
     * @param ODRPLT_License            $license
     * @param ODRPLT_Courier_Manager    $couriers
     * @param ODRPLT_Logger             $logger
     * @param ODRPLT_Fraud_Checker|null $fraud
     */
    public function __construct(
        ODRPLT_Settings $settings,
        ODRPLT_License $license,
        ODRPLT_Courier_Manager $couriers,
        ODRPLT_Logger $logger,
        ?ODRPLT_Fraud_Checker $fraud = null
    ) {
        $this->settings = $settings;
        $this->license  = $license;
        $this->couriers = $couriers;
        $this->logger   = $logger;
        $this->fraud    = $fraud ?? ( function_exists( 'order_pilot' ) && isset( order_pilot()->fraud ) ? order_pilot()->fraud : new ODRPLT_Fraud_Checker() );
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
                'path' => '/orders',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_orders' ],
                    'permission_callback' => [ $this, 'require_shop_manager' ],
                    'args'                => [
                        'page'     => [ 'default' => 1,    'sanitize_callback' => 'absint' ],
                        'per_page' => [ 'default' => 20,   'sanitize_callback' => 'absint' ],
                        'status'   => [ 'default' => '',   'sanitize_callback' => 'sanitize_key' ],
                        'courier'  => [ 'default' => '',   'sanitize_callback' => 'sanitize_key' ],
                        'search'   => [ 'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
                    ],
                ],
            ],
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
                            'required' => false,
                            'default'  => [],
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
            [
                'path' => '/orders/(?P<order_id>\\d+)/sync-status',
                'args' => [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'sync_order_status' ],
                    'permission_callback' => [ $this, 'require_shop_manager' ],
                ],
            ],

            // ── Tracking Dashboard ────────────────────────────────────────────
            [
                'path' => '/tracking/stats',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_tracking_stats' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/tracking/logs',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_tracking_logs_paged' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                    'args'                => [
                        'page'       => [ 'default' => 1,  'sanitize_callback' => 'absint' ],
                        'per_page'   => [ 'default' => 20, 'sanitize_callback' => 'absint' ],
                        'event_name' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                        'channel'    => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
                    ],
                ],
            ],
            [
                'path' => '/tracking/logs',
                'args' => [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_tracking_logs' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/tracking/logs/count',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_tracking_logs_count' ],
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

            // ── Fraud Checker ─────────────────────────────────────────────────
            [
                'path' => '/fraud/check/(?P<order_id>\\d+)',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'rest_fraud_check_order' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/fraud/lookup',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'rest_fraud_lookup_phone' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/fraud/history',
                'args' => [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'rest_fraud_get_history' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/fraud/history',
                'args' => [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'rest_fraud_delete_all_history' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
            [
                'path' => '/fraud/history/(?P<id>\\d+)',
                'args' => [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'rest_fraud_delete_history_item' ],
                    'permission_callback' => [ $this, 'require_admin' ],
                ],
            ],
        ];
    }

    // ─── Fraud Checker Endpoint Handlers ──────────────────────────────────────

    /**
     * GET /fraud/check/{order_id} — Run fraud check on an existing order.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_fraud_check_order( \WP_REST_Request $request ) {
        return $this->fraud->rest_check_order( $request );
    }

    /**
     * GET /fraud/lookup?phone={phone} — Manual fraud check for a phone number.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_fraud_lookup_phone( \WP_REST_Request $request ) {
        return $this->fraud->rest_lookup_phone( $request );
    }

    /**
     * GET /fraud/history — Get fraud check history.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function rest_fraud_get_history( \WP_REST_Request $request ): \WP_REST_Response {
        return $this->fraud->rest_get_history( $request );
    }

    /**
     * DELETE /fraud/history — Delete all fraud check history.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_fraud_delete_all_history( \WP_REST_Request $request ) {
        return $this->fraud->rest_delete_all_history( $request );
    }

    /**
     * DELETE /fraud/history/{id} — Delete single fraud check record.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_fraud_delete_history_item( \WP_REST_Request $request ) {
        return $this->fraud->rest_delete_history_item( $request );
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
            'general'  => $this->settings->get_general(),
            'couriers' => [
                'enable_courier' => $this->settings->is_courier_enabled(),
            ],
            'pixel'    => $this->settings->get_pixel(),
            'fraud'    => $this->settings->get_fraud(),
        ];

        if ( $this->license->is_pro() ) {
            $data['capi']   = $this->settings->get_capi();
            $data['ga4']    = $this->settings->get_ga4();
            $data['tiktok'] = $this->settings->get_tiktok();
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
        try {
            $body = $request->get_json_params();
            $tab  = sanitize_key( $body['tab'] ?? 'general' );

            switch ( $tab ) {
                case 'couriers':
                    $couriers = $body['couriers'] ?? $body['settings'] ?? [];
                    if ( isset( $couriers['enable_courier'] ) ) {
                        $this->settings->update_general( [
                            'enable_courier' => (bool) $couriers['enable_courier'],
                        ] );
                    }
                    break;

                case 'pixel':
                    $pixel = $body['pixel'] ?? $body['settings'] ?? [];
                    $this->settings->update_pixel( $this->sanitize_pixel_settings( $pixel ) );
                    break;

                case 'ga4':
                    $ga4 = $body['ga4'] ?? $body['settings'] ?? [];
                    $this->settings->update_ga4( $this->sanitize_ga4_settings( $ga4 ) );
                    break;

                case 'tiktok':
                    $tiktok = $body['tiktok'] ?? $body['settings'] ?? [];
                    $this->settings->update_tiktok( $this->sanitize_tiktok_settings( $tiktok ) );
                    break;

                case 'fraud':
                    $fraud = $body['fraud'] ?? $body['settings'] ?? [];
                    $this->settings->update_fraud( [
                        'enable_fraud'          => ! empty( $fraud['enable_fraud'] ),
                        'api_key'               => sanitize_text_field( $fraud['api_key'] ?? '' ),
                        'auto_check'            => ! empty( $fraud['auto_check'] ),
                        'auto_block_ip'         => ! empty( $fraud['auto_block_ip'] ),
                        'block_score_threshold' => isset( $fraud['block_score_threshold'] ) ? min( 100, max( 1, (int) $fraud['block_score_threshold'] ) ) : 80,
                        'block_message'         => sanitize_textarea_field( $fraud['block_message'] ?? '' ),
                    ] );
                    break;

                case 'capi':
                    $capi = $body['capi'] ?? $body['settings'] ?? [];
                    $this->settings->update_capi( [
                        'enable_capi'   => ! empty( $capi['enable_capi'] ),
                        'access_token'  => sanitize_text_field( $capi['access_token'] ?? '' ),
                        'pixel_id'      => sanitize_text_field( $capi['pixel_id'] ?? '' ),
                        'test_code'     => sanitize_text_field( $capi['test_code'] ?? '' ),
                        'deduplication' => ! empty( $capi['deduplication'] ),
                    ] );
                    break;

                case 'general':
                default:
                    $general = $body['general'] ?? $body['settings'] ?? [];
                    $this->settings->update_general( $this->sanitize_general_settings( $general ) );
                    break;
            }

            do_action( 'order_pilot_settings_saved', $tab, $body );

            // If a warning/notice was printed during the request, it will corrupt the JSON response.
            // Clean the output buffer to ensure we only send JSON.
            if ( ob_get_length() ) {
                ob_clean();
            }

            return rest_ensure_response( [ 'success' => true ] );
        } catch ( \Throwable $e ) {
            return rest_ensure_response( new \WP_Error( 'fatal_error', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), [ 'status' => 500 ] ) );
        }
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
            $creds       = $this->settings->get_courier_credentials( $slug );
            $data[] = [
                'slug'        => $slug,
                'name'        => $courier->get_name(),
                'connected'   => in_array( $slug, $connected_slugs, true ),
                'configured'  => $courier->is_configured(),
                'fields'      => $courier->get_credential_fields(),
                'credentials' => $creds,
                // Expose the saved environment value so the UI can show a badge.
                'environment' => $creds['environment'] ?? null,
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
     * Saves the courier API credentials and automatically connects the courier
     * (adds it to the active connected list) so the UI reflects the correct state.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function save_courier_credentials( \WP_REST_Request $request ) {
        $slug  = $request->get_param( 'slug' );
        $creds = $request->get_json_params();
        $creds = array_map( 'sanitize_text_field', (array) $creds );

        // Persist credentials.
        $this->settings->save_courier_credentials( $slug, $creds );

        // Reload courier instances so is_configured() picks up the new credentials.
        $this->couriers->reset();

        // Auto-connect: add to the connected list now that credentials are saved.
        // This is the definitive place where "connected" state is set.
        $connect_result = $this->couriers->connect( $slug );

        if ( is_wp_error( $connect_result ) ) {
            // Credentials were saved but connect failed (e.g. limit reached).
            // Return the error so the UI can show it, but credentials are kept.
            return $connect_result;
        }

        return rest_ensure_response( [
            'success'   => true,
            'connected' => true,
            'slug'      => $slug,
        ] );
    }

    /**
     * GET /orders — Paginated WC order list with merged courier data.
     *
     * Query params: page, per_page, status (WC status), courier (slug), search.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_orders( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $page                  = max( 1, (int) $request->get_param( 'page' ) );
        $per_page              = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
        $status                = sanitize_key( $request->get_param( 'status' ) ?? '' );
        $courier               = sanitize_key( $request->get_param( 'courier' ) ?? '' );
        $courier_status_filter = sanitize_key( $request->get_param( 'courier_status' ) ?? '' );
        $search                = sanitize_text_field( $request->get_param( 'search' ) ?? '' );

        // Build WC order query args.
        $query_args = [
            'limit'    => $per_page,
            'page'     => $page,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
        ];

        if ( ! empty( $status ) && 'all' !== $status ) {
            $clean_status = str_replace( 'wc-', '', $status );
            $query_args['status'] = $clean_status;
        } else {
            $query_args['status'] = 'all';
        }

        if ( ! empty( $search ) ) {
            $query_args['s'] = $search;
        }

        $results   = wc_get_orders( $query_args );
        $wc_orders = is_object( $results ) && isset( $results->orders ) ? $results->orders : (array) $results;
        $total     = is_object( $results ) && isset( $results->total ) ? $results->total : count( $wc_orders );
        $max_pages = is_object( $results ) && isset( $results->max_num_pages ) ? $results->max_num_pages : (int) ceil( $total / $per_page );

        // Collect order IDs to batch-fetch courier data.
        $order_ids = array_map( fn( $o ) => $o->get_id(), $wc_orders );

        // Batch fetch consignment rows.
        $consignment_map = [];
        if ( ! empty( $order_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $wpdb->prepare( "SELECT order_id, courier, consignment_id, tracking_id, status FROM {$wpdb->prefix}odrplt_consignments WHERE order_id IN ($placeholders)", ...$order_ids ),
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $consignment_map[ (int) $row['order_id'] ] = $row;
            }
        }

        // Build response list.
        $data = [];
        foreach ( $wc_orders as $order ) {
            $oid         = $order->get_id();
            $consignment = $consignment_map[ $oid ] ?? null;

            // Get courier info from DB consignment table or fallback to WC order meta (including legacy prefix)
            $courier_slug   = ! empty( $consignment['courier'] ) ? $consignment['courier'] : ( $order->get_meta( '_odrplt_courier', true ) ?: ( $order->get_meta( '_op_courier', true ) ?: null ) );
            $consignment_id = ! empty( $consignment['consignment_id'] ) ? $consignment['consignment_id'] : ( $order->get_meta( '_odrplt_consignment_id', true ) ?: ( $order->get_meta( '_op_consignment_id', true ) ?: null ) );
            $tracking_id    = ! empty( $consignment['tracking_id'] ) ? $consignment['tracking_id'] : ( $order->get_meta( '_odrplt_tracking_id', true ) ?: ( $order->get_meta( '_op_tracking_id', true ) ?: null ) );
            $c_status       = ! empty( $consignment['status'] ) ? $consignment['status'] : ( $courier_slug ? ODRPLT_Courier_Manager::STATUS_PENDING : null );

            // Self-heal: populate missing DB row if meta exists
            if ( $courier_slug && $consignment_id && ! $consignment ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->replace(
                    $wpdb->prefix . 'odrplt_consignments',
                    [
                        'order_id'       => $oid,
                        'courier'        => $courier_slug,
                        'consignment_id' => $consignment_id,
                        'tracking_id'    => $tracking_id ?: $consignment_id,
                        'status'         => $c_status ?: 'pending',
                        'created_at'     => current_time( 'mysql' ),
                        'last_synced_at' => current_time( 'mysql' ),
                    ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
                );
            }

            if ( $courier && $courier_slug !== $courier ) {
                continue;
            }

            if ( $courier_status_filter && $c_status !== $courier_status_filter ) {
                continue;
            }

            $raw_score = $order->get_meta( '_odrplt_fraud_score', true );
            if ( '' === $raw_score || false === $raw_score ) {
                $raw_score = $order->get_meta( '_op_fraud_score', true );
            }

            $raw_risk = $order->get_meta( '_odrplt_fraud_risk', true );
            if ( empty( $raw_risk ) ) {
                $raw_risk = $order->get_meta( '_op_fraud_risk', true );
            }

            $data[] = [
                'id'             => $oid,
                'number'         => $order->get_order_number(),
                'date'           => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i' ) : '',
                'status'         => $order->get_status(),
                'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'customer_phone' => $order->get_billing_phone(),
                'total'          => (float) $order->get_total(),
                'currency'       => $order->get_currency(),
                'courier'        => $courier_slug,
                'consignment_id' => $consignment_id,
                'tracking_id'    => $tracking_id,
                'courier_status' => $c_status,
                'fraud_score'    => ( $raw_score !== '' && $raw_score !== false && null !== $raw_score ) ? (int) $raw_score : null,
                'fraud_risk'     => $raw_risk ?: null,
                'edit_url'       => get_edit_post_link( $oid, 'raw' ) ?: admin_url( 'post.php?post=' . $oid . '&action=edit' ),
            ];
        }

        return rest_ensure_response( [
            'orders'      => $data,
            'total'       => (int) $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) $max_pages,
        ] );
    }

    /**
     * POST /orders/{order_id}/sync-status
     *
     * Calls the courier API live to get the latest delivery status,
     * updates the odrplt_consignments table, and returns the updated status.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function sync_order_status( \WP_REST_Request $request ) {
        global $wpdb;

        $order_id = (int) $request->get_param( 'order_id' );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'not_found', __( 'Order not found.', 'order-pilot' ), [ 'status' => 404 ] );
        }

        $courier_slug   = $order->get_meta( '_odrplt_courier', true );
        $consignment_id = $order->get_meta( '_odrplt_consignment_id', true );

        if ( ! $courier_slug || ! $consignment_id ) {
            return new \WP_Error(
                'no_consignment',
                __( 'This order has not been sent to a courier yet.', 'order-pilot' ),
                [ 'status' => 422 ]
            );
        }

        $courier = $this->couriers->get( $courier_slug );

        if ( ! $courier ) {
            return new \WP_Error( 'invalid_courier', __( 'Courier not found.', 'order-pilot' ), [ 'status' => 422 ] );
        }

        // Call the live courier API.
        $result = $courier->get_status( $consignment_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $normalized_status = $result['status'];

        // Update the odrplt_consignments table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->prefix . 'odrplt_consignments',
            [
                'status'         => $normalized_status,
                'last_synced_at' => current_time( 'mysql' ),
            ],
            [ 'order_id' => $order_id, 'courier' => $courier_slug ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        /**
         * Fires after a courier status has been synced.
         *
         * @since 1.0.0
         * @param int    $order_id
         * @param string $courier_slug
         * @param string $normalized_status
         * @param array  $result Full courier response.
         */
        do_action( 'order_pilot_courier_status_synced', $order_id, $courier_slug, $normalized_status, $result );

        return rest_ensure_response( [
            'success'        => true,
            'order_id'       => $order_id,
            'courier'        => $courier_slug,
            'consignment_id' => $consignment_id,
            'status'         => $normalized_status,
            'raw_status'     => $result['raw_status'] ?? '',
        ] );
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
            'courier'        => $order->get_meta( '_odrplt_courier', true ),
            'consignment_id' => $order->get_meta( '_odrplt_consignment_id', true ),
            'tracking_id'    => $order->get_meta( '_odrplt_tracking_id', true ),
        ] );
    }

    /**
     * GET /tracking/stats — Summary stats for the Tracking dashboard.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_tracking_stats( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'odrplt_tracking_logs' );

        // Auto-heal: Ensure table exists if not yet created.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            if ( class_exists( 'ODRPLT_Activator' ) ) {
                ODRPLT_Activator::create_tables();
            }
        }

        $now_ts         = current_time( 'timestamp' );
        $since          = wp_date( 'Y-m-d H:i:s', $now_ts - ( 30 * DAY_IN_SECONDS ) );
        $seven_days_ago = wp_date( 'Y-m-d 00:00:00', $now_ts - ( 6 * DAY_IN_SECONDS ) );

        // ── Stat card totals (30d) ──────────────────────────────────────────────
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_pixel = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel IN ('pixel', 'browser') AND created_at >= %s", $since ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_capi = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel IN ('capi', 'server') AND created_at >= %s", $since ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_tiktok = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel = 'tiktok' AND created_at >= %s", $since ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_ga4 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel = 'ga4' AND created_at >= %s", $since ) );

        // Deduplicated pairs = distinct event_ids that appear in BOTH Meta channels.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $dedup_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT t1.event_id) FROM {$table} t1 INNER JOIN {$table} t2 ON t1.event_id = t2.event_id AND t1.channel IN ('pixel', 'browser') AND t2.channel IN ('capi', 'server') WHERE t1.event_id IS NOT NULL AND t1.event_id <> '' AND t1.created_at >= %s", $since ) );

        // ── 7-day daily breakdown across all channels (single query) ────────────
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $daily_rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) as day, channel, COUNT(*) as count FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at), channel", $seven_days_ago ), ARRAY_A );

        $daily_map = [];
        for ( $i = 6; $i >= 0; $i-- ) {
            $day = wp_date( 'Y-m-d', $now_ts - ( $i * DAY_IN_SECONDS ) );
            $daily_map[ $day ] = [
                'day'    => $day,
                'pixel'  => 0,
                'capi'   => 0,
                'tiktok' => 0,
                'ga4'    => 0,
            ];
        }

        foreach ( (array) $daily_rows as $row ) {
            $d  = $row['day'];
            $ch = $row['channel'];
            if ( 'browser' === $ch ) {
                $ch = 'pixel';
            } elseif ( 'server' === $ch ) {
                $ch = 'capi';
            }
            $c  = (int) $row['count'];
            if ( isset( $daily_map[ $d ] ) && isset( $daily_map[ $d ][ $ch ] ) ) {
                $daily_map[ $d ][ $ch ] += $c;
            }
        }
        $daily = array_values( $daily_map );

        // ── Event breakdown table (30d, single query) ───────────────────────────
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $breakdown_rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_name, channel, COUNT(*) as count FROM {$table} WHERE created_at >= %s GROUP BY event_name, channel ORDER BY event_name ASC", $since ), ARRAY_A );

        $breakdown_map = [];
        foreach ( (array) $breakdown_rows as $row ) {
            $ev = $row['event_name'];
            $ch = $row['channel'];
            if ( 'browser' === $ch ) {
                $ch = 'pixel';
            } elseif ( 'server' === $ch ) {
                $ch = 'capi';
            }
            $c  = (int) $row['count'];

            if ( ! isset( $breakdown_map[ $ev ] ) ) {
                $breakdown_map[ $ev ] = [
                    'event'  => $ev,
                    'pixel'  => 0,
                    'capi'   => 0,
                    'tiktok' => 0,
                    'ga4'    => 0,
                    'total'  => 0,
                ];
            }
            if ( isset( $breakdown_map[ $ev ][ $ch ] ) ) {
                $breakdown_map[ $ev ][ $ch ] += $c;
            }
            $breakdown_map[ $ev ]['total'] += $c;
        }
        $breakdown = array_values( $breakdown_map );

        return rest_ensure_response( [
            'total_events' => $total_events,
            'total_pixel'  => $total_pixel,
            'total_capi'   => $total_capi,
            'total_tiktok' => $total_tiktok,
            'total_ga4'    => $total_ga4,
            'dedup_count'  => $dedup_count,
            'daily'        => $daily,
            'breakdown'    => $breakdown,
        ] );
    }

    /**
     * GET /tracking/logs — Paginated tracking logs for the Tracking dashboard.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_tracking_logs_paged( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table    = esc_sql( $wpdb->prefix . 'odrplt_tracking_logs' );
        $per_page = min( absint( $request->get_param( 'per_page' ) ?: 20 ), 100 );
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where    = [];
        $params   = [];

        $event_name = sanitize_text_field( $request->get_param( 'event_name' ) ?: '' );
        $channel    = sanitize_key( $request->get_param( 'channel' ) ?: '' );

        if ( $event_name ) {
            $where[]  = 'event_name = %s';
            $params[] = $event_name;
        }
        if ( $channel ) {
            if ( 'pixel' === $channel || 'browser' === $channel ) {
                $where[] = "channel IN ('pixel', 'browser')";
            } elseif ( 'capi' === $channel || 'server' === $channel ) {
                $where[] = "channel IN ('capi', 'server')";
            } else {
                $where[]  = 'channel = %s';
                $params[] = $channel;
            }
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Count total matching rows.
        if ( $params ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $params ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
        }
        $total_pages  = max( 1, (int) ceil( $total / $per_page ) );

        // Fetch the page.
        $params[] = $per_page;
        $params[] = $offset;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $logs = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", $params ), ARRAY_A );

        foreach ( $logs as &$log ) {
            if ( 'browser' === $log['channel'] ) {
                $log['channel'] = 'pixel';
            } elseif ( 'server' === $log['channel'] ) {
                $log['channel'] = 'capi';
            }

            // Derive dynamic telemetry status and API response from processing results and payload.
            $http_code    = isset( $log['http_code'] ) && '' !== $log['http_code'] ? (int) $log['http_code'] : null;
            $payload_data = null;
            if ( ! empty( $log['payload'] ) ) {
                $payload_data = is_string( $log['payload'] ) ? json_decode( $log['payload'], true ) : $log['payload'];
            }

            $status        = 'Sent';
            $api_response  = 'Success';
            $error_details = null;

            if ( ! empty( $payload_data['skipped'] ) || ( isset( $payload_data['status'] ) && 'skipped' === $payload_data['status'] ) || ! empty( $payload_data['skip_reason'] ) || 304 === $http_code ) {
                $status       = 'Skipped';
                $api_response = ! empty( $payload_data['skip_reason'] ) ? $payload_data['skip_reason'] : ( $payload_data['reason'] ?? 'Event was not sent due to configuration or conditions.' );
            } elseif ( ( isset( $payload_data['status'] ) && 'pending' === $payload_data['status'] ) || ! empty( $payload_data['pending'] ) || 202 === $http_code || 102 === $http_code || ( null === $http_code && 'pixel' !== $log['channel'] && empty( $payload_data ) ) ) {
                $status       = 'Pending';
                $api_response = 'Event is waiting to be processed.';
            } elseif ( ( null !== $http_code && ( $http_code >= 400 || 0 === $http_code ) ) || ! empty( $payload_data['error'] ) || ! empty( $payload_data['errors'] ) || ( isset( $payload_data['success'] ) && false === $payload_data['success'] ) ) {
                $status = 'Failed';
                if ( ! empty( $payload_data['error']['message'] ) ) {
                    $error_details = $payload_data['error']['message'];
                } elseif ( ! empty( $payload_data['error'] ) && is_string( $payload_data['error'] ) ) {
                    $error_details = $payload_data['error'];
                } elseif ( ! empty( $payload_data['errors'] ) ) {
                    $error_details = is_array( $payload_data['errors'] ) ? implode( '; ', $payload_data['errors'] ) : (string) $payload_data['errors'];
                } elseif ( ! empty( $payload_data['message'] ) ) {
                    $error_details = $payload_data['message'];
                } else {
                    $error_details = $http_code ? sprintf( 'API request failed with HTTP %d.', $http_code ) : 'API request failed / connection error.';
                }
                $api_response = $error_details;
            } else {
                $status       = 'Sent';
                $api_response = 'Success';
            }

            $log['status']        = $status;
            $log['api_response']  = $api_response;
            $log['error_details'] = $error_details;
        }
        unset( $log );

        return rest_ensure_response( [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        ] );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_orders = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE date_created_gmt LIKE %s",
                $this_month
            )
        );

        // Delivered this month.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $delivered = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}odrplt_consignments WHERE status = %s AND created_at LIKE %s",
                ODRPLT_Courier_Manager::STATUS_DELIVERED,
                $this_month
            )
        );

        // Total consignments this month.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_consignments = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}odrplt_consignments WHERE created_at LIKE %s",
                $this_month
            )
        );

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
        return ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) )
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
        $current = $this->settings->get_general();
        return [
            'currency'        => sanitize_text_field( $data['currency'] ?? $current['currency'] ?? '' ),
            'enable_courier'  => isset( $data['enable_courier'] ) ? (bool) $data['enable_courier'] : (bool) ( $current['enable_courier'] ?? true ),
            'enable_pixel'    => isset( $data['enable_pixel'] ) ? (bool) $data['enable_pixel'] : (bool) ( $current['enable_pixel'] ?? false ),
            'enable_fraud'    => (bool) ( $data['enable_fraud'] ?? ( $current['enable_fraud'] ?? false ) ),
            'log_retention'   => absint( $data['log_retention'] ?? ( $current['log_retention'] ?? 30 ) ),
        ];
    }

    /**
     * Sanitize pixel settings from REST input.
     *
     * @param array $data
     * @return array
     */
    private function sanitize_pixel_settings( array $data ): array {
        $default_events = [
            'page_view'         => true,
            'view_content'      => true,
            'add_to_cart'       => true,
            'initiate_checkout' => true,
            'purchase'          => true,
        ];
        $events = ( ! empty( $data['events'] ) && is_array( $data['events'] ) )
            ? array_merge( $default_events, array_map( 'boolval', $data['events'] ) )
            : $default_events;

        return [
            'enable_pixel'     => ! empty( $data['enable_pixel'] ),
            'pixel_id'         => sanitize_text_field( $data['pixel_id'] ?? '' ),
            'purchase_trigger' => sanitize_key( $data['purchase_trigger'] ?? 'order_created' ),
            'events'           => $events,
        ];
    }

    /** Restrict automatic fraud outcomes to supported operational actions. */
    private function sanitize_fraud_action( $action, string $default ): string {
        $action = sanitize_key( (string) $action );
        return in_array( $action, [ 'approve', 'manual_review', 'block' ], true ) ? $action : $default;
    }

    /**
     * Sanitize GA4 settings from REST input.
     *
     * @param array $data
     * @return array
     */
    private function sanitize_ga4_settings( array $data ): array {
        return [
            'enable_ga4'       => ! empty( $data['enable_ga4'] ),
            'measurement_id'   => sanitize_text_field( $data['measurement_id'] ?? '' ),
            'purchase_trigger' => sanitize_key( $data['purchase_trigger'] ?? 'order_created' ),
            'events'           => array_map( 'boolval', (array) ( $data['events'] ?? [] ) ),
        ];
    }

    /**
     * Sanitize TikTok settings from REST input.
     *
     * @param array $data
     * @return array
     */
    private function sanitize_tiktok_settings( array $data ): array {
        return [
            'enable_tiktok'    => ! empty( $data['enable_tiktok'] ),
            'pixel_id'         => sanitize_text_field( $data['pixel_id'] ?? '' ),
            'purchase_trigger' => sanitize_key( $data['purchase_trigger'] ?? 'order_created' ),
            'events'           => array_map( 'boolval', (array) ( $data['events'] ?? [] ) ),
        ];
    }

    /**
     * GET /tracking/logs/count — Total number of local tracking event records.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_tracking_logs_count( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'odrplt_tracking_logs' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        return rest_ensure_response( [ 'count' => $count ] );
    }

    /**
     * DELETE /tracking/logs — Permanently delete all local tracking event records.
     *
     * Only deletes from OrderPilot's own wp_odrplt_tracking_logs table.
     * Does NOT affect any data on Meta, GA4, TikTok, or any external service.
     * Requires administrator capability.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function delete_tracking_logs( \WP_REST_Request $request ) {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'odrplt_tracking_logs' );

        // Count before deletion so the response is informative.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // Use TRUNCATE for speed; falls back to DELETE if TRUNCATE fails.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->query( "TRUNCATE TABLE {$table}" );

        if ( false === $result ) {
            // TRUNCATE may be restricted on some hosts — fall back to DELETE ALL.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query( "DELETE FROM {$table}" );
        }

        if ( false === $result ) {
            return new \WP_Error(
                'delete_failed',
                __( 'Failed to delete tracking event records.', 'order-pilot' ),
                [ 'status' => 500 ]
            );
        }

        /**
         * Fires after tracking logs are cleared by admin.
         *
         * @since 1.0.0
         * @param int $count Number of cleared records.
         * @param int $user_id ID of admin who triggered clear.
         */
        do_action( 'order_pilot_tracking_logs_cleared', $count, get_current_user_id() );

        return rest_ensure_response( [
            'success' => true,
            'deleted' => $count,
            'message' => sprintf(
                /* translators: %d: number of deleted records */
                _n( '%d tracking event record deleted.', '%d tracking event records deleted.', $count, 'order-pilot' ),
                $count
            ),
        ] );
    }
}
