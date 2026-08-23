<?php
/**
 * Uninstall
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Drops all custom tables and removes all plugin options.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

// This file is executed by WordPress directly — double-check we're in a valid context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── Drop custom tables ─────────────────────────────────────────────────────

$tables = [
    $wpdb->prefix . 'op_courier_logs',
    $wpdb->prefix . 'op_tracking_logs',
    $wpdb->prefix . 'op_consignments',
    $wpdb->prefix . 'op_fraud_checks',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// ── Delete all options ─────────────────────────────────────────────────────

$options = [
    'order_pilot_settings',
    'order_pilot_connected_couriers',
    'order_pilot_pixel_settings',
    'order_pilot_capi_settings',
    'order_pilot_license',
    'order_pilot_db_version',
    'order_pilot_courier_steadfast_credentials',
    'order_pilot_courier_pathao_credentials',
    'order_pilot_courier_redx_credentials',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── Delete transients ──────────────────────────────────────────────────────

delete_transient( 'order_pilot_pathao_token_cache' );
delete_transient( 'op_pathao_fraud_token' );
