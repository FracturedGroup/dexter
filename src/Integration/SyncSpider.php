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
        add_action( 'save_post_product_variation', [ __CLASS__, 'maybe_convert_on_save' ], 20, 3 );
    
        // Bulletproof: catches Woo REST create/update (including batch) reliably
        add_action( 'woocommerce_rest_insert_product_object', [ __CLASS__, 'maybe_convert_on_rest' ], 20, 3 );
        add_action( 'woocommerce_rest_insert_product_variation_object', [ __CLASS__, 'maybe_convert_on_rest' ], 20, 3 );
    }

    /**
     * Convert imported product prices to GBP on save, but ONLY for SyncSpider imports.
     *
     * @param int     $post_id
     * @param \WP_Post $post
     * @param bool    $update
     */
    public static function maybe_convert_on_save( int $post_id, $post, bool $update ): void {
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
    
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
    
        if ( 'trash' === $post->post_status ) {
            return;
        }
    
        // If this save is happening during a REST request, let the REST hook handle it.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
    
        if ( ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
            return;
        }
    
        $source = get_post_meta( $post_id, self::META_SOURCE_KEY, true );
        if ( self::META_SOURCE_VALUE !== (string) $source ) {
            return;
        }
    
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }
    
        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            return;
        }
    
        // IMPORTANT: this path must NEVER treat current Woo prices as vendor currency.
        // convert_existing_product() now converts ONLY from stored vendor baselines.
        PriceConverter::convert_existing_product( $product );
    }
        
    /**
     * Convert prices after Woo REST insert/update.
     *
     * @param \WC_Data         $object
     * @param \WP_REST_Request $request
     * @param bool             $creating
     */            
    public static function maybe_convert_on_rest( $object, $request, $creating ): void {
        if ( ! $object instanceof \WC_Product && ! $object instanceof \WC_Product_Variation ) {
            return;
        }
    
        if ( ! $request instanceof \WP_REST_Request ) {
            return;
        }
    
        $post_id = (int) $object->get_id();
        if ( $post_id <= 0 ) {
            return;
        }
    
        $source = get_post_meta( $post_id, self::META_SOURCE_KEY, true );
        if ( self::META_SOURCE_VALUE !== (string) $source ) {
            return;
        }
    
        // CRITICAL: request-driven conversion (vendor prices are authoritative here)
        PriceConverter::maybe_convert_prices( $object, $request, (bool) $creating );
    
        // Persist changes made by maybe_convert_prices()
        $object->save();
    
        // If variation, sync parent price so admin Products list isn't £0.00
        if ( $object instanceof \WC_Product_Variation ) {
            $parent_id = (int) $object->get_parent_id();
            if ( $parent_id > 0 ) {
                wc_delete_product_transients( $parent_id );
                \WC_Product_Variable::sync( $parent_id );
    
                if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
                    wc_update_product_lookup_tables( $parent_id );
                }
            }
        }
    }
}