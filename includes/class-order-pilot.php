<?php
/**
 * Core Plugin Orchestrator
 *
 * Bootstraps all plugin components and registers hooks via ODRPLT_Loader.
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
     * @var ODRPLT_Loader
     */
    private ODRPLT_Loader $loader;

    /**
     * Settings manager.
     *
     * @var ODRPLT_Settings
     */
    public ODRPLT_Settings $settings;

    /**
     * License manager.
     *
     * @var ODRPLT_License
     */
    public ODRPLT_License $license;

    /**
     * Database manager.
     *
     * @var ODRPLT_Database
     */
    public ODRPLT_Database $db;

    /**
     * Logger.
     *
     * @var ODRPLT_Logger
     */
    public ODRPLT_Logger $logger;

    /**
     * Courier manager.
     *
     * @var ODRPLT_Courier_Manager
     */
    public ODRPLT_Courier_Manager $couriers;

    /**
     * Fraud checker manager.
     *
     * @var ODRPLT_Fraud_Checker
     */
    public ODRPLT_Fraud_Checker $fraud;

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
        $this->maybe_update_db();
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
        require_once ORDER_PILOT_PATH . 'includes/class-odrplt-loader.php';
        require_once ORDER_PILOT_PATH . 'includes/class-odrplt-license.php';
        require_once ORDER_PILOT_PATH . 'includes/class-odrplt-settings.php';
        require_once ORDER_PILOT_PATH . 'includes/class-odrplt-database.php';
        require_once ORDER_PILOT_PATH . 'includes/class-odrplt-logger.php';

        // Couriers
        require_once ORDER_PILOT_PATH . 'includes/couriers/interface-odrplt-courier.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-manager.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-steadfast.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-pathao.php';
        require_once ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-redx.php';

        // Fraud
        require_once ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-bdcourier.php';
        require_once ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-score.php';
        require_once ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-steadfast.php';
        require_once ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-pathao.php';
        require_once ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-checker.php';

        // WooCommerce
        require_once ORDER_PILOT_PATH . 'includes/woocommerce/class-odrplt-wc-integration.php';
        require_once ORDER_PILOT_PATH . 'includes/woocommerce/class-odrplt-order-actions.php';

        // Tracking
        require_once ORDER_PILOT_PATH . 'includes/tracking/class-odrplt-cookie-helper.php';
        require_once ORDER_PILOT_PATH . 'includes/tracking/class-odrplt-pixel.php';
        require_once ORDER_PILOT_PATH . 'includes/tracking/class-odrplt-tracking-manager.php';

        // Admin
        require_once ORDER_PILOT_PATH . 'admin/class-odrplt-admin.php';
        require_once ORDER_PILOT_PATH . 'admin/class-odrplt-admin-menu.php';
        require_once ORDER_PILOT_PATH . 'admin/class-odrplt-rest-api.php';

        // Instantiate core services
        $this->loader   = new ODRPLT_Loader();
        $this->settings = new ODRPLT_Settings();
        $this->license  = new ODRPLT_License();
        $this->db       = new ODRPLT_Database();
        $this->logger   = new ODRPLT_Logger( $this->db );
        $this->couriers = new ODRPLT_Courier_Manager( $this->settings, $this->logger, $this->license );
        $this->fraud    = new ODRPLT_Fraud_Checker();

        // Register built-in couriers via filter so pro/extensions can add more.
        add_filter( 'order_pilot_couriers', [ $this, 'register_core_couriers' ], 5, 1 );
    }

    /**
     * Check if database schema needs to be created or migrated.
     *
     * @since 1.0.0
     */
    private function maybe_update_db(): void {
        if ( get_option( 'order_pilot_db_version' ) !== ORDER_PILOT_DB_VERSION ) {
            require_once ORDER_PILOT_PATH . 'includes/class-odrplt-activator.php';
            ODRPLT_Activator::create_tables();
            update_option( 'order_pilot_db_version', ORDER_PILOT_DB_VERSION );
        }
    }

    // ─── Hook Registration ────────────────────────────────────────────────────

    /**
     * Define admin-area hooks.
     *
     * @since 1.0.0
     */
    private function define_admin_hooks(): void {
        $admin      = new ODRPLT_Admin( $this->settings, $this->license );
        $admin_menu = new ODRPLT_Admin_Menu( $this->license );

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
        $wc_integration = new ODRPLT_WC_Integration( $this->settings, $this->couriers, $this->logger );
        $order_actions  = new ODRPLT_Order_Actions( $this->couriers, $this->logger );

        // ── Order list columns (HPOS + legacy) ─────────────────────────────

        // HPOS.
        $this->loader->add_filter( 'manage_woocommerce_page_wc-orders_columns', $wc_integration, 'add_columns' );
        $this->loader->add_action( 'manage_woocommerce_page_wc-orders_custom_column', $wc_integration, 'render_column', 10, 2 );

        // Legacy CPT.
        $this->loader->add_filter( 'manage_edit-shop_order_columns', $wc_integration, 'add_columns' );
        $this->loader->add_action( 'manage_shop_order_posts_custom_column', $wc_integration, 'render_column', 10, 2 );

        // ── Bulk actions (HPOS + legacy) ────────────────────────────────────

        $this->loader->add_filter( 'bulk_actions-woocommerce_page_wc-orders', $wc_integration, 'register_bulk_actions' );
        $this->loader->add_filter( 'bulk_actions-edit-shop_order', $wc_integration, 'register_bulk_actions' );

        $this->loader->add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', $wc_integration, 'handle_bulk_action', 10, 3 );
        $this->loader->add_filter( 'handle_bulk_actions-edit-shop_order', $wc_integration, 'handle_bulk_action', 10, 3 );

        // ── Admin notices for bulk results ──────────────────────────────────
        $this->loader->add_action( 'admin_notices', $wc_integration, 'show_bulk_notice' );

        // ── Column action JS (only on WC order list pages) ──────────────────
        $this->loader->add_action( 'admin_footer', $wc_integration, 'output_column_js' );

        // ── WC order meta box actions ───────────────────────────────────────
        $this->loader->add_action( 'woocommerce_order_actions', $order_actions, 'add_order_actions' );
        $this->loader->add_action( 'woocommerce_order_action_odrplt_send_to_courier', $order_actions, 'handle_send_to_courier' );

        // ── Order status change ─────────────────────────────────────────────
        $this->loader->add_action( 'woocommerce_order_status_changed', $wc_integration, 'on_order_status_changed', 10, 4 );

        // ── Fraud Checker hooks ─────────────────────────────────────────────
        $this->loader->add_action( 'order_pilot_before_courier_submission', $this->fraud, 'check_before_courier', 10, 2 );
        $this->loader->add_action( 'woocommerce_thankyou', $this->fraud, 'check_on_order', 5, 1 );
        $this->loader->add_filter( 'order_pilot_fraud_result', $this->fraud, 'get_fraud_result', 10, 2 );
        $this->loader->add_action( 'wp_ajax_odrplt_fraud_check', $this->fraud, 'handle_ajax' );
        $this->loader->add_action( 'wp_ajax_odrplt_bulk_fraud_check', $this->fraud, 'handle_bulk_ajax' );
    }

    /**
     * Define tracking-related hooks.
     *
     * @since 1.0.0
     */
    private function define_tracking_hooks(): void {
        $pixel            = new ODRPLT_Pixel( $this->settings );
        $tracking_manager = new ODRPLT_Tracking_Manager( $this->settings, $pixel, $this->logger );

        // Pixel script injection.
        $this->loader->add_action( 'wp_head', $pixel, 'inject_pixel_script', 1 );

        // Inject the AJAX dispatcher script in footer (priority 5, before event output).
        $this->loader->add_action( 'wp_footer', $pixel, 'inject_ajax_dispatcher', 5 );

        // Print standard pixel events (and stray queued events) at the end of the page.
        $this->loader->add_action( 'wp_footer', $pixel, 'print_page_events', 20 );

        // WooCommerce event hooks.
        $this->loader->add_action( 'woocommerce_after_single_product', $tracking_manager, 'track_view_content' );
        $this->loader->add_action( 'woocommerce_add_to_cart', $tracking_manager, 'track_add_to_cart', 10, 6 );
        $this->loader->add_action( 'woocommerce_before_checkout_form', $tracking_manager, 'track_initiate_checkout' );
        // Fallback for Block checkout / custom checkout templates (hook above doesn't fire there).
        $this->loader->add_action( 'wp_footer', $tracking_manager, 'maybe_track_initiate_checkout', 1 );
        $this->loader->add_action( 'woocommerce_checkout_order_processed', $tracking_manager, 'capture_order_cookies', 10, 1 );
        // Block checkout (Store API) does not fire woocommerce_checkout_order_processed.
        $this->loader->add_action( 'woocommerce_store_api_checkout_order_processed', $tracking_manager, 'capture_order_cookies', 10, 1 );
        $this->loader->add_action( 'woocommerce_thankyou', $tracking_manager, 'track_purchase', 10, 1 );

        // Persist ?fbclid= from Facebook ad clicks into the _fbc cookie so the click ID
        // survives until checkout. Static callback → core add_action (loader needs an object).
        if ( class_exists( 'ODRPLT_Cookie_Helper' ) ) {
            add_action( 'template_redirect', [ 'ODRPLT_Cookie_Helper', 'persist_fbc_cookie' ], 1 );
        }
    }

    /**
     * Define REST API hooks.
     *
     * @since 1.0.0
     */
    private function define_rest_hooks(): void {
        $rest_api = new ODRPLT_Rest_Api( $this->settings, $this->license, $this->couriers, $this->logger, $this->fraud );
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

        $couriers['steadfast'] = new ODRPLT_Courier_Steadfast( $credentials );
        $couriers['pathao']    = new ODRPLT_Courier_Pathao( $credentials );
        $couriers['redx']      = new ODRPLT_Courier_RedX( $credentials );

        return $couriers;
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    /**
     * Get the loader.
     *
     * @return ODRPLT_Loader
     */
    public function get_loader(): ODRPLT_Loader {
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
