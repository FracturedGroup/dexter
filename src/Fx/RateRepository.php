<?php

namespace Fractured\Dexter\Fx;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repository for reading and writing FX rates in Dexter's custom table.
 */
final class RateRepository {

    /**
     * Get the table name for FX rates.
     */
    public static function table_name(): string {
        global $wpdb;

        /** @var wpdb $wpdb */
        return $wpdb->prefix . 'fxd_fx_rates';
    }


    /**
     * Fetch a rate from a vendor currency to the base currency (GBP by default).
     *
     * Dexter stores rates as "units of CURRENCY per 1 unit of BASE currency".
     * With GBP as base, rate means: 1 GBP = rate * CURRENCY.
     *
     * To convert vendor currency -> GBP, Dexter divides: amount / rate.
     */
    public static function get_rate_to_base( string $currency, string $base_currency = 'GBP' ): ?float {
        global $wpdb;

        $currency      = strtoupper( trim( $currency ) );
        $base_currency = strtoupper( trim( $base_currency ) );

        if ( $currency === $base_currency ) {
            return 1.0;
        }

        /** @var wpdb $wpdb */
        $table = self::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT rate FROM {$table} WHERE base_currency = %s AND currency = %s LIMIT 1",
                $base_currency,
                $currency
            )
        );

        if ( ! $row ) {
            return null;
        }

        return (float) $row->rate;
    }


    /**
     * Fetch all stored FX rates (for admin display or diagnostics).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_all_rates(): array {
        global $wpdb;

        /** @var wpdb $wpdb */
        $table = self::table_name();

        $rows = $wpdb->get_results(
            "SELECT base_currency, currency, rate, last_updated, source FROM {$table} ORDER BY base_currency, currency",
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return $rows;
    }

    /**
     * Insert or update an FX rate row.
     *
     * @param string      $base_currency
     * @param string      $currency
     * @param float       $rate
     * @param string      $last_updated MySQL datetime (UTC).
     * @param string|null $source
     */
    public static function upsert_rate(
        string $base_currency,
        string $currency,
        float $rate,
        string $last_updated,
        ?string $source = null
    ): void {
        global $wpdb;

        /** @var wpdb $wpdb */
        $table         = self::table_name();
        $base_currency = strtoupper( trim( $base_currency ) );
        $currency      = strtoupper( trim( $currency ) );

        $data = [
            'base_currency' => $base_currency,
            'currency'      => $currency,
            'rate'          => $rate,
            'last_updated'  => $last_updated,
            'source'        => $source,
        ];

        $formats = [ '%s', '%s', '%f', '%s', '%s' ];

        // Try to update first – if no rows affected, insert.
        $updated = $wpdb->update(
            $table,
            [
                'rate'         => $rate,
                'last_updated' => $last_updated,
                'source'       => $source,
            ],
            [
                'base_currency' => $base_currency,
                'currency'      => $currency,
            ],
            [ '%f', '%s', '%s' ],
            [ '%s', '%s' ]
        );

        if ( false === $updated || 0 === $updated ) {
            $wpdb->insert( $table, $data, $formats );
        }
    }

}