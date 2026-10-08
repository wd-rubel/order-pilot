<?php
/**
 * Cookie & Request Helper
 *
 * Reads Meta Pixel cookies (_fbp, _fbc), detects the real client IP,
 * and provides utilities for storing FBP/FBC in WooCommerce order meta
 * so they are available for delayed Purchase events (e.g. on delivery).
 *
 * @package OrderPilot
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ODRPLT_Cookie_Helper
 */
class ODRPLT_Cookie_Helper {

    const META_FBP = '_odrplt_fbp';
    const META_FBC = '_odrplt_fbc';
    const META_IP  = '_odrplt_client_ip';
    const META_UA  = '_odrplt_user_agent';
    const META_URL = '_odrplt_event_source_url';

    public static function get_fbp(): string {
        return isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';
    }

    /**
     * Get the fbc (click ID) value.
     *
     * Priority: fbclid in current URL (freshest click) → _fbc cookie.
     * Format per Meta docs: fb.{subdomain_index}.{creation_time_ms}.{fbclid}
     * The fbclid value itself must NOT be modified (case-sensitive).
     */
    public static function get_fbc(): string {
        $fbclid = self::get_fbclid_from_url();
        $cookie = ! empty( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '';

        if ( $fbclid ) {
            // Keep existing cookie if it already holds this same click ID.
            if ( $cookie && self::fbclid_from_fbc( $cookie ) === $fbclid ) {
                return $cookie;
            }
            return self::build_fbc( $fbclid );
        }

        return $cookie;
    }

    /**
     * Read the raw fbclid query parameter (unmodified, case preserved).
     */
    private static function get_fbclid_from_url(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading campaign tracking URL parameter.
        if ( empty( $_GET['fbclid'] ) || ! is_string( $_GET['fbclid'] ) ) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw = trim( wp_unslash( $_GET['fbclid'] ) );
        // fbclid is URL-safe; strip anything else without altering case.
        return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw );
    }

    /**
     * Build an fbc string from an fbclid (creation time in milliseconds).
     */
    private static function build_fbc( string $fbclid ): string {
        return 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . $fbclid;
    }

    /**
     * Extract the fbclid portion of an fbc value.
     */
    private static function fbclid_from_fbc( string $fbc ): string {
        $parts = explode( '.', $fbc, 4 );
        return isset( $parts[3] ) ? $parts[3] : '';
    }

    /**
     * Persist fbclid from the landing URL into a first-party _fbc cookie.
     *
     * Meta's Pixel normally does this in JS, but it fails when the Pixel is
     * delayed/blocked (cache/optimizer plugins, in-app browsers). Without this,
     * the fbclid is lost as soon as the visitor navigates away from the landing page.
     * Hooked to `init` (front-end only).
     */
    public static function persist_fbc_cookie(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || headers_sent() ) {
            return;
        }
        $fbclid = self::get_fbclid_from_url();
        if ( ! $fbclid ) {
            return;
        }
        $existing = ! empty( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '';
        if ( $existing && self::fbclid_from_fbc( $existing ) === $fbclid ) {
            return; // Same click already stored.
        }

        $fbc = self::build_fbc( $fbclid );
        setcookie( '_fbc', $fbc, [
            'expires'  => time() + 90 * DAY_IN_SECONDS,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => false, // Pixel JS must be able to read it.
            'samesite' => 'Lax',
        ] );
        $_COOKIE['_fbc'] = $fbc; // Available for the rest of this request.
    }

    public static function get_client_ip(): string {
        $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '';
    }

    public static function get_user_agent(): string {
        return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
    }

    public static function get_event_source_url(): string {
        if ( ! isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) return home_url();
        $scheme = is_ssl() ? 'https' : 'http';
        return esc_url_raw( $scheme . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
    }

    /**
     * Capture tracking cookies, client IP, UA, and URL to order meta.
     *
     * @param \WC_Order|int $order
     */
    public static function capture_to_order( $order ): void {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( (int) $order );
        }
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $map = [
            self::META_FBP => self::get_fbp(),
            self::META_FBC => self::get_fbc(),
            self::META_IP  => self::get_client_ip(),
            self::META_UA  => self::get_user_agent(),
            self::META_URL => self::get_event_source_url(),
        ];
        foreach ( $map as $key => $val ) {
            if ( $val ) {
                $order->update_meta_data( $key, $val );
            }
        }
        $order->save_meta_data();
    }

    public static function read_from_order( \WC_Order $order ): array {
        return [
            'fbp'        => (string) $order->get_meta( self::META_FBP, true ),
            'fbc'        => (string) $order->get_meta( self::META_FBC, true ),
            'ip'         => (string) $order->get_meta( self::META_IP, true ),
            'user_agent' => (string) $order->get_meta( self::META_UA, true ),
            'url'        => (string) $order->get_meta( self::META_URL, true ),
        ];
    }

    public static function hash( string $value ): string {
        $value = strtolower( trim( $value ) );
        return $value ? hash( 'sha256', $value ) : '';
    }

    /**
     * Normalize a phone number to E.164-style digits and hash it with SHA-256.
     *
     * Normalization rules (Bangladesh / E.164):
     *  1. Strip all non-digit characters (spaces, hyphens, parentheses, etc.).
     *  2. +8801... → 8801...  (leading + already stripped in step 1, "8801" kept)
     *  3. 8801...  → 8801...  (already correct, unchanged)
     *  4. 01...    → 8801...  (local BD format, prepend 880)
     *  5. Any other format (already has a different country code) → left as-is.
     *
     * @param string $phone Raw phone number string.
     * @return string SHA-256 hash of the normalized E.164 digits, or '' if empty.
     */
    public static function hash_phone( string $phone, string $country = '' ): string {
        $digits = self::normalize_phone( $phone, $country );
        return '' !== $digits ? hash( 'sha256', $digits ) : '';
    }

    /**
     * Normalize a phone number to E.164 digits (no +), per Meta CAPI `ph` rules:
     * digits only, include country code, no leading zeros.
     *
     * @param string $phone   Raw phone.
     * @param string $country ISO-2 billing country (defaults to BD).
     * @return string Normalized digits or '' if unusable.
     */
    public static function normalize_phone( string $phone, string $country = '' ): string {
        $digits = (string) preg_replace( '/[^0-9]/', '', $phone );
        if ( '' === $digits ) {
            return '';
        }

        // International dialing prefix "00" (e.g. 00880...) → drop it.
        if ( str_starts_with( $digits, '00' ) ) {
            $digits = substr( $digits, 2 );
        }

        $country = strtoupper( $country ?: 'BD' );
        $cc      = '880';
        if ( 'BD' !== $country && function_exists( 'WC' ) && WC()->countries ) {
            $calling = WC()->countries->get_country_calling_code( $country );
            $calling = is_array( $calling ) ? (string) reset( $calling ) : (string) $calling;
            $calling = (string) preg_replace( '/[^0-9]/', '', $calling );
            if ( $calling ) {
                $cc = $calling;
            }
        }

        if ( str_starts_with( $digits, $cc ) ) {
            // Already has country code (e.g. 8801712345678) — unchanged.
        } elseif ( str_starts_with( $digits, '0' ) ) {
            // Local format: 01712345678 → 8801712345678.
            $digits = $cc . ltrim( $digits, '0' );
        } elseif ( '880' === $cc && 10 === strlen( $digits ) && str_starts_with( $digits, '1' ) ) {
            // BD mobile typed without leading 0: 1712345678 → 8801712345678.
            $digits = $cc . $digits;
        }

        // Sanity: E.164 numbers are 8–15 digits.
        $len = strlen( $digits );
        return ( $len >= 8 && $len <= 15 ) ? $digits : '';
    }

    /**
     * Normalized (NOT hashed) location fields for Meta matching, per Meta rules:
     *  - ct      City: lowercase, no spaces/punctuation.
     *  - st      State/District: lowercase, no spaces/punctuation. WooCommerce stores
     *            BD districts as codes (e.g. "BD-13") → converted to the name ("dhaka").
     *  - zp      Postcode: lowercase, no spaces or dashes (US: first 5 digits).
     *  - country ISO 3166-1 alpha-2, lowercase (e.g. "bd").
     *
     * Billing fields first, shipping as fallback. Country falls back to the store's
     * base country when the checkout hides the country field (single-country shops).
     * Only values the customer entered are used — nothing is invented.
     *
     * @param \WC_Order $order
     * @return array{ct:string,st:string,zp:string,country:string}
     */
    public static function get_order_address( \WC_Order $order ): array {
        $pick = static function ( string $billing, string $shipping ): string {
            $v = trim( $billing );
            return '' !== $v ? $v : trim( $shipping );
        };
        $clean = static function ( string $v ): string {
            $v = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
            return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $v );
        };

        $country = strtoupper( $pick( (string) $order->get_billing_country(), (string) $order->get_shipping_country() ) );
        if ( '' === $country && function_exists( 'WC' ) && WC()->countries ) {
            $country = strtoupper( (string) WC()->countries->get_base_country() );
        }

        // District / state: resolve WooCommerce state code to its human name.
        $state = $pick( (string) $order->get_billing_state(), (string) $order->get_shipping_state() );
        if ( '' !== $state && $country && 'US' !== $country && function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( $country );
            if ( is_array( $states ) && isset( $states[ $state ] ) ) {
                $state = html_entity_decode( (string) $states[ $state ], ENT_QUOTES, 'UTF-8' );
            }
        }

        $zip = strtolower( (string) preg_replace( '/[\s\-]+/', '', $pick( (string) $order->get_billing_postcode(), (string) $order->get_shipping_postcode() ) ) );
        if ( 'US' === $country ) {
            $zip = substr( $zip, 0, 5 );
        }

        return [
            'ct'      => $clean( $pick( (string) $order->get_billing_city(), (string) $order->get_shipping_city() ) ),
            'st'      => $clean( $state ),
            'zp'      => $zip,
            'country' => strtolower( $country ),
        ];
    }
}
