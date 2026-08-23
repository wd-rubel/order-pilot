<?php
/**
 * Plugin Activator
 *
 * Runs on plugin activation: creates custom DB tables,
 * sets default options, and stores the DB schema version.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Activator
 */
class OP_Activator {

    /**
     * Run activation routines.
     *
     * @since 1.0.0
     */
    public static function activate(): void {
        require_once ORDER_PILOT_PATH . 'includes/class-op-database.php';
        require_once ORDER_PILOT_PATH . 'includes/class-op-settings.php';

        self::create_tables();
        self::set_default_options();

        // Store DB version for future migrations.
        update_option( 'order_pilot_db_version', ORDER_PILOT_DB_VERSION );

        /**
         * Fires after the plugin has been activated and tables created.
         *
         * @since 1.0.0
         */
        do_action( 'order_pilot_activated' );

        // Flush rewrite rules so our REST namespace is available immediately.
        flush_rewrite_rules();
    }

    // ─── Table Creation ───────────────────────────────────────────────────────

    /**
     * Create all custom database tables.
     *
     * Uses dbDelta() so it is safe to run on updates as well.
     *
     * @since 1.0.0
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Courier Submission Logs ──────────────────────────────────────────
        $sql = "CREATE TABLE {$wpdb->prefix}op_courier_logs (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id    BIGINT(20) UNSIGNED NOT NULL,
            courier     VARCHAR(50)  NOT NULL,
            action      VARCHAR(50)  NOT NULL,
            status      VARCHAR(20)  NOT NULL,
            response    LONGTEXT     DEFAULT NULL,
            payload     LONGTEXT     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY courier  (courier),
            KEY status   (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta( $sql );

        // ── Tracking / Pixel Event Logs ──────────────────────────────────────
        $sql = "CREATE TABLE {$wpdb->prefix}op_tracking_logs (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id    BIGINT(20) UNSIGNED DEFAULT NULL,
            event_name  VARCHAR(100) NOT NULL,
            event_id    VARCHAR(100) DEFAULT NULL,
            channel     VARCHAR(20)  NOT NULL,
            payload     LONGTEXT     DEFAULT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id   (order_id),
            KEY event_name (event_name),
            KEY event_id   (event_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta( $sql );

        // ── Courier Consignments ─────────────────────────────────────────────
        $sql = "CREATE TABLE {$wpdb->prefix}op_consignments (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        BIGINT(20) UNSIGNED NOT NULL,
            courier         VARCHAR(50)  NOT NULL,
            consignment_id  VARCHAR(100) NOT NULL,
            tracking_id     VARCHAR(100) DEFAULT NULL,
            status          VARCHAR(50)  DEFAULT NULL,
            raw_status      VARCHAR(100) DEFAULT NULL,
            last_synced_at  DATETIME     DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_courier   (order_id, courier),
            KEY consignment_id         (consignment_id),
            KEY tracking_id            (tracking_id),
            KEY status                 (status)
        ) $charset_collate;";
        dbDelta( $sql );

        // ── Fraud Check History (table created in free for hook extensibility)
        $sql = "CREATE TABLE {$wpdb->prefix}op_fraud_checks (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id    BIGINT(20) UNSIGNED NOT NULL,
            phone       VARCHAR(20)  NOT NULL,
            score       TINYINT(3) UNSIGNED DEFAULT NULL,
            risk_level  VARCHAR(10)  DEFAULT NULL,
            data        LONGTEXT     DEFAULT NULL,
            checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id  (order_id),
            KEY phone     (phone),
            KEY risk_level (risk_level)
        ) $charset_collate;";
        dbDelta( $sql );
    }

    // ─── Default Options ──────────────────────────────────────────────────────

    /**
     * Seed default wp_options values (only if they don't exist yet).
     *
     * @since 1.0.0
     */
    private static function set_default_options(): void {
        $defaults = [
            'order_pilot_settings'           => [
                'currency'        => get_woocommerce_currency(),
                'enable_courier'  => true,
                'enable_pixel'    => false,
                'enable_fraud'    => false,
                'log_retention'   => 30, // days
            ],
            'order_pilot_connected_couriers' => [],
            'order_pilot_pixel_settings'     => [
                'pixel_id'  => '',
                'events'    => [
                    'page_view'        => true,
                    'view_content'     => true,
                    'add_to_cart'      => true,
                    'initiate_checkout' => true,
                    'purchase'         => true,
                ],
                'purchase_trigger' => 'order_created',
            ],
            'order_pilot_capi_settings'      => [
                'pixel_id'         => '',
                'access_token'     => '',
                'test_event_code'  => '',
                'enable_capi'      => false,
                'deduplication'    => true,
            ],
            'order_pilot_license'            => [
                'key'    => '',
                'status' => 'inactive',
            ],
        ];

        foreach ( $defaults as $key => $value ) {
            // add_option() is a no-op if the option already exists.
            add_option( $key, $value );
        }
    }
}
