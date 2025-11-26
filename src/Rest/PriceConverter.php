<?php

namespace Fractured\Dexter\Rest;

use Fractured\Dexter\Vendor\Currency as VendorCurrency;
use Fractured\Dexter\Fx\RateRepository;
use WC_Product;
use WC_Product_Variation;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles conversion of incoming REST product prices from vendor currency to GBP.
 */
final class PriceConverter {

    /**
     * Main entry point for converting prices on REST product/variation insert.
     *
     * @param WC_Product|WC_Product_Variation $product
     * @param WP_REST_Request                 $request
     * @param bool                            $creating
     */
    public static function maybe_convert_prices( $product, $request, bool $creating ): void {
        if ( ! $product instanceof WC_Product && ! $product instanceof WC_Product_Variation ) {
            return;
        }

        if ( ! $request instanceof WP_REST_Request ) {
            return;
        }

        $vendor_id = self::resolve_vendor_id( $product, $request );

        if ( ! $vendor_id ) {
            // No vendor resolved – treat as GBP/no conversion.
            return;
        }

        $base_currency = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
        $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );

        // If vendor currency is same as base (GBP), we don't need FX conversion.
        if ( strtoupper( $vendor_currency ) === strtoupper( $base_currency ) ) {
            // We may still want to store audit metadata if prices are present.
            self::store_audit_meta_if_prices_present(
                $product,
                $request,
                $vendor_currency,
                1.0
            );
            return;
        }

        // Fetch FX rate for vendor_currency -> base_currency (GBP).
        $rate = RateRepository::get_rate_to_base( $vendor_currency, $base_currency );
        if ( null === $rate || $rate <= 0.0 ) {
            // No usable rate – bail without altering prices.
            return;
        }

        // Get original prices from the REST request (in vendor currency).
        $orig_regular = self::get_numeric_param( $request, 'regular_price' );
        $orig_sale    = self::get_numeric_param( $request, 'sale_price' );

        // If neither regular nor sale price provided, nothing to convert.
        if ( null === $orig_regular && null === $orig_sale ) {
            return;
        }

        // Convert and set GBP prices.
        if ( null !== $orig_regular ) {
            $gbp_regular = self::convert_to_gbp( (float) $orig_regular, $rate );
            $product->set_regular_price( $gbp_regular );
            $product->update_meta_data( '_fxd_orig_regular_price', $orig_regular );
        }

        if ( null !== $orig_sale ) {
            $gbp_sale = self::convert_to_gbp( (float) $orig_sale, $rate );
            $product->set_sale_price( $gbp_sale );
            $product->update_meta_data( '_fxd_orig_sale_price', $orig_sale );
        }

        // Ensure the main '_price' field is aligned with WooCommerce logic.
        $active_price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( '' !== $active_price && null !== $active_price ) {
            $product->set_price( $active_price );
        }

        // Store shared audit metadata.
        self::store_common_audit_meta( $product, $vendor_currency, $rate );
    }

    /**
     * Resolve the vendor (user) ID from the REST request and/or product.
     *
     * Tries, in order:
     *  - Known Dokan/SyncSpider REST params (dokan_vendor_id, dokan_vendor, vendor_id, seller_id).
     *  - Existing product post author (for updates).
     *
     * @param WC_Product|WC_Product_Variation $product
     * @param WP_REST_Request                 $request
     *
     * @return int|null
     */
    private static function resolve_vendor_id( $product, WP_REST_Request $request ): ?int {
        // 1) Look for vendor in request payload (creation/update from SyncSpider).
        $param_keys = [
            'author',
            'dokan_vendor_id',
            'dokan_vendor',
            'vendor_id',
            'seller_id',
        ];

        foreach ( $param_keys as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value && '' !== $value && is_numeric( $value ) ) {
                $id = (int) $value;
                if ( $id > 0 ) {
                    return $id;
                }
            }
        }

        // 2) Fallback: use existing product post author (for updates).
        $product_id = $product->get_id();
        if ( $product_id > 0 ) {
            $post = get_post( $product_id );
            if ( $post && ! empty( $post->post_author ) ) {
                $author_id = (int) $post->post_author;
                if ( $author_id > 0 ) {
                    return $author_id;
                }
            }
        }

        return null;
    }

    /**
     * Convert an amount in vendor currency to GBP, using Dexter's rate semantics.
     *
     * NOTE: With frankfurter.app data, the stored rate is "units of vendor currency per 1 GBP".
     * That means: 1 GBP = rate * VENDOR_CURRENCY
     * => 1 VENDOR_CURRENCY = 1 / rate GBP
     * => amount_in_gbp = amount_in_vendor / rate
     *
     * @param float $amount Vendor currency amount.
     * @param float $rate   Units of vendor currency per 1 GBP.
     *
     * @return string GBP amount formatted for WooCommerce (e.g. "12.34").
     */
    private static function convert_to_gbp( float $amount, float $rate ): string {
        if ( $rate <= 0.0 ) {
            // Fallback, no conversion – unlikely if we validate rate earlier.
            $gbp = $amount;
        } else {
            $gbp = $amount / $rate;
        }

        // Standard Woo decimal format (2 dp, dot as decimal separator).
        return number_format( $gbp, wc_get_price_decimals(), '.', '' );
    }

    /**
     * Extract a numeric REST parameter if present, otherwise return null.
     *
     * @param WP_REST_Request $request
     * @param string          $key
     *
     * @return string|null Original numeric string, or null.
     */
    private static function get_numeric_param( WP_REST_Request $request, string $key ): ?string {
        if ( ! $request->has_param( $key ) ) {
            return null;
        }

        $value = $request->get_param( $key );

        if ( null === $value || '' === $value ) {
            return null;
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        // Return as string so we can store the exact original value in meta.
        return (string) $value;
    }

    /**
     * Store audit metadata if prices are present but no conversion is needed (GBP vendors).
     *
     * @param WC_Product|WC_Product_Variation $product
     * @param WP_REST_Request                 $request
     * @param string                          $currency
     * @param float                           $rate
     */
    private static function store_audit_meta_if_prices_present(
        $product,
        WP_REST_Request $request,
        string $currency,
        float $rate
    ): void {
        $orig_regular = self::get_numeric_param( $request, 'regular_price' );
        $orig_sale    = self::get_numeric_param( $request, 'sale_price' );

        if ( null === $orig_regular && null === $orig_sale ) {
            return;
        }

        if ( null !== $orig_regular ) {
            $product->update_meta_data( '_fxd_orig_regular_price', $orig_regular );
        }
        if ( null !== $orig_sale ) {
            $product->update_meta_data( '_fxd_orig_sale_price', $orig_sale );
        }

        self::store_common_audit_meta( $product, $currency, $rate );
    }

    /**
     * Store common FX audit metadata on the product/variation.
     *
     * @param WC_Product|WC_Product_Variation $product
     * @param string                          $currency
     * @param float                           $rate
     */
    private static function store_common_audit_meta( $product, string $currency, float $rate ): void {
        $product->update_meta_data( '_fxd_orig_currency', strtoupper( $currency ) );
        $product->update_meta_data( '_fxd_fx_rate_used', $rate );
        $product->update_meta_data( '_fxd_fx_converted_at', current_time( 'mysql', true ) );
    }
}