<?php

namespace Fractured\Dexter\Integration;

use Fractured\Dexter\Rest\PriceConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SyncSpider integration.
 *
 * Converts prices ONLY for SyncSpider imports,
 * and NEVER during REST requests (REST has its own hook).
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
     * Convert imported product prices to GBP on save,
     * but ONLY for non-REST, SyncSpider-originated saves.
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

        // 🚫 NEVER run during REST (REST path handled elsewhere)
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
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

        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            return;
        }

        // Converts ONLY from vendor baselines
        // Idempotent + guarded
        PriceConverter::convert_existing_product( $product );
    }
}