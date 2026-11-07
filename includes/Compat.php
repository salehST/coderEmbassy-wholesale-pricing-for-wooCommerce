<?php

namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects an old standalone pre-split Pro tier (2.x and earlier) still active without the
 * Free/Pro 3.0+ split architecture. Those old trees run their own full pricing engine
 * directly against the same wp_cewp_prices/wp_cewp_roles tables and options this plugin
 * uses, so letting both engines register price filters at once produces unpredictable,
 * rule-type-dependent price divergence rather than a clean pass-through.
 *
 * Pro 3.0+ reuses the same plugin slug as old standalone Pro, so slug activity alone cannot
 * distinguish them; CEWPA_VERSION is defined unconditionally at the top of either plugin's
 * main file (before plugins_loaded fires for anyone), so a version check on that constant
 * does, with no dependency on old Pro's source.
 */
class Compat {

    /** Historical standalone-tier plugin basenames that predate the Free/Pro split (none define CEWPA_VERSION). */
    private const LEGACY_FAMILY = [
        'dealer-pricing-starter/dealer-pricing-starter.php',
        'dealer-pricing-business/dealer-pricing-business.php',
        'dealer-pricing-agency/dealer-pricing-agency.php',
        'coderembassy-wholesale-pricing-business/coderembassy-wholesale-pricing-business.php',
    ];

    private static ?bool $legacy_active = null;

    public static function legacy_pro_active(): bool {
        if ( self::$legacy_active !== null ) return self::$legacy_active;

        if ( defined( 'CEWPA_VERSION' ) ) {
            return self::$legacy_active = version_compare( CEWPA_VERSION, '3.0.0', '<' );
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach ( self::LEGACY_FAMILY as $plugin ) {
            if ( is_plugin_active( $plugin ) ) return self::$legacy_active = true;
        }
        return self::$legacy_active = false;
    }

    public static function notice(): void {
        add_action( 'admin_notices', static function (): void {
            if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) return;
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'CoderEmbassy Wholesale Pricing for WooCommerce detected an older standalone CoderEmbassy wholesale pricing plugin (Pro 2.x or earlier) still active. Its pricing engine and this plugin\'s would otherwise both try to price the same products, so this plugin is pausing its own pricing, roles and import features until the conflict is resolved. To fix: deactivate the older plugin (then reactivate this one), or follow the documented upgrade path - deactivate the old plugin, keep this plugin active, then update/activate the new Pro add-on.',
                    'coderembassy-wholesale-pricing'
                )
            );
        } );
    }
}
