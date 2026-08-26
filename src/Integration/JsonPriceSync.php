<?php

namespace Fractured\Dexter\Integration;

use Fractured\Dexter\PriceSource\SourcePriceResolver;
use Fractured\Dexter\Rest\PriceConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Re-apply Dexter pricing when authoritative SyncSpider source metadata
 * becomes available.
 *
 * The historical source JSON meta key is:
 *
 *     _fxd_gallery_image_urls
 *
 * Despite the name, the value contains the authoritative source-platform
 * product/variation JSON used by Dexter's modular price resolvers.
 *
 * This integration reacts to:
 *
 * 1. the source JSON being written; and
 * 2. the SyncSpider marker being written when source JSON already exists.
 *
 * The second case removes ordering dependence during first-time product
 * creation. It does not matter whether SyncSpider persists the JSON snapshot
 * or _fxd_import_source first.
 *
 * Platform behaviour:
 *
 * SHOPIFY
 * - variable parent snapshot contains variants[];
 * - parent snapshot update may therefore require child variations to be
 *   recalculated from that fresh parent snapshot.
 *
 * WOOCOMMERCE
 * - each Fractured variation carries its own authoritative source variation
 *   snapshot;
 * - variation snapshot writes therefore recalculate only that variation;
 * - Woo variable-parent snapshots do not contain authoritative child pricing,
 *   so a parent JSON write must not trigger a child-wide pricing rewrite.
 *
 * SIMPLE PRODUCTS
 * - recalculate the product directly from its own source snapshot.
 *
 * This integration performs no frontend work and no HTTP requests.
 */
final class JsonPriceSync {

    /**
     * SyncSpider ownership marker.
     */
    private const META_IMPORT_SOURCE = '_fxd_import_source';

    /**
     * Expected SyncSpider marker value.
     */
    private const IMPORT_SOURCE_VALUE = 'syncspider';

    /**
     * In-request re-entrancy guard.
     *
     * @var array<int, true>
     */
    private static array $processing = [];

    public static function init(): void {

        add_action(
            'added_post_meta',
            [ __CLASS__, 'maybe_resync_on_meta' ],
            20,
            4
        );

        add_action(
            'updated_post_meta',
            [ __CLASS__, 'maybe_resync_on_meta' ],
            20,
            4
        );
    }

    /**
     * React when either half of the authoritative SyncSpider source contract
     * becomes available.
     *
     * We process when:
     *
     * - source JSON is written and the object is already SyncSpider-managed;
     * - the SyncSpider marker is written and source JSON already exists.
     *
     * @param int    $meta_id
     * @param int    $object_id
     * @param string $meta_key
     * @param mixed  $meta_value
     */
    public static function maybe_resync_on_meta(
        $meta_id,
        $object_id,
        $meta_key,
        $meta_value
    ): void {

        if (
            ! is_numeric( $object_id )
            || (int) $object_id <= 0
        ) {
            return;
        }

        $object_id = (int) $object_id;
        $meta_key  = (string) $meta_key;

        $source_json_key = SourcePriceResolver::meta_key();

        /*
         * Ignore every unrelated meta write immediately.
         */
        if (
            $source_json_key !== $meta_key
            && self::META_IMPORT_SOURCE !== $meta_key
        ) {
            return;
        }

        /*
         * A marker write matters only when the new value actually identifies
         * this object as SyncSpider-managed.
         */
        if (
            self::META_IMPORT_SOURCE === $meta_key
            && self::IMPORT_SOURCE_VALUE
                !== strtolower(
                    trim(
                        (string) $meta_value
                    )
                )
        ) {
            return;
        }

        if ( isset( self::$processing[ $object_id ] ) ) {
            return;
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $post = get_post(
            $object_id
        );

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if (
            ! in_array(
                $post->post_type,
                [
                    'product',
                    'product_variation',
                ],
                true
            )
        ) {
            return;
        }

        /*
         * If this is the marker-write path, source JSON must already exist.
         *
         * If JSON has not been persisted yet, do nothing. Its later write will
         * trigger this integration through the normal JSON path.
         */
        if (
            self::META_IMPORT_SOURCE === $meta_key
            && ! self::has_source_json( $object_id )
        ) {
            return;
        }

        /*
         * Only SyncSpider-managed catalogue objects participate.
         *
         * For variations the marker may exist directly on the child or on
         * its parent.
         */
        if ( ! self::is_syncspider_object( $post ) ) {
            return;
        }

        self::$processing[ $object_id ] = true;

        try {

            self::process_object(
                $object_id
            );

        } finally {

            unset(
                self::$processing[ $object_id ]
            );
        }
    }

    /**
     * Process one product/variation from its authoritative source snapshot.
     */
    private static function process_object(
        int $object_id
    ): void {

        $product = wc_get_product(
            $object_id
        );

        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        /*
         * ---------------------------------------------------------
         * VARIATION
         * ---------------------------------------------------------
         *
         * Woo source variations carry their own authoritative snapshot.
         *
         * Shopify variations may resolve from the parent's authoritative
         * product snapshot.
         */
        if ( $product->is_type( 'variation' ) ) {

            PriceConverter::convert_existing_product(
                $product
            );

            self::sync_variable_parent(
                (int) $product->get_parent_id()
            );

            return;
        }

        /*
         * ---------------------------------------------------------
         * VARIABLE PARENT
         * ---------------------------------------------------------
         *
         * Never price the parent directly.
         *
         * Determine which resolver understands the newly-written parent
         * snapshot.
         */
        if ( $product->is_type( 'variable' ) ) {

            $state = SourcePriceResolver::resolve(
                $product
            );

            /*
             * Shopify source snapshots contain authoritative variants[]
             * inside the parent JSON.
             *
             * Re-evaluate each child from that fresh source snapshot.
             */
            if ( 'shopify_json' === $state->source() ) {

                $children = $product->get_children();

                foreach ( $children as $child_id ) {

                    $child_id = (int) $child_id;

                    if ( $child_id <= 0 ) {
                        continue;
                    }

                    if ( isset( self::$processing[ $child_id ] ) ) {
                        continue;
                    }

                    $child = wc_get_product(
                        $child_id
                    );

                    if ( ! $child instanceof \WC_Product_Variation ) {
                        continue;
                    }

                    self::$processing[ $child_id ] = true;

                    try {

                        PriceConverter::convert_existing_product(
                            $child
                        );

                    } finally {

                        unset(
                            self::$processing[ $child_id ]
                        );
                    }
                }

                self::sync_variable_parent(
                    $object_id
                );
            }

            /*
             * Woo variable parents intentionally stop here.
             *
             * Their parent JSON does not contain authoritative child pricing.
             * Each child is recalculated from its own source-variation JSON.
             *
             * Unknown/future parent formats also fail closed.
             */
            return;
        }

        /*
         * ---------------------------------------------------------
         * SIMPLE / OTHER SELLABLE PRODUCT
         * ---------------------------------------------------------
         */
        PriceConverter::convert_existing_product(
            $product
        );
    }

    /**
     * Determine whether an authoritative source JSON snapshot currently
     * exists for this object.
     */
    private static function has_source_json(
        int $object_id
    ): bool {

        if ( $object_id <= 0 ) {
            return false;
        }

        $raw = get_post_meta(
            $object_id,
            SourcePriceResolver::meta_key(),
            true
        );

        if (
            null === $raw
            || is_array( $raw )
            || is_object( $raw )
            || is_bool( $raw )
        ) {
            return false;
        }

        return '' !== trim(
            (string) $raw
        );
    }

    /**
     * Determine whether a product/variation belongs to the SyncSpider
     * catalogue integration.
     */
    private static function is_syncspider_object(
        \WP_Post $post
    ): bool {

        $source = get_post_meta(
            (int) $post->ID,
            self::META_IMPORT_SOURCE,
            true
        );

        if (
            self::IMPORT_SOURCE_VALUE
            === strtolower(
                trim(
                    (string) $source
                )
            )
        ) {
            return true;
        }

        /*
         * Variations may rely on the parent's integration marker.
         */
        if (
            'product_variation' === $post->post_type
            && (int) $post->post_parent > 0
        ) {
            $parent_source = get_post_meta(
                (int) $post->post_parent,
                self::META_IMPORT_SOURCE,
                true
            );

            return (
                self::IMPORT_SOURCE_VALUE
                === strtolower(
                    trim(
                        (string) $parent_source
                    )
                )
            );
        }

        return false;
    }

    /**
     * Rebuild WooCommerce variable-parent price state after one or more
     * child variations have changed.
     */
    private static function sync_variable_parent(
        int $parent_id
    ): void {

        if ( $parent_id <= 0 ) {
            return;
        }

        wc_delete_product_transients(
            $parent_id
        );

        \WC_Product_Variable::sync(
            $parent_id
        );
    }
}