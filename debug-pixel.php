<?php
/**
 * Diagnostic: Dump all pixel/tracking settings from the database.
 * Place in wp-content/plugins/order-pilot/ and open from browser, then delete.
 */

$wp_load = dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    die( 'Cannot find wp-load.php' );
}
require_once $wp_load;

if ( ! current_user_can( 'manage_options' ) ) {
    die( 'Access denied. Log in as admin first.' );
}

header( 'Content-Type: text/plain; charset=utf-8' );

$keys = [
    'order_pilot_settings',
    'order_pilot_pixel_settings',
    'order_pilot_capi_settings',
    'order_pilot_tiktok_settings',
    'order_pilot_ga4_settings',
];

foreach ( $keys as $key ) {
    $val = get_option( $key );
    echo "=== {$key} ===\n";
    echo print_r( $val, true );
    echo "\n\n";
}

echo "=== ODRPLT_Settings::get_pixel_id() ===\n";
if ( function_exists( 'order_pilot' ) ) {
    echo var_export( order_pilot()->settings->get_pixel_id(), true );
} else {
    echo "(order_pilot() not available)";
}
echo "\n";
