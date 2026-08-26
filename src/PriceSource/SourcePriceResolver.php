<?php

namespace Fractured\Dexter\PriceSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central entry point for authoritative source pricing.
 *
 * SyncSpider stores source-platform JSON in:
 *
 *     _fxd_gallery_image_urls
 *
 * Despite the historical meta-key name, this contains the authoritative
 * source product/variation snapshot used by Dexter's pricing resolvers.
 *
 * Confirmed platform behaviour:
 *
 * SHOPIFY
 * - the full product JSON is stored on the Fractured parent product;
 * - variants[] contains the authoritative child variant pricing;
 * - a Fractured variation therefore falls back to its parent's snapshot
 *   when it has no source snapshot of its own.
 *
 * WOOCOMMERCE
 * - simple products carry their own source snapshot;
 * - each Fractured variation carries its own source variation snapshot;
 * - Woo variations therefore resolve from their own snapshot and do not
 *   need the parent snapshot.
 *
 * Responsibilities:
 *
 * - locate the appropriate source snapshot;
 * - decode it safely;
 * - pass it through the platform resolver registry;
 * - return an immutable PriceState;
 * - fail closed to UNKNOWN whenever source state cannot be trusted.
 *
 * This class performs no:
 *
 * - HTTP requests;
 * - FX conversion;
 * - WooCommerce price writes;
 * - product saves.
 */
final class SourcePriceResolver {

    /**
     * Existing SyncSpider source JSON snapshot meta.
     */
    public const META_SOURCE_JSON = '_fxd_gallery_image_urls';

    /**
     * Cached resolver registry for the current PHP request.
     *
     * @var ResolverRegistry|null
     */
    private static $registry = null;

    /**
     * Resolve authoritative source pricing for a Woo product/variation.
     *
     * IMPORTANT:
     *
     * UNKNOWN means Dexter must not make assumptions about sale state from
     * this source result.
     *
     * Resolution order:
     *
     * Normal/simple product:
     *     own source snapshot
     *
     * Variation:
     *     1. own source snapshot;
     *     2. parent source snapshot, but ONLY when the variation genuinely
     *        has no snapshot of its own.
     *
     * @param \WC_Product|\WC_Product_Variation $product
     */
    public static function resolve(
        $product
    ): PriceState {

        if ( ! $product instanceof \WC_Product ) {
            return PriceState::unknown(
                'source',
                'Object is not a WooCommerce product.'
            );
        }

        $product_id = (int) $product->get_id();

        if ( $product_id <= 0 ) {
            return PriceState::unknown(
                'source',
                'Product has no persisted ID.'
            );
        }

        /*
         * ---------------------------------------------------------
         * OWN SNAPSHOT
         * ---------------------------------------------------------
         *
         * Prefer the product/variation's own source snapshot.
         *
         * This is authoritative for:
         *
         * - simple Shopify products;
         * - simple Woo products;
         * - Woo variations;
         * - future platforms that store object-level snapshots.
         */
        $own_snapshot = self::read_snapshot(
            $product
        );

        if ( $own_snapshot['present'] ) {

            if ( null === $own_snapshot['data'] ) {
                /*
                 * The object explicitly has source meta but it is malformed.
                 *
                 * Do NOT hide that problem by falling back to a parent
                 * snapshot. Malformed authoritative data must fail closed.
                 */
                return PriceState::unknown(
                    'source',
                    $own_snapshot['reason']
                );
            }

            return self::registry()->resolve(
                $product,
                $own_snapshot['data']
            );
        }

        /*
         * ---------------------------------------------------------
         * VARIATION PARENT FALLBACK
         * ---------------------------------------------------------
         *
         * Shopify stores its authoritative product JSON on the parent and
         * embeds child pricing inside variants[].
         *
         * Only use this fallback when the variation genuinely has no source
         * snapshot of its own.
         *
         * Woo variations normally have their own snapshot, so they will have
         * already returned above.
         */
        if ( $product->is_type( 'variation' ) ) {

            $parent_id = (int) $product->get_parent_id();

            if ( $parent_id <= 0 ) {
                return PriceState::unknown(
                    'source',
                    'Variation has no valid parent product.'
                );
            }

            if ( ! function_exists( 'wc_get_product' ) ) {
                return PriceState::unknown(
                    'source',
                    'WooCommerce product API is unavailable.'
                );
            }

            $parent = wc_get_product(
                $parent_id
            );

            if ( ! $parent instanceof \WC_Product ) {
                return PriceState::unknown(
                    'source',
                    'Variation parent could not be loaded.'
                );
            }

            $parent_snapshot = self::read_snapshot(
                $parent
            );

            if ( ! $parent_snapshot['present'] ) {
                return PriceState::unknown(
                    'source',
                    'Neither variation nor parent has an authoritative source snapshot.'
                );
            }

            if ( null === $parent_snapshot['data'] ) {
                return PriceState::unknown(
                    'source',
                    'Parent authoritative source snapshot is invalid: '
                        . $parent_snapshot['reason']
                );
            }

            /*
             * Pass the VARIATION object together with the PARENT snapshot.
             *
             * This is intentional.
             *
             * ShopifyJsonResolver uses the variation SKU/source identifier
             * to locate the exact record inside parent variants[].
             *
             * If a future/incorrect parent snapshot cannot safely resolve
             * this variation, the resolver layer returns UNKNOWN.
             */
            return self::registry()->resolve(
                $product,
                $parent_snapshot['data']
            );
        }

        return PriceState::unknown(
            'source',
            'Authoritative source JSON snapshot is missing.'
        );
    }

    /**
     * Whether a product currently has a fully authoritative pricing state.
     *
     * Both SALE and NO_SALE count as authoritative.
     *
     * UNKNOWN does not.
     *
     * @param \WC_Product|\WC_Product_Variation $product
     */
    public static function has_authoritative_state(
        $product
    ): bool {

        return ! self::resolve(
            $product
        )->is_unknown();
    }

    /**
     * Return the source JSON meta key.
     *
     * Integrations should use this rather than duplicating the literal key.
     */
    public static function meta_key(): string {
        return self::META_SOURCE_JSON;
    }

    /**
     * Read and decode an object's authoritative source snapshot.
     *
     * The in-memory Woo product meta is checked first because during a REST
     * update Woo may already contain the newly supplied source snapshot even
     * while the database still contains the previous value.
     *
     * Database meta is retained as a defensive fallback.
     *
     * Return shape:
     *
     * [
     *     'present' => bool,
     *     'data'    => array|null,
     *     'reason'  => string,
     * ]
     *
     * present=false
     *     No source snapshot exists on this object.
     *
     * present=true + data=null
     *     Source snapshot exists but is malformed and must fail closed.
     *
     * present=true + data=array
     *     Successfully decoded source snapshot.
     *
     * @return array{
     *     present: bool,
     *     data: array<string,mixed>|null,
     *     reason: string
     * }
     */
    private static function read_snapshot(
        \WC_Product $product
    ): array {

        $product_id = (int) $product->get_id();

        if ( $product_id <= 0 ) {
            return [
                'present' => false,
                'data'    => null,
                'reason'  => 'Product has no persisted ID.',
            ];
        }

        /*
         * Prefer in-memory meta.
         */
        $raw = $product->get_meta(
            self::META_SOURCE_JSON,
            true
        );

        /*
         * If in-memory meta is genuinely absent/empty, fall back to the
         * persisted value.
         */
        if (
            null === $raw
            || (
                is_scalar( $raw )
                && '' === trim(
                    (string) $raw
                )
            )
        ) {
            $raw = get_post_meta(
                $product_id,
                self::META_SOURCE_JSON,
                true
            );
        }

        /*
         * Empty means this object simply has no source snapshot.
         *
         * This distinction is what allows Shopify variations to safely fall
         * back to the parent snapshot.
         */
        if (
            null === $raw
            || (
                is_scalar( $raw )
                && '' === trim(
                    (string) $raw
                )
            )
        ) {
            return [
                'present' => false,
                'data'    => null,
                'reason'  => '',
            ];
        }

        /*
         * A populated non-string value is malformed source state.
         */
        if (
            is_array( $raw )
            || is_object( $raw )
            || is_bool( $raw )
        ) {
            return [
                'present' => true,
                'data'    => null,
                'reason'  => 'Authoritative source snapshot is not a JSON string.',
            ];
        }

        $raw = trim(
            (string) $raw
        );

        if ( '' === $raw ) {
            return [
                'present' => false,
                'data'    => null,
                'reason'  => '',
            ];
        }

        $snapshot = json_decode(
            $raw,
            true
        );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return [
                'present' => true,
                'data'    => null,
                'reason'  => 'Authoritative source JSON could not be decoded: '
                    . json_last_error_msg(),
            ];
        }

        if (
            ! is_array( $snapshot )
            || empty( $snapshot )
        ) {
            return [
                'present' => true,
                'data'    => null,
                'reason'  => 'Authoritative source JSON decoded to an empty or invalid structure.',
            ];
        }

        return [
            'present' => true,
            'data'    => $snapshot,
            'reason'  => '',
        ];
    }

    /**
     * Build the resolver registry once per PHP request.
     *
     * Adding another commerce platform later should require only:
     *
     * 1. implementing ResolverInterface;
     * 2. registering it here.
     *
     * PriceConverter should never contain platform-specific parsing.
     */
    private static function registry(): ResolverRegistry {

        if ( self::$registry instanceof ResolverRegistry ) {
            return self::$registry;
        }

        self::$registry = new ResolverRegistry(
            [
                new ShopifyJsonResolver(),
                new WooJsonResolver(),
            ]
        );

        return self::$registry;
    }
}