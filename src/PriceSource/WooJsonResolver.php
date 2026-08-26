<?php

namespace Fractured\Dexter\PriceSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve authoritative pricing from a WooCommerce source JSON snapshot.
 *
 * Confirmed SyncSpider/Woo source behaviour:
 *
 * SIMPLE PRODUCT
 *
 * The source snapshot is stored directly on the Fractured simple product:
 *
 * [
 *     'type'                => 'simple',
 *     'price'               => 25,
 *     'regular_price'       => 25,
 *     'sale_price'          => null,
 *     'date_on_sale_from'   => null,
 *     'date_on_sale_to'     => null,
 * ]
 *
 * VARIATION
 *
 * The source variation snapshot is stored directly on the corresponding
 * Fractured product variation:
 *
 * [
 *     'type'                => 'variation',
 *     'price'               => 27.99,
 *     'regular_price'       => 27.99,
 *     'sale_price'          => 22.99,
 *     'date_on_sale_from'   => null,
 *     'date_on_sale_to'     => '2026-02-13T23:59:59.000000Z',
 *     'id'                  => 2019,
 * ]
 *
 * IMPORTANT:
 *
 * We deliberately do NOT match Woo source variations by the source
 * snapshot's "sku".
 *
 * Real source data has shown that several source variations may share the
 * same source SKU while their source variation IDs differ.
 *
 * Because SyncSpider stores the authoritative source variation snapshot
 * directly on the corresponding Fractured variation, no further variation
 * lookup is required.
 *
 * WooCommerce sale semantics:
 *
 * - empty/null sale_price:
 *     NO_SALE
 *
 * - populated sale_price with future date_on_sale_from:
 *     NO_SALE currently
 *
 * - populated sale_price with expired date_on_sale_to:
 *     NO_SALE currently
 *
 * - populated valid sale_price within its active date window:
 *     SALE
 *
 * - malformed/ambiguous pricing or sale dates:
 *     UNKNOWN
 *
 * This resolver performs no:
 *
 * - HTTP requests;
 * - database writes;
 * - FX conversion;
 * - WooCommerce product writes.
 */
final class WooJsonResolver implements ResolverInterface {

    public function id(): string {
        return 'woo_json';
    }

    /**
     * Detect the WooCommerce REST-style source snapshot.
     *
     * Detection is intentionally conservative so another commerce
     * platform is not accidentally interpreted as WooCommerce.
     *
     * @param array<string,mixed> $snapshot
     */
    public function supports(
        array $snapshot
    ): bool {

        if (
            ! array_key_exists( 'type', $snapshot )
            || ! array_key_exists( 'price', $snapshot )
            || ! array_key_exists( 'regular_price', $snapshot )
            || ! array_key_exists( 'sale_price', $snapshot )
        ) {
            return false;
        }

        $type = strtolower(
            trim(
                (string) $snapshot['type']
            )
        );

        if (
            ! in_array(
                $type,
                [
                    'simple',
                    'variable',
                    'variation',
                    'external',
                    'grouped',
                ],
                true
            )
        ) {
            return false;
        }

        /*
         * WooCommerce source responses observed through SyncSpider include
         * identifying REST-style fields such as permalink/date_created.
         *
         * Require at least one so generic price JSON cannot be mistaken for
         * WooCommerce source data.
         */
        if (
            ! array_key_exists( 'permalink', $snapshot )
            && ! array_key_exists( 'date_created', $snapshot )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Resolve pricing for the Woo object currently being updated.
     *
     * Simple products and variations both carry their own authoritative
     * source snapshot.
     *
     * Variable parents deliberately remain unresolved because WooCommerce
     * derives their displayed price/range from child variations.
     *
     * @param \WC_Product         $product
     * @param array<string,mixed> $snapshot
     */
    public function resolve(
        \WC_Product $product,
        array $snapshot
    ): PriceState {

        if ( $product->is_type( 'variable' ) ) {
            return PriceState::unknown(
                $this->id(),
                'Variable parent pricing is derived from child variations.'
            );
        }

        $source_type = strtolower(
            trim(
                (string) ( $snapshot['type'] ?? '' )
            )
        );

        /*
         * A Woo variation must be backed by a source variation snapshot.
         *
         * Do not allow an accidentally attached parent/simple snapshot to
         * determine variation pricing.
         */
        if (
            $product->is_type( 'variation' )
            && 'variation' !== $source_type
        ) {
            return PriceState::unknown(
                $this->id(),
                'Woo variation does not have a source variation snapshot.'
            );
        }

        /*
         * Conversely, a normal sellable product must not consume a source
         * variation record.
         */
        if (
            ! $product->is_type( 'variation' )
            && 'variation' === $source_type
        ) {
            return PriceState::unknown(
                $this->id(),
                'Woo product has been given a source variation snapshot.'
            );
        }

        return $this->price_state_from_record(
            $snapshot
        );
    }

    /**
     * Convert an authoritative Woo source record into PriceState.
     *
     * @param array<string,mixed> $record
     */
    private function price_state_from_record(
        array $record
    ): PriceState {

        /*
         * regular_price is authoritative for a simple product or variation.
         *
         * Variable parents may legitimately have null regular_price, but
         * variable parents never reach this method.
         */
        if (
            ! array_key_exists(
                'regular_price',
                $record
            )
        ) {
            return PriceState::unknown(
                $this->id(),
                'Woo source record does not contain regular_price.'
            );
        }

        $regular = $this->normalise_source_price(
            $record['regular_price']
        );

        if ( null === $regular ) {
            return PriceState::unknown(
                $this->id(),
                'Woo regular_price is missing or invalid.'
            );
        }

        /*
         * sale_price is part of the authoritative Woo source schema.
         *
         * Its absence is different from an explicit null/empty value.
         */
        if (
            ! array_key_exists(
                'sale_price',
                $record
            )
        ) {
            return PriceState::unknown(
                $this->id(),
                'Woo source record does not contain sale_price.'
            );
        }

        /*
         * Explicit null/empty sale means the source is not on sale.
         */
        if (
            null === $record['sale_price']
            || '' === trim(
                (string) $record['sale_price']
            )
        ) {
            return PriceState::no_sale(
                $regular,
                $this->id()
            );
        }

        $sale = $this->normalise_source_price(
            $record['sale_price']
        );

        /*
         * A populated but malformed sale value is ambiguous.
         *
         * Never turn malformed source data into a live price assumption.
         */
        if ( null === $sale ) {
            return PriceState::unknown(
                $this->id(),
                'Woo sale_price is present but invalid.'
            );
        }

        /*
         * A valid Woo sale must be strictly below regular price.
         *
         * Equal/higher values cannot represent a genuine active discount.
         */
        if (
            (float) $sale >= (float) $regular
        ) {
            return PriceState::no_sale(
                $regular,
                $this->id()
            );
        }

        /*
         * Determine whether the source sale is ACTIVE NOW.
         *
         * Woo source snapshots can retain sale_price after a scheduled sale
         * has expired. We therefore cannot interpret populated sale_price
         * alone as proof of a currently active sale.
         */
        $now = time();

        /*
         * Future sale start.
         */
        if (
            array_key_exists(
                'date_on_sale_from',
                $record
            )
            && null !== $record['date_on_sale_from']
            && '' !== trim(
                (string) $record['date_on_sale_from']
            )
        ) {
            $sale_from = $this->parse_source_datetime(
                $record['date_on_sale_from']
            );

            if ( null === $sale_from ) {
                return PriceState::unknown(
                    $this->id(),
                    'Woo date_on_sale_from is present but invalid.'
                );
            }

            if ( $now < $sale_from ) {
                return PriceState::no_sale(
                    $regular,
                    $this->id()
                );
            }
        }

        /*
         * Expired sale.
         *
         * The source timestamp represents the final moment at which the
         * sale is valid. Once current time is greater than that timestamp,
         * the product is no longer on sale.
         */
        if (
            array_key_exists(
                'date_on_sale_to',
                $record
            )
            && null !== $record['date_on_sale_to']
            && '' !== trim(
                (string) $record['date_on_sale_to']
            )
        ) {
            $sale_to = $this->parse_source_datetime(
                $record['date_on_sale_to']
            );

            if ( null === $sale_to ) {
                return PriceState::unknown(
                    $this->id(),
                    'Woo date_on_sale_to is present but invalid.'
                );
            }

            if ( $now > $sale_to ) {
                return PriceState::no_sale(
                    $regular,
                    $this->id()
                );
            }
        }

        /*
         * We now have:
         *
         * - valid positive regular price;
         * - valid positive sale price;
         * - sale strictly below regular;
         * - no future start preventing activation;
         * - no expired end date.
         *
         * The authoritative source is therefore currently on sale.
         */
        return PriceState::sale(
            $regular,
            $sale,
            $this->id()
        );
    }

    /**
     * Validate and normalise a raw Woo source price.
     *
     * No FX conversion occurs here.
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

    /**
     * Parse an authoritative source timestamp into Unix time.
     *
     * Woo source dates observed through SyncSpider are ISO-8601 values,
     * commonly including an explicit UTC "Z" suffix.
     *
     * We use PHP's timestamp parser so timezone offsets in the source value
     * are respected.
     *
     * @param mixed $value
     */
    private function parse_source_datetime(
        $value
    ): ?int {

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

        if ( '' === $value ) {
            return null;
        }

        $timestamp = strtotime(
            $value
        );

        if ( false === $timestamp ) {
            return null;
        }

        return (int) $timestamp;
    }
}