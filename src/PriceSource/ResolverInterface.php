<?php

namespace Fractured\Dexter\PriceSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract for authoritative source-price resolvers.
 *
 * A resolver has two responsibilities:
 *
 * 1. Decide whether it understands the supplied source snapshot.
 * 2. Resolve the authoritative source-currency pricing state for the
 *    WooCommerce product or variation being updated.
 *
 * Resolvers MUST fail closed.
 *
 * If a resolver cannot establish the source pricing state with confidence,
 * it must return PriceState::unknown() rather than guessing.
 */
interface ResolverInterface {

    /**
     * Unique resolver identifier.
     *
     * Examples:
     * - shopify_json
     * - woo_json
     * - squarespace_json
     */
    public function id(): string;

    /**
     * Determine whether this resolver understands the supplied snapshot.
     *
     * This method must be cheap and side-effect free.
     *
     * It must not:
     * - perform HTTP requests;
     * - write post meta;
     * - modify products;
     * - apply FX conversion.
     *
     * @param array<string,mixed> $snapshot Decoded source JSON.
     */
    public function supports(
        array $snapshot
    ): bool;

    /**
     * Resolve authoritative source pricing.
     *
     * $product is the actual WooCommerce object currently being processed.
     * It may be either:
     *
     * - WC_Product_Simple
     * - WC_Product_Variation
     * - another WC_Product subtype
     *
     * For variations, implementations may use the variation SKU/source ID
     * to locate the corresponding variant inside the parent snapshot.
     *
     * The snapshot has already been decoded and validated as an array by
     * the caller.
     *
     * This method must not:
     * - perform HTTP requests;
     * - write product/meta data;
     * - save the product;
     * - apply currency conversion;
     * - infer a sale when source data is ambiguous.
     *
     * @param \WC_Product         $product
     * @param array<string,mixed> $snapshot
     */
    public function resolve(
        \WC_Product $product,
        array $snapshot
    ): PriceState;
}