<?php
/**
 * Admin Menu
 *
 * Registers the Order Pilot admin menu and sub-pages.
 * Pro pages are always visible but show an upgrade prompt when not licensed.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Admin_Menu
 */
class OP_Admin_Menu {

    /**
     * @var OP_License
     */
    private OP_License $license;

    /**
     * Constructor.
     *
     * @param OP_License $license
     */
    public function __construct( OP_License $license ) {
        $this->license = $license;
    }

    // ─── Menu Registration ────────────────────────────────────────────────────

    /**
     * Register all admin menu pages.
     * Hooked to `admin_menu`.
     *
     * @since 1.0.0
     */
    public function register_menus(): void {
        // Top-level menu.
        add_menu_page(
            __( 'Order Pilot', 'order-pilot' ),
            __( 'Order Pilot', 'order-pilot' ),
            'manage_woocommerce',
            'order-pilot',
            [ $this, 'render_page' ],
            $this->get_menu_icon(),
            56 // After WooCommerce.
        );

        // ── Sub-pages ───────────────────────────────────────────────────────

        $pages = $this->get_pages();

        /**
         * Filter: admin sub-pages registered under Order Pilot.
         *
         * Pro adds Fraud Checker and Analytics pages here.
         *
         * @since 1.0.0
         * @param array $pages Array of page definitions.
         */
        $pages = (array) apply_filters( 'order_pilot_admin_pages', $pages );

        foreach ( $pages as $page ) {
            if ( empty( $page['slug'] ) || empty( $page['title'] ) ) {
                continue;
            }

            // Label with "PRO" badge for locked features.
            $menu_title = $page['title'];
            if ( ! empty( $page['pro'] ) && ! $this->license->is_pro() ) {
                $menu_title .= ' <span class="op-pro-badge">PRO</span>';
            }

            add_submenu_page(
                'order-pilot',
                $page['title'],
                $menu_title,
                $page['capability'] ?? 'manage_woocommerce',
                $page['slug'],
                [ $this, 'render_page' ]
            );
        }
    }

    /**
     * Get the core sub-page definitions.
     *
     * @since 1.0.0
     * @return array
     */
    private function get_pages(): array {
        return [
            [
                'slug'       => 'order-pilot',
                'title'      => __( 'Dashboard', 'order-pilot' ),
                'capability' => 'manage_woocommerce',
                'pro'        => false,
            ],
            [
                'slug'       => 'order-pilot-orders',
                'title'      => __( 'Orders', 'order-pilot' ),
                'capability' => 'manage_woocommerce',
                'pro'        => false,
            ],
            [
                'slug'       => 'order-pilot-fraud',
                'title'      => __( 'Fraud Checker', 'order-pilot' ),
                'capability' => 'manage_woocommerce',
                'pro'        => true,
            ],
            [
                'slug'       => 'order-pilot-couriers',
                'title'      => __( 'Couriers', 'order-pilot' ),
                'capability' => 'manage_woocommerce',
                'pro'        => false,
            ],
            [
                'slug'       => 'order-pilot-tracking',
                'title'      => __( 'Tracking', 'order-pilot' ),
                'capability' => 'manage_woocommerce',
                'pro'        => false,
            ],
            [
                'slug'       => 'order-pilot-analytics',
                'title'      => __( 'Analytics', 'order-pilot' ),
                'capability' => 'manage_woocommerce',
                'pro'        => true,
            ],
            [
                'slug'       => 'order-pilot-logs',
                'title'      => __( 'Logs', 'order-pilot' ),
                'capability' => 'manage_woocommerce',
                'pro'        => false,
            ],
            [
                'slug'       => 'order-pilot-settings',
                'title'      => __( 'Settings', 'order-pilot' ),
                'capability' => 'manage_options',
                'pro'        => false,
            ],
        ];
    }

    // ─── Page Renderer ────────────────────────────────────────────────────────

    /**
     * Render the React app root container.
     *
     * All admin pages are rendered by the React SPA — this just outputs the mount point.
     *
     * @since 1.0.0
     */
    public function render_page(): void {
        $current_page = sanitize_key( $_GET['page'] ?? 'order-pilot' );

        echo '<div id="order-pilot-root" class="order-pilot-admin" data-page="' . esc_attr( $current_page ) . '">';
        echo '<div class="op-loading-screen"><span class="op-spinner"></span></div>';
        echo '</div>';
    }

    // ─── Icon ─────────────────────────────────────────────────────────────────

    /**
     * Get the menu icon (base64 SVG).
     *
     * @since 1.0.0
     * @return string
     */
    private function get_menu_icon(): string {
        // Simple airplane/pilot SVG icon.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21 4 19 4c-2 0-4 2-4 2L7 7l-3.5 3.5L11 12 4.3 18.7A1.4 1.4 0 0 0 5 21l6.7-6.7 7.1 5.8"/></svg>';

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }
}
