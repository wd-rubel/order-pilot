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
 * Class OP_Database
 */
class OP_Database {

    /**
     * Table name map (without prefix, prefix is added in methods).
     */
    const TABLE_COURIER_LOGS   = 'op_courier_logs';
    const TABLE_TRACKING_LOGS  = 'op_tracking_logs';
    const TABLE_CONSIGNMENTS   = 'op_consignments';
    const TABLE_FRAUD_CHECKS   = 'op_fraud_checks';

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
        return $this->wpdb->prefix . $table;
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
        $table  = $this->table( self::TABLE_COURIER_LOGS );
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

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, $params ), // phpcs:ignore
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
        $inserted = $this->wpdb->insert(
            $this->table( self::TABLE_TRACKING_LOGS ),
            [
                'order_id'   => ! empty( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
                'event_name' => sanitize_text_field( $data['event_name'] ),
                'event_id'   => sanitize_text_field( $data['event_id'] ?? '' ),
                'channel'    => sanitize_text_field( $data['channel'] ?? 'browser' ),
                'payload'    => isset( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
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
        $table  = $this->table( self::TABLE_TRACKING_LOGS );
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
            $where[]  = 'channel = %s';
            $params[] = sanitize_text_field( $args['channel'] );
        }

        $limit    = absint( $args['limit'] ?? 50 );
        $offset   = absint( $args['offset'] ?? 0 );
        $params[] = $limit;
        $params[] = $offset;

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        return (array) $this->wpdb->get_results(
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
        $table = $this->table( self::TABLE_CONSIGNMENTS );

        $sql = $this->wpdb->prepare(
            "INSERT INTO {$table} (order_id, courier, consignment_id, tracking_id, status, raw_status, created_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                consignment_id = VALUES(consignment_id),
                tracking_id    = VALUES(tracking_id),
                status         = VALUES(status),
                raw_status     = VALUES(raw_status)",
            absint( $data['order_id'] ),
            sanitize_key( $data['courier'] ),
            sanitize_text_field( $data['consignment_id'] ),
            sanitize_text_field( $data['tracking_id'] ?? '' ),
            sanitize_text_field( $data['status'] ?? 'pending' ),
            sanitize_text_field( $data['raw_status'] ?? '' ),
            current_time( 'mysql' )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (bool) $this->wpdb->query( $sql );
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
        $table = $this->table( self::TABLE_CONSIGNMENTS );

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d AND courier = %s LIMIT 1",
                $order_id,
                sanitize_key( $courier )
            ),
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
        return (bool) $this->wpdb->update(
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
        $inserted = $this->wpdb->insert(
            $this->table( self::TABLE_FRAUD_CHECKS ),
            [
                'order_id'   => absint( $data['order_id'] ),
                'phone'      => sanitize_text_field( $data['phone'] ),
                'score'      => isset( $data['score'] ) ? absint( $data['score'] ) : null,
                'risk_level' => sanitize_text_field( $data['risk_level'] ?? '' ),
                'data'       => isset( $data['data'] ) ? wp_json_encode( $data['data'] ) : null,
                'checked_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s' ]
        );

        return $inserted ? (int) $this->wpdb->insert_id : false;
    }

    /**
     * Get fraud check history for a phone number.
     *
     * @since 1.0.0
     * @param string $phone
     * @param int    $limit
     * @return array
     */
    public function get_fraud_history_by_phone( string $phone, int $limit = 20 ): array {
        $table = $this->table( self::TABLE_FRAUD_CHECKS );

        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE phone = %s ORDER BY checked_at DESC LIMIT %d",
                sanitize_text_field( $phone ),
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Get the latest fraud check for a specific order.
     *
     * @since 1.0.0
     * @param int $order_id
     * @return array|null
     */
    public function get_fraud_check_by_order( int $order_id ): ?array {
        $table = $this->table( self::TABLE_FRAUD_CHECKS );

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d ORDER BY checked_at DESC LIMIT 1",
                $order_id
            ),
            ARRAY_A
        );

        return $row ?: null;
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
        $total    = 0;
        $cutoff   = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $log_tables = [
            self::TABLE_COURIER_LOGS  => 'created_at',
            self::TABLE_TRACKING_LOGS => 'created_at',
        ];

        foreach ( $log_tables as $table => $col ) {
            $deleted = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$this->wpdb->prefix}{$table} WHERE {$col} < %s",
                    $cutoff
                )
            );
            $total += (int) $deleted;
        }

        return $total;
    }
}
