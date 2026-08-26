<?php

namespace Fractured\Dexter\PriceSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve authoritative pricing from a Shopify source JSON snapshot.
 *
 * Expected product shape:
 *
 * [
 *     'idFormatted'           => '8772186800472',
 *     'hasOnlyDefaultVariant' => false,
 *     'variants'              => [
 *         [
 *             'idFormatted'   => '47178405806424',
 *             'price'         => '89.00',
 *             'compareAtPrice'=> null,
 *         ],
 *     ],
 * ]
 *
 * Shopify semantics:
 *
 * price
 *     Current selling price.
 *
 * compareAtPrice
 *     Original/higher price when the variant is genuinely on sale.
 *
 * Therefore:
 *
 * price = 44, compareAtPrice = 59
 *     SALE
 *     regular = 59
 *     sale    = 44
 *
 * price = 89, compareAtPrice = null
 *     NO_SALE
 *     regular = 89
 *
 * This resolver performs no:
 *
 * - HTTP requests;
 * - database writes;
 * - FX conversion;
 * - WooCommerce product writes.
 */
final class ShopifyJsonResolver implements ResolverInterface {

    public function id(): string {
        return 'shopify_json';
    }

    /**
     * Detect the specific Shopify snapshot shape used by SyncSpider.
     *
     * Detection is intentionally stricter than simply checking for
     * "variants", because many commerce platforms expose variants.
     *
     * @param array<string,mixed> $snapshot
     */
    public function supports(
        array $snapshot
    ): bool {

        if (
            ! array_key_exists( 'idFormatted', $snapshot )
            || ! array_key_exists( 'hasOnlyDefaultVariant', $snapshot )
            || ! array_key_exists( 'variants', $snapshot )
            || ! is_array( $snapshot['variants'] )
        ) {
            return false;
        }

        /*
         * A Shopify snapshot from the confirmed SyncSpider source contains
         * Shopify GIDs in the raw product/variant IDs.
         *
         * Prefer this strong platform signature when available.
         */
        if (
            isset( $snapshot['id'] )
            && is_string( $snapshot['id'] )
            && 0 === strpos(
                $snapshot['id'],
                'gid://shopify/Product/'
            )
        ) {
            return true;
        }

        /*
         * Some mapped snapshots may omit the top-level raw "id".
         *
         * In that case require at least one variant with the Shopify
         * SyncSpider fields plus a Shopify variant GID.
         */
        foreach ( $snapshot['variants'] as $variant ) {
            if ( ! is_array( $variant ) ) {
                continue;
            }

            if (
                ! array_key_exists( 'idFormatted', $variant )
                || ! array_key_exists( 'price', $variant )
            ) {
                continue;
            }

            if (
                isset( $variant['id'] )
                && is_string( $variant['id'] )
                && 0 === strpos(
                    $variant['id'],
                    'gid://shopify/ProductVariant/'
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve pricing for a Woo product or variation.
     *
     * @param \WC_Product         $product
     * @param array<string,mixed> $snapshot
     */
    public function resolve(
        \WC_Product $product,
        array $snapshot
    ): PriceState {

        if ( $product->is_type( 'variation' ) ) {
            return $this->resolve_variation(
                $product,
                $snapshot
            );
        }

        /*
         * A variable parent must not be assigned a synthetic price from one
         * of its variants. WooCommerce derives its displayed price range
         * from the children.
         */
        if ( $product->is_type( 'variable' ) ) {
            return PriceState::unknown(
                $this->id(),
                'Variable parent pricing is resolved from its variations.'
            );
        }

        return $this->resolve_simple(
            $product,
            $snapshot
        );
    }

    /**
     * Resolve a simple/default-variant Shopify product.
     *
     * Shopify still represents a product with only one/default variant
     * inside variants[], so that variant remains the authoritative price.
     *
     * @param \WC_Product         $product
     * @param array<string,mixed> $snapshot
     */
    private function resolve_simple(
        \WC_Product $product,
        array $snapshot
    ): PriceState {

        if (
            empty( $snapshot['variants'] )
            || ! is_array( $snapshot['variants'] )
        ) {
            return PriceState::unknown(
                $this->id(),
                'Shopify snapshot contains no variants.'
            );
        }

        /*
         * First try the Woo SKU.
         *
         * Depending on a SyncSpider pipeline, a simple Woo product may use
         * either the Shopify variant ID or another source identifier.
         */
        $sku = trim(
            (string) $product->get_sku()
        );

        if ( '' !== $sku ) {
            $matches = $this->find_variants_by_id(
                $snapshot['variants'],
                $sku
            );

            if ( 1 === count( $matches ) ) {
                return $this->price_state_from_variant(
                    $matches[0]
                );
            }

            if ( count( $matches ) > 1 ) {
                return PriceState::unknown(
                    $this->id(),
                    'Multiple Shopify variants match the Woo SKU.'
                );
            }
        }

        /*
         * A genuinely single-variant Shopify product is safe to resolve
         * from its sole source variant.
         *
         * We require BOTH:
         *
         * - hasOnlyDefaultVariant === true
         * - exactly one variant
         *
         * This avoids silently selecting the first variant from an
         * ambiguous product.
         */
        if (
            true === $snapshot['hasOnlyDefaultVariant']
            && 1 === count( $snapshot['variants'] )
        ) {
            $variant = reset(
                $snapshot['variants']
            );

            if ( is_array( $variant ) ) {
                return $this->price_state_from_variant(
                    $variant
                );
            }
        }

        return PriceState::unknown(
            $this->id(),
            'Could not identify the authoritative Shopify variant for the Woo product.'
        );
    }

    /**
     * Resolve a Woo variation against Shopify variants[].
     *
     * The confirmed Lefrik mapping uses:
     *
     * Shopify variant idFormatted -> Woo variation SKU
     *
     * We therefore require an exact identifier match.
     *
     * @param \WC_Product         $product
     * @param array<string,mixed> $snapshot
     */
    private function resolve_variation(
        \WC_Product $product,
        array $snapshot
    ): PriceState {

        $sku = trim(
            (string) $product->get_sku()
        );

        if ( '' === $sku ) {
            return PriceState::unknown(
                $this->id(),
                'Woo variation has no SKU/source variant identifier.'
            );
        }

        if (
            empty( $snapshot['variants'] )
            || ! is_array( $snapshot['variants'] )
        ) {
            return PriceState::unknown(
                $this->id(),
                'Shopify snapshot contains no variants.'
            );
        }

        $matches = $this->find_variants_by_id(
            $snapshot['variants'],
            $sku
        );

        if ( 0 === count( $matches ) ) {
            return PriceState::unknown(
                $this->id(),
                'Woo variation SKU does not match a Shopify variant.'
            );
        }

        if ( 1 !== count( $matches ) ) {
            return PriceState::unknown(
                $this->id(),
                'Woo variation SKU matches multiple Shopify variants.'
            );
        }

        return $this->price_state_from_variant(
            $matches[0]
        );
    }

    /**
     * Find Shopify variants whose idFormatted exactly matches an identifier.
     *
     * Exact string comparison is intentional. Shopify IDs can exceed the
     * safe integer range on some systems and must never be compared as
     * floats.
     *
     * @param array<int,mixed> $variants
     *
     * @return array<int,array<string,mixed>>
     */
    private function find_variants_by_id(
        array $variants,
        string $identifier
    ): array {

        $identifier = trim(
            $identifier
        );

        if ( '' === $identifier ) {
            return [];
        }

        $matches = [];

        foreach ( $variants as $variant ) {
            if ( ! is_array( $variant ) ) {
                continue;
            }

            if (
                ! array_key_exists(
                    'idFormatted',
                    $variant
                )
            ) {
                continue;
            }

            $source_id = trim(
                (string) $variant['idFormatted']
            );

            if (
                '' !== $source_id
                && $source_id === $identifier
            ) {
                $matches[] = $variant;
            }
        }

        return $matches;
    }

    /**
     * Convert a Shopify variant into an authoritative PriceState.
     *
     * @param array<string,mixed> $variant
     */
    private function price_state_from_variant(
        array $variant
    ): PriceState {

        if (
            ! array_key_exists(
                'price',
                $variant
            )
        ) {
            return PriceState::unknown(
                $this->id(),
                'Shopify variant does not contain price.'
            );
        }

        $price = $this->normalise_source_price(
            $variant['price']
        );

        if ( null === $price ) {
            return PriceState::unknown(
                $this->id(),
                'Shopify variant price is missing or invalid.'
            );
        }

        /*
         * Missing compareAtPrice and explicit null/empty compareAtPrice
         * both mean there is no active Shopify comparison price.
         *
         * Because we have an authoritative current price, this is an
         * explicit NO_SALE state.
         */
        if (
            ! array_key_exists(
                'compareAtPrice',
                $variant
            )
            || null === $variant['compareAtPrice']
            || '' === trim(
                (string) $variant['compareAtPrice']
            )
        ) {
            return PriceState::no_sale(
                $price,
                $this->id()
            );
        }

        $compare_at = $this->normalise_source_price(
            $variant['compareAtPrice']
        );

        /*
         * compareAtPrice exists but is malformed.
         *
         * Do NOT interpret malformed source data as NO_SALE because that
         * could incorrectly remove a legitimate sale.
         */
        if ( null === $compare_at ) {
            return PriceState::unknown(
                $this->id(),
                'Shopify compareAtPrice is present but invalid.'
            );
        }

        /*
         * Shopify only represents a meaningful sale when the comparison
         * price is strictly greater than the current selling price.
         *
         * Equal/lower comparison values are not a valid active sale.
         */
        if (
            (float) $compare_at <= (float) $price
        ) {
            return PriceState::no_sale(
                $price,
                $this->id()
            );
        }

        return PriceState::sale(
            $compare_at,
            $price,
            $this->id()
        );
    }

    /**
     * Validate and normalise a raw Shopify source price.
     *
     * This does NOT apply FX conversion.
     *
     * @param mixed $value
     */
    private function normalise_source_price(
        $value
    ): ?string {

        if (
            null === $value
            || is_array( $value )
            || is_object( $value )
            || is_bool( $value )
        ) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        if (
            '' === $value
            || ! is_numeric( $value )
        ) {
            return null;
        }

        $number = (float) $value;

        if (
            ! is_finite( $number )
            || $number <= 0.0
        ) {
            return null;
        }

        return $value;
    }
}