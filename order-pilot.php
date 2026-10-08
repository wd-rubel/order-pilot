<?php
/**
 * Order Pilot
 *
 * @package           OrderPilot
 * @author            Order Pilot
 * @copyright         2024 Order Pilot
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Order Pilot
 * Plugin URI:        https://arsyntax.com/asxc-product/order-pilot/
 * Description:       All-in-One WooCommerce COD Automation — Courier Management, Fraud Detection & Meta Conversion Tracking.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Order Pilot
 * Author URI:        https://arsyntax.com/asxc-product/order-pilot/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-pilot
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:      9.9
 */

defined( 'ABSPATH' ) || exit;

// ─── Plugin Constants ─────────────────────────────────────────────────────────

define( 'ORDER_PILOT_VERSION',    '1.0.0' );
define( 'ORDER_PILOT_DB_VERSION', '1.0.2' );
define( 'ORDER_PILOT_FILE',       __FILE__ );
define( 'ORDER_PILOT_PATH',       plugin_dir_path( __FILE__ ) );
define( 'ORDER_PILOT_URL',        plugin_dir_url( __FILE__ ) );
define( 'ORDER_PILOT_BASENAME',   plugin_basename( __FILE__ ) );
define( 'ORDER_PILOT_SLUG',       'order-pilot' );

// ─── Autoloader ───────────────────────────────────────────────────────────────

/**
 * Simple PSR-4-style autoloader for Order Pilot classes.
 * Maps class name prefixes to directory paths.
 */
spl_autoload_register( function ( $class ) {
    $prefixes = [
        'Order_Pilot' => ORDER_PILOT_PATH . 'includes/class-order-pilot.php',
        'ODRPLT_Loader'   => ORDER_PILOT_PATH . 'includes/class-odrplt-loader.php',
        'ODRPLT_Activator'   => ORDER_PILOT_PATH . 'includes/class-odrplt-activator.php',
        'ODRPLT_Deactivator' => ORDER_PILOT_PATH . 'includes/class-odrplt-deactivator.php',
        'ODRPLT_License'     => ORDER_PILOT_PATH . 'includes/class-odrplt-license.php',
        'ODRPLT_Settings'    => ORDER_PILOT_PATH . 'includes/class-odrplt-settings.php',
        'ODRPLT_Database'    => ORDER_PILOT_PATH . 'includes/class-odrplt-database.php',
        'ODRPLT_Logger'      => ORDER_PILOT_PATH . 'includes/class-odrplt-logger.php',
        'ODRPLT_Admin'       => ORDER_PILOT_PATH . 'admin/class-odrplt-admin.php',
        'ODRPLT_Admin_Menu'  => ORDER_PILOT_PATH . 'admin/class-odrplt-admin-menu.php',
        'ODRPLT_Rest_Api'    => ORDER_PILOT_PATH . 'admin/class-odrplt-rest-api.php',
        // Couriers
        'ODRPLT_Courier_Interface' => ORDER_PILOT_PATH . 'includes/couriers/interface-odrplt-courier.php',
        'ODRPLT_Courier_Manager'   => ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-manager.php',
        'ODRPLT_Courier_Steadfast' => ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-steadfast.php',
        'ODRPLT_Courier_Pathao'    => ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-pathao.php',
        'ODRPLT_Courier_RedX'      => ORDER_PILOT_PATH . 'includes/couriers/class-odrplt-courier-redx.php',
        // WooCommerce
        'ODRPLT_WC_Integration'  => ORDER_PILOT_PATH . 'includes/woocommerce/class-odrplt-wc-integration.php',
        'ODRPLT_Order_Actions'   => ORDER_PILOT_PATH . 'includes/woocommerce/class-odrplt-order-actions.php',
        // Tracking
        'ODRPLT_Cookie_Helper'    => ORDER_PILOT_PATH . 'includes/tracking/class-odrplt-cookie-helper.php',
        'ODRPLT_Pixel'            => ORDER_PILOT_PATH . 'includes/tracking/class-odrplt-pixel.php',
        'ODRPLT_Tracking_Manager' => ORDER_PILOT_PATH . 'includes/tracking/class-odrplt-tracking-manager.php',
        // Fraud
        'ODRPLT_Fraud_Checker'   => ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-checker.php',
        'ODRPLT_Fraud_Score'     => ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-score.php',
        'ODRPLT_Fraud_BDCourier' => ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-bdcourier.php',
        'ODRPLT_Fraud_Steadfast' => ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-steadfast.php',
        'ODRPLT_Fraud_Pathao'    => ORDER_PILOT_PATH . 'includes/fraud/class-odrplt-fraud-pathao.php',
    ];

    if ( isset( $prefixes[ $class ] ) && file_exists( $prefixes[ $class ] ) ) {
        require_once $prefixes[ $class ];
    }
} );

// ─── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook( __FILE__, function () {
    require_once ORDER_PILOT_PATH . 'includes/class-odrplt-activator.php';
    ODRPLT_Activator::activate();
} );

register_deactivation_hook( __FILE__, function () {
    require_once ORDER_PILOT_PATH . 'includes/class-odrplt-deactivator.php';
    ODRPLT_Deactivator::deactivate();
} );

// ─── Declare WooCommerce HPOS Compatibility ───────────────────────────────────

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
} );

// ─── Bootstrap ────────────────────────────────────────────────────────────────

/**
 * Returns the main instance of Order_Pilot.
 *
 * @since 1.0.0
 * @return Order_Pilot
 */
function order_pilot() {
    return Order_Pilot::instance();
}

/**
 * Initialize on plugins_loaded to ensure WooCommerce is available.
 */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                 esc_html__( 'Order Pilot requires WooCommerce to be installed and active.', 'order-pilot' ) .
                 '</p></div>';
        } );
        return;
    }

    order_pilot();
}, 10 );
