<?php

namespace Fractured\Dexter\Admin;

use Fractured\Dexter\Fx\RateRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Dexter’s admin menu and main settings/status page.
 */
final class Menu {

    /**
     * Register admin hooks.
     */
    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
    }

    /**
     * Register the Dexter FX page in the WordPress admin menu.
     */
    public static function register_menu(): void {
        add_menu_page(
            __( 'Dexter FX Status', 'fractured-dexter' ),
            __( 'Dexter FX', 'fractured-dexter' ),
            'manage_options',
            'dexter-fx',
            [ __CLASS__, 'render_main_page' ],
            'dashicons-chart-line',
            56
        );
    }

    /**
     * Render the main Dexter admin page.
     */
    public static function render_main_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'fractured-dexter' ) );
        }

        $rates = RateRepository::get_all_rates();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Dexter FX Status', 'fractured-dexter' ); ?></h1>

            <div class="wrap">
            <h1><?php esc_html_e( 'Dexter FX Status', 'fractured-dexter' ); ?></h1>

            <?php if ( isset( $_GET['dexter_fx_refreshed'] ) && '1' === $_GET['dexter_fx_refreshed'] ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'FX rates refreshed successfully.', 'fractured-dexter' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 20px;">
                <?php wp_nonce_field( 'dexter_fx_refresh' ); ?>
                <input type="hidden" name="action" value="dexter_fx_refresh" />
                <?php submit_button( __( 'Refresh FX Rates Now', 'fractured-dexter' ), 'primary', 'submit', false ); ?>
            </form>

            <p>
                <?php esc_html_e( 'Dexter manages currency conversion for multi-currency vendors by storing FX rates and converting imported prices to GBP.', 'fractured-dexter' ); ?>
            </p>

            <h2><?php esc_html_e( 'Current FX Rates', 'fractured-dexter' ); ?></h2>

            <?php if ( empty( $rates ) ) : ?>
                <p>
                    <?php esc_html_e( 'No FX rates stored yet. Once the FX updater is implemented, rates will appear here.', 'fractured-dexter' ); ?>
                </p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Base Currency', 'fractured-dexter' ); ?></th>
                            <th><?php esc_html_e( 'Currency', 'fractured-dexter' ); ?></th>
                            <th><?php esc_html_e( 'Rate', 'fractured-dexter' ); ?></th>
                            <th><?php esc_html_e( 'Last Updated', 'fractured-dexter' ); ?></th>
                            <th><?php esc_html_e( 'Source', 'fractured-dexter' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rates as $rate ) : ?>
                            <tr>
                                <td><?php echo esc_html( $rate['base_currency'] ); ?></td>
                                <td><?php echo esc_html( $rate['currency'] ); ?></td>
                                <td><?php echo esc_html( $rate['rate'] ); ?></td>
                                <td><?php echo esc_html( $rate['last_updated'] ); ?></td>
                                <td><?php echo esc_html( $rate['source'] ?? '' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}