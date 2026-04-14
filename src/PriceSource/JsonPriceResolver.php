<?php

namespace Fractured\Dexter\PriceSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves authoritative vendor-currency prices from a JSON meta field
 * for selected vendors only.
 */
final class JsonPriceResolver {

    /**
     * Default JSON meta key containing the full product snapshot.
     */
    private const DEFAULT_META_KEY = '_fxd_gallery_image_urls';

    /**
     * Vendor IDs that should use JSON price override.
     *
     * IMPORTANT:
     * Keep this list intentionally small and explicit.
     */
    private const DEFAULT_VENDOR_IDS = [ 218 ];

    /**
     * Whether this vendor should use JSON price override.
     */
    public static function is_override_vendor( int $vendor_id ): bool {
        if ( $vendor_id <= 0 ) {
            return false;
        }

        $vendor_ids = apply_filters(
            'fractured_dexter_json_price_override_vendor_ids',
            self::DEFAULT_VENDOR_IDS
        );

        $vendor_ids = array_map( 'intval', (array) $vendor_ids );

        return in_array( $vendor_id, $vendor_ids, true );
    }

    /**
     * Get the configured JSON meta key.
     */
    public static function get_meta_key(): string {
        $meta_key = apply_filters(
            'fractured_dexter_json_price_override_meta_key',
            self::DEFAULT_META_KEY
        );

        return is_string( $meta_key ) && '' !== trim( $meta_key )
            ? trim( $meta_key )
            : self::DEFAULT_META_KEY;
    }

    /**
     * Resolve regular/sale vendor-currency prices from JSON for a product/variation.
     *
     * Returns:
     * [
     *   'regular' => '59000.00',
     *   'sale'    => null,
     * ]
     *
     * Or null if:
     * - vendor is not configured for override
     * - JSON missing / invalid
     * - variant match not found
     *
     * @param \WC_Product|\WC_Product_Variation $product
     * @param int                               $vendor_id
     *
     * @return array<string, string|null>|null
     */
    public static function get_override_prices( $product, int $vendor_id ): ?array {
        if ( ! $product instanceof \WC_Product && ! $product instanceof \WC_Product_Variation ) {
            return null;
        }

        if ( ! self::is_override_vendor( $vendor_id ) ) {
            return null;
        }

        $root_post_id = self::get_root_post_id( $product );
        if ( $root_post_id <= 0 ) {
            return null;
        }

        $raw = get_post_meta( $root_post_id, self::get_meta_key(), true );
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return null;
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $variants = $decoded['variants'] ?? null;
        if ( ! is_array( $variants ) || empty( $variants ) ) {
            return null;
        }

        // Variation: match by SKU -> idFormatted.
        if ( $product instanceof \WC_Product_Variation ) {
            $sku = (string) $product->get_sku();
            if ( '' === $sku ) {
                return null;
            }

            foreach ( $variants as $variant ) {
                if ( ! is_array( $variant ) ) {
                    continue;
                }

                $id_formatted = isset( $variant['idFormatted'] ) ? (string) $variant['idFormatted'] : '';
                if ( '' === $id_formatted || $id_formatted !== $sku ) {
                    continue;
                }

                return self::extract_prices_from_variant( $variant );
            }

            return null;
        }

        // Simple / single-variant parent product:
        // if exactly one variant is present, use it.
        if ( 1 === count( $variants ) && is_array( $variants[0] ) ) {
            return self::extract_prices_from_variant( $variants[0] );
        }

        return null;
    }

    /**
     * Get the product ID that holds the JSON meta snapshot.
     *
     * For variations, JSON is expected on the parent product.
     */
    private static function get_root_post_id( $product ): int {
        if ( $product instanceof \WC_Product_Variation ) {
            return (int) $product->get_parent_id();
        }

        return (int) $product->get_id();
    }

    /**
     * Extract regular/sale prices from one JSON variant entry.
     *
     * Shopify semantics:
     * - price = current selling price
     * - compareAtPrice = crossed-out regular price when on sale
     */
    private static function extract_prices_from_variant( array $variant ): ?array {
        $price_raw = isset( $variant['price'] ) ? self::normalise_numeric_string( $variant['price'] ) : null;

        if ( null === $price_raw ) {
            return null;
        }

        $compare_raw = isset( $variant['compareAtPrice'] )
            ? self::normalise_numeric_string( $variant['compareAtPrice'] )
            : null;

        // Sale case only if compare-at is valid and greater than price.
        if (
            null !== $compare_raw
            && (float) $compare_raw > (float) $price_raw
        ) {
            return [
                'regular' => $compare_raw,
                'sale'    => $price_raw,
            ];
        }

        return [
            'regular' => $price_raw,
            'sale'    => null,
        ];
    }

    /**
     * Normalise a numeric value into a string Dexter can safely store/convert.
     */
    private static function normalise_numeric_string( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        return (string) $value;
    }
}