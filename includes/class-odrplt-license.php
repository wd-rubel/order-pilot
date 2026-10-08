<?php
/**
 * License Manager (Stub)
 *
 * Provides a fallback license implementation.
 * The real license verification logic will be injected later
 * via the custom license management system without modifying this class.
 *
 * Pro plugin overrides `order_pilot_is_pro` and `order_pilot_license_status`
 * filters to change the return values of this class.
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_License
 *
 * Fallback license class. All methods are filterable so the pro plugin
 * (or a future license server integration) can override them without
 * touching this file.
 */
class ODRPLT_License {

    /**
     * License status constants.
     */
    const STATUS_ACTIVE   = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_EXPIRED  = 'expired';
    const STATUS_INVALID  = 'invalid';

    /**
     * Cached pro status.
     *
     * @var bool|null
     */
    private ?bool $is_pro_cache = null;

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Check whether the Pro add-on is active and licensed.
     *
     * This is the primary gate used throughout the codebase.
     * Pro plugin overrides the `order_pilot_is_pro` filter.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_pro(): bool {
        if ( null !== $this->is_pro_cache ) {
            return $this->is_pro_cache;
        }

        /**
         * Filter: whether the Pro version is active and licensed.
         *
         * Pro plugin hooks here with priority 10 and returns true.
         *
         * @since 1.0.0
         * @param bool $is_pro Default false (free version).
         */
        $this->is_pro_cache = (bool) apply_filters( 'order_pilot_is_pro', false );

        return $this->is_pro_cache;
    }

    /**
     * Check whether the current license key is valid.
     *
     * Wraps the filterable license check so the real verification
     * logic can be injected later.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_valid(): bool {
        /**
         * Filter: whether the stored license key is valid.
         *
         * @since 1.0.0
         * @param bool $valid Default false until a real license system is connected.
         */
        return (bool) apply_filters( 'order_pilot_license_is_valid', false );
    }

    /**
     * Get the stored license key.
     *
     * @since 1.0.0
     * @return string Empty string if none saved.
     */
    public function get_key(): string {
        $license = get_option( 'order_pilot_license', [] );
        return sanitize_text_field( $license['key'] ?? '' );
    }

    /**
     * Get the current license status string.
     *
     * @since 1.0.0
     * @return string One of the STATUS_* constants.
     */
    public function get_status(): string {
        /**
         * Filter: the license status string.
         *
         * @since 1.0.0
         * @param string $status Default 'inactive'.
         */
        return (string) apply_filters( 'order_pilot_license_status', self::STATUS_INACTIVE );
    }

    /**
     * Store a license key.
     *
     * @since 1.0.0
     * @param string $key The license key to save.
     * @return bool
     */
    public function save_key( string $key ): bool {
        $license          = get_option( 'order_pilot_license', [] );
        $license['key']   = sanitize_text_field( $key );
        $license['status'] = self::STATUS_INACTIVE; // Reset; real system will verify.

        $this->is_pro_cache = null; // Bust the cache.

        /**
         * Fires when a license key is saved (before verification).
         *
         * @since 1.0.0
         * @param string $key The license key.
         */
        do_action( 'order_pilot_license_key_saved', $key );

        return update_option( 'order_pilot_license', $license );
    }

    /**
     * Get the maximum number of courier connections allowed by the current plan.
     *
     * Free: 2  |  Pro: unlimited (PHP_INT_MAX)
     *
     * @since 1.0.0
     * @return int
     */
    public function max_couriers(): int {
        /**
         * Filter: maximum number of active courier connections.
         *
         * Pro plugin hooks here to return PHP_INT_MAX.
         *
         * @since 1.0.0
         * @param int $max Default 2 for free plan.
         */
        return (int) apply_filters( 'order_pilot_max_couriers', 2 );
    }

    /**
     * Flush the is_pro cache.
     * Useful after activating/deactivating the pro plugin at runtime.
     *
     * @since 1.0.0
     */
    public function flush_cache(): void {
        $this->is_pro_cache = null;
    }
}
