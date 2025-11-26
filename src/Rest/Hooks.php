<?php

namespace Fractured\Dexter\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers WooCommerce REST hooks that Dexter uses to convert prices.
 */
final class Hooks {

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
     * Handle conversion for main product objects.
     *
     * @param \WC_Product      $product
     * @param \WP_REST_Request $request
     * @param bool             $creating
     *
     * @return \WC_Product
     */
    public static function handle_product( $product, $request, $creating ) {
        PriceConverter::maybe_convert_prices( $product, $request, $creating );
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
        PriceConverter::maybe_convert_prices( $variation, $request, $creating );
        return $variation;
    }
}