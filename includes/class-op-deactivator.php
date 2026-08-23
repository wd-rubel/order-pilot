<?php
/**
 * Plugin Deactivator
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class OP_Deactivator
 *
 * Runs cleanup logic on plugin deactivation.
 * Note: Tables and options are deliberately NOT deleted here.
 * Full uninstall cleanup belongs in uninstall.php.
 */
class OP_Deactivator {

    /**
     * Run deactivation routines.
     *
     * @since 1.0.0
     */
    public static function deactivate(): void {
        // Cancel any scheduled cron jobs added by this plugin.
        self::clear_scheduled_events();

        /**
         * Fires after the plugin has been deactivated.
         *
         * Pro and extensions should hook here to clean up their own schedules.
         *
         * @since 1.0.0
         */
        do_action( 'order_pilot_deactivated' );

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Clear all WP-Cron events registered by this plugin.
     *
     * @since 1.0.0
     */
    private static function clear_scheduled_events(): void {
        $events = [
            'order_pilot_sync_courier_statuses',
            'order_pilot_bulk_fraud_check',
            'order_pilot_clean_logs',
        ];

        foreach ( $events as $event ) {
            $timestamp = wp_next_scheduled( $event );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $event );
            }
        }
    }
}
