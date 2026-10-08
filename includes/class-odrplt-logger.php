<?php
/**
 * Logger
 *
 * Writes courier and tracking log entries to custom DB tables
 * and writes detailed physical log files for developer debugging.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Logger
 */
class ODRPLT_Logger {

    /**
     * Log channel constants.
     */
    const CHANNEL_BROWSER = 'pixel';
    const CHANNEL_SERVER  = 'capi';

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
     * @var ODRPLT_Database
     */
    private ODRPLT_Database $db;

    /**
     * Constructor.
     *
     * @param ODRPLT_Database $db
     */
    public function __construct( ODRPLT_Database $db ) {
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
        $error_data = $error;
        if ( $error instanceof \WP_Error ) {
            $error_data = [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => $error->get_error_data(),
            ];
        }
        return $this->write_courier_log( $order_id, $courier, $action, self::STATUS_FAILED, $error_data, $payload );
    }

    /**
     * Write a courier log entry to the DB and to physical log file.
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

        // 1. Write to Database
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

        // 2. Write to Physical File
        $this->write_to_file( 'courier', $entry );

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
        if ( 'browser' === $channel ) {
            $channel = 'pixel';
        } elseif ( 'server' === $channel ) {
            $channel = 'capi';
        }

        $entry = [
            'order_id'   => $order_id,
            'event_name' => $event_name,
            'event_id'   => $event_id,
            'channel'    => $channel,
            'payload'    => $payload,
            'http_code'  => 200,
        ];

        /** @see order_pilot_log_entry */
        $entry = (array) apply_filters( 'order_pilot_log_entry', $entry );

        $log_id = $this->db->insert_tracking_log( $entry );

        // Always write to physical tracking.log for debug visibility.
        $this->write_tracking_to_file( $entry );

        if ( $log_id ) {
            /** @see order_pilot_log_added */
            do_action( 'order_pilot_log_added', $log_id, $entry );
        }

        return $log_id;
    }

    /**
     * Write a tracking event to the physical tracking.log file.
     *
     * @since 1.0.0
     * @param array $entry
     */
    private function write_tracking_to_file( array $entry ): void {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/order-pilot-logs';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            @file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
            @file_put_contents( $log_dir . '/index.php', '<?php // Silence' );
        }

        $log_file   = $log_dir . '/tracking.log';
        $timestamp  = current_time( 'Y-m-d H:i:s' );
        $event      = strtoupper( $entry['event_name'] ?? 'UNKNOWN' );
        $channel    = strtoupper( $entry['channel'] ?? 'UNKNOWN' );
        $order_id   = $entry['order_id'] ?? 'N/A';
        $event_id   = $entry['event_id'] ?? 'N/A';
        $status     = strtoupper( $entry['status'] ?? 'SENT' );
        $http_code  = $entry['http_code'] ?? null;

        $formatted  = "================================================================================\n";
        $formatted .= sprintf(
            "[%s] [%s] [CHANNEL: %s] [ORDER #%s] [EVENT_ID: %s]%s\n",
            $timestamp,
            $event,
            $channel,
            $order_id,
            $event_id,
            $http_code ? " [HTTP: {$http_code}]" : ''
        );

        if ( ! empty( $entry['payload'] ) ) {
            $formatted .= "--- PAYLOAD ---\n";
            $formatted .= ( is_string( $entry['payload'] )
                ? $entry['payload']
                : wp_json_encode( $entry['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            ) . "\n";
        }

        if ( ! empty( $entry['error'] ) ) {
            $formatted .= "--- ERROR ---\n" . $entry['error'] . "\n";
        }

        $formatted .= "================================================================================\n\n";

        @file_put_contents( $log_file, $formatted, FILE_APPEND | LOCK_EX );
    }

    // ─── File Logging ─────────────────────────────────────────────────────────

    /**
     * Write formatted log entry to a persistent log file on disk.
     *
     * @since 1.0.0
     * @param string $channel 'courier' | 'tracking'.
     * @param array  $entry
     */
    private function write_to_file( string $channel, array $entry ): void {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/order-pilot-logs';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            // Security: add .htaccess and index.php to block direct access
            @file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
            @file_put_contents( $log_dir . '/index.php', '<?php // Silence' );
        }

        $log_file = $log_dir . '/' . sanitize_file_name( $channel ) . '.log';

        $timestamp = current_time( 'Y-m-d H:i:s' );
        $order_id  = $entry['order_id'] ?? 'N/A';
        $courier   = strtoupper( $entry['courier'] ?? 'UNKNOWN' );
        $action    = strtoupper( $entry['action'] ?? 'ACTION' );
        $status    = strtoupper( $entry['status'] ?? 'INFO' );

        $formatted  = "================================================================================\n";
        $formatted .= sprintf( "[%s] [%s] [ORDER #%s] [ACTION: %s] [STATUS: %s]\n", $timestamp, $courier, $order_id, $action, $status );
        
        if ( ! empty( $entry['payload'] ) ) {
            $formatted .= "--- REQUEST PAYLOAD ---\n";
            $formatted .= ( is_string( $entry['payload'] ) ? $entry['payload'] : wp_json_encode( $entry['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n";
        }

        if ( ! empty( $entry['response'] ) ) {
            $formatted .= "--- API RESPONSE ---\n";
            $formatted .= ( is_string( $entry['response'] ) ? $entry['response'] : wp_json_encode( $entry['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n";
        }

        $formatted .= "================================================================================\n\n";

        @file_put_contents( $log_file, $formatted, FILE_APPEND | LOCK_EX );
    }

    /**
     * Helper to log raw courier HTTP calls directly to physical courier.log.
     *
     * @since 1.0.0
     * @param string $courier
     * @param string $endpoint
     * @param mixed  $payload
     * @param mixed  $response
     * @param int    $code
     */
    public static function log_courier_call( string $courier, string $endpoint, $payload, $response, int $code = 200 ): void {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/order-pilot-logs';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            @file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
            @file_put_contents( $log_dir . '/index.php', '<?php // Silence' );
        }

        $log_file = $log_dir . '/courier.log';

        $timestamp   = current_time( 'Y-m-d H:i:s' );
        $courier_str = strtoupper( $courier );
        $status_str  = ( $code >= 200 && $code < 300 ) ? 'SUCCESS' : 'FAILED';

        $formatted  = "================================================================================\n";
        $formatted .= sprintf( "[%s] [%s] [ENDPOINT: %s] [HTTP %d] [STATUS: %s]\n", $timestamp, $courier_str, $endpoint, $code, $status_str );

        if ( ! empty( $payload ) ) {
            $formatted .= "--- REQUEST PAYLOAD ---\n";
            $formatted .= ( is_string( $payload ) ? $payload : wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n";
        }

        if ( ! empty( $response ) ) {
            $formatted .= "--- API RESPONSE ---\n";
            $formatted .= ( is_string( $response ) ? $response : wp_json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n";
        }

        $formatted .= "================================================================================\n\n";

        @file_put_contents( $log_file, $formatted, FILE_APPEND | LOCK_EX );
    }

    /**
     * Get path to a log file.
     *
     * @since 1.0.0
     * @param string $channel
     * @return string
     */
    public function get_log_file_path( string $channel = 'courier' ): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/order-pilot-logs/' . sanitize_file_name( $channel ) . '.log';
    }

    // ─── Query Helpers ────────────────────────────────────────────────────────

    /**
     * Get courier logs (delegates to DB).
     *
     * @since 1.0.0
     * @param array $args See ODRPLT_Database::get_courier_logs().
     * @return array
     */
    public function get_courier_logs( array $args = [] ): array {
        return $this->db->get_courier_logs( $args );
    }

    /**
     * Get tracking logs (delegates to DB).
     *
     * @since 1.0.0
     * @param array $args See ODRPLT_Database::get_tracking_logs().
     * @return array
     */
    public function get_tracking_logs( array $args = [] ): array {
        return $this->db->get_tracking_logs( $args );
    }
}
