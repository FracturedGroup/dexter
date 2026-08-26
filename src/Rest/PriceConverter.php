<?php

namespace Fractured\Dexter\Rest;

use Fractured\Dexter\Vendor\Currency as VendorCurrency;
use Fractured\Dexter\Fx\RateRepository;
use Fractured\Dexter\PriceSource\SourcePriceResolver;
use Fractured\Dexter\PriceSource\PriceState;

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
    public static function maybe_convert_prices(
        $product,
        $request,
        bool $creating
    ): void {

        if (
            ! $product instanceof WC_Product
            && ! $product instanceof WC_Product_Variation
        ) {
            return;
        }

        if ( ! $request instanceof WP_REST_Request ) {
            return;
        }

        /*
         * Variable parents must never be priced directly.
         *
         * Their child variations are authoritative and WooCommerce derives
         * the parent price/range from those children.
         */
        if (
            $product instanceof WC_Product
            && $product->is_type( 'variable' )
        ) {
            return;
        }

        $vendor_id = self::resolve_vendor_id(
            $product,
            $request
        );

        if ( ! $vendor_id ) {
            return;
        }

        $base_currency = strtoupper(
            (string) apply_filters(
                'fractured_dexter_fx_base_currency',
                'GBP'
            )
        );

        $vendor_currency = strtoupper(
            (string) VendorCurrency::get_vendor_currency(
                $vendor_id
            )
        );

        if (
            '' === $vendor_currency
            || '' === $base_currency
        ) {
            return;
        }

        /*
         * Determine whether this is a SyncSpider-originated request.
         *
         * Check:
         *
         * 1. in-memory product meta;
         * 2. direct REST parameter;
         * 3. REST meta_data payload.
         *
         * This works for both existing products and first-time creation.
         */
        $is_syncspider = self::is_syncspider_request(
            $product,
            $request
        );

        /*
         * Capture the incoming REST price state exactly once.
         *
         * These values are only the fallback source. Whenever Dexter can
         * establish authoritative source pricing from the mapped JSON snapshot,
         * that snapshot wins.
         */
        $incoming_regular = self::get_numeric_param(
            $request,
            'regular_price'
        );

        $incoming_sale = self::get_numeric_param(
            $request,
            'sale_price'
        );

        $has_regular_param = $request->has_param(
            'regular_price'
        );

        $has_sale_param = $request->has_param(
            'sale_price'
        );

        $has_incoming_regular = (
            $has_regular_param
            && null !== $incoming_regular
        );

        $has_incoming_sale = (
            $has_sale_param
            && null !== $incoming_sale
        );

        /*
         * Resolve authoritative source pricing.
         *
         * This is deliberately only used for SyncSpider products because
         * _fxd_gallery_image_urls is the SyncSpider source snapshot contract.
         */
        $source_state = $is_syncspider
            ? SourcePriceResolver::resolve( $product )
            : PriceState::unknown(
                'source',
                'Not a SyncSpider request.'
            );

        $has_authoritative_source = (
            ! $source_state->is_unknown()
        );

        /*
         * If a platform resolver recognised the snapshot but could not safely
         * establish pricing, FAIL CLOSED.
         *
         * We must not fall back to destination Woo sale values after a
         * recognised source resolver has reported ambiguity.
         *
         * "source" and "registry" mean there was no usable/recognised local
         * snapshot, so conservative legacy fallback remains available for
         * platforms not yet supported by the resolver layer.
         */
        if (
            $source_state->is_unknown()
            && ! in_array(
                $source_state->source(),
                [
                    '',
                    'source',
                    'registry',
                ],
                true
            )
        ) {
            return;
        }

        /*
         * Existing commercial safety floor.
         *
         * Default:
         * sale must be >= 10% of regular.
         */
        $minimum_sale_ratio = (float) apply_filters(
            'fractured_dexter_min_sale_ratio',
            0.10,
            $vendor_id,
            $product
        );

        if ( $minimum_sale_ratio < 0.0 ) {
            $minimum_sale_ratio = 0.0;
        }

        $orig_regular = null;
        $orig_sale    = null;

        $authoritative_no_sale = false;

        /*
         * ---------------------------------------------------------
         * PATH A — AUTHORITATIVE SOURCE JSON
         * ---------------------------------------------------------
         *
         * Shopify/Woo/future supported platforms land here.
         *
         * The REST regular_price/sale_price fields are ignored for pricing
         * truth once the source snapshot has been resolved.
         */
        if ( $has_authoritative_source ) {

            $orig_regular = $source_state->regular_price();
            $orig_sale    = $source_state->sale_price();

            if (
                null === $orig_regular
                || ! is_numeric( $orig_regular )
                || (float) $orig_regular <= 0.0
            ) {
                return;
            }

            if ( $source_state->is_no_sale() ) {
                $orig_sale             = null;
                $authoritative_no_sale = true;
            }

            if ( $source_state->is_sale() ) {

                if (
                    null === $orig_sale
                    || ! is_numeric( $orig_sale )
                ) {
                    return;
                }

                $regular_amount = (float) $orig_regular;
                $sale_amount    = (float) $orig_sale;

                if (
                    $regular_amount <= 0.0
                    || $sale_amount <= 0.0
                    || $sale_amount >= $regular_amount
                    || (
                        $minimum_sale_ratio > 0.0
                        && $sale_amount
                            < (
                                $regular_amount
                                * $minimum_sale_ratio
                            )
                    )
                ) {
                    /*
                     * Fail safe to regular price.
                     *
                     * Never activate an implausibly low source sale.
                     */
                    $orig_sale             = null;
                    $authoritative_no_sale = true;
                }
            }

            self::update_meta_if_changed(
                $product,
                self::META_VENDOR_REGULAR,
                (string) $orig_regular
            );

            if ( null !== $orig_sale ) {
                self::update_meta_if_changed(
                    $product,
                    self::META_VENDOR_SALE,
                    (string) $orig_sale
                );
            } else {
                self::delete_meta_if_exists(
                    $product,
                    self::META_VENDOR_SALE
                );
            }

        /*
         * ---------------------------------------------------------
         * PATH B — LEGACY / UNSUPPORTED PLATFORM FALLBACK
         * ---------------------------------------------------------
         *
         * IMPORTANT:
         *
         * This path is deliberately conservative.
         *
         * For an unsupported platform:
         *
         * - an incoming numeric regular_price may update regular;
         * - an incoming valid numeric sale_price may update sale;
         * - an EXPLICIT empty/null sale_price means SALE OFF;
         * - an OMITTED sale_price means "no information about sale state".
         *
         * Therefore omission alone must NEVER clear an existing sale.
         */
        } else {

            $syncspider_price_update = (
                $is_syncspider
                && $has_incoming_regular
            );

            $invalid_syncspider_sale = false;

            if (
                $syncspider_price_update
                && $has_incoming_sale
            ) {
                $regular_amount = (float) $incoming_regular;
                $sale_amount    = (float) $incoming_sale;

                if (
                    $regular_amount <= 0.0
                    || $sale_amount <= 0.0
                    || $sale_amount >= $regular_amount
                    || (
                        $minimum_sale_ratio > 0.0
                        && $sale_amount
                            < (
                                $regular_amount
                                * $minimum_sale_ratio
                            )
                    )
                ) {
                    $invalid_syncspider_sale = true;
                }
            }

            /*
             * Explicit sale-state clearing.
             *
             * Only an explicitly supplied empty/null sale_price can prove
             * SALE OFF in the unsupported-platform fallback.
             *
             * Critically:
             *
             *     omitted sale_price != no sale
             *
             * This prevents partial SyncSpider updates from accidentally
             * removing a legitimate existing sale.
             */
            $explicit_empty_sale = (
                $is_syncspider
                && $has_sale_param
                && null === $incoming_sale
                && self::is_explicit_empty_param(
                    $request,
                    'sale_price'
                )
            );

            /*
             * A populated but commercially invalid sale is not authoritative
             * evidence of SALE OFF.
             *
             * Fail closed and preserve the existing stored sale baseline.
             */
            $clear_syncspider_sale = (
                $syncspider_price_update
                && $explicit_empty_sale
            );

            $orig_regular = self::get_vendor_price_baseline_or_request(
                $product,
                $request,
                'regular_price',
                self::META_VENDOR_REGULAR
            );

            if ( $clear_syncspider_sale ) {

                $orig_sale             = null;
                $authoritative_no_sale = true;

                self::delete_meta_if_exists(
                    $product,
                    self::META_VENDOR_SALE
                );

            } elseif (
                $has_incoming_sale
                && ! $invalid_syncspider_sale
            ) {

                $orig_sale = $incoming_sale;

            } else {

                /*
                 * sale_price omitted OR populated but unsafe:
                 *
                 * preserve the known vendor baseline rather than inferring
                 * a new sale state from incomplete/unsafe destination input.
                 */
                $stored_sale = (string) $product->get_meta(
                    self::META_VENDOR_SALE,
                    true
                );

                if (
                    '' !== $stored_sale
                    && is_numeric( $stored_sale )
                    && (float) $stored_sale > 0.0
                ) {
                    $orig_sale = $stored_sale;
                }
            }

            if ( null !== $orig_regular ) {
                self::update_meta_if_changed(
                    $product,
                    self::META_VENDOR_REGULAR,
                    (string) $orig_regular
                );
            }

            if ( null !== $orig_sale ) {
                self::update_meta_if_changed(
                    $product,
                    self::META_VENDOR_SALE,
                    (string) $orig_sale
                );
            } elseif ( $clear_syncspider_sale ) {
                self::delete_meta_if_exists(
                    $product,
                    self::META_VENDOR_SALE
                );
            }
        }

        /*
         * No usable regular source price means there is nothing safe to do.
         */
        if (
            null === $orig_regular
            || ! is_numeric( $orig_regular )
            || (float) $orig_regular <= 0.0
        ) {
            return;
        }

        /*
         * ---------------------------------------------------------
         * GBP VENDOR
         * ---------------------------------------------------------
         */
        if ( $vendor_currency === $base_currency ) {

            $regular_price = (string) $orig_regular;

            $product->set_regular_price(
                $regular_price
            );

            self::update_meta_if_changed(
                $product,
                '_fxd_orig_regular_price',
                $regular_price
            );

            if (
                null !== $orig_sale
                && is_numeric( $orig_sale )
                && (float) $orig_sale > 0.0
            ) {
                $sale_price = (string) $orig_sale;

                if (
                    (float) $sale_price
                        < (float) $regular_price
                    && (
                        $minimum_sale_ratio <= 0.0
                        || (float) $sale_price
                            >= (
                                (float) $regular_price
                                * $minimum_sale_ratio
                            )
                    )
                ) {
                    $product->set_sale_price(
                        $sale_price
                    );

                    self::update_meta_if_changed(
                        $product,
                        '_fxd_orig_sale_price',
                        $sale_price
                    );
                } elseif ( $has_authoritative_source ) {
                    /*
                     * Only authoritative source data may turn an invalid sale
                     * into a confirmed no-sale state here.
                     */
                    $authoritative_no_sale = true;
                }
            }

            if (
                null === $orig_sale
                && $authoritative_no_sale
            ) {
                self::clear_sale_state(
                    $product
                );
            }

            $active_price = $product->get_sale_price()
                ?: $product->get_regular_price();

            if (
                '' !== $active_price
                && null !== $active_price
            ) {
                $product->set_price(
                    $active_price
                );
            }

            self::store_common_audit_meta_if_changed(
                $product,
                $vendor_currency,
                1.0
            );

            return;
        }

        /*
         * ---------------------------------------------------------
         * NON-GBP VENDOR
         * ---------------------------------------------------------
         */

        $rate = RateRepository::get_rate_to_base(
            $vendor_currency,
            $base_currency
        );

        if (
            null === $rate
            || $rate <= 0.0
        ) {
            return;
        }

        $gbp_regular = self::convert_to_gbp(
            (float) $orig_regular,
            $rate
        );

        if (
            ! is_numeric( $gbp_regular )
            || (float) $gbp_regular <= 0.0
        ) {
            return;
        }

        $product->set_regular_price(
            $gbp_regular
        );

        self::update_meta_if_changed(
            $product,
            '_fxd_orig_regular_price',
            (string) $orig_regular
        );

        $gbp_sale = null;

        if (
            null !== $orig_sale
            && is_numeric( $orig_sale )
            && (float) $orig_sale > 0.0
        ) {
            $gbp_sale = self::convert_to_gbp(
                (float) $orig_sale,
                $rate
            );
        }

        if ( null !== $gbp_sale ) {

            $regular_amount = (float) $gbp_regular;
            $sale_amount    = (float) $gbp_sale;

            $converted_sale_valid = (
                $regular_amount > 0.0
                && $sale_amount > 0.0
                && $sale_amount < $regular_amount
                && (
                    $minimum_sale_ratio <= 0.0
                    || $sale_amount
                        >= (
                            $regular_amount
                            * $minimum_sale_ratio
                        )
                )
            );

            if ( $converted_sale_valid ) {

                $product->set_sale_price(
                    $gbp_sale
                );

                self::update_meta_if_changed(
                    $product,
                    '_fxd_orig_sale_price',
                    (string) $orig_sale
                );

            } elseif ( $has_authoritative_source ) {

                /*
                 * Only authoritative source data may prove that an unsafe
                 * converted sale must be removed.
                 */
                $gbp_sale              = null;
                $authoritative_no_sale = true;

                self::delete_meta_if_exists(
                    $product,
                    self::META_VENDOR_SALE
                );
            }
        }

        if (
            null === $gbp_sale
            && $authoritative_no_sale
        ) {
            self::clear_sale_state(
                $product
            );
        }

        $active_price = $product->get_sale_price()
            ?: $product->get_regular_price();

        if (
            '' !== $active_price
            && null !== $active_price
        ) {
            $product->set_price(
                $active_price
            );
        }

        self::store_common_audit_meta_if_changed(
            $product,
            $vendor_currency,
            $rate
        );
    }

    /**
     * Convert an already-saved WooCommerce product to GBP.
     *
     * IMPORTANT:
     *
     * This MUST be idempotent and must NOT treat current Woo prices as vendor
     * currency if vendor baselines exist.
     *
     * @param WC_Product|WC_Product_Variation $product
     */
    public static function convert_existing_product(
        $product
    ): void {

        if (
            ! $product instanceof \WC_Product
            && ! $product instanceof \WC_Product_Variation
        ) {
            return;
        }

        $product_id = (int) $product->get_id();

        if ( $product_id <= 0 ) {
            return;
        }

        $post = get_post(
            $product_id
        );

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        /*
         * Variable parents are never directly converted.
         */
        if ( $product->is_type( 'variable' ) ) {
            wc_delete_product_transients(
                $product_id
            );

            \WC_Product_Variable::sync(
                $product_id
            );

            return;
        }

        $vendor_id = (int) $post->post_author;

        if (
            $vendor_id <= 0
            && ! empty( $post->post_parent )
        ) {
            $parent = get_post(
                (int) $post->post_parent
            );

            if (
                $parent instanceof \WP_Post
                && ! empty( $parent->post_author )
            ) {
                $vendor_id = (int) $parent->post_author;
            }
        }

        if ( $vendor_id <= 0 ) {
            return;
        }

        $base_currency = strtoupper(
            (string) apply_filters(
                'fractured_dexter_fx_base_currency',
                'GBP'
            )
        );

        $vendor_currency = strtoupper(
            (string) VendorCurrency::get_vendor_currency(
                $vendor_id
            )
        );

        if (
            '' === $base_currency
            || '' === $vendor_currency
        ) {
            return;
        }

        /*
         * Authoritative source JSON wins whenever available.
         */
        $source_state = SourcePriceResolver::resolve(
            $product
        );

        $orig_regular = null;
        $orig_sale    = null;

        $authoritative_no_sale = false;

        if ( ! $source_state->is_unknown() ) {

            $orig_regular = $source_state->regular_price();
            $orig_sale    = $source_state->sale_price();

            if (
                null === $orig_regular
                || ! is_numeric( $orig_regular )
                || (float) $orig_regular <= 0.0
            ) {
                return;
            }

            if ( $source_state->is_no_sale() ) {
                $orig_sale             = null;
                $authoritative_no_sale = true;
            }

            self::update_meta_if_changed(
                $product,
                self::META_VENDOR_REGULAR,
                (string) $orig_regular
            );

            if ( null !== $orig_sale ) {
                self::update_meta_if_changed(
                    $product,
                    self::META_VENDOR_SALE,
                    (string) $orig_sale
                );
            } else {
                self::delete_meta_if_exists(
                    $product,
                    self::META_VENDOR_SALE
                );
            }

        } else {

            /*
             * Recognised resolver + ambiguous source = fail closed.
             */
            if (
                ! in_array(
                    $source_state->source(),
                    [
                        '',
                        'source',
                        'registry',
                    ],
                    true
                )
            ) {
                return;
            }

            /*
             * Unsupported/no-snapshot compatibility path.
             *
             * Stored Dexter vendor baselines win over current Woo prices,
             * preserving idempotence.
             */
            $stored_regular = (string) $product->get_meta(
                self::META_VENDOR_REGULAR,
                true
            );

            $stored_sale = (string) $product->get_meta(
                self::META_VENDOR_SALE,
                true
            );

            if (
                '' !== $stored_regular
                && is_numeric( $stored_regular )
                && (float) $stored_regular > 0.0
            ) {
                $orig_regular = $stored_regular;
            }

            if (
                '' !== $stored_sale
                && is_numeric( $stored_sale )
                && (float) $stored_sale > 0.0
            ) {
                $orig_sale = $stored_sale;
            }

            /*
             * Legacy fallback for a genuinely never-audited product only.
             *
             * Once Dexter baselines/audit exist, current Woo GBP prices must
             * never be reinterpreted as vendor currency.
             */
            if ( null === $orig_regular ) {

                $has_dexter_audit = (
                    '' !== (string) $product->get_meta(
                        '_fxd_orig_currency',
                        true
                    )
                    || '' !== (string) $product->get_meta(
                        '_fxd_fx_converted_at',
                        true
                    )
                );

                if ( $has_dexter_audit ) {
                    return;
                }

                $current_regular = $product->get_regular_price();

                if (
                    '' === $current_regular
                    || null === $current_regular
                    || ! is_numeric( $current_regular )
                    || (float) $current_regular <= 0.0
                ) {
                    return;
                }

                $orig_regular = (string) $current_regular;

                $current_sale = $product->get_sale_price();

                if (
                    '' !== $current_sale
                    && null !== $current_sale
                    && is_numeric( $current_sale )
                    && (float) $current_sale > 0.0
                ) {
                    $orig_sale = (string) $current_sale;
                }

                self::update_meta_if_changed(
                    $product,
                    self::META_VENDOR_REGULAR,
                    $orig_regular
                );

                if ( null !== $orig_sale ) {
                    self::update_meta_if_changed(
                        $product,
                        self::META_VENDOR_SALE,
                        $orig_sale
                    );
                }
            }
        }

        if (
            null === $orig_regular
            || ! is_numeric( $orig_regular )
            || (float) $orig_regular <= 0.0
        ) {
            return;
        }

        $minimum_sale_ratio = (float) apply_filters(
            'fractured_dexter_min_sale_ratio',
            0.10,
            $vendor_id,
            $product
        );

        if ( $minimum_sale_ratio < 0.0 ) {
            $minimum_sale_ratio = 0.0;
        }

        /*
         * Validate source/baseline sale one final time.
         *
         * If this came from an authoritative resolver, an unsafe sale can be
         * removed safely. If it came only from a legacy stored baseline, fail
         * closed rather than inferring SALE OFF.
         */
        if ( null !== $orig_sale ) {

            if (
                ! is_numeric( $orig_sale )
                || (float) $orig_sale <= 0.0
                || (float) $orig_sale >= (float) $orig_regular
                || (
                    $minimum_sale_ratio > 0.0
                    && (float) $orig_sale
                        < (
                            (float) $orig_regular
                            * $minimum_sale_ratio
                        )
                )
            ) {
                if ( ! $source_state->is_unknown() ) {
                    $orig_sale             = null;
                    $authoritative_no_sale = true;

                    self::delete_meta_if_exists(
                        $product,
                        self::META_VENDOR_SALE
                    );
                } else {
                    return;
                }
            }
        }

        $changed = false;

        /*
         * GBP vendor.
         */
        if ( $vendor_currency === $base_currency ) {

            if (
                (string) $product->get_regular_price()
                !== (string) $orig_regular
            ) {
                $product->set_regular_price(
                    (string) $orig_regular
                );

                $changed = true;
            }

            $changed = self::update_meta_if_changed(
                $product,
                '_fxd_orig_regular_price',
                (string) $orig_regular
            ) || $changed;

            if ( null !== $orig_sale ) {

                if (
                    (string) $product->get_sale_price()
                    !== (string) $orig_sale
                ) {
                    $product->set_sale_price(
                        (string) $orig_sale
                    );

                    $changed = true;
                }

                $changed = self::update_meta_if_changed(
                    $product,
                    '_fxd_orig_sale_price',
                    (string) $orig_sale
                ) || $changed;

            } elseif ( $authoritative_no_sale ) {

                $changed = self::clear_sale_state(
                    $product
                ) || $changed;
            }

            $active_price = $product->get_sale_price()
                ?: $product->get_regular_price();

            if (
                '' !== $active_price
                && null !== $active_price
                && (string) $product->get_price()
                    !== (string) $active_price
            ) {
                $product->set_price(
                    $active_price
                );

                $changed = true;
            }

            $changed = self::store_common_audit_meta_if_changed(
                $product,
                $vendor_currency,
                1.0,
                $changed
            ) || $changed;

            if ( $changed ) {
                $product->save();
            }

            return;
        }

        /*
         * Non-GBP vendor.
         */
        $rate = RateRepository::get_rate_to_base(
            $vendor_currency,
            $base_currency
        );

        if (
            null === $rate
            || $rate <= 0.0
        ) {
            return;
        }

        $gbp_regular = self::convert_to_gbp(
            (float) $orig_regular,
            $rate
        );

        if (
            (string) $product->get_regular_price()
            !== (string) $gbp_regular
        ) {
            $product->set_regular_price(
                $gbp_regular
            );

            $changed = true;
        }

        $changed = self::update_meta_if_changed(
            $product,
            '_fxd_orig_regular_price',
            (string) $orig_regular
        ) || $changed;

        if ( null !== $orig_sale ) {

            $gbp_sale = self::convert_to_gbp(
                (float) $orig_sale,
                $rate
            );

            if (
                (float) $gbp_sale <= 0.0
                || (float) $gbp_sale >= (float) $gbp_regular
                || (
                    $minimum_sale_ratio > 0.0
                    && (float) $gbp_sale
                        < (
                            (float) $gbp_regular
                            * $minimum_sale_ratio
                        )
                )
            ) {
                if ( ! $source_state->is_unknown() ) {
                    $orig_sale             = null;
                    $authoritative_no_sale = true;

                    self::delete_meta_if_exists(
                        $product,
                        self::META_VENDOR_SALE
                    );
                } else {
                    return;
                }

            } else {

                if (
                    (string) $product->get_sale_price()
                    !== (string) $gbp_sale
                ) {
                    $product->set_sale_price(
                        $gbp_sale
                    );

                    $changed = true;
                }

                $changed = self::update_meta_if_changed(
                    $product,
                    '_fxd_orig_sale_price',
                    (string) $orig_sale
                ) || $changed;
            }
        }

        if (
            null === $orig_sale
            && $authoritative_no_sale
        ) {
            $changed = self::clear_sale_state(
                $product
            ) || $changed;
        }

        $active_price = $product->get_sale_price()
            ?: $product->get_regular_price();

        if (
            '' !== $active_price
            && null !== $active_price
            && (string) $product->get_price()
                !== (string) $active_price
        ) {
            $product->set_price(
                $active_price
            );

            $changed = true;
        }

        $changed = self::store_common_audit_meta_if_changed(
            $product,
            $vendor_currency,
            $rate,
            $changed
        ) || $changed;

        if ( $changed ) {
            $product->save();
        }
    }

    /**
     * Prefer an incoming numeric REST value; otherwise use the stored
     * vendor-currency baseline.
     */
    private static function get_vendor_price_baseline_or_request(
        $product,
        WP_REST_Request $request,
        string $request_key,
        string $baseline_meta_key
    ): ?string {

        $incoming = self::get_numeric_param(
            $request,
            $request_key
        );

        if ( null !== $incoming ) {
            return $incoming;
        }

        $baseline = (string) $product->get_meta(
            $baseline_meta_key,
            true
        );

        if (
            '' !== $baseline
            && is_numeric( $baseline )
            && (float) $baseline > 0.0
        ) {
            return $baseline;
        }

        return null;
    }

    /**
     * Resolve the vendor (user) ID from the REST request and/or product.
     *
     * @return int|null
     */
    private static function resolve_vendor_id(
        $product,
        WP_REST_Request $request
    ): ?int {

        $param_keys = [
            'author',
            'dokan_vendor_id',
            'dokan_vendor',
            'vendor_id',
            'seller_id',
        ];

        foreach ( $param_keys as $key ) {

            $value = $request->get_param(
                $key
            );

            if (
                null !== $value
                && '' !== $value
                && is_numeric( $value )
            ) {
                $id = (int) $value;

                if ( $id > 0 ) {
                    return $id;
                }
            }
        }

        $product_id = (int) $product->get_id();

        if ( $product_id > 0 ) {

            $post = get_post(
                $product_id
            );

            if (
                $post
                && ! empty( $post->post_author )
            ) {
                $author_id = (int) $post->post_author;

                if ( $author_id > 0 ) {
                    return $author_id;
                }
            }

            /*
             * Variations normally inherit vendor ownership from the parent.
             */
            if (
                $post instanceof \WP_Post
                && 'product_variation' === $post->post_type
                && (int) $post->post_parent > 0
            ) {
                $parent = get_post(
                    (int) $post->post_parent
                );

                if (
                    $parent instanceof \WP_Post
                    && (int) $parent->post_author > 0
                ) {
                    return (int) $parent->post_author;
                }
            }
        }

        return null;
    }

    private static function convert_to_gbp(
        float $amount,
        float $rate
    ): string {

        $gbp = ( $rate > 0.0 )
            ? ( $amount / $rate )
            : $amount;

        return number_format(
            $gbp,
            wc_get_price_decimals(),
            '.',
            ''
        );
    }

    private static function get_numeric_param(
        WP_REST_Request $request,
        string $key
    ): ?string {

        if ( ! $request->has_param( $key ) ) {
            return null;
        }

        $value = $request->get_param(
            $key
        );

        if (
            null === $value
            || '' === trim( (string) $value )
        ) {
            return null;
        }

        if (
            is_array( $value )
            || is_object( $value )
            || is_bool( $value )
            || ! is_numeric( $value )
        ) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Whether a REST parameter was explicitly supplied as empty/null.
     *
     * This is intentionally different from an omitted parameter.
     */
    private static function is_explicit_empty_param(
        WP_REST_Request $request,
        string $key
    ): bool {

        if ( ! $request->has_param( $key ) ) {
            return false;
        }

        $value = $request->get_param(
            $key
        );

        if ( null === $value ) {
            return true;
        }

        if (
            is_array( $value )
            || is_object( $value )
            || is_bool( $value )
        ) {
            return false;
        }

        return '' === trim(
            (string) $value
        );
    }

    /**
     * Update meta only if different.
     */
    private static function update_meta_if_changed(
        $product,
        string $key,
        string $value
    ): bool {

        $current = (string) $product->get_meta(
            $key,
            true
        );

        if ( $current === (string) $value ) {
            return false;
        }

        $product->update_meta_data(
            $key,
            (string) $value
        );

        return true;
    }

    /**
     * Delete meta only if it currently has a meaningful value.
     */
    private static function delete_meta_if_exists(
        $product,
        string $key
    ): bool {

        $current = $product->get_meta(
            $key,
            true
        );

        if (
            null === $current
            || '' === (string) $current
        ) {
            return false;
        }

        $product->delete_meta_data(
            $key
        );

        return true;
    }

    /**
     * Store common audit metadata.
     *
     * converted_at is updated only when this conversion actually changed
     * pricing or another audit value. This prevents every JSON snapshot write
     * from forcing an otherwise unnecessary product save.
     *
     * @param bool $pricing_changed Whether the caller already changed a
     *                              meaningful price/meta value.
     */
    private static function store_common_audit_meta_if_changed(
        $product,
        string $currency,
        float $rate,
        bool $pricing_changed = true
    ): bool {

        $changed = false;

        $currency_changed = self::update_meta_if_changed(
            $product,
            '_fxd_orig_currency',
            strtoupper( $currency )
        );

        $changed = $currency_changed || $changed;

        $current_rate = $product->get_meta(
            '_fxd_fx_rate_used',
            true
        );

        $rate_changed = (
            (string) $current_rate
            !== (string) $rate
        );

        if ( $rate_changed ) {
            $product->update_meta_data(
                '_fxd_fx_rate_used',
                $rate
            );

            $changed = true;
        }

        /*
         * Timestamp only a meaningful conversion change.
         */
        if (
            $pricing_changed
            || $currency_changed
            || $rate_changed
        ) {
            $product->update_meta_data(
                '_fxd_fx_converted_at',
                current_time( 'mysql', true )
            );

            $changed = true;
        }

        return $changed;
    }

    /**
     * Determine whether the current REST request is SyncSpider-originated.
     */
    private static function is_syncspider_request(
        $product,
        WP_REST_Request $request
    ): bool {

        if (
            'syncspider'
            === strtolower(
                trim(
                    (string) $product->get_meta(
                        '_fxd_import_source',
                        true
                    )
                )
            )
        ) {
            return true;
        }

        $direct = $request->get_param(
            '_fxd_import_source'
        );

        if (
            'syncspider'
            === strtolower(
                trim(
                    (string) $direct
                )
            )
        ) {
            return true;
        }

        $meta_data = $request->get_param(
            'meta_data'
        );

        if ( ! is_array( $meta_data ) ) {
            return false;
        }

        foreach ( $meta_data as $row ) {

            if ( ! is_array( $row ) ) {
                continue;
            }

            $key = isset( $row['key'] )
                ? (string) $row['key']
                : '';

            $value = isset( $row['value'] )
                ? (string) $row['value']
                : '';

            if (
                '_fxd_import_source' === $key
                && 'syncspider'
                    === strtolower(
                        trim( $value )
                    )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Completely clear WooCommerce and Dexter sale state.
     *
     * @return bool Whether anything changed.
     */
    private static function clear_sale_state(
        $product
    ): bool {

        $changed = false;

        if ( '' !== (string) $product->get_sale_price() ) {
            $product->set_sale_price( '' );
            $changed = true;
        }

        if ( null !== $product->get_date_on_sale_from() ) {
            $product->set_date_on_sale_from( null );
            $changed = true;
        }

        if ( null !== $product->get_date_on_sale_to() ) {
            $product->set_date_on_sale_to( null );
            $changed = true;
        }

        $changed = self::delete_meta_if_exists(
            $product,
            self::META_VENDOR_SALE
        ) || $changed;

        $changed = self::delete_meta_if_exists(
            $product,
            '_fxd_orig_sale_price'
        ) || $changed;

        return $changed;
    }
}