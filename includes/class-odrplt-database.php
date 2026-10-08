<?php
/**
 * Database Manager
 *
 * Provides typed query helpers for all custom tables.
 * Raw SQL goes here; no other class should touch $wpdb directly.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Database
 */
class ODRPLT_Database {

    /**
     * Table name map (without prefix, prefix is added in methods).
     */
    const TABLE_COURIER_LOGS   = 'odrplt_courier_logs';
    const TABLE_TRACKING_LOGS  = 'odrplt_tracking_logs';
    const TABLE_CONSIGNMENTS   = 'odrplt_consignments';
    const TABLE_FRAUD_CHECKS   = 'odrplt_fraud_checks';
    const TABLE_BLOCKED_IPS    = 'odrplt_blocked_ips';

    /**
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    // ─── Table Helpers ────────────────────────────────────────────────────────

    /**
     * Get the full prefixed table name.
     *
     * @param string $table One of the TABLE_* constants.
     * @return string
     */
    public function table( string $table ): string {
        return esc_sql( $this->wpdb->prefix . $table );
    }

    // ─── Courier Logs ─────────────────────────────────────────────────────────

    /**
     * Insert a courier log entry.
     *
     * @since 1.0.0
     * @param array $data {
     *     @type int    $order_id  WC Order ID.
     *     @type string $courier   Courier slug.
     *     @type string $action    e.g. 'create_order', 'get_status', 'cancel_order'.
     *     @type string $status    'success' | 'failed'.
     *     @type mixed  $response  The API response (will be JSON-encoded).
     *     @type mixed  $payload   The request payload (will be JSON-encoded).
     * }
     * @return int|false Inserted row ID or false on failure.
     */
    public function insert_courier_log( array $data ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $this->wpdb->insert(
            $this->table( self::TABLE_COURIER_LOGS ),
            [
                'order_id'   => absint( $data['order_id'] ),
                'courier'    => sanitize_key( $data['courier'] ),
                'action'     => sanitize_text_field( $data['action'] ),
                'status'     => sanitize_text_field( $data['status'] ),
                'response'   => isset( $data['response'] ) ? wp_json_encode( $data['response'] ) : null,
                'payload'    => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $inserted ? (int) $this->wpdb->insert_id : false;
    }

    /**
     * Get courier logs with optional filters.
     *
     * @since 1.0.0
     * @param array $args {
     *     @type int    $order_id  Filter by order.
     *     @type string $courier   Filter by courier slug.
     *     @type string $status    Filter by status.
     *     @type int    $limit     Rows to return (default 50).
     *     @type int    $offset    Offset (default 0).
     * }
     * @return array
     */
    public function get_courier_logs( array $args = [] ): array {
        $table  = esc_sql( $this->table( self::TABLE_COURIER_LOGS ) );
        $where  = [];
        $params = [];

        if ( ! empty( $args['order_id'] ) ) {
            $where[]  = 'order_id = %d';
            $params[] = absint( $args['order_id'] );
        }
        if ( ! empty( $args['courier'] ) ) {
            $where[]  = 'courier = %s';
            $params[] = sanitize_key( $args['courier'] );
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = sanitize_text_field( $args['status'] );
        }

        $limit  = absint( $args['limit'] ?? 50 );
        $offset = absint( $args['offset'] ?? 0 );
        $params[] = $limit;
        $params[] = $offset;

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (array) $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $this->wpdb->prepare( $sql, $params ),
            ARRAY_A
        );
    }

    // ─── Tracking Logs ────────────────────────────────────────────────────────

    /**
     * Insert a tracking/pixel event log.
     *
     * @since 1.0.0
     * @param array $data {
     *     @type int|null $order_id   WC Order ID (nullable for page-level events).
     *     @type string   $event_name e.g. 'Purchase', 'AddToCart'.
     *     @type string   $event_id   Deduplication ID.
     *     @type string   $channel    'browser' | 'server'.
     *     @type mixed    $payload    Event payload (will be JSON-encoded).
     * }
     * @return int|false
     */
    public function insert_tracking_log( array $data ) {
        $channel = sanitize_text_field( $data['channel'] ?? 'pixel' );
        if ( 'browser' === $channel ) {
            $channel = 'pixel';
        } elseif ( 'server' === $channel ) {
            $channel = 'capi';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $this->wpdb->insert(
            $this->table( self::TABLE_TRACKING_LOGS ),
            [
                'order_id'   => ! empty( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
                'event_name' => sanitize_text_field( $data['event_name'] ),
                'event_id'   => sanitize_text_field( $data['event_id'] ?? '' ),
                'channel'    => $channel,
                'payload'    => isset( $data['payload'] ) ? ( is_string( $data['payload'] ) ? $data['payload'] : wp_json_encode( $data['payload'], JSON_UNESCAPED_UNICODE ) ) : null,
                'http_code'  => isset( $data['http_code'] ) ? (int) $data['http_code'] : null,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        return $inserted ? (int) $this->wpdb->insert_id : false;
    }

    /**
     * Get tracking logs.
     *
     * @since 1.0.0
     * @param array $args {
     *     @type int    $order_id
     *     @type string $event_name
     *     @type string $channel
     *     @type int    $limit
     *     @type int    $offset
     * }
     * @return array
     */
    public function get_tracking_logs( array $args = [] ): array {
        $table  = esc_sql( $this->table( self::TABLE_TRACKING_LOGS ) );
        $where  = [];
        $params = [];

        if ( ! empty( $args['order_id'] ) ) {
            $where[]  = 'order_id = %d';
            $params[] = absint( $args['order_id'] );
        }
        if ( ! empty( $args['event_name'] ) ) {
            $where[]  = 'event_name = %s';
            $params[] = sanitize_text_field( $args['event_name'] );
        }
        if ( ! empty( $args['channel'] ) ) {
            $ch = sanitize_text_field( $args['channel'] );
            if ( 'pixel' === $ch || 'browser' === $ch ) {
                $where[] = "channel IN ('pixel', 'browser')";
            } elseif ( 'capi' === $ch || 'server' === $ch ) {
                $where[] = "channel IN ('capi', 'server')";
            } else {
                $where[]  = 'channel = %s';
                $params[] = $ch;
            }
        }

        $limit    = absint( $args['limit'] ?? 50 );
        $offset   = absint( $args['offset'] ?? 0 );
        $params[] = $limit;
        $params[] = $offset;

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (array) $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $this->wpdb->prepare( $sql, $params ),
            ARRAY_A
        );
    }

    // ─── Consignments ─────────────────────────────────────────────────────────

    /**
     * Upsert a consignment record.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so repeated courier sends update in place.
     *
     * @since 1.0.0
     * @param array $data {
     *     @type int    $order_id       WC Order ID.
     *     @type string $courier        Courier slug.
     *     @type string $consignment_id Consignment/parcel ID from courier API.
     *     @type string $tracking_id    Tracking ID (may equal consignment_id).
     *     @type string $status         Normalised courier status.
     *     @type string $raw_status     Raw status string from courier API.
     * }
     * @return bool
     */
    public function upsert_consignment( array $data ): bool {
        global $wpdb;
        $table = esc_sql( $this->table( self::TABLE_CONSIGNMENTS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (bool) $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (order_id, courier, consignment_id, tracking_id, status, raw_status, created_at) VALUES (%d, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE consignment_id = VALUES(consignment_id), tracking_id = VALUES(tracking_id), status = VALUES(status), raw_status = VALUES(raw_status)", absint( $data['order_id'] ), sanitize_key( $data['courier'] ), sanitize_text_field( $data['consignment_id'] ), sanitize_text_field( $data['tracking_id'] ?? '' ), sanitize_text_field( $data['status'] ?? 'pending' ), sanitize_text_field( $data['raw_status'] ?? '' ), current_time( 'mysql' ) ) );
    }

    /**
     * Get the consignment record for an order + courier.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $courier
     * @return array|null
     */
    public function get_consignment( int $order_id, string $courier ): ?array {
        global $wpdb;
        $table = esc_sql( $this->table( self::TABLE_CONSIGNMENTS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND courier = %s LIMIT 1", $order_id, sanitize_key( $courier ) ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Update the delivery status of a consignment.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $courier
     * @param string $status     Normalized status.
     * @param string $raw_status Raw status from courier.
     * @return bool
     */
    public function update_consignment_status(
        int $order_id,
        string $courier,
        string $status,
        string $raw_status = ''
    ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->update(
            $this->table( self::TABLE_CONSIGNMENTS ),
            [
                'status'        => sanitize_text_field( $status ),
                'raw_status'    => sanitize_text_field( $raw_status ),
                'last_synced_at' => current_time( 'mysql' ),
            ],
            [
                'order_id' => $order_id,
                'courier'  => sanitize_key( $courier ),
            ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );
    }

    // ─── Fraud Checks ─────────────────────────────────────────────────────────

    /**
     * Insert a fraud check result.
     *
     * Called by the pro plugin via the `order_pilot_after_fraud_check` action.
     *
     * @since 1.0.0
     * @param array $data {
     *     @type int    $order_id  WC Order ID.
     *     @type string $phone     Customer phone number.
     *     @type int    $score     Fraud score 0-100.
     *     @type string $risk_level 'low' | 'medium' | 'high'.
     *     @type array  $data      Full fraud analysis data.
     * }
     * @return int|false
     */
    public function insert_fraud_check( array $data ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert(
            $this->table( self::TABLE_FRAUD_CHECKS ),
            [
                'order_id'   => isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0,
                'phone'      => sanitize_text_field( $data['phone'] ),
                'score'      => isset( $data['score'] ) ? absint( $data['score'] ) : 0,
                'risk_level' => sanitize_text_field( $data['risk_level'] ?? 'low' ),
                'data'       => isset( $data['data'] ) ? ( is_string( $data['data'] ) ? $data['data'] : wp_json_encode( $data['data'] ) ) : null,
                'checked_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s' ]
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get fraud check history (all recent checks or filtered by phone).
     *
     * @since 1.0.0
     * @param string $phone Optional phone number.
     * @param int    $limit Max rows to return.
     * @return array
     */
    public function get_fraud_history_by_phone( string $phone = '', int $limit = 30 ): array {
        global $wpdb;
        $table = esc_sql( $this->table( self::TABLE_FRAUD_CHECKS ) );

        if ( '' !== trim( $phone ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->prepare( "SELECT * FROM {$table} WHERE phone = %s ORDER BY checked_at DESC LIMIT %d", sanitize_text_field( $phone ), $limit ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->prepare( "SELECT * FROM {$table} ORDER BY checked_at DESC LIMIT %d", $limit ),
                ARRAY_A
            );
        }

        if ( ! is_array( $rows ) ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            if ( ! empty( $row['data'] ) && is_string( $row['data'] ) ) {
                $row['data'] = json_decode( $row['data'], true );
            }
            if ( ! empty( $row['checked_at'] ) ) {
                $row['created_at'] = $row['checked_at'];
            }
        }

        return $rows;
    }

    /**
     * Get the latest fraud check for a specific order.
     *
     * @since 1.0.0
     * @param int $order_id
     * @return array|null
     */
    public function get_fraud_check_by_order( int $order_id ): ?array {
        global $wpdb;
        $table = esc_sql( $this->table( self::TABLE_FRAUD_CHECKS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY checked_at DESC LIMIT 1", $order_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    // ─── Blocked IPs ──────────────────────────────────────────────────────────

    /**
     * Add an IP address to the blocked list.
     *
     * Uses INSERT IGNORE so duplicate IPs are silently skipped.
     *
     * @since 1.1.0
     * @param array $data {
     *     @type string $ip_address IP address (IPv4 or IPv6).
     *     @type string $reason     Optional reason for blocking.
     *     @type string $source     'manual' | 'automatic'.
     * }
     * @return int|false Inserted row ID, 0 if duplicate (ignored), or false on error.
     */
    public function add_blocked_ip( array $data ) {
        global $wpdb;
        $table = esc_sql( $this->table( self::TABLE_BLOCKED_IPS ) );

        $ip = sanitize_text_field( $data['ip_address'] ?? '' );
        if ( '' === $ip ) {
            return false;
        }

        // Use INSERT IGNORE to prevent duplicate IP entries.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare( "INSERT IGNORE INTO {$table} (ip_address, reason, source, created_at) VALUES (%s, %s, %s, %s)", $ip, sanitize_text_field( $data['reason'] ?? '' ), in_array( $data['source'] ?? '', [ 'manual', 'automatic' ], true ) ? $data['source'] : 'manual', current_time( 'mysql' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $wpdb->query( $sql );

        if ( false === $result ) {
            // Self-heal: ensure table is created if missing, then retry once
            require_once ORDER_PILOT_PATH . 'includes/class-odrplt-activator.php';
            ODRPLT_Activator::create_tables();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query( $sql );
            if ( false === $result ) {
                return false;
            }
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Remove a blocked IP by its row ID.
     *
     * @since 1.1.0
     * @param int $id Row ID.
     * @return bool
     */
    public function remove_blocked_ip( int $id ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->delete(
            $this->table( self::TABLE_BLOCKED_IPS ),
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    /**
     * Get the list of blocked IPs.
     *
     * @since 1.1.0
     * @param array $args {
     *     @type string $search  Optional IP/reason search string.
     *     @type string $source  Filter by source ('manual' | 'automatic').
     *     @type int    $limit   Max rows (default 100).
     *     @type int    $offset  Offset (default 0).
     * }
     * @return array
     */
    public function get_blocked_ips( array $args = [] ): array {
        global $wpdb;
        $table  = esc_sql( $this->table( self::TABLE_BLOCKED_IPS ) );
        $where  = [];
        $params = [];

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]  = '(ip_address LIKE %s OR reason LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ( ! empty( $args['source'] ) ) {
            $where[]  = 'source = %s';
            $params[] = sanitize_text_field( $args['source'] );
        }

        $limit    = absint( $args['limit']  ?? 100 );
        $offset   = absint( $args['offset'] ?? 0 );
        $params[] = $limit;
        $params[] = $offset;

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (array) $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare( $sql, $params ),
            ARRAY_A
        );
    }

    /**
     * Get a blocked IP record by IP address.
     *
     * @since 1.1.0
     * @param string $ip IP address.
     * @return array|null Row array or null if not found.
     */
    public function get_blocked_ip( string $ip ): ?array {
        if ( '' === $ip ) {
            return null;
        }

        global $wpdb;
        $table = esc_sql( $this->table( self::TABLE_BLOCKED_IPS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row   = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "SELECT * FROM {$table} WHERE ip_address = %s LIMIT 1", $ip ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Check whether a given IP address is blocked.
     *
     * @since 1.1.0
     * @param string $ip IP address.
     * @return bool
     */
    public function is_ip_blocked( string $ip ): bool {
        if ( '' === $ip ) {
            return false;
        }

        global $wpdb;
        $table = esc_sql( $this->table( self::TABLE_BLOCKED_IPS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s LIMIT 1", $ip )
        );

        return $count > 0;
    }

    // ─── Maintenance ──────────────────────────────────────────────────────────

    /**
     * Delete log entries older than a given number of days.
     *
     * @since 1.0.0
     * @param int $days
     * @return int Number of deleted rows across all log tables.
     */
    public function purge_old_logs( int $days = 30 ): int {
        global $wpdb;
        $total      = 0;
        $cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $log_tables = [
            self::TABLE_COURIER_LOGS  => 'created_at',
            self::TABLE_TRACKING_LOGS => 'created_at',
        ];

        foreach ( $log_tables as $table => $col ) {
            $table_name = esc_sql( $wpdb->prefix . $table );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $deleted    = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE {$col} < %s", $cutoff ) );
            $total     += (int) $deleted;
        }

        return $total;
    }
}
