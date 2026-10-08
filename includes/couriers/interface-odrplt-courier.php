<?php
/**
 * Courier Interface
 *
 * Every courier adapter must implement this contract.
 * This enables a clean adapter pattern — the manager only
 * needs to know about this interface, not any specific courier.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface ODRPLT_Courier_Interface
 */
interface ODRPLT_Courier_Interface {

    /**
     * Return the unique machine slug for this courier.
     *
     * @since 1.0.0
     * @return string e.g. 'steadfast', 'pathao', 'redx'
     */
    public function get_slug(): string;

    /**
     * Return the human-readable display name.
     *
     * @since 1.0.0
     * @return string e.g. 'Steadfast Courier'
     */
    public function get_name(): string;

    /**
     * Return the fields required to connect this courier
     * (shown in the settings / couriers page).
     *
     * @since 1.0.0
     * @return array<string, array{label:string, type:string, required:bool}>
     */
    public function get_credential_fields(): array;

    /**
     * Check whether the stored credentials are sufficient to make API calls.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_configured(): bool;

    /**
     * Submit a WooCommerce order to this courier.
     *
     * @since 1.0.0
     * @param \WC_Order $order       The WooCommerce order.
     * @param array     $extra_data  Optional extra data (e.g. override city/area).
     * @return array|\WP_Error {
     *     On success, an array with at minimum:
     *     @type string $consignment_id
     *     @type string $tracking_id
     *     @type string $status
     *     @type mixed  $raw           Raw API response.
     * }
     */
    public function create_order( \WC_Order $order, array $extra_data = [] );

    /**
     * Retrieve the current delivery status for a consignment.
     *
     * @since 1.0.0
     * @param string $consignment_id The consignment/parcel ID.
     * @return array|\WP_Error {
     *     On success:
     *     @type string $status     Normalized status (see ODRPLT_Courier_Manager::normalize_status()).
     *     @type string $raw_status Raw status string from the courier API.
     *     @type mixed  $raw        Full raw API response.
     * }
     */
    public function get_status( string $consignment_id );

    /**
     * Cancel a consignment.
     *
     * @since 1.0.0
     * @param string $consignment_id
     * @return true|\WP_Error True on success.
     */
    public function cancel_order( string $consignment_id );
}
