<?php
/**
 * Plugin Name: Dexter – FX Layer for Fractured
 * Plugin URI: https://fracturedstore.com
 * Description: Central currency conversion layer for Fractured’s multi-currency vendors. Converts imported prices to GBP while storing original prices and FX rates.
 * Version: 1.0.0
 * Author: Fractured
 * Text Domain: fractured-dexter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FRACTURED_DEXTER_VERSION', '0.1.0' );
define( 'FRACTURED_DEXTER_FILE', __FILE__ );
define( 'FRACTURED_DEXTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRACTURED_DEXTER_URL', plugin_dir_url( __FILE__ ) );

require FRACTURED_DEXTER_DIR . 'src/bootstrap.php';

// Register WP-CLI commands (only in CLI context).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \Fractured\Dexter\Cli\Commands::register();
}

// Activation: create/upgrade Dexter database structures.
register_activation_hook(
    FRACTURED_DEXTER_FILE,
    static function () {
        \Fractured\Dexter\Infrastructure\Activation::activate();
    }
);

// Bootstrap main plugin.
add_action(
    'plugins_loaded',
    static function () {
        \Fractured\Dexter\Plugin::init();
    }
);