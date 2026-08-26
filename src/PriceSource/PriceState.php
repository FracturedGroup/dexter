<?php

namespace Fractured\Dexter\PriceSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Immutable authoritative source pricing state.
 *
 * A resolver may return exactly one of three states:
 *
 * SALE
 * - Source explicitly says the item is on sale.
 * - Regular and sale prices must both be valid positive numeric values.
 * - Sale must be below regular.
 *
 * NO_SALE
 * - Source explicitly says the item is not on sale.
 * - Regular price must be a valid positive numeric value.
 * - Sale price is deliberately absent.
 *
 * UNKNOWN
 * - Source pricing state could not be established safely.
 * - No pricing assumptions should be made from this object.
 */
final class PriceState {

    public const STATE_SALE    = 'sale';
    public const STATE_NO_SALE = 'no_sale';
    public const STATE_UNKNOWN = 'unknown';

    /**
     * @var string
     */
    private $state;

    /**
     * Authoritative source-currency regular price.
     *
     * @var string|null
     */
    private $regular_price;

    /**
     * Authoritative source-currency sale price.
     *
     * @var string|null
     */
    private $sale_price;

    /**
     * Resolver/source identifier for diagnostics.
     *
     * Examples:
     * - shopify_json
     * - woo_json
     *
     * @var string
     */
    private $source;

    /**
     * Optional diagnostic reason.
     *
     * @var string
     */
    private $reason;

    private function __construct(
        string $state,
        ?string $regular_price,
        ?string $sale_price,
        string $source,
        string $reason = ''
    ) {
        $this->state         = $state;
        $this->regular_price = $regular_price;
        $this->sale_price    = $sale_price;
        $this->source        = trim( $source );
        $this->reason        = trim( $reason );
    }

    /**
     * Build an authoritative SALE state.
     */
    public static function sale(
        string $regular_price,
        string $sale_price,
        string $source
    ): self {

        $regular = self::normalise_price(
            $regular_price
        );

        $sale = self::normalise_price(
            $sale_price
        );

        /*
         * A resolver must never be allowed to create an invalid sale state.
         *
         * Fail closed to UNKNOWN instead.
         */
        if (
            null === $regular
            || null === $sale
            || (float) $regular <= 0.0
            || (float) $sale <= 0.0
            || (float) $sale >= (float) $regular
        ) {
            return self::unknown(
                $source,
                'Resolver supplied an invalid sale price state.'
            );
        }

        return new self(
            self::STATE_SALE,
            $regular,
            $sale,
            $source
        );
    }

    /**
     * Build an authoritative NO_SALE state.
     */
    public static function no_sale(
        string $regular_price,
        string $source
    ): self {

        $regular = self::normalise_price(
            $regular_price
        );

        if (
            null === $regular
            || (float) $regular <= 0.0
        ) {
            return self::unknown(
                $source,
                'Resolver supplied an invalid regular price.'
            );
        }

        return new self(
            self::STATE_NO_SALE,
            $regular,
            null,
            $source
        );
    }

    /**
     * Build an UNKNOWN state.
     *
     * UNKNOWN means Dexter must not infer sale state from this result.
     */
    public static function unknown(
        string $source = '',
        string $reason = ''
    ): self {

        return new self(
            self::STATE_UNKNOWN,
            null,
            null,
            $source,
            $reason
        );
    }

    public function state(): string {
        return $this->state;
    }

    public function is_sale(): bool {
        return self::STATE_SALE === $this->state;
    }

    public function is_no_sale(): bool {
        return self::STATE_NO_SALE === $this->state;
    }

    public function is_unknown(): bool {
        return self::STATE_UNKNOWN === $this->state;
    }

    public function regular_price(): ?string {
        return $this->regular_price;
    }

    public function sale_price(): ?string {
        return $this->sale_price;
    }

    public function source(): string {
        return $this->source;
    }

    public function reason(): string {
        return $this->reason;
    }

    /**
     * Normalise a source price without applying FX conversion.
     *
     * Important:
     * - does not round to Woo currency decimals;
     * - does not convert currencies;
     * - does not accept zero/negative/non-numeric values;
     * - avoids scientific notation in downstream comparisons.
     */
    private static function normalise_price(
        string $value
    ): ?string {

        $value = trim(
            $value
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

        /*
         * Preserve enough precision for source-currency prices while
         * removing unnecessary trailing zeros.
         */
        $normalised = rtrim(
            rtrim(
                number_format(
                    $number,
                    8,
                    '.',
                    ''
                ),
                '0'
            ),
            '.'
        );

        return '' !== $normalised
            ? $normalised
            : null;
    }
}