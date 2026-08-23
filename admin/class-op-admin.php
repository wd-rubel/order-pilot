<?php
/**
 * Admin Controller
 *
 * Handles asset enqueueing and settings save for the WP admin area.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Admin
 */
class OP_Admin {

    /**
     * @var OP_Settings
     */
    private OP_Settings $settings;

    /**
     * @var OP_License
     */
    private OP_License $license;

    /**
     * Constructor.
     *
     * @param OP_Settings $settings
     * @param OP_License  $license
     */
    public function __construct( OP_Settings $settings, OP_License $license ) {
        $this->settings = $settings;
        $this->license  = $license;
    }

    // ─── Asset Enqueueing ─────────────────────────────────────────────────────

    /**
     * Enqueue admin scripts and styles.
     * Only loads on Order Pilot admin pages.
     *
     * @since 1.0.0
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_scripts( string $hook_suffix ): void {
        if ( ! $this->is_op_page( $hook_suffix ) ) {
            return;
        }

        // Main React bundle (built by @wordpress/scripts).
        $asset_file = ORDER_PILOT_PATH . 'build/index.asset.php';

        if ( ! file_exists( $asset_file ) ) {
            // Dev mode: build hasn't run yet.
            return;
        }

        $asset = require $asset_file;

        wp_enqueue_script(
            'order-pilot-admin',
            ORDER_PILOT_URL . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'order-pilot-admin',
            ORDER_PILOT_URL . 'build/index.css',
            [ 'wp-components' ],
            $asset['version']
        );

        // Localize data for the React app.
        wp_localize_script( 'order-pilot-admin', 'orderPilot', $this->get_localized_data() );
    }

    /**
     * Build the data object passed to the React app via wp_localize_script.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_localized_data(): array {
        $data = [
            'nonce'       => wp_create_nonce( 'order_pilot_nonce' ),
            'restUrl'     => esc_url_raw( rest_url( 'order-pilot/v1/' ) ),
            'restNonce'   => wp_create_nonce( 'wp_rest' ),
            'adminUrl'    => esc_url_raw( admin_url() ),
            'isPro'       => $this->license->is_pro(),
            'version'     => ORDER_PILOT_VERSION,
            'currency'    => get_woocommerce_currency(),
            'currencySymbol' => get_woocommerce_currency_symbol(),
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'pluginUrl'   => ORDER_PILOT_URL,
            'settings'    => [
                'general'  => $this->settings->get_general(),
                'pixel'    => [
                    'pixel_id' => $this->settings->get_pixel_id(),
                    'events'   => $this->settings->get_pixel()['events'] ?? [],
                    'purchase_trigger' => $this->settings->get_purchase_trigger(),
                ],
            ],
        ];

        /**
         * Filter: data passed to the React admin app via wp_localize_script.
         *
         * Pro adds its own settings (fraud thresholds, CAPI config, etc.) here.
         *
         * @since 1.0.0
         * @param array $data
         */
        return (array) apply_filters( 'order_pilot_admin_localized_data', $data );
    }

    // ─── Settings Save ────────────────────────────────────────────────────────

    /**
     * Handle traditional form-based settings saves (non-REST fallback).
     * The React app uses REST API, so this handles direct WP admin form posts.
     *
     * @since 1.0.0
     */
    public function handle_settings_save(): void {
        if (
            ! isset( $_POST['op_settings_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['op_settings_nonce'] ) ), 'op_save_settings' )
            || ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        $tab = sanitize_key( $_POST['op_tab'] ?? 'general' );

        /**
         * Fires when admin settings are saved.
         * Pro handles its own settings tabs by hooking here.
         *
         * @since 1.0.0
         * @param string $tab  Settings tab slug.
         * @param array  $post Raw $_POST data (sanitize inside the handler).
         */
        do_action( 'order_pilot_settings_save_' . $tab, $tab, $_POST );
        do_action( 'order_pilot_settings_saved', $tab, $_POST );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Determine whether the current admin page belongs to Order Pilot.
     *
     * @since 1.0.0
     * @param string $hook_suffix
     * @return bool
     */
    private function is_op_page( string $hook_suffix ): bool {
        // All OP pages start with 'toplevel_page_order-pilot' or have 'order-pilot' in the hook.
        return str_contains( $hook_suffix, 'order-pilot' );
    }
}
