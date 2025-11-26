<?php

namespace Fractured\Dexter\Infrastructure;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin activation and database setup for Dexter.
 */
final class Activation {

    /**
     * Runs on plugin activation.
     */
    public static function activate(): void {
        self::create_fx_rates_table();
    }

    /**
     * Create or update the FX rates table used by Dexter.
     */
    private static function create_fx_rates_table(): void {
        global $wpdb;

        /** @var wpdb $wpdb */
        $table_name      = $wpdb->prefix . 'fxd_fx_rates';
        $charset_collate = $wpdb->get_charset_collate();

        // Using dbDelta so schema changes can be applied safely over time.
        $sql = "
            CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                base_currency CHAR(3) NOT NULL DEFAULT 'GBP',
                currency CHAR(3) NOT NULL,
                rate DECIMAL(18,8) NOT NULL,
                last_updated DATETIME NOT NULL,
                source VARCHAR(50) DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY currency_unique (base_currency, currency)
            ) {$charset_collate};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}