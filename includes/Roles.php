<?php

namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Roles – manages custom WP user roles for wholesale pricing.
 * Creates the default "Wholesale Customer" role and syncs with the cewp_roles table.
 */
class Roles
{

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'sync_roles_to_wp']);
        foreach ( ['add_user_role','remove_user_role','set_user_role'] as $tag ) add_action($tag, [__CLASS__, 'invalidate']);
        add_action('added_option', [__CLASS__, 'role_option_changed']);
        add_action('updated_option', [__CLASS__, 'role_option_changed']);
        add_action('deleted_option', [__CLASS__, 'role_option_changed']);
        add_filter('woocommerce_coupon_is_valid', [__CLASS__, 'disallow_coupons_for_wholesale'], 10, 3);
    }

    /** Disallow coupons for users with wholesale roles (per setting). */
    public static function disallow_coupons_for_wholesale(bool $valid, $coupon, $discounts): bool
    {
        if (! $valid) return $valid;
        $settings = get_option('cewp_settings', []);
        if (($settings['disallow_coupons'] ?? true) && self::is_dealer()) return false;
        return $valid;
    }

    public static function create_default_roles(): void
    {
        // Create the default Wholesale Customer role if it does not exist
        if (! get_role('wholesale_customer')) {
            add_role(
                'wholesale_customer',
                __('Wholesale Customer', 'coderembassy-wholesale-pricing'),
                get_role('customer') ? get_role('customer')->capabilities : ['read' => true]
            );
        }

        // Preserve existing dealer records, including administrator edits.
        foreach (Database::get_roles() as $row) {
            if ($row->role_key === 'wholesale_customer') return;
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
                self::invalidate();
            }
        }
    }

    /** Return all dealer role keys currently managed by this plugin */
    public static function get_role_keys(): array
    {
        return array_column(Database::get_roles(), 'role_key');
    }

    /** Request-local context caches retain listener identity and membership changes. */
    private static array $contexts = [];

    public static function role_option_changed($option): void {
        global $wpdb;
        if ( $option === $wpdb->prefix . 'user_roles' ) self::invalidate();
    }

    public static function invalidate(): void {
        self::$contexts = [];
        Pricing::invalidate();
    }

    private static function context(int $user_id): array {
        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
        $uid = $user ? (int)$user->ID : 0;
        $roles = $user ? array_values(array_unique((array)$user->roles)) : [];
        sort($roles, SORT_STRING);
        $context = ['uid'=>$uid, 'roles'=>$roles, 'groups'=>[]];
        if ( $uid === get_current_user_id() ) $context = Pricing::ctx();
        return $context;
    }

    private static function selection(string $tag, int $user_id) {
        global $wp_filter;
        $context = self::context($user_id);
        $dealers = self::get_role_keys();
        $listeners = $wp_filter[$tag]->callbacks ?? [];
        $key = $tag . ':' . $context['uid'];
        $signature = [$context, $dealers, $listeners];
        if ( isset(self::$contexts[$key]) && self::$contexts[$key][0] === $signature ) return self::$contexts[$key][1];
        $role = null;
        // Preserve the existing user's role ordering for the default selection.
        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
        foreach ( (array)($user->roles ?? []) as $r ) {
            if ( in_array($r, $dealers, true) ) { $role = $r; break; }
        }
        $value = $tag === 'cewp_is_dealer' ? $role !== null : $role;
        $value = Pricing::filtered($tag, $value, [$context], $tag === 'cewp_is_dealer'
            ? static fn($v) => is_bool($v)
            : static fn($v) => $v === null || (is_string($v) && in_array($v, $dealers, true)));
        self::$contexts[$key] = [$signature, $value];
        return $value;
    }

    /** Check if the current (or given) user has any dealer role. */
    public static function is_dealer(int $user_id = 0): bool {
        return self::selection('cewp_is_dealer', $user_id);
    }

    /** Get the selected existing dealer role, or null. */
    public static function get_user_dealer_role(int $user_id = 0): ?string {
        return self::selection('cewp_dealer_role', $user_id);
    }
}
