<?php

namespace Fractured\Dexter\Fx;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles fetching and storing FX rates for Dexter.
 *
 * - Schedules a WP-Cron event.
 * - Provides a manual "Refresh now" action.
 * - Fetches rates from a remote FX API and stores them in the Dexter FX table.
 */
final class Updater {

    /**
     * Cron hook name for Dexter FX updates.
     */
    public const CRON_HOOK = 'fractured_dexter_fx_update';

    /**
     * Register hooks for FX updates.
     */
    public static function init(): void {
        // Schedule cron event if not already scheduled.
        add_action( 'wp', [ __CLASS__, 'schedule_cron' ] );

        // Cron callback.
        add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );

        // Manual admin action: Refresh rates now.
        add_action( 'admin_post_dexter_fx_refresh', [ __CLASS__, 'handle_manual_refresh' ] );
    }

    /**
     * Schedule a daily cron event if it doesn't exist.
     */
    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Once per day; we can adjust later if needed.
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Handle the manual "Refresh FX rates" admin action.
     */
    public static function handle_manual_refresh(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to refresh FX rates.', 'fractured-dexter' ) );
        }

        check_admin_referer( 'dexter_fx_refresh' );

        self::run();

        // Redirect back to Dexter FX admin page with a status flag.
        wp_safe_redirect(
            add_query_arg(
                'dexter_fx_refreshed',
                '1',
                admin_url( 'admin.php?page=dexter-fx' )
            )
        );
        exit;
    }

    /**
     * Run the FX update job:
     * - Fetch rates from remote API.
     * - Store/update them in Dexter’s FX table.
     */
    public static function run(): void {
        $base_currency = apply_filters( 'fractured_dexter_fx_base_currency', 'GBP' );

        // Currencies we actually care about.
        $target_currencies = apply_filters(
            'fractured_dexter_fx_target_currencies',
            [ 'GBP', 'EUR', 'CAD', 'AED', 'INR' ]
        );

        // Normalise and remove base from targets.
        $target_currencies = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $c ) => strtoupper( trim( (string) $c ) ),
                        $target_currencies
                    )
                )
            )
        );

        if ( empty( $target_currencies ) ) {
            return;
        }

        // If base is in the list, we don't need to fetch it as a "target".
        $symbols = array_diff( $target_currencies, [ $base_currency ] );

        if ( empty( $symbols ) ) {
            return;
        }

        $response = self::fetch_rates_from_api( $base_currency, $symbols );

        if ( ! $response || ! isset( $response['rates'] ) || ! is_array( $response['rates'] ) ) {
            // TODO: add logging if needed.
            return;
        }

        $now = current_time( 'mysql', true );

        foreach ( $response['rates'] as $currency => $rate ) {
            $currency = strtoupper( (string) $currency );
            if ( ! is_numeric( $rate ) ) {
                continue;
            }

            RateRepository::upsert_rate(
                (string) $base_currency,
                $currency,
                (float) $rate,
                $now,
                $response['source'] ?? 'exchangerate.host'
            );
        }

        // Ensure base currency has a self-rate of 1.0 for convenience.
        RateRepository::upsert_rate(
            (string) $base_currency,
            (string) $base_currency,
            1.0,
            $now,
            $response['source'] ?? 'exchangerate.host'
        );
    }

    /**
     * Fetch rates from a remote FX API.
     *
     * Current implementation uses frankfurter.app (ECB-based, no API key).
     *
     * @param string   $base_currency
     * @param string[] $symbols
     *
     * @return array<string, mixed>|null
     */
    private static function fetch_rates_from_api( string $base_currency, array $symbols ): ?array {
        $base_currency = strtoupper( $base_currency );
        $symbols       = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $c ) => strtoupper( trim( (string) $c ) ),
                        $symbols
                    )
                )
            )
        );

        if ( empty( $symbols ) ) {
            return null;
        }

        // Frankfurter uses 'from' and 'to' instead of base/symbols.
        $endpoint = add_query_arg(
            [
                'from' => $base_currency,
                'to'   => implode( ',', $symbols ),
            ],
            'https://api.frankfurter.app/latest'
        );

        $response = wp_remote_get(
            $endpoint,
            [
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );

        // (Optional) keep this while testing; you can remove later.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "Dexter FX Updater URL: " . $endpoint );
            error_log( "Dexter FX Updater response: " . $body );
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || empty( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
            return null;
        }

        return [
            'base'   => $data['base'] ?? $base_currency,
            'rates'  => $data['rates'],
            'date'   => $data['date'] ?? null,
            'source' => 'frankfurter.app',
        ];
    }
}