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
 * Plugin URI:        https://orderpilot.io
 * Description:       All-in-One WooCommerce COD Automation — Courier Management, Fraud Detection & Meta Conversion Tracking.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Order Pilot
 * Author URI:        https://orderpilot.io
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
define( 'ORDER_PILOT_DB_VERSION', '1.0.0' );
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
        'OP_Loader'   => ORDER_PILOT_PATH . 'includes/class-op-loader.php',
        'OP_Activator'   => ORDER_PILOT_PATH . 'includes/class-op-activator.php',
        'OP_Deactivator' => ORDER_PILOT_PATH . 'includes/class-op-deactivator.php',
        'OP_License'     => ORDER_PILOT_PATH . 'includes/class-op-license.php',
        'OP_Settings'    => ORDER_PILOT_PATH . 'includes/class-op-settings.php',
        'OP_Database'    => ORDER_PILOT_PATH . 'includes/class-op-database.php',
        'OP_Logger'      => ORDER_PILOT_PATH . 'includes/class-op-logger.php',
        'OP_Admin'       => ORDER_PILOT_PATH . 'admin/class-op-admin.php',
        'OP_Admin_Menu'  => ORDER_PILOT_PATH . 'admin/class-op-admin-menu.php',
        'OP_Rest_Api'    => ORDER_PILOT_PATH . 'admin/class-op-rest-api.php',
        // Couriers
        'OP_Courier_Interface' => ORDER_PILOT_PATH . 'includes/couriers/interface-op-courier.php',
        'OP_Courier_Manager'   => ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-manager.php',
        'OP_Courier_Steadfast' => ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-steadfast.php',
        'OP_Courier_Pathao'    => ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-pathao.php',
        'OP_Courier_RedX'      => ORDER_PILOT_PATH . 'includes/couriers/class-op-courier-redx.php',
        // WooCommerce
        'OP_WC_Integration'  => ORDER_PILOT_PATH . 'includes/woocommerce/class-op-wc-integration.php',
        'OP_Order_Actions'   => ORDER_PILOT_PATH . 'includes/woocommerce/class-op-order-actions.php',
        // Tracking
        'OP_Pixel'            => ORDER_PILOT_PATH . 'includes/tracking/class-op-pixel.php',
        'OP_Tracking_Manager' => ORDER_PILOT_PATH . 'includes/tracking/class-op-tracking-manager.php',
    ];

    if ( isset( $prefixes[ $class ] ) && file_exists( $prefixes[ $class ] ) ) {
        require_once $prefixes[ $class ];
    }
} );

// ─── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook( __FILE__, function () {
    require_once ORDER_PILOT_PATH . 'includes/class-op-activator.php';
    OP_Activator::activate();
} );

register_deactivation_hook( __FILE__, function () {
    require_once ORDER_PILOT_PATH . 'includes/class-op-deactivator.php';
    OP_Deactivator::deactivate();
} );

// ─── Declare WooCommerce HPOS Compatibility ───────────────────────────────────

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
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
