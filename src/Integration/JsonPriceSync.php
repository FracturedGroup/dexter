<?php

namespace Fractured\Dexter\Integration;

use Fractured\Dexter\PriceSource\JsonPriceResolver;
use Fractured\Dexter\Rest\PriceConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Re-applies Dexter conversion when the authoritative JSON snapshot meta is written.
 *
 * This is especially important for vendors whose direct imported price field is not reliable.
 */
final class JsonPriceSync {

    /**
     * In-request re-entrancy guard.
     *
     * @var array<int, true>
     */
    private static array $processing = [];

    public static function init(): void {
        add_action( 'added_post_meta', [ __CLASS__, 'maybe_resync_on_json_meta' ], 20, 4 );
        add_action( 'updated_post_meta', [ __CLASS__, 'maybe_resync_on_json_meta' ], 20, 4 );
    }

    /**
     * When the JSON snapshot meta is written, re-run conversion from JSON
     * for configured vendors only.
     *
     * @param int    $meta_id
     * @param int    $object_id
     * @param string $meta_key
     * @param mixed  $meta_value
     */
    public static function maybe_resync_on_json_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
        if ( ! is_numeric( $object_id ) ) {
            return;
        }

        $object_id = (int) $object_id;
        if ( $object_id <= 0 ) {
            return;
        }

        if ( JsonPriceResolver::get_meta_key() !== (string) $meta_key ) {
            return;
        }

        if ( isset( self::$processing[ $object_id ] ) ) {
            return;
        }

        $post = get_post( $object_id );
        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }

        $vendor_id = (int) $post->post_author;
        if ( $vendor_id <= 0 || ! JsonPriceResolver::is_override_vendor( $vendor_id ) ) {
            return;
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        self::$processing[ $object_id ] = true;

        try {
            $product = wc_get_product( $object_id );
            if ( ! $product ) {
                return;
            }

            // Variable parent: re-convert each child from JSON, then sync parent once.
            if ( $product->is_type( 'variable' ) ) {
                $children = $product->get_children();

                foreach ( $children as $child_id ) {
                    $child = wc_get_product( (int) $child_id );
                    if ( $child ) {
                        PriceConverter::convert_existing_product( $child );
                    }
                }

                wc_delete_product_transients( $object_id );
                \WC_Product_Variable::sync( $object_id );

                return;
            }

            // Simple product.
            PriceConverter::convert_existing_product( $product );

        } finally {
            // Keep guard set for this request to avoid ping-pong during the same meta/update cycle.
        }
    }
}