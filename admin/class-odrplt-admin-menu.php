<?php
/**
 * Admin Menu
 *
 * Registers the Order Pilot admin menu and sub-pages.
 * The "Orders" entry redirects to WooCommerce's native order screen
 * (HPOS-compatible), so WooCommerce remains the single source of truth.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Admin_Menu
 */
class ODRPLT_Admin_Menu {

    /**
     * @var ODRPLT_License
     */
    private ODRPLT_License $license;

    /**
     * Constructor.
     *
     * @param ODRPLT_License $license
     */
    public function __construct( ODRPLT_License $license ) {
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
         * Pro adds Fraud Checker page here.
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
                $menu_title .= ' <span class="odrplt-pro-badge">PRO</span>';
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

        // ── Orders redirect (injected directly into global $submenu) ────────
        // We inject a raw URL entry rather than a WordPress-registered page so
        // clicking it navigates straight to WooCommerce's native order screen.
        // This avoids duplicating WC order management and is HPOS-compatible.
        $this->inject_orders_redirect_link();
    }

    /**
     * Inject the "Orders" submenu entry that links to WooCommerce's native
     * order listing screen (HPOS or legacy).
     *
     * WordPress does not officially support external URLs in submenus, but
     * directly appending to `$submenu` is the standard community practice and
     * is safe as long as the current user has the required capability.
     *
     * @since 1.0.0
     */
    private function inject_orders_redirect_link(): void {
        global $submenu;

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $wc_orders_url = $this->get_wc_orders_url();

        // WP submenu format: [ page_title, capability, url, menu_title ]
        // Insert after index 0 (Dashboard) so Orders appears second.
        $orders_entry = [
            __( 'Orders', 'order-pilot' ),
            'manage_woocommerce',
            $wc_orders_url,
            __( 'Orders', 'order-pilot' ),
        ];

        if ( isset( $submenu['order-pilot'] ) ) {
            // Splice in after the first entry (Dashboard).
            array_splice( $submenu['order-pilot'], 1, 0, [ $orders_entry ] );
        } else {
            $submenu['order-pilot'][] = $orders_entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        }
    }

    /**
     * Get the WooCommerce orders admin URL, detecting HPOS vs. legacy mode.
     *
     * @since 1.0.0
     * @return string
     */
    public static function get_wc_orders_url(): string {
        // Official WC 7.1+ API for HPOS detection.
        if (
            class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            return admin_url( 'admin.php?page=wc-orders' );
        }

        // Legacy (CPT-based) orders screen.
        return admin_url( 'edit.php?post_type=shop_order' );
    }

    /**
     * Get the core sub-page definitions.
     * The "Orders" page is intentionally absent — it is injected as a
     * redirect link via inject_orders_redirect_link().
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
                'slug'       => 'order-pilot-fraud',
                'title'      => __( 'Fraud Checker', 'order-pilot' ),
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read-only view parameter for mounting the React SPA.
        $current_page = sanitize_key( wp_unslash( $_GET['page'] ?? 'order-pilot' ) );

        echo '<div id="order-pilot-root" class="order-pilot-admin" data-page="' . esc_attr( $current_page ) . '">';
        echo '<div class="odrplt-loading-screen"><span class="odrplt-spinner"></span></div>';
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
