<?php
/**
 * Logger
 *
 * Writes courier and tracking log entries to the custom DB tables.
 * All writes fire the `order_pilot_log_added` action so extensions
 * can react (e.g. send to external log aggregators).
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Logger
 */
class OP_Logger {

    /**
     * Log channel constants.
     */
    const CHANNEL_BROWSER = 'browser';
    const CHANNEL_SERVER  = 'server';

    /**
     * Courier action constants.
     */
    const ACTION_CREATE = 'create_order';
    const ACTION_STATUS = 'get_status';
    const ACTION_CANCEL = 'cancel_order';
    const ACTION_SYNC   = 'status_sync';
    const ACTION_BULK   = 'bulk_create';

    /**
     * Status constants.
     */
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED  = 'failed';

    /**
     * Database instance.
     *
     * @var OP_Database
     */
    private OP_Database $db;

    /**
     * Constructor.
     *
     * @param OP_Database $db
     */
    public function __construct( OP_Database $db ) {
        $this->db = $db;
    }

    // ─── Courier Logging ──────────────────────────────────────────────────────

    /**
     * Log a successful courier API call.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $courier  Courier slug.
     * @param string $action   One of the ACTION_* constants.
     * @param mixed  $response API response.
     * @param mixed  $payload  Request payload.
     * @return int|false Log ID or false on failure.
     */
    public function courier_success( int $order_id, string $courier, string $action, $response = null, $payload = null ) {
        return $this->write_courier_log( $order_id, $courier, $action, self::STATUS_SUCCESS, $response, $payload );
    }

    /**
     * Log a failed courier API call.
     *
     * @since 1.0.0
     * @param int    $order_id
     * @param string $courier
     * @param string $action
     * @param mixed  $error   Error message or WP_Error.
     * @param mixed  $payload Request payload.
     * @return int|false
     */
    public function courier_failed( int $order_id, string $courier, string $action, $error = null, $payload = null ) {
        $error_data = $error instanceof \WP_Error ? $error->get_error_message() : $error;
        return $this->write_courier_log( $order_id, $courier, $action, self::STATUS_FAILED, $error_data, $payload );
    }

    /**
     * Write a courier log entry to the DB.
     *
     * @since 1.0.0
     */
    private function write_courier_log(
        int $order_id,
        string $courier,
        string $action,
        string $status,
        $response,
        $payload
    ) {
        $entry = [
            'order_id' => $order_id,
            'courier'  => $courier,
            'action'   => $action,
            'status'   => $status,
            'response' => $response,
            'payload'  => $payload,
        ];

        /**
         * Filter a courier log entry before it is saved.
         *
         * @since 1.0.0
         * @param array $entry Log entry data.
         */
        $entry = (array) apply_filters( 'order_pilot_log_entry', $entry );

        $log_id = $this->db->insert_courier_log( $entry );

        if ( $log_id ) {
            /**
             * Fires after a log entry has been saved.
             *
             * @since 1.0.0
             * @param int   $log_id Log row ID.
             * @param array $entry  The log entry data.
             */
            do_action( 'order_pilot_log_added', $log_id, $entry );
        }

        return $log_id;
    }

    // ─── Tracking Logging ─────────────────────────────────────────────────────

    /**
     * Log a browser-side pixel event.
     *
     * @since 1.0.0
     * @param string   $event_name
     * @param string   $event_id   Deduplication ID.
     * @param int|null $order_id
     * @param mixed    $payload    Event data payload.
     * @return int|false
     */
    public function pixel_event( string $event_name, string $event_id, ?int $order_id = null, $payload = null ) {
        return $this->write_tracking_log( $event_name, $event_id, self::CHANNEL_BROWSER, $order_id, $payload );
    }

    /**
     * Log a server-side CAPI event.
     *
     * @since 1.0.0
     * @param string   $event_name
     * @param string   $event_id
     * @param int|null $order_id
     * @param mixed    $payload
     * @return int|false
     */
    public function capi_event( string $event_name, string $event_id, ?int $order_id = null, $payload = null ) {
        return $this->write_tracking_log( $event_name, $event_id, self::CHANNEL_SERVER, $order_id, $payload );
    }

    /**
     * Write a tracking log entry.
     *
     * @since 1.0.0
     */
    private function write_tracking_log(
        string $event_name,
        string $event_id,
        string $channel,
        ?int $order_id,
        $payload
    ) {
        $entry = [
            'order_id'   => $order_id,
            'event_name' => $event_name,
            'event_id'   => $event_id,
            'channel'    => $channel,
            'payload'    => $payload,
        ];

        /** @see order_pilot_log_entry */
        $entry = (array) apply_filters( 'order_pilot_log_entry', $entry );

        $log_id = $this->db->insert_tracking_log( $entry );

        if ( $log_id ) {
            /** @see order_pilot_log_added */
            do_action( 'order_pilot_log_added', $log_id, $entry );
        }

        return $log_id;
    }

    // ─── Query Helpers ────────────────────────────────────────────────────────

    /**
     * Get courier logs (delegates to DB).
     *
     * @since 1.0.0
     * @param array $args See OP_Database::get_courier_logs().
     * @return array
     */
    public function get_courier_logs( array $args = [] ): array {
        return $this->db->get_courier_logs( $args );
    }

    /**
     * Get tracking logs (delegates to DB).
     *
     * @since 1.0.0
     * @param array $args See OP_Database::get_tracking_logs().
     * @return array
     */
    public function get_tracking_logs( array $args = [] ): array {
        return $this->db->get_tracking_logs( $args );
    }
}
