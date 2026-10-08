<?php
/**
 * Settings Manager
 *
 * A typed CRUD wrapper around wp_options for all plugin settings.
 * Credentials are stored per-courier under namespaced option keys.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Settings
 */
class ODRPLT_Settings {

    /**
     * In-memory cache of retrieved options.
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    // ─── General Settings ─────────────────────────────────────────────────────

    /**
     * Get all general settings.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_general(): array {
        $defaults = [
            'currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BDT',
            'enable_courier'  => true,
            'enable_pixel'    => false,
            'enable_fraud'    => false,
            'log_retention'   => 30,
        ];
        return array_merge( $defaults, $this->get( 'order_pilot_settings', [] ) );
    }

    /**
     * Update general settings (merges with existing values).
     *
     * @since 1.0.0
     * @param array $data Partial or full settings array.
     * @return bool
     */
    public function update_general( array $data ): bool {
        $current = $this->get_general();
        $merged  = array_merge( $current, $data );
        return $this->set( 'order_pilot_settings', $merged );
    }

    // ─── Pixel Settings ───────────────────────────────────────────────────────

    /**
     * Get Meta Pixel settings.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_pixel(): array {
        $defaults = [
            'enable_pixel'     => false,
            'pixel_id'         => '',
            'purchase_trigger' => 'order_created',
            'events'           => [
                'page_view'         => true,
                'view_content'      => true,
                'add_to_cart'       => true,
                'initiate_checkout' => true,
                'purchase'          => true,
            ],
        ];
        $saved  = $this->get( 'order_pilot_pixel_settings', [] );
        $merged = array_merge( $defaults, (array) $saved );

        // If enable_pixel not explicitly saved in pixel settings, inherit from general settings.
        if ( ! isset( $saved['enable_pixel'] ) ) {
            $general = $this->get_general();
            if ( isset( $general['enable_pixel'] ) ) {
                $merged['enable_pixel'] = (bool) $general['enable_pixel'];
            }
        }

        if ( ! empty( $saved['events'] ) && is_array( $saved['events'] ) ) {
            $merged['events'] = array_merge( $defaults['events'], $saved['events'] );
        } else {
            $merged['events'] = $defaults['events'];
        }
        return $merged;
    }

    /**
     * Update Pixel settings.
     *
     * @since 1.0.0
     * @param array $data
     * @return bool
     */
    public function update_pixel( array $data ): bool {
        $current = $this->get_pixel();
        $updated = array_merge( $current, $data );
        $saved   = $this->set( 'order_pilot_pixel_settings', $updated );
        if ( isset( $data['enable_pixel'] ) ) {
            $this->update_general( [ 'enable_pixel' => (bool) $data['enable_pixel'] ] );
        }
        return $saved;
    }

    /**
     * Check if Meta Pixel tracking is globally enabled.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_pixel_enabled(): bool {
        $pixel = $this->get_pixel();
        if ( isset( $pixel['enable_pixel'] ) ) {
            return (bool) $pixel['enable_pixel'];
        }
        $general = $this->get_general();
        return ! empty( $general['enable_pixel'] );
    }

    /**
     * Check if courier integration features are enabled.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_courier_enabled(): bool {
        $general = $this->get_general();
        return (bool) ( $general['enable_courier'] ?? true );
    }

    /**
     * Get the Pixel ID.
     *
     * @since 1.0.0
     * @return string
     */
    public function get_pixel_id(): string {
        return sanitize_text_field( $this->get_pixel()['pixel_id'] ?? '' );
    }

    /**
     * Check if a specific Pixel event is enabled.
     *
     * @since 1.0.0
     * @param string $event_key e.g. 'purchase', 'add_to_cart'
     * @return bool
     */
    public function is_pixel_event_enabled( string $event_key ): bool {
        $events = $this->get_pixel()['events'] ?? [];
        if ( empty( $events ) ) {
            return true;
        }
        return isset( $events[ $event_key ] ) ? (bool) $events[ $event_key ] : true;
    }

    /**
     * Get the Purchase event trigger setting.
     *
     * @since 1.0.0
     * @return string 'order_created' | 'payment_completed' | 'order_delivered'
     */
    public function get_purchase_trigger(): string {
        /**
         * Filter: when the Purchase pixel event is triggered.
         *
         * Pro plugin can change this to 'order_delivered' for COD-aware tracking.
         *
         * @since 1.0.0
         * @param string $trigger Default 'order_created'.
         */
        $trigger = $this->get_pixel()['purchase_trigger'] ?? '';
        // Normalise: empty/unset defaults to 'order_created'.
        if ( empty( $trigger ) ) {
            $trigger = 'order_created';
        }
        return (string) apply_filters( 'order_pilot_purchase_trigger', $trigger );
    }

    // ─── CAPI Settings ────────────────────────────────────────────────────────

    /**
     * Get Conversions API settings.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_capi(): array {
        $defaults = [
            'pixel_id'        => '',
            'access_token'    => '',
            'test_code'       => '',
            'enable_capi'     => false,
            'deduplication'   => true,
        ];
        return array_merge( $defaults, $this->get( 'order_pilot_capi_settings', [] ) );
    }

    /**
     * Update CAPI settings.
     *
     * @since 1.0.0
     * @param array $data
     * @return bool
     */
    public function update_capi( array $data ): bool {
        $current = $this->get_capi();
        return $this->set( 'order_pilot_capi_settings', array_merge( $current, $data ) );
    }

    // ─── GA4 Settings ─────────────────────────────────────────────────────────

    /**
     * Get GA4 settings.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_ga4(): array {
        $defaults = [
            'enable_ga4'       => false,
            'measurement_id'   => '',
            'purchase_trigger' => 'order_created',
            'events'           => [
                'view_item'      => true,
                'add_to_cart'    => true,
                'begin_checkout' => true,
                'purchase'       => true,
            ],
        ];
        return array_merge( $defaults, $this->get( 'order_pilot_ga4_settings', [] ) );
    }

    /**
     * Update GA4 settings.
     *
     * @since 1.0.0
     * @param array $data
     * @return bool
     */
    public function update_ga4( array $data ): bool {
        $current = $this->get_ga4();
        return $this->set( 'order_pilot_ga4_settings', array_merge( $current, $data ) );
    }

    // ─── TikTok Settings ──────────────────────────────────────────────────────

    /**
     * Get TikTok Pixel settings.
     *
     * @since 1.0.0
     * @return array
     */
    public function get_tiktok(): array {
        $defaults = [
            'enable_tiktok'    => false,
            'pixel_id'         => '',
            'purchase_trigger' => 'order_created',
            'events'           => [
                'view_content'      => true,
                'add_to_cart'       => true,
                'initiate_checkout' => true,
                'purchase'          => true,
            ],
        ];
        return array_merge( $defaults, $this->get( 'order_pilot_tiktok_settings', [] ) );
    }

    /**
     * Update TikTok settings.
     *
     * @since 1.0.0
     * @param array $data
     * @return bool
     */
    public function update_tiktok( array $data ): bool {
        $current = $this->get_tiktok();
        return $this->set( 'order_pilot_tiktok_settings', array_merge( $current, $data ) );
    }

    // ─── Fraud Settings ───────────────────────────────────────────────────────

    /**
     * Get Fraud Checker settings (including BDCourier API key).
     *
     * @since 1.0.0
     * @return array
     */
    public function get_fraud(): array {
        $defaults = [
            'enable_fraud'          => true,
            'api_key'               => '',
            'auto_check'            => true,
            'auto_block_ip'         => false,
            'block_score_threshold' => 80,
            'block_message'         => 'Sorry, we’re unable to process your order at this time. Please contact our support team for assistance.',
        ];
        return array_merge( $defaults, $this->get( 'order_pilot_fraud_settings', [] ) );
    }

    /**
     * Update Fraud settings.
     *
     * @since 1.0.0
     * @param array $data
     * @return bool
     */
    public function update_fraud( array $data ): bool {
        $current = $this->get_fraud();
        return $this->set( 'order_pilot_fraud_settings', array_merge( $current, $data ) );
    }

    // ─── Courier Automation Settings ─────────────────────────────────────────

    /**
     * Get Courier Automation settings (auto-select, auto-send, default courier, rules).
     *
     * @since 1.0.0
     * @return array
     */
    public function get_courier_automation(): array {
        return $this->get( 'order_pilot_courier_automation', [
            'auto_select'     => false,
            'auto_send'       => false,
            'default_courier' => '',
            'rules'           => [],
        ] );
    }

    /**
     * Update Courier Automation settings (merges with existing).
     *
     * @since 1.0.0
     * @param array $data
     * @return bool
     */
    public function update_courier_automation( array $data ): bool {
        $current = $this->get_courier_automation();
        return $this->set( 'order_pilot_courier_automation', array_merge( $current, $data ) );
    }

    // ─── Courier Credentials ─────────────────────────────────────────────────

    /**
     * Get API credentials for a specific courier.
     *
     * @since 1.0.0
     * @param string $slug Courier slug e.g. 'steadfast', 'pathao', 'redx'.
     * @return array Associative array of credentials.
     */
    public function get_courier_credentials( string $slug ): array {
        $key = 'order_pilot_courier_' . sanitize_key( $slug ) . '_credentials';
        return $this->get( $key, [] );
    }

    /**
     * Save API credentials for a courier.
     *
     * @since 1.0.0
     * @param string $slug  Courier slug.
     * @param array  $creds Credential key/value pairs.
     * @return bool
     */
    public function save_courier_credentials( string $slug, array $creds ): bool {
        $key = 'order_pilot_courier_' . sanitize_key( $slug ) . '_credentials';
        // Do NOT store empty values.
        $creds = array_filter( $creds );
        return $this->set( $key, $creds );
    }

    // ─── Connected Couriers ───────────────────────────────────────────────────

    /**
     * Get the list of connected courier slugs.
     *
     * @since 1.0.0
     * @return string[]
     */
    public function get_connected_couriers(): array {
        return (array) $this->get( 'order_pilot_connected_couriers', [] );
    }

    /**
     * Save the list of connected courier slugs.
     *
     * @since 1.0.0
     * @param string[] $slugs
     * @return bool
     */
    public function set_connected_couriers( array $slugs ): bool {
        return $this->set( 'order_pilot_connected_couriers', array_values( array_unique( $slugs ) ) );
    }

    /**
     * Check if a specific courier is connected.
     *
     * @since 1.0.0
     * @param string $slug
     * @return bool
     */
    public function is_courier_connected( string $slug ): bool {
        return in_array( $slug, $this->get_connected_couriers(), true );
    }

    // ─── Generic Get / Set ────────────────────────────────────────────────────

    /**
     * Get an option value with in-memory caching.
     *
     * @since 1.0.0
     * @param string $key     Option key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get( string $key, $default = null ) {
        if ( ! array_key_exists( $key, $this->cache ) ) {
            $this->cache[ $key ] = get_option( $key, $default );
        }
        return $this->cache[ $key ];
    }

    /**
     * Set an option value and bust the local cache.
     *
     * @since 1.0.0
     * @param string $key   Option key.
     * @param mixed  $value Value to store.
     * @return bool
     */
    public function set( string $key, $value ): bool {
        unset( $this->cache[ $key ] ); // Bust cache.
        return update_option( $key, $value );
    }

    /**
     * Delete an option and remove from cache.
     *
     * @since 1.0.0
     * @param string $key Option key.
     * @return bool
     */
    public function delete( string $key ): bool {
        unset( $this->cache[ $key ] );
        return delete_option( $key );
    }

    /**
     * Flush the in-memory cache.
     *
     * @since 1.0.0
     */
    public function flush_cache(): void {
        $this->cache = [];
    }
}
