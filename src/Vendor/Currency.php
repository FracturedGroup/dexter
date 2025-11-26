<?php

namespace Fractured\Dexter\Vendor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages per-vendor currency settings.
 *
 * - Stores a vendor currency meta on the user (Dokan vendor).
 * - Provides helpers to get the vendor currency.
 * - Adds a dropdown on the user edit screen in WP Admin.
 */
final class Currency {

    /**
     * User meta key for vendor currency.
     */
    public const META_KEY = 'fxd_vendor_currency';

    /**
     * Bootstrap hooks.
     */
    public static function init(): void {
        // Show field on user profile screens (admin).
        add_action( 'show_user_profile', [ __CLASS__, 'render_vendor_currency_field' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'render_vendor_currency_field' ] );

        // Save from user profile screens.
        add_action( 'personal_options_update', [ __CLASS__, 'save_vendor_currency_field' ] );
        add_action( 'edit_user_profile_update', [ __CLASS__, 'save_vendor_currency_field' ] );
    }

    /**
     * Get the vendor currency for a given user ID.
     *
     * Defaults to GBP if not set.
     *
     * @param int $user_id
     *
     * @return string 3-letter ISO currency code.
     */
    public static function get_vendor_currency( int $user_id ): string {
        $currency = get_user_meta( $user_id, self::META_KEY, true );

        if ( ! is_string( $currency ) || '' === $currency ) {
            return 'GBP';
        }

        return strtoupper( trim( $currency ) );
    }

    /**
     * Render the vendor currency field on the user profile screen.
     *
     * @param \WP_User $user
     */
    public static function render_vendor_currency_field( $user ): void {
        if ( ! $user instanceof \WP_User ) {
            return;
        }

        // Only show for vendors (Dokan's default role is 'seller').
        $roles = (array) $user->roles;
        if ( ! in_array( 'seller', $roles, true ) ) {
            // If you ever change Dokan vendor role, adjust this.
            return;
        }

        // Only admins / store managers should see this field.
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current = self::get_vendor_currency( $user->ID );

        // Allowed currencies; extendable via filter.
        $currencies = apply_filters(
            'fractured_dexter_vendor_currencies',
            [
                'GBP' => __( 'GBP – Pound Sterling', 'fractured-dexter' ),
                'EUR' => __( 'EUR – Euro', 'fractured-dexter' ),
                'CAD' => __( 'CAD – Canadian Dollar', 'fractured-dexter' ),
                'AED' => __( 'AED – UAE Dirham', 'fractured-dexter' ),
                'INR' => __( 'INR – Indian Rupee', 'fractured-dexter' ),
            ]
        );
        ?>
        <h2><?php esc_html_e( 'Dexter – Vendor Currency', 'fractured-dexter' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th>
                    <label for="fxd_vendor_currency">
                        <?php esc_html_e( 'Vendor Currency', 'fractured-dexter' ); ?>
                    </label>
                </th>
                <td>
                    <select name="fxd_vendor_currency" id="fxd_vendor_currency">
                        <?php foreach ( $currencies as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Dexter will treat this vendor’s product prices as being in this currency and convert them to GBP on import.', 'fractured-dexter' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the vendor currency field from the user profile screen.
     *
     * @param int $user_id
     */
    public static function save_vendor_currency_field( int $user_id ): void {
        // Only allow admins/store managers to change vendor currency.
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['fxd_vendor_currency'] ) ) {
            return;
        }

        $raw = wp_unslash( (string) $_POST['fxd_vendor_currency'] );
        $raw = strtoupper( trim( $raw ) );

        // Allowed list (same filter as render).
        $allowed = apply_filters(
            'fractured_dexter_vendor_currencies',
            [
                'GBP' => __( 'GBP – Pound Sterling', 'fractured-dexter' ),
                'EUR' => __( 'EUR – Euro', 'fractured-dexter' ),
                'CAD' => __( 'CAD – Canadian Dollar', 'fractured-dexter' ),
                'AED' => __( 'AED – UAE Dirham', 'fractured-dexter' ),
                'INR' => __( 'INR – Indian Rupee', 'fractured-dexter' ),
            ]
        );

        $allowed_codes = array_keys( $allowed );

        if ( ! in_array( $raw, $allowed_codes, true ) ) {
            // Fallback to GBP if something unexpected comes through.
            $raw = 'GBP';
        }

        update_user_meta( $user_id, self::META_KEY, $raw );
    }
}