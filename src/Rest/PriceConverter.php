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
     * Convert an already-saved WooCommerce product to GBP using the vendor's currency setting.
     *
     * This is used for integrations (e.g. SyncSpider) that insert products without using the
     * WooCommerce REST insert hooks Dexter listens to.
     *
     * Assumes that the product's current prices are in the vendor's native currency.
     *
     * @param WC_Product $product
     */
    //public static function convert_existing_product( WC_Product $product ): void {
    /**
    public static function convert_existing_product( $product ): void {
        if ( ! $product instanceof \WC_Product && ! $product instanceof \WC_Product_Variation ) {
            return;
        }
    
        $product_id = (int) $product->get_id();
        if ( $product_id <= 0 ) {
            return;
        }

        // Avoid double conversion.
        $already = $product->get_meta( '_fxd_fx_converted_at', true );
        if ( ! empty( $already ) ) {
            return;
        }

        // Resolve vendor from the saved product's post_author.
        //$post = get_post( $product_id );
        //if ( ! $post || empty( $post->post_author ) ) {
        //    return;
        //}

        $post = get_post( $product_id );
        if ( ! $post ) {
            return;
        }

        $vendor_id = (int) $post->post_author;

        // If variation author is missing, fall back to parent product author.
        if ( $vendor_id <= 0 && $post->post_parent ) {
            $parent = get_post( (int) $post->post_parent );
            if ( $parent && ! empty( $parent->post_author ) ) {
                $vendor_id = (int) $parent->post_author;
            }
        }

        if ( $vendor_id <= 0 ) {
            return;
        }

        //$vendor_id = (int) $post->post_author;
        if ( $vendor_id <= 0 ) {
            return;
        }

        $base_currency   = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
        $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );

        // If vendor currency is base (GBP), nothing to convert.
        if ( strtoupper( $vendor_currency ) === strtoupper( $base_currency ) ) {
            // Store minimal audit meta only if prices exist (useful for traceability).
            $regular = $product->get_regular_price();
            $sale    = $product->get_sale_price();

            if ( ( '' !== $regular && is_numeric( $regular ) ) || ( '' !== $sale && is_numeric( $sale ) ) ) {
                if ( '' !== $regular && is_numeric( $regular ) ) {
                    $product->update_meta_data( '_fxd_orig_regular_price', (string) $regular );
                }
                if ( '' !== $sale && is_numeric( $sale ) ) {
                    $product->update_meta_data( '_fxd_orig_sale_price', (string) $sale );
                }
                self::store_common_audit_meta( $product, $vendor_currency, 1.0 );
                $product->save();
            }

            return;
        }

        // Fetch FX rate for vendor_currency -> base_currency (GBP).
        $rate = RateRepository::get_rate_to_base( $vendor_currency, $base_currency );
        if ( null === $rate || $rate <= 0.0 ) {
            return;
        }

        // Read existing prices (currently in vendor currency).
        $regular = $product->get_regular_price();
        $sale    = $product->get_sale_price();

        $has_regular = ( '' !== $regular && null !== $regular && is_numeric( $regular ) );
        $has_sale    = ( '' !== $sale && null !== $sale && is_numeric( $sale ) );

        if ( ! $has_regular && ! $has_sale ) {
            return;
        }

        // Convert and set GBP prices.
        if ( $has_regular ) {
            $orig_regular = (string) $regular;
            $gbp_regular  = self::convert_to_gbp( (float) $regular, $rate );
            $product->set_regular_price( $gbp_regular );
            $product->update_meta_data( '_fxd_orig_regular_price', $orig_regular );
            $product->update_meta_data( '_fxd_last_converted_regular_gbp', $gbp_regular );
        }

        if ( $has_sale ) {
            $orig_sale = (string) $sale;
            $gbp_sale  = self::convert_to_gbp( (float) $sale, $rate );
            $product->set_sale_price( $gbp_sale );
            $product->update_meta_data( '_fxd_orig_sale_price', $orig_sale );
            $product->update_meta_data( '_fxd_last_converted_sale_gbp', $gbp_sale );
        }

        // Ensure the active price is aligned with WooCommerce logic.
        $active_price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( '' !== $active_price && null !== $active_price ) {
            $product->set_price( $active_price );
        }

        // Store shared audit metadata.
        self::store_common_audit_meta( $product, $vendor_currency, $rate );

        // Persist changes.
        $product->save();
    }
    */

    /**
     * Convert an already-saved WooCommerce product to GBP using the vendor's currency setting.
     *
     * Update-safe behaviour:
     * - If prices match the last converted GBP prices, this was likely a stock-only update → do nothing.
     * - If prices differ, assume SyncSpider overwrote prices in vendor currency → reconvert.
     *
     * @param WC_Product $product
     */
    //public static function convert_existing_product( WC_Product $product ): void {
    public static function convert_existing_product( $product ): void {
        if ( ! $product instanceof \WC_Product && ! $product instanceof \WC_Product_Variation ) {
            return;
        }

        $product_id = (int) $product->get_id();
        if ( $product_id <= 0 ) {
            return;
        }

        $post = get_post( $product_id );
        if ( ! $post ) {
            return;
        }

        $vendor_id = (int) $post->post_author;

        // If variation author is missing, fall back to parent product author.
        if ( $vendor_id <= 0 && ! empty( $post->post_parent ) ) {
            $parent = get_post( (int) $post->post_parent );
            if ( $parent && ! empty( $parent->post_author ) ) {
                $vendor_id = (int) $parent->post_author;
            }
        }

        if ( $vendor_id <= 0 ) {
            return;
        }

        $base_currency   = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
        $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );

        // If vendor is GBP, nothing to convert.
        if ( strtoupper( $vendor_currency ) === strtoupper( $base_currency ) ) {
            return;
        }

        $rate = RateRepository::get_rate_to_base( $vendor_currency, $base_currency );
        if ( null === $rate || $rate <= 0.0 ) {
            return;
        }

        // Current stored prices (whatever SyncSpider/Woo last saved).
        $regular = $product->get_regular_price();
        $sale    = $product->get_sale_price();

        $has_regular = ( '' !== $regular && null !== $regular && is_numeric( $regular ) );
        $has_sale    = ( '' !== $sale && null !== $sale && is_numeric( $sale ) );

        if ( ! $has_regular && ! $has_sale ) {
            return;
        }

        // If we have "last converted GBP" metas and prices still match them, this is likely a stock-only update.
        $last_gbp_regular = $product->get_meta( '_fxd_last_converted_regular_gbp', true );
        $last_gbp_sale    = $product->get_meta( '_fxd_last_converted_sale_gbp', true );

        $decimals = wc_get_price_decimals();

        $same_regular = true;
        if ( $has_regular && '' !== (string) $last_gbp_regular ) {
            $same_regular = ( number_format( (float) $regular, $decimals, '.', '' ) === number_format( (float) $last_gbp_regular, $decimals, '.', '' ) );
        } elseif ( $has_regular && '' === (string) $last_gbp_regular ) {
            // No baseline yet: treat as not same (we should convert once to establish baseline).
            $same_regular = false;
        }

        $same_sale = true;
        if ( $has_sale && '' !== (string) $last_gbp_sale ) {
            $same_sale = ( number_format( (float) $sale, $decimals, '.', '' ) === number_format( (float) $last_gbp_sale, $decimals, '.', '' ) );
        } elseif ( $has_sale && '' === (string) $last_gbp_sale ) {
            $same_sale = false;
        }

        // If both match (or are not present), and we already have a conversion timestamp, skip.
        $already_converted_at = $product->get_meta( '_fxd_fx_converted_at', true );
        if ( ! empty( $already_converted_at ) && $same_regular && $same_sale ) {
            return;
        }

        // Convert current values assuming they are vendor-currency inputs.
        if ( $has_regular ) {
            $orig_regular = (string) $regular;
            $gbp_regular  = self::convert_to_gbp( (float) $regular, $rate );
            $product->set_regular_price( $gbp_regular );
            $product->update_meta_data( '_fxd_orig_regular_price', $orig_regular );
            $product->update_meta_data( '_fxd_last_converted_regular_gbp', $gbp_regular );
        }

        if ( $has_sale ) {
            $orig_sale = (string) $sale;
            $gbp_sale  = self::convert_to_gbp( (float) $sale, $rate );
            $product->set_sale_price( $gbp_sale );
            $product->update_meta_data( '_fxd_orig_sale_price', $orig_sale );
            $product->update_meta_data( '_fxd_last_converted_sale_gbp', $gbp_sale );
        } else {
            // If sale price is now empty, clear last converted sale baseline.
            $product->delete_meta_data( '_fxd_last_converted_sale_gbp' );
            $product->delete_meta_data( '_fxd_orig_sale_price' );
        }

        $active_price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( '' !== $active_price && null !== $active_price ) {
            $product->set_price( $active_price );
        }

        self::store_common_audit_meta( $product, $vendor_currency, $rate );
        $product->save();
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