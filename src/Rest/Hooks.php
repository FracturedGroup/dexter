<?php

namespace Fractured\Dexter\Rest;

use Fractured\Dexter\Rest\PriceConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers WooCommerce REST hooks that Dexter uses to convert prices.
 */
final class Hooks {

    /**
     * SyncSpider marker meta.
     */
    private const META_SOURCE_KEY   = '_fxd_import_source';
    private const META_SOURCE_VALUE = 'syncspider';

    /**
     * Bootstrap REST hooks.
     */
    public static function init(): void {
        // Simple products + parent product objects.
        add_filter(
            'woocommerce_rest_pre_insert_product_object',
            [ __CLASS__, 'handle_product' ],
            10,
            3
        );

        // Variations.
        add_filter(
            'woocommerce_rest_pre_insert_product_variation_object',
            [ __CLASS__, 'handle_variation' ],
            10,
            3
        );
    }

    /**
     * Only convert for SyncSpider-sourced objects.
     */
    private static function is_syncspider_source( int $post_id ): bool {
        if ( $post_id <= 0 ) {
            return false;
        }
        $source = get_post_meta( $post_id, self::META_SOURCE_KEY, true );
        return self::META_SOURCE_VALUE === (string) $source;
    }

    /**
     * Handle conversion for main product objects.
     *
     * @param \WC_Product      $product
     * @param \WP_REST_Request $request
     * @param bool             $creating
     *
     * @return \WC_Product
     */
    public static function handle_product( $product, $request, $creating ) {
        if ( ! $product instanceof \WC_Product || ! $request instanceof \WP_REST_Request ) {
            return $product;
        }

        $post_id = (int) $product->get_id();
        if ( ! self::is_syncspider_source( $post_id ) ) {
            return $product;
        }

        PriceConverter::maybe_convert_prices( $product, $request, (bool) $creating );
        return $product;
    }

    /**
     * Handle conversion for product variations.
     *
     * @param \WC_Product_Variation $variation
     * @param \WP_REST_Request      $request
     * @param bool                  $creating
     *
     * @return \WC_Product_Variation
     */
    public static function handle_variation( $variation, $request, $creating ) {
        if ( ! $variation instanceof \WC_Product_Variation || ! $request instanceof \WP_REST_Request ) {
            return $variation;
        }

        $post_id = (int) $variation->get_id();
        if ( ! self::is_syncspider_source( $post_id ) ) {
            return $variation;
        }

        PriceConverter::maybe_convert_prices( $variation, $request, (bool) $creating );
        return $variation;
    }
}