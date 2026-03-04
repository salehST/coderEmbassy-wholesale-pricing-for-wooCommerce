<?php

namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Roles – manages custom WP user roles for dealer/wholesale tiers.
 * FREE:  Creates the default "Wholesale Customer" role.
 * PRO:   Supports unlimited custom roles created via admin UI.
 */
class Roles
{

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'sync_roles_to_wp']);
        add_filter('woocommerce_coupon_is_valid', [__CLASS__, 'disallow_coupons_for_wholesale'], 10, 3);
    }

    /** Starter: disallow coupons for wholesale users (per spec). */
    public static function disallow_coupons_for_wholesale(bool $valid, $coupon, $discounts): bool
    {
        if (! $valid) return $valid;
        if (self::is_dealer()) return false;
        return $valid;
    }

    public static function create_default_roles(): void
    {
        // Always create the base "Dealer / Wholesale Customer" role (FREE)
        if (! get_role('wholesale_customer')) {
            add_role(
                'wholesale_customer',
                __('Wholesale Customer', 'coderembassy-wholesale-pricing'),
                get_role('customer') ? get_role('customer')->capabilities : ['read' => true]
            );
        }

        // Record it in our table
        Database::save_role([
            'role_key'   => 'wholesale_customer',
            'label'      => 'Wholesale Customer',
            'is_default' => 1,
        ]);
    }

    public static function flush_rewrite(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Ensure all roles saved in cewp_roles exist as WP roles.
     * Called on every init so newly-created roles from the admin UI take effect.
     */
    public static function sync_roles_to_wp(): void
    {
        foreach (Database::get_roles() as $row) {
            if (! get_role($row->role_key)) {
                $customer_caps = get_role('customer') ? get_role('customer')->capabilities : ['read' => true];
                add_role($row->role_key, $row->label, $customer_caps);
            }
        }
    }

    /** Return all dealer role keys currently managed by this plugin */
    public static function get_role_keys(): array
    {
        return array_column(Database::get_roles(), 'role_key');
    }

    /** Check if the current (or given) user has any dealer role */
    public static function is_dealer(int $user_id = 0): bool
    {
        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
        if (! $user) return false;
        $dealer_roles = self::get_role_keys();
        return (bool) array_intersect((array)$user->roles, $dealer_roles);
    }

    /** Get first dealer role of user (or null) */
    public static function get_user_dealer_role(int $user_id = 0): ?string
    {
        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
        if (! $user) return null;
        $dealer_roles = self::get_role_keys();
        foreach ((array)$user->roles as $r) {
            if (in_array($r, $dealer_roles)) return $r;
        }
        return null;
    }
}
