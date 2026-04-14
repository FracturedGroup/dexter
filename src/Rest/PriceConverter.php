<?php

namespace Fractured\Dexter\Rest;

use Fractured\Dexter\Vendor\Currency as VendorCurrency;
use Fractured\Dexter\Fx\RateRepository;
use Fractured\Dexter\PriceSource\JsonPriceResolver;
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

    // Vendor baseline meta keys (authoritative vendor-currency values).
    private const META_VENDOR_REGULAR = '_fxd_vendor_regular_price';
    private const META_VENDOR_SALE    = '_fxd_vendor_sale_price';

    /**
     * Main entry point for converting prices on REST product/variation insert.
     *
     * @param WC_Product|WC_Product_Variation $product
     * @param WP_REST_Request                 $request
     * @param bool                            $creating
     */
    /*public static function maybe_convert_prices( $product, $request, bool $creating ): void {
        if ( ! $product instanceof WC_Product && ! $product instanceof WC_Product_Variation ) {
            return;
        }

        if ( ! $request instanceof WP_REST_Request ) {
            return;
        }

        // If this is a VARIABLE parent product, do not try to convert prices here.
        // Variations will be handled via the variation hook and syncing parent is done elsewhere.
        if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
            return;
        }

        $vendor_id = self::resolve_vendor_id( $product, $request );
        if ( ! $vendor_id ) {
            return;
        }

        $base_currency   = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
        $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );

        // Vendor-specific JSON override (authoritative when available).
        $override_prices = JsonPriceResolver::get_override_prices( $product, $vendor_id );

        if ( is_array( $override_prices ) ) {
            $orig_regular = $override_prices['regular'] ?? null;
            $orig_sale    = $override_prices['sale'] ?? null;

            self::apply_vendor_baselines_from_override( $product, $orig_regular, $orig_sale );
        } else {
            // Pull "authoritative" vendor-currency prices:
            // 1) Prefer stored vendor baseline meta (idempotent).
            // 2) Fall back to REST request values (first-time import).
            $orig_regular = self::get_vendor_price_baseline_or_request( $product, $request, 'regular_price', self::META_VENDOR_REGULAR );
            $orig_sale    = self::get_vendor_price_baseline_or_request( $product, $request, 'sale_price', self::META_VENDOR_SALE );
        }

        // If neither regular nor sale price present, nothing to do.
        if ( null === $orig_regular && null === $orig_sale ) {
            return;
        }

        // If vendor currency is base (GBP), we don't need FX conversion.
        if ( strtoupper( $vendor_currency ) === strtoupper( $base_currency ) ) {
            if ( null !== $orig_regular ) {
                self::update_meta_if_changed( $product, '_fxd_orig_regular_price', $orig_regular );
            }
            if ( null !== $orig_sale ) {
                self::update_meta_if_changed( $product, '_fxd_orig_sale_price', $orig_sale );
            }

            self::store_common_audit_meta_if_changed( $product, $vendor_currency, 1.0 );
            return;
        }

        // Fetch FX rate for vendor_currency -> base_currency (GBP).
        $rate = RateRepository::get_rate_to_base( $vendor_currency, $base_currency );
        if ( null === $rate || $rate <= 0.0 ) {
            return;
        }

        // Compute GBP prices.
        $gbp_regular = null;
        $gbp_sale    = null;

        if ( null !== $orig_regular ) {
            $gbp_regular = self::convert_to_gbp( (float) $orig_regular, $rate );
        }
        if ( null !== $orig_sale ) {
            $gbp_sale = self::convert_to_gbp( (float) $orig_sale, $rate );
        }

        if ( null !== $gbp_regular ) {
            $product->set_regular_price( $gbp_regular );
            self::update_meta_if_changed( $product, '_fxd_orig_regular_price', $orig_regular );
        }

        if ( null !== $gbp_sale ) {
            $product->set_sale_price( $gbp_sale );
            self::update_meta_if_changed( $product, '_fxd_orig_sale_price', $orig_sale );
        } elseif ( is_array( $override_prices ) ) {
            // JSON override is authoritative: clear stale sale if JSON says no sale.
            $product->set_sale_price( '' );
            self::delete_meta_if_exists( $product, '_fxd_orig_sale_price' );
        }

        // Ensure '_price' aligns with Woo logic.
        $active_price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( '' !== $active_price && null !== $active_price ) {
            $product->set_price( $active_price );
        }

        self::store_common_audit_meta_if_changed( $product, $vendor_currency, $rate );
        return;
    }*/
    public static function maybe_convert_prices( $product, $request, bool $creating ): void {
        if ( ! $product instanceof WC_Product && ! $product instanceof WC_Product_Variation ) {
            return;
        }
    
        if ( ! $request instanceof WP_REST_Request ) {
            return;
        }
    
        // If this is a VARIABLE parent product, do not try to convert prices here.
        // Variations will be handled via the variation hook and syncing parent is done elsewhere.
        if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
            return;
        }
    
        $vendor_id = self::resolve_vendor_id( $product, $request );
        if ( ! $vendor_id ) {
            return;
        }
    
        $base_currency   = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
        $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );
    
        // Vendor-specific JSON override (authoritative when available).
        $override_prices = JsonPriceResolver::get_override_prices( $product, $vendor_id );
    
        if ( is_array( $override_prices ) ) {
            $orig_regular = $override_prices['regular'] ?? null;
            $orig_sale    = $override_prices['sale'] ?? null;
    
            self::apply_vendor_baselines_from_override( $product, $orig_regular, $orig_sale );
        } else {
            // Pull vendor-currency prices:
            // 1) Prefer incoming REST request values when present.
            // 2) Fall back to stored vendor baseline meta only when request lacks the field.
            $orig_regular = self::get_vendor_price_baseline_or_request( $product, $request, 'regular_price', self::META_VENDOR_REGULAR );
            $orig_sale    = self::get_vendor_price_baseline_or_request( $product, $request, 'sale_price', self::META_VENDOR_SALE );
    
            // Persist fresh request-driven baselines so stale historical values do not linger.
            if ( null !== $orig_regular ) {
                self::update_meta_if_changed( $product, self::META_VENDOR_REGULAR, $orig_regular );
            }
            if ( null !== $orig_sale ) {
                self::update_meta_if_changed( $product, self::META_VENDOR_SALE, $orig_sale );
            } elseif ( $request->has_param( 'sale_price' ) ) {
                self::delete_meta_if_exists( $product, self::META_VENDOR_SALE );
            }
        }
    
        // If neither regular nor sale price present, nothing to do.
        if ( null === $orig_regular && null === $orig_sale ) {
            return;
        }
    
        // If vendor currency is base (GBP), we don't need FX conversion.
        if ( strtoupper( $vendor_currency ) === strtoupper( $base_currency ) ) {
            if ( null !== $orig_regular ) {
                self::update_meta_if_changed( $product, '_fxd_orig_regular_price', $orig_regular );
            }
            if ( null !== $orig_sale ) {
                self::update_meta_if_changed( $product, '_fxd_orig_sale_price', $orig_sale );
            } elseif ( ! is_array( $override_prices ) && $request->has_param( 'sale_price' ) ) {
                self::delete_meta_if_exists( $product, '_fxd_orig_sale_price' );
            }
    
            self::store_common_audit_meta_if_changed( $product, $vendor_currency, 1.0 );
            return;
        }
    
        // Fetch FX rate for vendor_currency -> base_currency (GBP).
        $rate = RateRepository::get_rate_to_base( $vendor_currency, $base_currency );
        if ( null === $rate || $rate <= 0.0 ) {
            return;
        }
    
        // Compute GBP prices.
        $gbp_regular = null;
        $gbp_sale    = null;
    
        if ( null !== $orig_regular ) {
            $gbp_regular = self::convert_to_gbp( (float) $orig_regular, $rate );
        }
        if ( null !== $orig_sale ) {
            $gbp_sale = self::convert_to_gbp( (float) $orig_sale, $rate );
        }
    
        if ( null !== $gbp_regular ) {
            $product->set_regular_price( $gbp_regular );
            self::update_meta_if_changed( $product, '_fxd_orig_regular_price', $orig_regular );
        }
    
        if ( null !== $gbp_sale ) {
            $product->set_sale_price( $gbp_sale );
            self::update_meta_if_changed( $product, '_fxd_orig_sale_price', $orig_sale );
        } elseif ( is_array( $override_prices ) || ( ! is_array( $override_prices ) && $request->has_param( 'sale_price' ) ) ) {
            // Clear stale sale when the authoritative source says there is no sale.
            $product->set_sale_price( '' );
            self::delete_meta_if_exists( $product, '_fxd_orig_sale_price' );
        }
    
        // Ensure '_price' aligns with Woo logic.
        $active_price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( '' !== $active_price && null !== $active_price ) {
            $product->set_price( $active_price );
        }
    
        self::store_common_audit_meta_if_changed( $product, $vendor_currency, $rate );
        return;
    }

    /**
     * Convert an already-saved WooCommerce product to GBP.
     *
     * IMPORTANT: This MUST be idempotent and must NOT treat current Woo prices as vendor currency
     * if vendor baselines exist.
     *
     * @param WC_Product|WC_Product_Variation $product
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

        // If variation author is missing, fall back to parent product author.
        if ( $vendor_id <= 0 && $post->post_parent ) {
            $parent = get_post( (int) $post->post_parent );
            if ( $parent && ! empty( $parent->post_author ) ) {
                $vendor_id = (int) $parent->post_author;
            }
        }

        /*
        if ( $vendor_id <= 0 ) {
            return;
        }

        $base_currency   = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
        $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );
        */
        
        if ( $vendor_id <= 0 ) {
            return;
        }

        // For JSON-override vendors only, variable parents must never be directly converted.
        // Their display price should come from converted child variations.
        if (
            $product instanceof \WC_Product
            && $product->is_type( 'variable' )
            && JsonPriceResolver::is_override_vendor( $vendor_id )
        ) {
            wc_delete_product_transients( $product_id );
            \WC_Product_Variable::sync( $product_id );
            return;
        }

        $base_currency   = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );
        $vendor_currency = VendorCurrency::get_vendor_currency( $vendor_id );

        // Vendor-specific JSON override (authoritative when available).
        $override_prices = JsonPriceResolver::get_override_prices( $product, $vendor_id );

        if ( is_array( $override_prices ) ) {
            $orig_regular = $override_prices['regular'] ?? null;
            $orig_sale    = $override_prices['sale'] ?? null;

            self::apply_vendor_baselines_from_override( $product, $orig_regular, $orig_sale );

            $has_regular = ( null !== $orig_regular && '' !== $orig_regular && is_numeric( $orig_regular ) );
            $has_sale    = ( null !== $orig_sale && '' !== $orig_sale && is_numeric( $orig_sale ) );
        } else {
            // Prefer vendor baselines; if missing, fall back to current prices (best-effort).
            $orig_regular = (string) $product->get_meta( self::META_VENDOR_REGULAR, true );
            $orig_sale    = (string) $product->get_meta( self::META_VENDOR_SALE, true );

            $has_regular = ( '' !== $orig_regular && is_numeric( $orig_regular ) );
            $has_sale    = ( '' !== $orig_sale && is_numeric( $orig_sale ) );

            if ( ! $has_regular && ! $has_sale ) {
                // Fallback to existing Woo prices (legacy behaviour).
                $regular = $product->get_regular_price();
                $sale    = $product->get_sale_price();

                $has_regular = ( '' !== $regular && null !== $regular && is_numeric( $regular ) );
                $has_sale    = ( '' !== $sale && null !== $sale && is_numeric( $sale ) );

                if ( ! $has_regular && ! $has_sale ) {
                    return;
                }

                $orig_regular = $has_regular ? (string) $regular : '';
                $orig_sale    = $has_sale ? (string) $sale : '';
            }
        }

        if ( strtoupper( $vendor_currency ) === strtoupper( $base_currency ) ) {
            $changed = false;

            if ( $has_regular ) {
                $changed = self::update_meta_if_changed( $product, '_fxd_orig_regular_price', (string) $orig_regular ) || $changed;
            }
            if ( $has_sale ) {
                $changed = self::update_meta_if_changed( $product, '_fxd_orig_sale_price', (string) $orig_sale ) || $changed;
            } elseif ( is_array( $override_prices ) ) {
                if ( '' !== (string) $product->get_sale_price() ) {
                    $product->set_sale_price( '' );
                    $changed = true;
                }
                $changed = self::delete_meta_if_exists( $product, '_fxd_orig_sale_price' ) || $changed;
            }

            $changed = self::store_common_audit_meta_if_changed( $product, $vendor_currency, 1.0 ) || $changed;

            if ( $changed ) {
                $active_price = $product->get_sale_price() ?: $product->get_regular_price();
                if ( '' !== $active_price && null !== $active_price ) {
                    $product->set_price( $active_price );
                }
                $product->save();
            }
            return;
        }

        $rate = RateRepository::get_rate_to_base( $vendor_currency, $base_currency );
        if ( null === $rate || $rate <= 0.0 ) {
            return;
        }

        $changed = false;

        if ( $has_regular ) {
            $gbp_regular = self::convert_to_gbp( (float) $orig_regular, $rate );
            if ( (string) $product->get_regular_price() !== (string) $gbp_regular ) {
                $product->set_regular_price( $gbp_regular );
                $changed = true;
            }
            $changed = self::update_meta_if_changed( $product, '_fxd_orig_regular_price', (string) $orig_regular ) || $changed;
        }

        if ( $has_sale ) {
            $gbp_sale = self::convert_to_gbp( (float) $orig_sale, $rate );
            if ( (string) $product->get_sale_price() !== (string) $gbp_sale ) {
                $product->set_sale_price( $gbp_sale );
                $changed = true;
            }
            $changed = self::update_meta_if_changed( $product, '_fxd_orig_sale_price', (string) $orig_sale ) || $changed;
        } elseif ( is_array( $override_prices ) ) {
            // JSON override is authoritative: clear stale sale if JSON says no sale.
            if ( '' !== (string) $product->get_sale_price() ) {
                $product->set_sale_price( '' );
                $changed = true;
            }
            $changed = self::delete_meta_if_exists( $product, '_fxd_orig_sale_price' ) || $changed;
        }

        $active_price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( '' !== $active_price && null !== $active_price ) {
            if ( (string) $product->get_price() !== (string) $active_price ) {
                $product->set_price( $active_price );
                $changed = true;
            }
        }

        $changed = self::store_common_audit_meta_if_changed( $product, $vendor_currency, $rate ) || $changed;

        if ( $changed ) {
            $product->save();
        }
    }

    /**
     * Prefer vendor baseline meta; fall back to request.
     */
    
    /* UPDATED ON 13/04/2026 - TO FORCE DEXTER NOT TO USE STALE BASLINES PRICES - THIS FIX WAS APPLIED WHEN JIWYA CURRENCY CHANGED FROM INR TO USD AT SOURCE
    private static function get_vendor_price_baseline_or_request(
        $product,
        WP_REST_Request $request,
        string $request_key,
        string $baseline_meta_key
    ): ?string {
        // Baseline meta is stored as string.
        $baseline = (string) $product->get_meta( $baseline_meta_key, true );
        if ( '' !== $baseline && is_numeric( $baseline ) ) {
            return $baseline;
        }
        return self::get_numeric_param( $request, $request_key );
    }
    */
    
    private static function get_vendor_price_baseline_or_request(
        $product,
        WP_REST_Request $request,
        string $request_key,
        string $baseline_meta_key
    ): ?string {
        // On real REST imports/updates, the incoming mapped price must win if present.
        // This prevents stale stored baselines from overriding fresh SyncSpider prices.
        $incoming = self::get_numeric_param( $request, $request_key );
        if ( null !== $incoming ) {
            return $incoming;
        }
    
        // If the request did not include this price field, fall back to stored baseline.
        $baseline = (string) $product->get_meta( $baseline_meta_key, true );
        if ( '' !== $baseline && is_numeric( $baseline ) ) {
            return $baseline;
        }
    
        return null;
    }
    

    /**
     * Apply vendor-currency baselines from authoritative override prices.
     *
     * @param \WC_Product|\WC_Product_Variation $product
     * @param string|null                       $regular
     * @param string|null                       $sale
     */
    private static function apply_vendor_baselines_from_override( $product, ?string $regular, ?string $sale ): void {
        if ( null !== $regular && '' !== $regular && is_numeric( $regular ) ) {
            self::update_meta_if_changed( $product, self::META_VENDOR_REGULAR, $regular );
        }

        if ( null !== $sale && '' !== $sale && is_numeric( $sale ) ) {
            self::update_meta_if_changed( $product, self::META_VENDOR_SALE, $sale );
        } else {
            self::delete_meta_if_exists( $product, self::META_VENDOR_SALE );
        }
    }

    /**
     * Resolve the vendor (user) ID from the REST request and/or product.
     *
     * @return int|null
     */
    private static function resolve_vendor_id( $product, WP_REST_Request $request ): ?int {
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

        $product_id = (int) $product->get_id();
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

    private static function convert_to_gbp( float $amount, float $rate ): string {
        $gbp = ( $rate > 0.0 ) ? ( $amount / $rate ) : $amount;
        return number_format( $gbp, wc_get_price_decimals(), '.', '' );
    }

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

        return (string) $value;
    }

    /**
     * Update meta only if different (reduces writes).
     */
    private static function update_meta_if_changed( $product, string $key, string $value ): bool {
        $current = (string) $product->get_meta( $key, true );
        if ( $current === (string) $value ) {
            return false;
        }
        $product->update_meta_data( $key, (string) $value );
        return true;
    }

    /**
     * Delete meta only if it currently exists.
     */
    private static function delete_meta_if_exists( $product, string $key ): bool {
        $current = $product->get_meta( $key, true );
        if ( '' === (string) $current && null !== $current ) {
            return false;
        }
        if ( '' === (string) $current && null === $current ) {
            return false;
        }

        $product->delete_meta_data( $key );
        return true;
    }

    /**
     * Store audit meta only if different (reduces writes).
     */
    private static function store_common_audit_meta_if_changed( $product, string $currency, float $rate ): bool {
        $changed = false;

        $changed = self::update_meta_if_changed( $product, '_fxd_orig_currency', strtoupper( $currency ) ) || $changed;

        $current_rate = $product->get_meta( '_fxd_fx_rate_used', true );
        // Compare as strings to avoid float quirks.
        if ( (string) $current_rate !== (string) $rate ) {
            $product->update_meta_data( '_fxd_fx_rate_used', $rate );
            $changed = true;
        }

        // Only update converted_at if we actually changed something meaningful.
        // NOTE: callers may also rely on this timestamp as "last touched".
        $product->update_meta_data( '_fxd_fx_converted_at', current_time( 'mysql', true ) );
        $changed = true;

        return $changed;
    }
}