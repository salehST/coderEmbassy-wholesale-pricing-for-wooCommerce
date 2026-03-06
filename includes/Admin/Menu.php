<?php

namespace CEWP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Menu
{

    public static function init(): void
    {
        add_action('admin_menu',            [__CLASS__, 'register']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_cewp_save_settings', [__CLASS__, 'save_settings']);
    }

    public static function register(): void
    {
        add_menu_page(
            'CoderEmbassy Wholesale Pricing',
            'CoderEmbassy Wholesale Pricing',
            'manage_woocommerce',
            'coderembassy-wholesale-pricing',
            [__CLASS__, 'render'],
            'dashicons-tag',
            56
        );
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_coderembassy-wholesale-pricing') return;
        wp_enqueue_style('cewp-admin', CEWP_URL . 'assets/admin.css', [], CEWP_VERSION);
        wp_enqueue_script('cewp-admin', CEWP_URL . 'assets/admin.js',  [], CEWP_VERSION, true);
        wp_localize_script('cewp-admin', 'CEWP', [
            'rest_url'     => rest_url('cewp/v1/'),
            'nonce'        => wp_create_nonce('wp_rest'),
            'admin_url'    => admin_url('admin-post.php'),
            'form_nonce'   => wp_create_nonce('cewp_import_csv'),
            'currency'     => get_woocommerce_currency_symbol(),
            'current_user' => ['display_name' => wp_get_current_user()->display_name],
            'logo_light'   => CEWP_URL . 'assets/logo-light.png',
            'logo_dark'    => CEWP_URL . 'assets/logo-dark.png',
            'version'     => CEWP_VERSION,
        ]);
    }

    public static function render(): void
    {
        echo '<div id="cewp-root"></div>';
    }

    public static function save_settings(): void
    {
        check_admin_referer('cewp_save_settings');
        if (! current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        $allowed = ['show_retail_to_wholesale', 'hide_retail_from_wholesale', 'disallow_coupons', 'catalog_mode', 'delete_on_uninstall'];
        $data = [];
        foreach ($allowed as $key) {
            $data[$key] = sanitize_text_field(wp_unslash($_POST[$key] ?? '0'));
        }
        \CEWP\Database::save_settings($data);
        wp_safe_redirect(admin_url('admin.php?page=coderembassy-wholesale-pricing&tab=settings&saved=1'));
        exit;
    }
}
