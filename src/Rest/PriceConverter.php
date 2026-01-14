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
        
        
        /* NEW EDIT */
        // Persist vendor baselines from REST payload (authoritative vendor-currency values)
        if ( null !== $orig_regular ) {
            $product->update_meta_data( '_fxd_vendor_regular_price', (string) $orig_regular );
        } else {
            // If regular_price absent in this update, do NOT clear baseline (keeps last known vendor price)
        }
        
        if ( null !== $orig_sale ) {
            $product->update_meta_data( '_fxd_vendor_sale_price', (string) $orig_sale );
        } else {
            // If sale_price explicitly sent empty, clear baseline
            if ( $request->has_param('sale_price') && (string) $request->get_param('sale_price') === '' ) {
                $product->delete_meta_data( '_fxd_vendor_sale_price' );
            }
        }

        // NEW EDIT COMPLETE //

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
    
        // Variations may not have author
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
    
        if ( strtoupper( $vendor_currency ) === strtoupper( $base_currency ) ) {
            return;
        }
    
        $rate = RateRepository::get_rate_to_base( $vendor_currency, $base_currency );
        if ( ! $rate || $rate <= 0 ) {
            return;
        }
    
        /*
         * ---------------------------------------------------------
         * BULLETPROOF CONVERSION LOGIC (vendor-baseline driven)
         * ---------------------------------------------------------
         */
    
        // Immutable vendor-currency baselines
        $vendor_regular = $product->get_meta( '_fxd_vendor_regular_price', true );
        $vendor_sale    = $product->get_meta( '_fxd_vendor_sale_price', true );
    
        // What SyncSpider just wrote (vendor currency)
        $incoming_regular = $product->get_regular_price();
        $incoming_sale    = $product->get_sale_price();
    
        // Update vendor baselines ONLY from incoming values
        if ( is_numeric( $incoming_regular ) && (string) $incoming_regular !== (string) $vendor_regular ) {
            $vendor_regular = (string) $incoming_regular;
            $product->update_meta_data( '_fxd_vendor_regular_price', $vendor_regular );
        }
    
        if ( is_numeric( $incoming_sale ) && (string) $incoming_sale !== (string) $vendor_sale ) {
            $vendor_sale = (string) $incoming_sale;
            $product->update_meta_data( '_fxd_vendor_sale_price', $vendor_sale );
        }
    
        if ( $vendor_regular === '' && $vendor_sale === '' ) {
            return;
        }
    
        // Convert ONLY from vendor baselines
        if ( $vendor_regular !== '' ) {
            $gbp_regular = self::convert_to_gbp( (float) $vendor_regular, $rate );
            $product->set_regular_price( $gbp_regular );
            $product->update_meta_data( '_fxd_last_converted_regular_gbp', $gbp_regular );
        }
    
        if ( $vendor_sale !== '' ) {
            $gbp_sale = self::convert_to_gbp( (float) $vendor_sale, $rate );
            $product->set_sale_price( $gbp_sale );
            $product->update_meta_data( '_fxd_last_converted_sale_gbp', $gbp_sale );
        } else {
            $product->set_sale_price( '' );
            $product->delete_meta_data( '_fxd_last_converted_sale_gbp' );
        }
    
        // Align Woo active price
        $active_price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( $active_price !== '' ) {
            $product->set_price( $active_price );
        }
        
        self::store_common_audit_meta( $product, $vendor_currency, $rate );
        $product->save();
    
        /*
         * ---------------------------------------------------------
         * VARIABLE PARENT PRICE FIX (CRITICAL UX FIX)
         * ---------------------------------------------------------
         */
        // After syncing a variation, ensure the variable parent has a displayable meta price.
        // Some dashboards (often Dokan/admin lists) read _regular_price directly and show 0 for variable parents otherwise.
        if ( $product instanceof \WC_Product_Variation ) {
            $parent_id = (int) $product->get_parent_id();
        
            if ( $parent_id > 0 ) {
                // Clear caches/transients then sync ranges.
                wc_delete_product_transients( $parent_id );
                \WC_Product_Variable::sync( $parent_id );
        
                // Ensure lookup table is refreshed (depends on WC version).
                if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
                    wc_update_product_lookup_tables( $parent_id );
                }
        
                // Compute min variation price (variations are already GBP at this point).
                $parent = wc_get_product( $parent_id );
                if ( $parent && $parent instanceof \WC_Product_Variable ) {
                    $min_price = $parent->get_variation_price( 'min', true );
        
                    if ( is_numeric( $min_price ) && (float) $min_price > 0 ) {
                        $min_price_str = number_format( (float) $min_price, wc_get_price_decimals(), '.', '' );
        
                        // Write "display" metas for UIs that read parent metas directly.
                        update_post_meta( $parent_id, '_regular_price', $min_price_str );
                        update_post_meta( $parent_id, '_price', $min_price_str );
                    }
        
                    $parent->save();
                }
            }
        }
        
        // Optional: if convert_existing_product() is ever called directly on a variable parent, keep it consistent.
        if ( $product instanceof \WC_Product_Variable ) {
            $pid = (int) $product->get_id();
            if ( $pid > 0 ) {
                wc_delete_product_transients( $pid );
                \WC_Product_Variable::sync( $pid );
        
                if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
                    wc_update_product_lookup_tables( $pid );
                }
            }
        }
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