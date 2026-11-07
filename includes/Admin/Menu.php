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

    /** Only authorized screen bootstraps may discover extension tabs. */
    public static function tabs(): array {
        if ( ! current_user_can('manage_woocommerce') ) return [];
        $core = [];
        foreach ( ['dashboard'=>'Dashboard','prices'=>'Price Rules','import'=>'CSV Import','settings'=>'Settings'] as $id => $label ) {
            $core[] = ['id'=>$id,'label'=>$label,'order'=>(count($core)+1)*10,'capability'=>'manage_woocommerce','script_handle'=>'cewp-admin'];
        }
        $tabs = \CEWP\Pricing::filtered('cewp_admin_tabs', $core, [\CEWP\Pricing::ctx()], static function (&$next) use ($core) {
            if ( ! is_array($next) ) return false;
            $valid = array_column($core, null, 'id');
            foreach ( $next as $tab ) {
                if ( ! is_array($tab) || ! isset($tab['id'],$tab['label'],$tab['order'],$tab['capability'],$tab['script_handle'])
                    || ! is_string($tab['id']) || ! preg_match('~^[a-z][a-z0-9_-]*/[a-z][a-z0-9_/-]*$~D', $tab['id'])
                    || isset($valid[$tab['id']]) || ! is_string($tab['label']) || trim($tab['label']) === ''
                    || ! is_int($tab['order']) || ! is_string($tab['capability']) || ! current_user_can($tab['capability'])
                    || ! is_string($tab['script_handle']) || ! preg_match('/^[a-z][a-z0-9_-]*$/D', $tab['script_handle']) ) continue;
                $entry = array_intersect_key($tab, array_flip(['id','label','order','capability','script_handle']));
                // Presentation metadata is optional. Extensions can organize their own
                // tabs without teaching Free about any particular paid feature.
                if ( isset($tab['group'], $tab['group_label']) && is_string($tab['group'])
                    && preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $tab['group'])
                    && is_string($tab['group_label']) && trim(wp_strip_all_tags($tab['group_label'])) !== ''
                    && strlen($tab['group_label']) <= 80 ) {
                    $entry['group'] = $tab['group'];
                    $entry['group_label'] = wp_strip_all_tags($tab['group_label']);
                    if ( isset($tab['group_order']) && is_int($tab['group_order'])
                        && $tab['group_order'] >= 0 && $tab['group_order'] <= 999 ) {
                        $entry['group_order'] = $tab['group_order'];
                    }
                }
                if ( isset($tab['icon']) && is_string($tab['icon'])
                    && in_array($tab['icon'], ['users','currency','upload','clipboard','license','layers','discount','cart','truck','payment','eye','settings'], true) ) {
                    $entry['icon'] = $tab['icon'];
                }
                $valid[$tab['id']] = $entry;
            }
            $next = array_values($valid);
            return true;
        });
        usort($tabs, static fn($a,$b) => ($a['order'] <=> $b['order']) ?: strcmp($a['id'],$b['id']));
        // Plain text is serialized and escaped by the browser at the HTML boundary.
        foreach ( $tabs as &$tab ) $tab['label'] = wp_strip_all_tags($tab['label']);
        unset($tab);
        return $tabs;
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_coderembassy-wholesale-pricing') return;
        wp_enqueue_style('cewp-admin', CEWP_URL . 'assets/admin.css', [], CEWP_VERSION);
        wp_enqueue_script('cewp-admin', CEWP_URL . 'assets/admin.js',  [], CEWP_VERSION, true);
        $tabs = self::tabs();
        foreach ( $tabs as $tab ) {
            if ( $tab['script_handle'] !== 'cewp-admin' ) wp_enqueue_script($tab['script_handle']);
        }
        wp_localize_script('cewp-admin', 'CEWP', [
            'tabs' => $tabs,
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
