<?php

/**
 * Plugin Name:       CoderEmbassy Wholesale Pricing for WooCommerce
 * Plugin URI:         https://github.com/salehST/coderEmbassy-wholesale-pricing-for-wooCommerce 
 * Description:       A powerful wholesale pricing plugin for WooCommerce, allowing role-based pricing and discounts.
 * Version:           1.0.0
 * Author:            codersaleh
 * Author URI:        https://coderembassy.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coderembassy-wholesale-pricing
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      6.9
 * WC requires at least: 7.0
 * WC tested up to:   10.2 
 * Requires Plugins: woocommerce
 * 
 */

if (! defined('ABSPATH')) exit;

// ── Constants ────────────────────────────────────────────────────────────────
define('CEWP_VERSION',  '1.0.0');
define('CEWP_FILE',     __FILE__);
define('CEWP_DIR',      plugin_dir_path(__FILE__));
define('CEWP_URL',      plugin_dir_url(__FILE__));
define('CEWP_BASENAME', plugin_basename(__FILE__));
// Plugin is fully functional; no tier or rule limits.
if (! function_exists('cewp_is_pro')) {
    function cewp_is_pro(): bool
    {
        return false;
    }
}

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register(function (string $class) {
    $prefix = 'CEWP\\';
    if (strpos($class, $prefix) !== 0) return;
    $path = str_replace([$prefix, '\\'], [CEWP_DIR . 'includes/', '/'], $class) . '.php';
    if (file_exists($path)) require_once $path;
});

// ── HPOS compatibility ───────────────────────────────────────────────────────
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', CEWP_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', CEWP_FILE, true);
    }
});

// ── Boot ─────────────────────────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', fn() => printf(
            '<div class="notice notice-error"><p><strong>CoderEmbassy Wholesale Pricing for WooCommerce</strong> requires WooCommerce.</p></div>'
        ));
        return;
    }

    \CEWP\Database::migrate_dps_to_cewp();
    \CEWP\Database::install_if_needed();
    \CEWP\Roles::init();
    \CEWP\Pricing::init();
    \CEWP\MinOrder::init();
    \CEWP\Visibility::init();
    \CEWP\Admin\Menu::init();
    \CEWP\Admin\ProductMeta::init();
    \CEWP\Admin\OrderColumn::init();
    \CEWP\REST\Controller::init();
    \CEWP\Import::init();
});

// ── Activation / Deactivation / Uninstall ────────────────────────────────────
register_activation_hook(CEWP_FILE, ['\CEWP\Database', 'install']);
register_activation_hook(CEWP_FILE, ['\CEWP\Roles',    'create_default_roles']);
register_deactivation_hook(CEWP_FILE, ['\CEWP\Roles',  'flush_rewrite']);
register_uninstall_hook(CEWP_FILE,    ['\CEWP\Database', 'uninstall']);
