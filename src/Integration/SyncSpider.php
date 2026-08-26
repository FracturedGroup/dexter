<?php

namespace Fractured\Dexter\Integration;

use Fractured\Dexter\Rest\PriceConverter;
use Fractured\Dexter\Vendor\Currency as VendorCurrency;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SyncSpider integration.
 *
 * Converts prices ONLY for SyncSpider imports.
 *
 * Normal rule:
 *  - Never run during REST (REST path handled by Dexter REST hooks).
 *
 * Surgical safety-net:
 *  - If SyncSpider writes prices via meta during REST in a way that bypasses Dexter REST conversion,
 *    and the product is still unconverted, then convert once here.
 *
 * Non-REST guard:
 *  - Only convert on non-REST save if the product has never been FX-audited yet.
 *    This prevents accidental reconversion when unrelated actions (for example admin/vendor saves)
 *    touch already-converted SyncSpider products.
 */
final class SyncSpider {

    public const META_SOURCE_KEY   = '_fxd_import_source';
    public const META_SOURCE_VALUE = 'syncspider';

    /**
     * In-request guard to prevent re-entrancy.
     *
     * @var array<int, true>
     */
    private static array $processing = [];

    public static function init(): void {
        add_action( 'save_post_product', [ __CLASS__, 'maybe_convert_on_save' ], 20, 3 );
        add_action( 'save_post_product_variation', [ __CLASS__, 'maybe_convert_on_save' ], 20, 3 );
    }

    /**
     * Convert imported product prices to GBP on save.
     */
    public static function maybe_convert_on_save( int $post_id, $post, bool $update ): void {
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        // Autosave / revision / trash
        if (
            wp_is_post_autosave( $post_id ) ||
            wp_is_post_revision( $post_id ) ||
            'trash' === $post->post_status
        ) {
            return;
        }

        if ( ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
            return;
        }

        // Only SyncSpider products
        $source = get_post_meta( $post_id, self::META_SOURCE_KEY, true );
        if ( self::META_SOURCE_VALUE !== (string) $source ) {
            return;
        }

        // 🧠 In-request re-entrancy guard
        if ( isset( self::$processing[ $post_id ] ) ) {
            return;
        }
        self::$processing[ $post_id ] = true;

        try {
            /**
             * Default behaviour: do not run during REST.
             * REST conversion is handled by Dexter REST hooks.
             */
            $is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST );

            if ( $is_rest ) {
                // If already converted (or clearly audited), do nothing.
                $converted_at = get_post_meta( $post_id, '_fxd_fx_converted_at', true );
                if ( ! empty( $converted_at ) ) {
                    return;
                }

                $orig_currency = get_post_meta( $post_id, '_fxd_orig_currency', true );
                if ( ! empty( $orig_currency ) ) {
                    return;
                }

                // If there is no numeric price in DB, there's nothing to convert.
                $regular = get_post_meta( $post_id, '_regular_price', true );
                $active  = get_post_meta( $post_id, '_price', true );

                $has_numeric_price =
                    ( '' !== $regular && null !== $regular && is_numeric( $regular ) ) ||
                    ( '' !== $active  && null !== $active  && is_numeric( $active ) );

                if ( ! $has_numeric_price ) {
                    return;
                }

                // Only run this safety-net for non-GBP vendors.
                $vendor_id = (int) $post->post_author;

                // Variations can sometimes have odd authors; fall back to parent author.
                if ( $vendor_id <= 0 && ! empty( $post->post_parent ) ) {
                    $parent = get_post( (int) $post->post_parent );
                    if ( $parent instanceof \WP_Post && ! empty( $parent->post_author ) ) {
                        $vendor_id = (int) $parent->post_author;
                    }
                }

                if ( $vendor_id <= 0 ) {
                    return;
                }

                $base_currency   = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
                $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );

                if ( strtoupper( (string) $vendor_currency ) === strtoupper( (string) $base_currency ) ) {
                    return; // GBP vendor — no FX conversion needed.
                }

                // ✅ Safety-net conversion (runs only in this unconverted REST edge case)
                if ( ! function_exists( 'wc_get_product' ) ) {
                    return;
                }

                $product = wc_get_product( $post_id );
                if ( ! $product ) {
                    return;
                }

                PriceConverter::convert_existing_product( $product );
                return;
            }

            /**
             * Non-REST guard:
             * Do NOT reconvert products that already have Dexter FX audit meta.
             * This prevents unrelated non-REST saves from reinterpreting legacy source values
             * under the vendor's current currency setting.
             */
            $converted_at  = get_post_meta( $post_id, '_fxd_fx_converted_at', true );
            $orig_currency = get_post_meta( $post_id, '_fxd_orig_currency', true );

            if ( ! empty( $converted_at ) || ! empty( $orig_currency ) ) {
                return;
            }

            // Non-REST: first-time SyncSpider-originated save only.
            if ( ! function_exists( 'wc_get_product' ) ) {
                return;
            }

            $product = wc_get_product( $post_id );
            if ( ! $product ) {
                return;
            }

            PriceConverter::convert_existing_product( $product );

        } finally {
            // Keep the guard set for the remainder of the request (prevents re-entrancy storms).
            // No unset here by design.
        }
    }
}