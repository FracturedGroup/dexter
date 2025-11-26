<?php

namespace Fractured\Dexter\Cli;

use Fractured\Dexter\Fx\Updater;
use Fractured\Dexter\Fx\RateRepository;
use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP-CLI commands for Dexter.
 *
 * Usage:
 *   wp dexter fx:update
 *   wp dexter fx:list
 */
final class Commands extends WP_CLI_Command {

    /**
     * Register the Dexter CLI command namespace.
     */
    public static function register(): void {
        if ( ! class_exists( '\WP_CLI' ) ) {
            return;
        }

        WP_CLI::add_command( 'dexter', __CLASS__ );
    }

    /**
     * Update FX rates now (same as clicking "Refresh FX Rates Now" in admin).
     *
     * ## EXAMPLES
     *
     *     wp dexter fx:update
     *
     * @subcommand fx:update
     */
    public function fx_update( array $args, array $assoc_args ): void {
        WP_CLI::log( 'Running Dexter FX updater...' );

        Updater::run();

        WP_CLI::success( 'Dexter FX rates updated.' );
    }

    /**
     * List current FX rates stored in Dexter.
     *
     * ## EXAMPLES
     *
     *     wp dexter fx:list
     *
     * @subcommand fx:list
     */
    public function fx_list( array $args, array $assoc_args ): void {
        $rates = RateRepository::get_all_rates();

        if ( empty( $rates ) ) {
            WP_CLI::warning( 'No FX rates found in Dexter.' );
            return;
        }

        $items = [];

        foreach ( $rates as $rate ) {
            $items[] = [
                'base_currency' => $rate['base_currency'],
                'currency'      => $rate['currency'],
                'rate'          => $rate['rate'],
                'last_updated'  => $rate['last_updated'],
                'source'        => $rate['source'] ?? '',
            ];
        }

        WP_CLI::table( $items, [ 'base_currency', 'currency', 'rate', 'last_updated', 'source' ] );
    }
}