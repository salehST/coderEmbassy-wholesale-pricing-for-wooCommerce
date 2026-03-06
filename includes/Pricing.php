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

    public static function ctx(): ?array {
        if ( ! is_user_logged_in() ) return null;
        $uid = get_current_user_id();
        if ( isset( self::$cache[$uid] ) ) return self::$cache[$uid];
        $user  = wp_get_current_user();
        $roles = (array) $user->roles;
        $groups= Database::get_user_group_ids($uid);
        return self::$cache[$uid] = compact('uid','roles','groups');
    }

    // ── Core resolver ─────────────────────────────────────────────────────────

    /**
     * Returns the resolved dealer price or null if no rule matches.
     * $qty is used for min_qty tiered rules.
     */
    public static function resolve( float $base, int $product_id, int $variation_id = 0, int $qty = 1 ): ?float {
        $ctx = self::ctx();
        if ( ! $ctx ) return null;

        // 1-3: product-level rules (user > group > role)
        $rules = Database::get_matching_prices( $product_id, $variation_id, $ctx['uid'], $ctx['roles'], $ctx['groups'] );
        if ( $rules ) {
            foreach ( $rules as $r ) {
                if ( $qty < (int)$r->min_qty ) continue;
                return self::apply( $base, $r );
            }
        }


        return null;
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
        if ( ! empty($rule->sale_price) && (float)$rule->sale_price < $price ) {
            $price = (float)$rule->sale_price;
        }
        return round($price, wc_get_price_decimals());
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public static function price( $price, $product ) {
        if ( $product->is_type('variable') ) return $price;
        $r = self::resolve( (float)$price, $product->get_id() );
        return $r !== null ? (string)$r : $price;
    }

    public static function price_variation( $price, $variation ) {
        $r = self::resolve( (float)$price, $variation->get_parent_id(), $variation->get_id() );
        return $r !== null ? (string)$r : $price;
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
            $regular    = (float) $product->get_regular_price();
            $sale       = $product->get_sale_price();
            $retail     = ( $sale !== '' && (float) $sale >= 0 ) ? (float) $sale : $regular;
            $product_id = (int) ( $item['product_id'] ?? $product->get_id() );
            $vid        = (int) ( $item['variation_id'] ?? 0 );
            $qty        = (int) $item['quantity'];
            $r          = self::resolve( $regular, $product_id, $vid, $qty );
            $product->set_price( $r !== null ? $r : $retail );
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
