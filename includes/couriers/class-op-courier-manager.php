<?php
/**
 * Courier Manager
 *
 * Manages registered courier adapters, enforces the free/pro courier limit,
 * and provides the single entry point for sending orders to couriers.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Courier_Manager
 */
class OP_Courier_Manager {

    /**
     * Normalized courier status constants.
     * These are the canonical statuses used internally by the plugin.
     */
    const STATUS_PENDING        = 'pending';
    const STATUS_PICKED_UP      = 'picked_up';
    const STATUS_IN_TRANSIT     = 'in_transit';
    const STATUS_DELIVERED      = 'delivered';
    const STATUS_CANCELLED      = 'cancelled';
    const STATUS_RETURNED       = 'returned';
    const STATUS_FAILED_DELIVERY = 'failed_delivery';
    const STATUS_UNKNOWN        = 'unknown';

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

    /**
     * @var OP_Logger
     */
    private OP_Logger $logger;

    /**
     * @var OP_License
     */
    private OP_License $license;

    /**
     * Lazy-loaded registered courier adapters.
     *
     * @var OP_Courier_Interface[]|null
     */
    private ?array $couriers = null;

    /**
     * Constructor.
     *
     * @param OP_Settings $settings
     * @param OP_Logger   $logger
     * @param OP_License  $license
     */
    public function __construct( OP_Settings $settings, OP_Logger $logger, OP_License $license ) {
        $this->settings = $settings;
        $this->logger   = $logger;
        $this->license  = $license;
    }

    // ─── Courier Registry ─────────────────────────────────────────────────────

    /**
     * Get all registered courier adapters.
     *
     * Triggers the `order_pilot_couriers` filter so the pro plugin (or third parties)
     * can add additional couriers without modifying the free plugin.
     *
     * @since 1.0.0
     * @return OP_Courier_Interface[]  Keyed by courier slug.
     */
    public function get_all(): array {
        if ( null === $this->couriers ) {
            /**
             * Filter: registered courier adapters.
             *
             * Core couriers (Steadfast, Pathao, RedX) are added at priority 5
             * in Order_Pilot::register_core_couriers(). Pro plugin adds more at 10+.
             *
             * @since 1.0.0
             * @param OP_Courier_Interface[] $couriers Initial empty array.
             */
            $couriers = (array) apply_filters( 'order_pilot_couriers', [] );

            // Ensure each value implements the interface.
            $this->couriers = array_filter(
                $couriers,
                fn( $c ) => $c instanceof OP_Courier_Interface
            );
        }

        return $this->couriers;
    }

    /**
     * Get a single courier adapter by slug.
     *
     * @since 1.0.0
     * @param string $slug
     * @return OP_Courier_Interface|null
     */
    public function get( string $slug ): ?OP_Courier_Interface {
        return $this->get_all()[ $slug ] ?? null;
    }

    /**
     * Get only the couriers that are connected (active credentials saved).
     *
     * @since 1.0.0
     * @return OP_Courier_Interface[]
     */
    public function get_connected(): array {
        $connected_slugs = $this->settings->get_connected_couriers();
        $all             = $this->get_all();

        return array_filter(
            $all,
            fn( $c ) => in_array( $c->get_slug(), $connected_slugs, true ) && $c->is_configured()
        );
    }

    /**
     * Invalidate the cached courier list.
     * Call after adding/removing couriers at runtime.
     *
     * @since 1.0.0
     */
    public function reset(): void {
        $this->couriers = null;
    }

    // ─── Connection Management ────────────────────────────────────────────────

    /**
     * Connect a courier (add to the active list).
     * Enforces the free-plan max courier limit.
     *
     * @since 1.0.0
     * @param string $slug
     * @return true|\WP_Error
     */
    public function connect( string $slug ) {
        if ( ! isset( $this->get_all()[ $slug ] ) ) {
            return new \WP_Error( 'invalid_courier', __( 'Unknown courier slug.', 'order-pilot' ) );
        }

        $connected = $this->settings->get_connected_couriers();

        if ( in_array( $slug, $connected, true ) ) {
            return true; // Already connected.
        }

        $max = $this->license->max_couriers();
        if ( count( $connected ) >= $max ) {
            /* translators: %d: max courier count */
            return new \WP_Error(
                'courier_limit_reached',
                sprintf(
                    __( 'You can connect a maximum of %d couriers on your current plan. Upgrade to Pro for unlimited connections.', 'order-pilot' ),
                    $max
                )
            );
        }

        $connected[] = $slug;
        $this->settings->set_connected_couriers( $connected );

        /**
         * Fires when a courier has been connected.
         *
         * @since 1.0.0
         * @param string $slug Courier slug.
         */
        do_action( 'order_pilot_courier_connected', $slug );

        return true;
    }

    /**
     * Disconnect a courier (remove from active list).
     *
     * @since 1.0.0
     * @param string $slug
     * @return bool
     */
    public function disconnect( string $slug ): bool {
        $connected = $this->settings->get_connected_couriers();
        $updated   = array_values( array_filter( $connected, fn( $s ) => $s !== $slug ) );
        $this->settings->set_connected_couriers( $updated );

        /**
         * Fires when a courier is disconnected.
         *
         * @since 1.0.0
         * @param string $slug
         */
        do_action( 'order_pilot_courier_disconnected', $slug );

        return true;
    }

    // ─── Order Submission ─────────────────────────────────────────────────────

    /**
     * Send a WooCommerce order to a specific courier.
     *
     * This is the central method for all courier submission.
     * It fires before/after hooks and writes the log.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $courier_slug
     * @param array  $extra_data   Optional overrides (city, area, note, etc.)
     * @return array|\WP_Error
     */
    public function send_order( int $order_id, string $courier_slug, array $extra_data = [] ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'invalid_order', __( 'Order not found.', 'order-pilot' ) );
        }

        $courier = $this->get( $courier_slug );

        if ( ! $courier ) {
            return new \WP_Error( 'invalid_courier', __( 'Courier not found.', 'order-pilot' ) );
        }

        if ( ! $courier->is_configured() ) {
            return new \WP_Error( 'courier_not_configured', __( 'Courier credentials are not configured.', 'order-pilot' ) );
        }

        /**
         * Fires before an order is sent to a courier.
         *
         * @since 1.0.0
         * @param int    $order_id     WC Order ID.
         * @param string $courier_slug Courier slug.
         * @param array  $extra_data   Optional override data.
         */
        do_action( 'order_pilot_before_courier_send', $order_id, $courier_slug, $extra_data );

        $result = $courier->create_order( $order, $extra_data );

        if ( is_wp_error( $result ) ) {
            $this->logger->courier_failed( $order_id, $courier_slug, OP_Logger::ACTION_CREATE, $result );

            /**
             * Fires when a courier send fails.
             *
             * @since 1.0.0
             * @param int      $order_id
             * @param string   $courier_slug
             * @param \WP_Error $error
             */
            do_action( 'order_pilot_courier_send_failed', $order_id, $courier_slug, $result );

        } else {
            // Save consignment data.
            global $order_pilot;
            if ( isset( $order_pilot ) ) {
                $order_pilot->db->upsert_consignment( [
                    'order_id'       => $order_id,
                    'courier'        => $courier_slug,
                    'consignment_id' => $result['consignment_id'] ?? '',
                    'tracking_id'    => $result['tracking_id'] ?? $result['consignment_id'] ?? '',
                    'status'         => $result['status'] ?? self::STATUS_PENDING,
                ] );
            }

            $this->logger->courier_success( $order_id, $courier_slug, OP_Logger::ACTION_CREATE, $result );

            // Store basic meta on the order itself.
            $order->update_meta_data( '_op_courier', $courier_slug );
            $order->update_meta_data( '_op_consignment_id', $result['consignment_id'] ?? '' );
            $order->update_meta_data( '_op_tracking_id', $result['tracking_id'] ?? '' );
            $order->save_meta_data();

            /**
             * Fires on a successful courier send.
             *
             * @since 1.0.0
             * @param int    $order_id
             * @param string $courier_slug
             * @param array  $result
             */
            do_action( 'order_pilot_courier_send_success', $order_id, $courier_slug, $result );
        }

        /**
         * Fires after courier send (regardless of outcome).
         *
         * @since 1.0.0
         * @param int          $order_id
         * @param string       $courier_slug
         * @param array|\WP_Error $result
         */
        do_action( 'order_pilot_after_courier_send', $order_id, $courier_slug, $result );

        return $result;
    }

    // ─── Status Normalization ─────────────────────────────────────────────────

    /**
     * Normalize a raw courier status string to one of the STATUS_* constants.
     *
     * Each courier returns different raw status strings; this maps them
     * to the plugin's canonical set.
     *
     * @since 1.0.0
     * @param string $raw_status
     * @param string $courier_slug
     * @return string One of the STATUS_* constants.
     */
    public function normalize_status( string $raw_status, string $courier_slug ): string {
        $raw = strtolower( trim( $raw_status ) );

        // Common mappings shared across couriers.
        $common = [
            'pending'          => self::STATUS_PENDING,
            'in_review'        => self::STATUS_PENDING,
            'picked_up'        => self::STATUS_PICKED_UP,
            'pickup'           => self::STATUS_PICKED_UP,
            'in_transit'       => self::STATUS_IN_TRANSIT,
            'transit'          => self::STATUS_IN_TRANSIT,
            'on_the_way'       => self::STATUS_IN_TRANSIT,
            'delivered'        => self::STATUS_DELIVERED,
            'delivery_success' => self::STATUS_DELIVERED,
            'cancelled'        => self::STATUS_CANCELLED,
            'cancel'           => self::STATUS_CANCELLED,
            'returned'         => self::STATUS_RETURNED,
            'return'           => self::STATUS_RETURNED,
            'failed'           => self::STATUS_FAILED_DELIVERY,
            'failed_delivery'  => self::STATUS_FAILED_DELIVERY,
            'hold'             => self::STATUS_IN_TRANSIT,
        ];

        if ( isset( $common[ $raw ] ) ) {
            return $common[ $raw ];
        }

        /**
         * Filter: normalize a courier-specific raw status.
         *
         * Pro plugin or courier-specific code can hook here to handle
         * edge-case statuses that don't match the common map.
         *
         * @since 1.0.0
         * @param string $normalized  Current normalized value (STATUS_UNKNOWN).
         * @param string $raw_status  Original raw status from the courier.
         * @param string $courier_slug
         */
        return (string) apply_filters(
            'order_pilot_normalize_courier_status',
            self::STATUS_UNKNOWN,
            $raw_status,
            $courier_slug
        );
    }
}
