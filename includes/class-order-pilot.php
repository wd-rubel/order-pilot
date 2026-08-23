<?php
/**
 * Core Plugin Orchestrator
 *
 * Bootstraps all plugin components and registers hooks via OP_Loader.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Pilot
 *
 * Main plugin singleton. Loads all dependencies and wires components together.
 */
final class Order_Pilot {

    /**
     * Plugin version.
     *
     * @var string
     */
    public string $version = ORDER_PILOT_VERSION;

    /**
     * Singleton instance.
     *
     * @var Order_Pilot|null
     */
    private static ?Order_Pilot $instance = null;

    /**
     * The hook loader.
     *
     * @var OP_Loader
     */
    private OP_Loader $loader;

    /**
     * Settings manager.
     *
     * @var OP_Settings
     */
    public OP_Settings $settings;

    /**
     * License manager.
     *
     * @var OP_License
     */
    public OP_License $license;

    /**
     * Database manager.
     *
     * @var OP_Database
     */
    public OP_Database $db;

    /**
     * Logger.
     *
     * @var OP_Logger
     */
    public OP_Logger $logger;

    /**
     * Courier manager.
     *
     * @var OP_Courier_Manager
     */
    public OP_Courier_Manager $couriers;

    // ─── Singleton ────────────────────────────────────────────────────────────

    /**
     * Returns the singleton instance.
     *
     * @since 1.0.0
     * @return Order_Pilot
     */
    public static function instance(): Order_Pilot {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_woocommerce_hooks();
        $this->define_tracking_hooks();
        $this->define_rest_hooks();
        $this->loader->run();

        /**
         * Fires after Order Pilot has fully loaded all components.
         *
         * Pro and other extensions should hook here to register their features.
         *
         * @since 1.0.0
         * @param Order_Pilot $instance The main plugin instance.
         */
        do_action( 'order_pilot_loaded', $this );
    }

    /**
     * Prevent cloning.
     */
    public function __clone() {}

    /**
     * Prevent unserializing.
     */
    public function __wakeup() {}

    // ─── Dependency Loading ───────────────────────────────────────────────────

    /**
     * Load all required class files and instantiate core components.
     *
     * @since 1.0.0
     */
    private function load_dependencies(): void {
        // Core
        require_once ORDER_PILOT_PATH . 'includes/class-op-loader.php';
        require_once ORDER_PILOT_PATH . 'includes/class-op-license.php';
        require_once ORDER_PILOT_PATH . 'includes/class-op-settings.php';
        require_once ORDER_PILOT_PATH . 'includes/class-op-database.php';
        require_once ORDER_PILOT_PATH . 'includes/class-op-logger.php';

        // Couriers
        require_once ORDER_PILOT_PATH . 'includes/couriers/interface-op-courier.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-manager.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-steadfast.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-pathao.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-redx.php';

        // WooCommerce
        require_once ORDER_PILOT_PATH . 'includes/woocommerce/class-op-wc-integration.php';
        require_once ORDER_PILOT_PATH . 'includes/woocommerce/class-op-order-actions.php';

        // Tracking
        require_once ORDER_PILOT_PATH . 'includes/tracking/class-op-pixel.php';
        require_once ORDER_PILOT_PATH . 'includes/tracking/class-op-tracking-manager.php';

        // Admin
        require_once ORDER_PILOT_PATH . 'admin/class-op-admin.php';
        require_once ORDER_PILOT_PATH . 'admin/class-op-admin-menu.php';
        require_once ORDER_PILOT_PATH . 'admin/class-op-rest-api.php';

        // Instantiate core services
        $this->loader   = new OP_Loader();
        $this->settings = new OP_Settings();
        $this->license  = new OP_License();
        $this->db       = new OP_Database();
        $this->logger   = new OP_Logger( $this->db );
        $this->couriers = new OP_Courier_Manager( $this->settings, $this->logger, $this->license );

        // Register built-in couriers via filter so pro/extensions can add more.
        add_filter( 'order_pilot_couriers', [ $this, 'register_core_couriers' ], 5, 1 );
    }

    // ─── Hook Registration ────────────────────────────────────────────────────

    /**
     * Define admin-area hooks.
     *
     * @since 1.0.0
     */
    private function define_admin_hooks(): void {
        $admin      = new OP_Admin( $this->settings, $this->license );
        $admin_menu = new OP_Admin_Menu( $this->license );

        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
        $this->loader->add_action( 'admin_menu', $admin_menu, 'register_menus' );
        $this->loader->add_action( 'admin_init', $admin, 'handle_settings_save' );
    }

    /**
     * Define WooCommerce-related hooks.
     *
     * @since 1.0.0
     */
    private function define_woocommerce_hooks(): void {
        $wc_integration = new OP_WC_Integration( $this->settings, $this->couriers, $this->logger );
        $order_actions  = new OP_Order_Actions( $this->couriers, $this->logger );

        // Add courier status column to order list.
        $this->loader->add_filter( 'manage_woocommerce_page_wc-orders_columns', $wc_integration, 'add_courier_column' );
        $this->loader->add_action( 'manage_woocommerce_page_wc-orders_custom_column', $wc_integration, 'render_courier_column', 10, 2 );

        // Legacy order list support.
        $this->loader->add_filter( 'manage_edit-shop_order_columns', $wc_integration, 'add_courier_column' );
        $this->loader->add_action( 'manage_shop_order_posts_custom_column', $wc_integration, 'render_courier_column', 10, 2 );

        // Order action buttons.
        $this->loader->add_action( 'woocommerce_order_actions', $order_actions, 'add_order_actions' );
        $this->loader->add_action( 'woocommerce_order_action_op_send_to_courier', $order_actions, 'handle_send_to_courier' );

        // Order status change hook (for status sync from courier).
        $this->loader->add_action( 'woocommerce_order_status_changed', $wc_integration, 'on_order_status_changed', 10, 4 );
    }

    /**
     * Define tracking-related hooks.
     *
     * @since 1.0.0
     */
    private function define_tracking_hooks(): void {
        $pixel           = new OP_Pixel( $this->settings );
        $tracking_manager = new OP_Tracking_Manager( $this->settings, $pixel );

        // Pixel script injection.
        $this->loader->add_action( 'wp_head', $pixel, 'inject_pixel_script', 1 );

        // WooCommerce event hooks.
        $this->loader->add_action( 'woocommerce_after_single_product', $tracking_manager, 'track_view_content' );
        $this->loader->add_action( 'woocommerce_add_to_cart', $tracking_manager, 'track_add_to_cart', 10, 6 );
        $this->loader->add_action( 'woocommerce_before_checkout_form', $tracking_manager, 'track_initiate_checkout' );
        $this->loader->add_action( 'woocommerce_thankyou', $tracking_manager, 'track_purchase', 10, 1 );
    }

    /**
     * Define REST API hooks.
     *
     * @since 1.0.0
     */
    private function define_rest_hooks(): void {
        $rest_api = new OP_Rest_Api( $this->settings, $this->license, $this->couriers, $this->logger );
        $this->loader->add_action( 'rest_api_init', $rest_api, 'register_routes' );
    }

    // ─── Core Courier Registration ─────────────────────────────────────────────

    /**
     * Register the built-in courier adapters.
     *
     * Hooked onto `order_pilot_couriers` at priority 5 so pro can override or extend at 10+.
     *
     * @since 1.0.0
     * @param array $couriers Existing registered couriers.
     * @return array
     */
    public function register_core_couriers( array $couriers ): array {
        $credentials = $this->settings;

        $couriers['steadfast'] = new OP_Courier_Steadfast( $credentials );
        $couriers['pathao']    = new OP_Courier_Pathao( $credentials );
        $couriers['redx']      = new OP_Courier_RedX( $credentials );

        return $couriers;
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    /**
     * Get the loader.
     *
     * @return OP_Loader
     */
    public function get_loader(): OP_Loader {
        return $this->loader;
    }

    /**
     * Get plugin version.
     *
     * @return string
     */
    public function get_version(): string {
        return $this->version;
    }
}
