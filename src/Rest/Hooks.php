<?php

namespace Fractured\Dexter\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers WooCommerce REST hooks used by Dexter's pricing pipeline.
 *
 * Dexter must only inspect SyncSpider-originated catalogue writes.
 *
 * IMPORTANT:
 *
 * The SyncSpider marker may exist in different places depending on whether
 * WooCommerce is creating or updating an object:
 *
 * - persisted post meta on an existing product/variation;
 * - in-memory product meta populated from the current REST request;
 * - incoming REST meta_data before the marker has been persisted;
 * - parent product meta for a variation.
 *
 * Requiring the marker to already exist in wp_postmeta would incorrectly
 * bypass Dexter during first-time creation and some variation updates.
 */
final class Hooks {

    /**
     * SyncSpider marker meta.
     */
    private const META_SOURCE_KEY   = '_fxd_import_source';
    private const META_SOURCE_VALUE = 'syncspider';

    /**
     * Bootstrap WooCommerce REST hooks.
     */
    public static function init(): void {

        /*
         * Simple products and parent product objects.
         */
        add_filter(
            'woocommerce_rest_pre_insert_product_object',
            [ __CLASS__, 'handle_product' ],
            10,
            3
        );

        /*
         * Product variations.
         */
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
    public static function handle_product(
        $product,
        $request,
        $creating
    ) {

        if (
            ! $product instanceof \WC_Product
            || ! $request instanceof \WP_REST_Request
        ) {
            return $product;
        }

        if (
            ! self::is_syncspider_request(
                $product,
                $request
            )
        ) {
            return $product;
        }

        PriceConverter::maybe_convert_prices(
            $product,
            $request,
            (bool) $creating
        );

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
    public static function handle_variation(
        $variation,
        $request,
        $creating
    ) {

        if (
            ! $variation instanceof \WC_Product_Variation
            || ! $request instanceof \WP_REST_Request
        ) {
            return $variation;
        }

        if (
            ! self::is_syncspider_request(
                $variation,
                $request
            )
        ) {
            return $variation;
        }

        PriceConverter::maybe_convert_prices(
            $variation,
            $request,
            (bool) $creating
        );

        return $variation;
    }

    /**
     * Determine whether the current object/request belongs to SyncSpider.
     *
     * Resolution order:
     *
     * 1. in-memory object meta;
     * 2. persisted object meta;
     * 3. incoming direct REST marker;
     * 4. incoming REST meta_data marker;
     * 5. parent marker for variations.
     *
     * No database writes occur here.
     */
    private static function is_syncspider_request(
        \WC_Product $product,
        \WP_REST_Request $request
    ): bool {

        /*
         * ---------------------------------------------------------
         * 1. IN-MEMORY OBJECT META
         * ---------------------------------------------------------
         *
         * During REST processing Woo may already have applied incoming
         * meta_data to the product object even though it has not yet been
         * persisted.
         */
        $source = self::normalise_source(
            $product->get_meta(
                self::META_SOURCE_KEY,
                true
            )
        );

        if ( self::META_SOURCE_VALUE === $source ) {
            return true;
        }

        /*
         * ---------------------------------------------------------
         * 2. PERSISTED OBJECT META
         * ---------------------------------------------------------
         */
        $product_id = (int) $product->get_id();

        if ( $product_id > 0 ) {

            $source = self::normalise_source(
                get_post_meta(
                    $product_id,
                    self::META_SOURCE_KEY,
                    true
                )
            );

            if ( self::META_SOURCE_VALUE === $source ) {
                return true;
            }
        }

        /*
         * ---------------------------------------------------------
         * 3. DIRECT REST PARAMETER
         * ---------------------------------------------------------
         *
         * Support this defensively even though SyncSpider normally sends
         * the marker through meta_data.
         */
        $direct_source = self::normalise_source(
            $request->get_param(
                self::META_SOURCE_KEY
            )
        );

        if (
            self::META_SOURCE_VALUE
            === $direct_source
        ) {
            return true;
        }

        /*
         * ---------------------------------------------------------
         * 4. REST meta_data
         * ---------------------------------------------------------
         */
        if (
            self::request_meta_contains_syncspider(
                $request
            )
        ) {
            return true;
        }

        /*
         * ---------------------------------------------------------
         * 5. VARIATION PARENT META
         * ---------------------------------------------------------
         *
         * Some variation records may rely on the parent's SyncSpider marker.
         */
        if ( $product->is_type( 'variation' ) ) {

            $parent_id = (int) $product->get_parent_id();

            if ( $parent_id > 0 ) {

                $parent_source = self::normalise_source(
                    get_post_meta(
                        $parent_id,
                        self::META_SOURCE_KEY,
                        true
                    )
                );

                if (
                    self::META_SOURCE_VALUE
                    === $parent_source
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Inspect incoming REST meta_data for the SyncSpider marker.
     *
     * Woo REST normally represents meta_data as:
     *
     * [
     *     [
     *         'key'   => '_fxd_import_source',
     *         'value' => 'syncspider',
     *     ],
     * ]
     *
     * The associative-key form is also accepted defensively.
     */
    private static function request_meta_contains_syncspider(
        \WP_REST_Request $request
    ): bool {

        $meta_data = $request->get_param(
            'meta_data'
        );

        if ( ! is_array( $meta_data ) ) {
            return false;
        }

        /*
         * Defensive associative form:
         *
         * [
         *     '_fxd_import_source' => 'syncspider'
         * ]
         */
        if (
            array_key_exists(
                self::META_SOURCE_KEY,
                $meta_data
            )
            && self::META_SOURCE_VALUE
                === self::normalise_source(
                    $meta_data[
                        self::META_SOURCE_KEY
                    ]
                )
        ) {
            return true;
        }

        /*
         * Normal Woo REST list-of-meta-records form.
         */
        foreach ( $meta_data as $row ) {

            if ( is_object( $row ) ) {
                $row = (array) $row;
            }

            if ( ! is_array( $row ) ) {
                continue;
            }

            $key = isset( $row['key'] )
                ? trim(
                    (string) $row['key']
                )
                : '';

            if (
                self::META_SOURCE_KEY
                !== $key
            ) {
                continue;
            }

            $value = array_key_exists(
                'value',
                $row
            )
                ? self::normalise_source(
                    $row['value']
                )
                : '';

            if (
                self::META_SOURCE_VALUE
                === $value
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise source marker values for strict comparison.
     *
     * @param mixed $value
     */
    private static function normalise_source(
        $value
    ): string {

        if (
            null === $value
            || is_array( $value )
            || is_object( $value )
            || is_bool( $value )
        ) {
            return '';
        }

        return strtolower(
            trim(
                (string) $value
            )
        );
    }
}