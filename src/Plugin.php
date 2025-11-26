<?php

namespace Fractured\Dexter;

use Fractured\Dexter\Admin\Menu as AdminMenu;
use Fractured\Dexter\Fx\Updater as FxUpdater;
use Fractured\Dexter\Vendor\Currency as VendorCurrency;
use Fractured\Dexter\Rest\Hooks as RestHooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    /**
     * Bootstrap Dexter’s subsystems.
     */
    public static function init(): void {
        // Core hooks.
        add_action( 'init', [ __CLASS__, 'on_init' ] );

        // FX updater (cron + manual).
        FxUpdater::init();

        // Vendor currency.
        VendorCurrency::init();

        // REST conversion layer.
        RestHooks::init();

        // Admin UI.
        if ( is_admin() ) {
            AdminMenu::init();
        }
    }

    /**
     * Runs on the WordPress 'init' hook.
     */
    public static function on_init(): void {
        // Placeholder for any future core init.
    }
}