<?php
namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Visibility – catalog mode (hide prices until login) and option to hide retail-only products from wholesale customers.
 */
class Visibility {

    public static function init(): void {
        add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'price_html' ], 20, 2 );
        add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'is_purchasable' ], 20, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'variation_is_purchasable' ], 20, 2 );
        add_filter( 'woocommerce_cart_item_price', [ __CLASS__, 'cart_item_price' ], 20, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', [ __CLASS__, 'cart_item_subtotal' ], 20, 3 );
        add_filter( 'woocommerce_cart_totals_subtotal_html', [ __CLASS__, 'cart_totals_subtotal_html' ], 20, 1 );
        add_filter( 'woocommerce_cart_get_total', [ __CLASS__, 'cart_get_total' ], 20, 1 );
        add_filter( 'woocommerce_cart_totals_order_total_html', [ __CLASS__, 'cart_totals_order_total_html' ], 20, 1 );
        add_filter( 'pre_get_posts', [ __CLASS__, 'hide_retail_only_from_wholesale' ], 20, 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect_retail_only_single' ], 5 );
        add_action( 'template_redirect', [ __CLASS__, 'redirect_cart_guest_catalog' ], 5 );
        add_action( 'wp', [ __CLASS__, 'maybe_show_cart_login_notice' ], 15 );
    }

    /**
     * Catalog mode: hide prices from guests.
     */
    public static function price_html( string $html, $product ): string {
        if ( ! $product ) return $html;
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return '<span class="dps-catalog-price">' . esc_html__( 'Log in to see prices', 'coderembassy-wholesale-pricing' ) . '</span>';
        }
        return $html;
    }

    /** Catalog mode: guests cannot add to cart (hide button and block adding). */
    public static function is_purchasable( bool $purchasable, $product ): bool {
        if ( ! $purchasable || ! $product ) return $purchasable;
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return false;
        }
        return $purchasable;
    }

    /** Catalog mode: variation add to cart for guests. WC passes ( $purchasable, $variation ). */
    public static function variation_is_purchasable( bool $purchasable, $variation ): bool {
        if ( ! $purchasable ) return $purchasable;
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return false;
        }
        return $purchasable;
    }

    /** Catalog mode: redirect guests from cart to shop and show login message. */
    public static function redirect_cart_guest_catalog(): void {
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( ! $catalog || is_user_logged_in() ) return;
        if ( ! function_exists( 'is_cart' ) || ! is_cart() ) return;
        wp_safe_redirect( add_query_arg( 'cewp_please_login', '1', wc_get_page_permalink( 'shop' ) ) );
        exit;
    }

    /** Show "Please log in to view your cart" on shop when redirected from cart. */
    public static function maybe_show_cart_login_notice(): void {
        if ( ! function_exists( 'is_shop' ) || ! is_shop() ) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['cewp_please_login'] ) ) return;
        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( __( 'Please log in to view your cart.', 'coderembassy-wholesale-pricing' ), 'notice' );
        }
        wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
        exit;
    }

    /** Catalog mode: in cart, hide price from guests (show message). */
    public static function cart_item_price( string $price, $cart_item, $cart_item_key ): string {
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return '<span class="dps-catalog-price">' . esc_html__( 'Log in to see prices', 'coderembassy-wholesale-pricing' ) . '</span>';
        }
        return $price;
    }

    public static function cart_item_subtotal( string $subtotal, $cart_item, $cart_item_key ): string {
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return '<span class="dps-catalog-price">' . esc_html__( 'Log in to see prices', 'coderembassy-wholesale-pricing' ) . '</span>';
        }
        return $subtotal;
    }

    /** Catalog mode: hide cart subtotal from guests. */
    public static function cart_totals_subtotal_html( string $html ): string {
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return '<span class="dps-catalog-price">' . esc_html__( 'Log in to see prices', 'coderembassy-wholesale-pricing' ) . '</span>';
        }
        return $html;
    }

    /** Catalog mode: hide order total value from guests (used in cart object). */
    public static function cart_get_total( string $total ): string {
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return '';
        }
        return $total;
    }

    /** Catalog mode: hide order total display from guests. */
    public static function cart_totals_order_total_html( string $html ): string {
        $catalog = Database::get_setting( 'catalog_mode', '0' );
        if ( $catalog && ! is_user_logged_in() ) {
            return '<span class="dps-catalog-price">' . esc_html__( 'Log in to see prices', 'coderembassy-wholesale-pricing' ) . '</span>';
        }
        return $html;
    }

    /**
     * Hide retail-only products from wholesale customers: only show products that have a price rule for their role(s).
     * "Retail only" = no dealer/wholesale price rule for that product.
     */
    public static function hide_retail_only_from_wholesale( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $query->get( 'post_type' ) ) return;
        if ( $query->is_singular() ) return;
        $post_type = $query->get( 'post_type' );
        $is_product_query = ( $post_type === 'product' || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) );
        if ( ! $is_product_query ) return;

        $hide = Database::get_setting( 'hide_retail_from_wholesale', '0' );
        if ( ! $hide || ! Roles::is_dealer() ) return;

        $user = wp_get_current_user();
        $roles = array_values( array_filter( (array) $user->roles ) );
        $allowed_ids = Database::get_product_ids_with_role_rules( $roles );
        if ( empty( $allowed_ids ) ) {
            $query->set( 'post__in', [ 0 ] );
            return;
        }
        $query->set( 'post__in', $allowed_ids );
    }

    /** On single product page, redirect wholesale customers away from retail-only products when hide setting is on. */
    public static function maybe_redirect_retail_only_single(): void {
        if ( ! is_singular( 'product' ) ) return;
        $hide = Database::get_setting( 'hide_retail_from_wholesale', '0' );
        if ( ! $hide || ! Roles::is_dealer() ) return;

        $product_id = get_the_ID();
        $user = wp_get_current_user();
        $roles = array_values( array_filter( (array) $user->roles ) );
        $allowed_ids = Database::get_product_ids_with_role_rules( $roles );
        if ( ! in_array( (int) $product_id, $allowed_ids, true ) ) {
            wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
            exit;
        }
    }
}
