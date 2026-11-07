<?php
namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Pricing – core WooCommerce price override engine.
 *
 * Per-product price rules (role scope), fixed / percent_discount / fixed_discount types,
 * wholesale sale price, min-qty price tiers.
 *
 * Resolution order: product-level role rule, then WooCommerce default.
 */
class Pricing {

    private static array $cache = [];
    private static ?\WeakMap $cart_quantities = null;

    public static function init(): void {
        add_filter( 'woocommerce_product_get_price',                   [ __CLASS__, 'price' ],           20, 2 );
        add_filter( 'woocommerce_product_get_regular_price',           [ __CLASS__, 'price' ],           20, 2 );
        add_filter( 'woocommerce_product_get_sale_price',              [ __CLASS__, 'price' ],           20, 2 );
        add_filter( 'woocommerce_product_variation_get_price',         [ __CLASS__, 'price_variation' ], 20, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', [ __CLASS__, 'price_variation' ], 20, 2 );
        add_filter( 'woocommerce_product_variation_get_sale_price',    [ __CLASS__, 'price_variation' ], 20, 2 );
        add_filter( 'woocommerce_variation_prices',                    [ __CLASS__, 'variation_prices' ],20, 3 );
        add_action( 'woocommerce_before_calculate_totals',             [ __CLASS__, 'cart_prices' ],     20, 1 );
        // Show retail price to wholesalers (with show/hide setting)
        add_filter( 'woocommerce_get_price_html',                      [ __CLASS__, 'price_html' ],      20, 2 );
    }

    // ── User context ──────────────────────────────────────────────────────────

    /** Validate each listener before passing its result to the next listener. */
    public static function filtered( string $tag, $value, array $args, callable $validate ) {
        $guard = static function ( $next ) use ( &$value, $validate ) {
            if ( $validate( $next, $value ) ) $value = $next;
            return $value;
        };
        global $wp_filter, $wp_current_filter;
        $hook = $wp_filter[$tag] ?? null;
        if ( ! $hook ) return $value;
        $clean = clone $hook;
        $stack = $wp_current_filter;
        $wrappers = [];
        static $identities = [];
        foreach ( $hook->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $id => $entry ) {
                $callback = $entry['function'];
                // Resolver cache keys include WP callback IDs. Keep identities alive
                // for this request so a removed closure's ID cannot be recycled.
                if ( is_object($callback) ) $identities[spl_object_id($callback)] = $callback;
                $wrapper = static function ( ...$received ) use ( $callback, $guard ) {
                    return $guard( call_user_func_array( $callback, $received ) );
                };
                $wrappers[$priority][$id] = [$wrapper, $callback];
                $hook->callbacks[$priority][$id]['function'] = $wrapper;
            }
        }
        $failed = true;
        try {
            $result = apply_filters( $tag, $value, ...$args );
            $failed = false;
            return $result;
        } finally {
            // Unwrap only surviving entries. Listener removals and replacements persist.
            foreach ( $wrappers as $priority => $callbacks ) {
                foreach ( $callbacks as $id => [$wrapper, $callback] ) {
                    if ( ($hook->callbacks[$priority][$id]['function'] ?? null) === $wrapper ) {
                        $hook->callbacks[$priority][$id]['function'] = $callback;
                    }
                }
            }
            if ( $failed ) {
                // WP_Hook does not unwind iteration state on exceptions. Rebuild from
                // its pre-dispatch state while preserving callback mutations.
                foreach ( $clean->callbacks as $priority => $callbacks ) {
                    foreach ( $callbacks as $entry ) $clean->remove_filter($tag, $entry['function'], $priority);
                }
                foreach ( $hook->callbacks as $priority => $callbacks ) {
                    foreach ( $callbacks as $entry ) $clean->add_filter($tag, $entry['function'], $priority, $entry['accepted_args']);
                }
                $wp_filter[$tag] = $clean;
                $wp_current_filter = $stack;
            }
        }
    }

    public static function ctx(): array {
        $uid = get_current_user_id();
        $roles = $uid ? array_values( array_unique( (array) wp_get_current_user()->roles ) ) : [];
        sort( $roles, SORT_STRING );
        $key = 'ctx:' . $uid . ':' . serialize( $roles );
        if ( isset( self::$cache[$key] ) ) return self::$cache[$key];
        $context = compact( 'uid', 'roles' ) + ['groups' => []];
        $next = self::filtered( 'cewp_user_context', $context, [$uid], static function ( &$c ) use ( $uid, $roles ) {
            if ( ! is_array( $c ) || ! isset( $c['groups'] ) || ! is_array( $c['groups'] ) ) return false;
            $c['uid'] = $uid;
            $c['roles'] = $roles;
            return true;
        } );
        $groups = array_values( array_unique( array_filter( $next['groups'], static fn( $g ) => is_int( $g ) && $g > 0 ) ) );
        sort( $groups, SORT_NUMERIC );
        return self::$cache[$key] = compact( 'uid', 'roles', 'groups' );
    }

    private static function listener_key( string $tag ): array {
        global $wp_filter;
        $keys = [];
        foreach ( $wp_filter[$tag]->callbacks ?? [] as $priority => $callbacks ) $keys[$priority] = array_keys( $callbacks );
        return $keys;
    }

    public static function invalidate(): void { self::$cache = []; }

    /** Numeric/date validation shared by candidates, fallback providers and writes. */
    public static function valid_rule( array $r ): bool {
        if ( ! in_array( $r['price_type'] ?? null, ['fixed','percent_discount','fixed_discount'], true ) ) return false;
        foreach ( ['price_value','sale_price'] as $field ) {
            $v = $r[$field] ?? null;
            if ( $field === 'sale_price' && ( $v === null || $v === '' ) ) continue;
            if ( ! is_numeric( $v ) || ! is_finite( (float)$v ) || (float)$v < 0 ) return false;
        }
        if ( $r['price_type'] === 'percent_discount' && (float)$r['price_value'] > 100 ) return false;
        $qty = $r['min_qty'] ?? 1;
        if ( ! is_numeric( $qty ) || (int)$qty < 1 || (float)$qty != (int)$qty ) return false;
        foreach ( ['date_from','date_to'] as $field ) {
            $v = $r[$field] ?? null;
            if ( $v === null || $v === '' ) continue;
            if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m ) || ! checkdate( (int)$m[2], (int)$m[3], (int)$m[1] ) ) return false;
        }
        return true;
    }

    private static function eligible( array $r, int $qty ): bool {
        $today = current_time( 'Y-m-d' );
        return self::valid_rule( $r ) && $qty >= (int)($r['min_qty'] ?? 1)
            && ( empty( $r['date_from'] ) || $r['date_from'] <= $today )
            && ( empty( $r['date_to'] ) || $r['date_to'] >= $today );
    }

    /** The resolver and public tier reader share candidate validation and ordering. */
    private static function matching_rules( array $stored_rules, float $base, int $product_id, int $variation_id, int $qty, array $ctx ): array {
        $rules = array_map( static fn( $r ) => (array)$r, $stored_rules );
        $rules = self::filtered( 'cewp_price_rules', $rules, [$base,$product_id,$variation_id,$qty,$ctx], static fn( $r ) => is_array( $r ) );
        $rules = array_filter( $rules, static function ( $r ) use ( $ctx,$product_id,$variation_id,$qty ) {
            if ( ! is_array( $r ) || ! self::eligible( $r, $qty ) || (int)($r['product_id'] ?? 0) !== $product_id || ! in_array( (int)($r['variation_id'] ?? 0), [0,$variation_id], true ) ) return false;
            if ( ! in_array( $r['scope_type'] ?? '', Scopes::allowed(), true ) ) return false;
            $value = $r['scope_value'] ?? '';
            return match( $r['scope_type'] ) {
                'user' => (string)$value === (string)$ctx['uid'],
                'group' => in_array( (string)$value, array_map( 'strval', $ctx['groups'] ), true ),
                'role' => in_array( $value, $ctx['roles'], true ),
                default => false,
            };
        } );
        $priority = ['user'=>1,'group'=>2,'role'=>3];
        usort( $rules, static fn( $a,$b ) => ($priority[$a['scope_type']] <=> $priority[$b['scope_type']]) ?: ((int)($b['variation_id'] ?? 0) <=> (int)($a['variation_id'] ?? 0)) ?: ((int)($b['min_qty'] ?? 1) <=> (int)($a['min_qty'] ?? 1)) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)) );
        return $rules;
    }

    /** Read in an isolated user/cache context; never mutate a catalog/cart product. */
    public static function dealer_price_tiers( int $product_id, int $variation_id = 0, int $user_id = 0 ): array {
        $product = $product_id > 0 ? wc_get_product( $product_id ) : false;
        if ( ! $product || $product->is_type('variation') || $variation_id < 0 || $user_id < 0 ) return [];
        $raw = $variation_id ? wc_get_product( $variation_id ) : $product;
        if ( ! $raw || ( $variation_id && ( ! $raw->is_type('variation') || $raw->get_parent_id() !== $product_id ) ) ) return [];
        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
        if ( ! $user || ! $user->ID ) return [];
        $previous_user = $GLOBALS['current_user'];
        $previous_cache = self::$cache;
        try {
            // Avoid wp_set_current_user actions and preserve the caller's exact user object.
            $GLOBALS['current_user'] = $user;
            self::$cache = [];
            if ( ! Roles::is_dealer() ) return [];
            $ctx = self::ctx();
            $stored = Database::get_matching_prices( $product_id, $variation_id, $ctx['uid'], $ctx['roles'], $ctx['groups'] );
            $base = (float)$raw->get_regular_price('edit');
            $candidates = self::matching_rules( $stored, $base, $product_id, $variation_id, PHP_INT_MAX, $ctx );
            $quantities = [1];
            foreach ( $candidates as $r ) $quantities[] = (int)($r['min_qty'] ?? 1);
            $quantities = array_unique($quantities);
            sort($quantities, SORT_NUMERIC);
            $result = [];
            foreach ( $quantities as $qty ) {
                $rules = self::matching_rules( $stored, $base, $product_id, $variation_id, $qty, $ctx );
                // Ignore thresholds belonging exclusively to a shadowed lower-priority rule.
                if ( $qty !== 1 && (int)($rules[0]['min_qty'] ?? 0) !== $qty ) continue;
                $price = self::resolve( 0, $product_id, $variation_id, $qty );
                if ( $price !== null ) $result[] = ['min_qty'=>$qty, 'price'=>$price];
            }
            if ( $result && $result[0]['min_qty'] !== 1 ) {
                array_unshift($result, ['min_qty'=>1, 'price'=>(float)$raw->get_price('edit')]);
            }
            return $result;
        } finally {
            $GLOBALS['current_user'] = $previous_user;
            self::$cache = $previous_cache;
        }
    }

    public static function resolve( float $base, int $product_id, int $variation_id = 0, int $qty = 1 ): ?float {
        $ctx = self::ctx();
        if ( ! $ctx['uid'] ) return null;
        $raw = wc_get_product( $variation_id ?: $product_id );
        if ( ! $raw ) return null;
        $base = (float)$raw->get_regular_price( 'edit' );
        $retail = (float)$raw->get_price( 'edit' );
        $stored_rules = Database::get_matching_prices( $product_id, $variation_id, $ctx['uid'], $ctx['roles'], $ctx['groups'] );
        $key = 'price:' . md5( serialize( [$stored_rules,$ctx,$product_id,$variation_id,$qty,$base,$retail,current_time('Y-m-d'),get_woocommerce_currency(),Scopes::allowed(),self::listener_key( 'cewp_price_rules' ),self::listener_key( 'cewp_resolve_price' ),self::listener_key( 'cewp_resolved_price' )] ) );
        if ( array_key_exists( $key, self::$cache ) ) return self::$cache[$key];
        static $resolving = false;
        if ( $resolving ) return null;
        $resolving = true;
        try {
            $rules = self::matching_rules( $stored_rules, $base, $product_id, $variation_id, $qty, $ctx );
            $rule = $rules[0] ?? null;
            if ( $rule === null ) {
                $result = self::filtered( 'cewp_resolve_price', ['matched'=>false,'rule'=>null,'source'=>null], [$base,$product_id,$variation_id,$qty,$ctx,true], static function ( $r,$previous ) use ( $qty ) {
                    if ( $previous['matched'] ) return false;
                    if ( ! is_array( $r ) || ! isset( $r['matched'] ) || ! is_bool( $r['matched'] ) ) return false;
                    if ( ! $r['matched'] ) return $r === ['matched'=>false,'rule'=>null,'source'=>null];
                    return is_array( $r['rule'] ?? null ) && is_string( $r['source'] ?? null ) && trim( $r['source'] ) !== '' && self::eligible( $r['rule'], $qty );
                } );
                $rule = $result['rule'];
            }
            if ( $rule === null ) return self::$cache[$key] = null;
            $price = round( min( self::apply( $base, (object)$rule ), $retail ), wc_get_price_decimals() );
            // Add-ons convert only a matched, capped and rounded dealer price.
            $price = self::filtered( 'cewp_resolved_price', $price, [$product_id,$variation_id,$qty,$ctx],
                static fn( $value ) => is_numeric( $value ) && is_finite( (float)$value ) && (float)$value >= 0 );
            return self::$cache[$key] = (float)$price;
        } finally { $resolving = false; }
    }

    public static function apply( float $base, object $rule ): float {
        $v = (float)$rule->price_value;
        $price = match( $rule->price_type ) {
            'fixed'            => $v,
            'percent_discount' => max(0, $base - ($base * $v / 100)),
            'fixed_discount'   => max(0, $base - $v),
            default            => $base,
        };
        // Wholesale sale price (lower of price vs sale_price)
        if ( (float)($rule->sale_price ?? 0) > 0 && (float)($rule->sale_price ?? 0) < $price ) {
            $price = (float)($rule->sale_price ?? 0);
        }
        return $price;
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public static function price( $price, $product ) {
        if ( $product->is_type('variable') ) return $price;
        $vid = $product->is_type('variation') ? $product->get_id() : 0;
        $pid = $vid ? $product->get_parent_id() : $product->get_id();
        $qty = self::$cart_quantities[$product] ?? 1;
        $r = self::resolve( 0, $pid, $vid, $qty );
        if ( $r === null ) return $price;
        // An absent native sale must stay absent, including for matched zero prices.
        if ( str_ends_with( current_filter(), '_get_sale_price' )
            && $product->get_sale_price('edit') === '' ) return '';
        return (string)$r;
    }

    public static function price_variation( $price, $variation ) {
        return self::price( $price, $variation );
    }

    public static function variation_prices( array $prices, $product ): array {
        $ctx = self::ctx();
        if ( ! $ctx ) return $prices;
        foreach ( ['price','regular_price','sale_price'] as $type ) {
            if ( empty($prices[$type]) ) continue;
            foreach ( $prices[$type] as $vid => $p ) {
                $r = self::resolve( (float)$p, $product->get_id(), $vid );
                if ( $r !== null ) $prices[$type][$vid] = (string)$r;
            }
        }
        return $prices;
    }

    public static function cart_prices( $cart ): void {
        if ( is_admin() && ! defined('DOING_AJAX') ) return;
        if ( ! is_user_logged_in() ) return;
        foreach ( $cart->get_cart() as $item ) {
            $product    = $item['data'];
            self::$cart_quantities ??= new \WeakMap();
            self::$cart_quantities[$product] = (int)$item['quantity'];
            $vid = $product->is_type('variation') ? $product->get_id() : 0;
            $pid = $vid ? $product->get_parent_id() : $product->get_id();
            $r = self::resolve( 0, $pid, $vid, (int)$item['quantity'] );
            if ( $r !== null ) $product->set_price( $r );
        }
    }

    // ── Price HTML: show / hide retail price for dealers ──────────────────────
    public static function price_html( string $html, $product ): string {
        if ( ! is_user_logged_in() ) return $html;
        $show_retail = Database::get_setting( 'show_retail_to_wholesale', '1' );
        if ( ! $show_retail && Roles::is_dealer() ) {
            // Strip the original "from" price and just show the dealer price
            $price = $product->get_price();
            return '<span class="dpp-dealer-price">' . wc_price($price) . '</span>';
        }
        return $html;
    }
}
