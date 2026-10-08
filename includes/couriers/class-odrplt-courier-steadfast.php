<?php
/**
 * Steadfast Courier Adapter
 *
 * Implements the Steadfast Courier Limited API v1.
 *
 * API Docs: https://portal.packzy.com
 * Base URL:  https://portal.packzy.com/api/v1
 *
 * Authentication (header for every request):
 *   Api-Key       — provided by Steadfast
 *   Secret-Key    — provided by Steadfast
 *   Content-Type  — application/json
 *
 * Endpoints used:
 *   POST /create_order                          — Place a single order
 *   POST /create_order/bulk-order               — Place up to 500 orders (Pro)
 *   GET  /status_by_cid/{consignment_id}        — Status by Steadfast consignment ID
 *   GET  /status_by_invoice/{invoice}           — Status by merchant invoice ID
 *   GET  /status_by_trackingcode/{trackingCode} — Status by tracking code
 *   GET  /get_balance                           — Current wallet balance
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Courier_Steadfast
 */
class ODRPLT_Courier_Steadfast implements ODRPLT_Courier_Interface {

    /**
     * Steadfast API base URL.
     *
     * @since 1.0.0
     */
    const API_BASE = 'https://portal.packzy.com/api/v1';

    /**
     * Steadfast delivery status constants (as returned by the API).
     */
    const STATUS_PENDING                         = 'pending';
    const STATUS_IN_REVIEW                       = 'in_review';
    const STATUS_DELIVERED                       = 'delivered';
    const STATUS_PARTIAL_DELIVERED               = 'partial_delivered';
    const STATUS_CANCELLED                       = 'cancelled';
    const STATUS_HOLD                            = 'hold';
    const STATUS_UNKNOWN                         = 'unknown';
    const STATUS_DELIVERED_APPROVAL_PENDING      = 'delivered_approval_pending';
    const STATUS_PARTIAL_DELIVERED_APPROVAL_PENDING = 'partial_delivered_approval_pending';
    const STATUS_CANCELLED_APPROVAL_PENDING      = 'cancelled_approval_pending';
    const STATUS_UNKNOWN_APPROVAL_PENDING        = 'unknown_approval_pending';

    /**
     * @var ODRPLT_Settings
     */
    private ODRPLT_Settings $settings;

    /**
     * Cached credentials.
     *
     * @var array
     */
    private array $credentials = [];

    /**
     * Constructor.
     *
     * @param ODRPLT_Settings $settings
     */
    public function __construct( ODRPLT_Settings $settings ) {
        $this->settings    = $settings;
        $this->credentials = $settings->get_courier_credentials( $this->get_slug() );
    }

    // ─── Interface Methods ────────────────────────────────────────────────────

    /** @inheritDoc */
    public function get_slug(): string {
        return 'steadfast';
    }

    /** @inheritDoc */
    public function get_name(): string {
        return __( 'Steadfast Courier', 'order-pilot' );
    }

    /**
     * Credential fields displayed in the Couriers settings UI.
     *
     * @inheritDoc
     */
    public function get_credential_fields(): array {
        return [
            'api_key'    => [
                'label'       => __( 'API Key', 'order-pilot' ),
                'type'        => 'text',
                'required'    => true,
                'placeholder' => __( 'Enter your Steadfast API Key', 'order-pilot' ),
            ],
            'secret_key' => [
                'label'       => __( 'Secret Key', 'order-pilot' ),
                'type'        => 'password',
                'required'    => true,
                'placeholder' => __( 'Enter your Steadfast Secret Key', 'order-pilot' ),
            ],
        ];
    }

    /** @inheritDoc */
    public function is_configured(): bool {
        return ! empty( $this->credentials['api_key'] )
            && ! empty( $this->credentials['secret_key'] );
    }

    // ─── Core API Methods ─────────────────────────────────────────────────────

    /**
     * Create a single consignment order in Steadfast.
     *
     * {@inheritDoc}
     *
     * Payload fields:
     *   invoice          (required) — unique order number (alphanumeric, hyphens, underscores)
     *   recipient_name   (required) — max 100 characters
     *   recipient_phone  (required) — must be 11 digits
     *   recipient_address(required) — max 250 characters
     *   cod_amount       (required) — numeric, BDT, cannot be < 0
     *   note             (optional) — delivery instructions
     *   item_description (optional) — item summary text
     *   alternative_phone(optional) — must be 11 digits
     *   recipient_email  (optional)
     *   total_lot        (optional) — number of parcels/lots
     *   delivery_type    (optional) — 0 = home delivery, 1 = hub/point pickup
     *
     * Success response shape:
     * {
     *   "status": 200,
     *   "message": "Consignment has been created successfully.",
     *   "consignment": {
     *     "consignment_id": 1424107,   ← numeric Steadfast internal ID
     *     "invoice": "Aa12-das4",
     *     "tracking_code": "15BAEB8A", ← short alphanumeric tracking code
     *     "recipient_name": "John Smith",
     *     "recipient_phone": "01234567890",
     *     "recipient_address": "...",
     *     "cod_amount": 1060,
     *     "status": "in_review",
     *     "note": "...",
     *     "created_at": "2021-03-21T07:05:31.000000Z",
     *     "updated_at": "2021-03-21T07:05:31.000000Z"
     *   }
     * }
     *
     * @param \WC_Order $order
     * @param array     $extra_data Override/extend fields from the UI or bulk submission.
     * @return array|\WP_Error
     */
    public function create_order( \WC_Order $order, array $extra_data = [] ) {
        $payload = $this->build_order_payload( $order, $extra_data );

        /**
         * Filter: Steadfast order payload before it is sent.
         *
         * @since 1.0.0
         * @param array     $payload    Request payload.
         * @param \WC_Order $order      WooCommerce order.
         * @param array     $extra_data Additional / override data.
         */
        $payload = (array) apply_filters( 'order_pilot_courier_payload', $payload, $order, $extra_data );
        $payload = (array) apply_filters( 'order_pilot_steadfast_payload', $payload, $order, $extra_data );

        $response = $this->request( 'POST', '/create_order', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $consignment = $body['consignment'] ?? [];

        // The API returns both a numeric `consignment_id` and a short `tracking_code`.
        // We need the tracking_code to query status later; consignment_id is the internal DB id.
        if ( empty( $consignment['tracking_code'] ) ) {
            return new \WP_Error(
                'steadfast_create_failed',
                $body['message'] ?? __( 'Steadfast: Failed to create consignment. No tracking code returned.', 'order-pilot' )
            );
        }

        return [
            // Steadfast-internal numeric ID (stored for reference).
            'consignment_id' => (string) ( $consignment['consignment_id'] ?? '' ),
            // Short alphanumeric tracking code — used for status lookups.
            'tracking_id'    => (string) $consignment['tracking_code'],
            'status'         => $this->map_delivery_status( $consignment['status'] ?? self::STATUS_IN_REVIEW ),
            'raw'            => $body,
        ];
    }

    /**
     * Get consignment status by Steadfast consignment ID.
     *
     * Endpoint: GET /status_by_cid/{consignment_id}
     *
     * {@inheritDoc}
     *
     * Response shape:
     * { "status": 200, "delivery_status": "in_review" }
     *
     * @param string $consignment_id The Steadfast numeric consignment ID.
     * @return array|\WP_Error
     */
    public function get_status( string $consignment_id ) {
        $response = $this->request( 'GET', "/status_by_cid/{$consignment_id}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $raw_status = $body['delivery_status'] ?? '';

        return [
            'status'     => $this->map_delivery_status( $raw_status ),
            'raw_status' => $raw_status,
            'raw'        => $body,
        ];
    }

    /**
     * Get consignment status by the merchant's invoice number.
     *
     * Endpoint: GET /status_by_invoice/{invoice}
     *
     * @since 1.0.0
     * @param string $invoice The merchant invoice / order number.
     * @return array|\WP_Error
     */
    public function get_status_by_invoice( string $invoice ) {
        $response = $this->request( 'GET', "/status_by_invoice/{$invoice}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $raw_status = $body['delivery_status'] ?? '';

        return [
            'status'     => $this->map_delivery_status( $raw_status ),
            'raw_status' => $raw_status,
            'raw'        => $body,
        ];
    }

    /**
     * Get consignment status by tracking code.
     *
     * Endpoint: GET /status_by_trackingcode/{trackingCode}
     *
     * @since 1.0.0
     * @param string $tracking_code The Steadfast tracking code (e.g. "15BAEB8A").
     * @return array|\WP_Error
     */
    public function get_status_by_tracking_code( string $tracking_code ) {
        $response = $this->request( 'GET', "/status_by_trackingcode/{$tracking_code}" );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $raw_status = $body['delivery_status'] ?? '';

        return [
            'status'     => $this->map_delivery_status( $raw_status ),
            'raw_status' => $raw_status,
            'raw'        => $body,
        ];
    }

    /**
     * Get the current wallet balance.
     *
     * Endpoint: GET /get_balance
     *
     * Response: { "status": 200, "current_balance": 0 }
     *
     * @since 1.0.0
     * @return array|\WP_Error { current_balance: float }
     */
    public function get_balance() {
        $response = $this->request( 'GET', '/get_balance' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        return [
            'current_balance' => (float) ( $body['current_balance'] ?? 0 ),
            'raw'             => $body,
        ];
    }

    /**
     * Bulk order creation (Pro feature — up to 500 orders at once).
     *
     * Endpoint: POST /create_order/bulk-order
     * Body: { "data": "<json_encoded_array_of_order_arrays>" }
     *
     * Each item in the array follows the same structure as a single-order payload.
     *
     * @since 1.0.0
     * @param array $orders Array of raw order payload arrays (already built).
     * @return array|\WP_Error Array of per-order results from Steadfast.
     */
    public function create_bulk_orders( array $orders ) {
        if ( empty( $orders ) ) {
            return new \WP_Error( 'steadfast_bulk_empty', __( 'No orders provided for bulk submission.', 'order-pilot' ) );
        }

        // Steadfast accepts a max of 500 orders per call.
        if ( count( $orders ) > 500 ) {
            return new \WP_Error( 'steadfast_bulk_limit', __( 'Steadfast bulk order limit is 500 per request.', 'order-pilot' ) );
        }

        $response = $this->request( 'POST', '/create_order/bulk-order', [
            'data' => wp_json_encode( $orders ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $this->parse_response( $response );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Response is a flat array of per-order results (not wrapped in "consignment").
        return is_array( $body ) ? $body : [];
    }

    /**
     * Cancellation is not supported via the Steadfast API.
     *
     * {@inheritDoc}
     */
    public function cancel_order( string $consignment_id ) {
        return new \WP_Error(
            'steadfast_cancel_unsupported',
            __( 'Steadfast does not support consignment cancellation via API. Please cancel from the Steadfast portal at https://portal.packzy.com.', 'order-pilot' )
        );
    }

    // ─── Payload Builder ─────────────────────────────────────────────────────

    /**
     * Build the Steadfast API payload from a WooCommerce order.
     *
     * Required fields:
     *   invoice, recipient_name, recipient_phone, recipient_address, cod_amount
     *
     * Optional fields:
     *   note, item_description, alternative_phone, recipient_email,
     *   total_lot, delivery_type
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @param array     $extra_data Override or extend fields. Keys mirror the API field names.
     * @return array
     */
    private function build_order_payload( \WC_Order $order, array $extra_data = [] ): array {
        // ── Required fields ───────────────────────────────────────────────────

        $recipient_name = $extra_data['recipient_name']
            ?? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );

        // Fall back to billing name if shipping name is empty.
        if ( '' === $recipient_name ) {
            $recipient_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        }

        $recipient_address = $extra_data['recipient_address']
            ?? trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );

        // Fall back to billing address if shipping is empty.
        if ( '' === $recipient_address ) {
            $recipient_address = $order->get_billing_address_1();
        }

        $payload = [
            'invoice'           => $extra_data['invoice']          ?? $order->get_order_number(),
            'recipient_name'    => $recipient_name,
            'recipient_phone'   => $extra_data['recipient_phone']   ?? $order->get_billing_phone(),
            'recipient_address' => $recipient_address,
            'cod_amount'        => $extra_data['cod_amount']        ?? (float) $order->get_total(),
        ];

        // ── Optional fields ───────────────────────────────────────────────────

        // Delivery note / customer message.
        $note = $extra_data['note'] ?? $order->get_customer_note();
        if ( '' !== (string) $note ) {
            $payload['note'] = (string) $note;
        }

        // Item description summary.
        $item_description = $extra_data['item_description'] ?? $this->get_items_summary( $order );
        if ( '' !== $item_description ) {
            $payload['item_description'] = $item_description;
        }

        // Alternative phone (must be 11 digits if provided).
        if ( ! empty( $extra_data['alternative_phone'] ) ) {
            $payload['alternative_phone'] = (string) $extra_data['alternative_phone'];
        }

        // Recipient email.
        $email = $extra_data['recipient_email'] ?? $order->get_billing_email();
        if ( ! empty( $email ) ) {
            $payload['recipient_email'] = $email;
        }

        // Number of parcels/lots.
        if ( isset( $extra_data['total_lot'] ) ) {
            $payload['total_lot'] = (int) $extra_data['total_lot'];
        }

        // Delivery type: 0 = home delivery, 1 = hub/point pickup.
        if ( isset( $extra_data['delivery_type'] ) ) {
            $payload['delivery_type'] = (int) $extra_data['delivery_type'];
        }

        /**
         * Filter: the final order payload before it is sent to Steadfast.
         *
         * @since 1.0.0
         * @param array     $payload
         * @param \WC_Order $order
         * @param array     $extra_data
         */
        return (array) apply_filters( 'order_pilot_steadfast_order_payload', $payload, $order, $extra_data );
    }

    /**
     * Build a bulk-order item payload from a WC_Order (for bulk submission).
     *
     * Same fields as a single order but returned as a plain array
     * to be collected into the `data` parameter.
     *
     * @since 1.0.0
     * @param \WC_Order $order
     * @param array     $extra_data
     * @return array
     */
    public function build_bulk_item( \WC_Order $order, array $extra_data = [] ): array {
        return $this->build_order_payload( $order, $extra_data );
    }

    /**
     * Generate a brief summary of order line items.
     *
     * @param \WC_Order $order
     * @return string
     */
    private function get_items_summary( \WC_Order $order ): string {
        $parts = [];
        foreach ( $order->get_items() as $item ) {
            $parts[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        return implode( ', ', $parts );
    }

    // ─── Status Mapping ───────────────────────────────────────────────────────

    /**
     * Map a Steadfast delivery_status string to an ODRPLT_Courier_Manager status constant.
     *
     * Steadfast statuses (from official docs):
     *   in_review                         — order placed, under review
     *   pending                           — not yet delivered or cancelled
     *   hold                              — on hold
     *   delivered_approval_pending        — delivered, waiting admin approval
     *   partial_delivered_approval_pending — partially delivered, waiting admin approval
     *   cancelled_approval_pending        — cancelled, waiting admin approval
     *   unknown_approval_pending          — unknown, contact support
     *   delivered                         — delivered, balance added
     *   partial_delivered                 — partially delivered, balance added
     *   cancelled                         — cancelled, balance updated
     *   unknown                           — contact support
     *
     * @since 1.0.0
     * @param string $raw_status Steadfast API delivery_status value.
     * @return string ODRPLT_Courier_Manager status constant.
     */
    public function map_delivery_status( string $raw_status ): string {
        $map = [
            self::STATUS_IN_REVIEW                          => ODRPLT_Courier_Manager::STATUS_PENDING,
            self::STATUS_PENDING                            => ODRPLT_Courier_Manager::STATUS_PENDING,
            self::STATUS_HOLD                               => ODRPLT_Courier_Manager::STATUS_PENDING,
            self::STATUS_DELIVERED_APPROVAL_PENDING         => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            self::STATUS_PARTIAL_DELIVERED_APPROVAL_PENDING => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            self::STATUS_CANCELLED_APPROVAL_PENDING         => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            self::STATUS_UNKNOWN_APPROVAL_PENDING           => ODRPLT_Courier_Manager::STATUS_IN_TRANSIT,
            self::STATUS_DELIVERED                          => ODRPLT_Courier_Manager::STATUS_DELIVERED,
            self::STATUS_PARTIAL_DELIVERED                  => ODRPLT_Courier_Manager::STATUS_DELIVERED,
            self::STATUS_CANCELLED                          => ODRPLT_Courier_Manager::STATUS_CANCELLED,
            self::STATUS_UNKNOWN                            => ODRPLT_Courier_Manager::STATUS_PENDING,
        ];

        /**
         * Filter: Steadfast delivery status to ODRPLT_Courier_Manager status mapping.
         *
         * @since 1.0.0
         * @param array  $map        Default mapping.
         * @param string $raw_status Raw status string from Steadfast.
         */
        $map = (array) apply_filters( 'order_pilot_steadfast_status_map', $map, $raw_status );

        return $map[ $raw_status ] ?? ODRPLT_Courier_Manager::STATUS_PENDING;
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Make an authenticated request to the Steadfast API.
     *
     * Authentication headers (required on every request):
     *   Api-Key       → credentials['api_key']
     *   Secret-Key    → credentials['secret_key']
     *   Content-Type  → application/json
     *
     * @since 1.0.0
     * @param string $method   'GET' | 'POST'.
     * @param string $endpoint Relative endpoint, e.g. '/create_order'.
     * @param array  $body     Request body (for POST requests, JSON-encoded automatically).
     * @return array|\WP_Error Raw wp_remote_request() response array.
     */
    private function request( string $method, string $endpoint, array $body = [] ) {
        $url  = self::API_BASE . $endpoint;
        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'Api-Key'      => $this->credentials['api_key']    ?? '',
                'Secret-Key'   => $this->credentials['secret_key'] ?? '',
                'Content-Type' => 'application/json',
            ],
        ];

        // Encode body for mutating requests
        if ( 'POST' === strtoupper( $method ) && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        // Physical file logging for diagnostics
        if ( class_exists( 'ODRPLT_Logger' ) ) {
            $code      = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
            $resp_body = is_wp_error( $response ) ? [ 'error' => $response->get_error_message() ] : json_decode( wp_remote_retrieve_body( $response ), true );
            ODRPLT_Logger::log_courier_call( 'steadfast', $endpoint, $body, $resp_body, (int) $code );
        }

        return $response;
    }

    /**
     * Parse and validate a Steadfast API response.
     *
     * Returns the decoded JSON body array on success (HTTP 2xx).
     * Returns a WP_Error on HTTP errors or non-2xx status codes.
     *
     * @since 1.0.0
     * @param array $response wp_remote_request() response array.
     * @return array|\WP_Error
     */
    private function parse_response( array $response ) {
        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );
        $body      = json_decode( $raw_body, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = is_array( $body ) && isset( $body['message'] )
                ? $body['message']
                : /* translators: %d: HTTP status code */
                  sprintf( __( 'Steadfast API error (HTTP %d).', 'order-pilot' ), $http_code );

            return new \WP_Error(
                'steadfast_api_error',
                $message,
                [ 'status' => $http_code, 'body' => $body ]
            );
        }

        return is_array( $body ) ? $body : [];
    }
}
