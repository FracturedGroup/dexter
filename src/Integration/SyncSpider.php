<?php

namespace Fractured\Dexter\Integration;

use Fractured\Dexter\Rest\PriceConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SyncSpider integration.
 *
 * Dexter does not assume SyncSpider creates products via Woo REST.
 * Instead, we convert on save_post_product when a deterministic marker meta is present.
 *
 * Required marker (set by SyncSpider mapping):
 *   _fxd_import_source = "syncspider"
 */
final class SyncSpider {

    /**
     * Meta key set on imported products by SyncSpider.
     */
    public const META_SOURCE_KEY = '_fxd_import_source';

    /**
     * Expected meta value for SyncSpider imports.
     */
    public const META_SOURCE_VALUE = 'syncspider';

    /**
     * Bootstrap hooks.
     */
    public static function init(): void {
        add_action( 'save_post_product', [ __CLASS__, 'maybe_convert_on_save' ], 20, 3 );
    }

    /**
     * Convert imported product prices to GBP on save, but ONLY for SyncSpider imports.
     *
     * @param int     $post_id
     * @param \WP_Post $post
     * @param bool    $update
     */
    public static function maybe_convert_on_save( int $post_id, $post, bool $update ): void {
        // Defensive: ensure post is a WP_Post.
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        // Ignore autosaves / revisions.
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Ignore trash.
        if ( 'trash' === $post->post_status ) {
            return;
        }

        // Only act on product post type.
        if ( 'product' !== $post->post_type ) {
            return;
        }

        // Only act when SyncSpider marker meta is present.
        $source = get_post_meta( $post_id, self::META_SOURCE_KEY, true );
        if ( self::META_SOURCE_VALUE !== (string) $source ) {
            return;
        }

        // Avoid double conversion: if already converted, do nothing.
        $already = get_post_meta( $post_id, '_fxd_fx_converted_at', true );
        if ( ! empty( $already ) ) {
            return;
        }

        // Ensure WooCommerce is available.
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            return;
        }

        // Convert "as-is" prices currently stored on the product.
        // (Step 3 will implement this method in PriceConverter.)
        PriceConverter::convert_existing_product( $product );
    }
}