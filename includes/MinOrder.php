<?php
namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * MinOrder – shows minimum order notice for wholesale customers.
 * When require_before_whl is enabled, blocks checkout until minimum is met.
 */
class MinOrder {

    public static function init(): void {
        add_action( 'woocommerce_check_cart_items',            [ __CLASS__, 'check_cart' ] );
        add_action( 'woocommerce_cart_totals_after_order_total', [ __CLASS__, 'cart_notice' ] );
        add_filter( 'woocommerce_checkout_process',            [ __CLASS__, 'check_checkout' ] );
    }

    private static function get_rule(): ?object {
        $role = Roles::get_user_dealer_role();
        if ( ! $role ) return null;
        return Database::get_min_order( $role );
    }

    public static function check_cart(): void {
        $rule = self::get_rule();
        if ( ! $rule ) return;

        $cart     = WC()->cart;
        $subtotal = (float)$cart->get_subtotal();
        $qty      = (int)$cart->get_cart_contents_count();

        if ( $rule->min_subtotal > 0 && $subtotal < $rule->min_subtotal ) {
            $remaining = $rule->min_subtotal - $subtotal;
            $msg = sprintf(
                /* translators: 1: Minimum order amount, 2: Remaining amount needed */
                __( 'Minimum order amount for wholesale is %1$s. Add %2$s more to qualify.', 'coderembassy-wholesale-pricing' ),
                wc_price($rule->min_subtotal), wc_price($remaining)
            );
            if ( $rule->require_before_whl ) {
                wc_add_notice( $msg, 'error' );
            } else {
                wc_add_notice( $msg, 'notice' );
            }
        }

        if ( $rule->min_qty_total > 0 && $qty < $rule->min_qty_total ) {
            $msg = sprintf(
                /* translators: 1: Minimum number of items, 2: Current number of items */
                __( 'Minimum %1$d items required for wholesale pricing. You have %2$d.', 'coderembassy-wholesale-pricing' ),
                $rule->min_qty_total, $qty
            );
            if ( $rule->require_before_whl ) {
                wc_add_notice( $msg, 'error' );
            } else {
                wc_add_notice( $msg, 'notice' );
            }
        }
    }

    public static function check_checkout(): void {
        $rule = self::get_rule();
        if ( ! $rule || ! $rule->require_before_whl ) return;

        $cart     = WC()->cart;
        $subtotal = (float)$cart->get_subtotal();
        $qty      = (int)$cart->get_cart_contents_count();

        if ( $rule->min_subtotal > 0 && $subtotal < $rule->min_subtotal ) {
            wc_add_notice( sprintf(
                /* translators: 1: Minimum subtotal amount */
                __( 'Your order does not meet the minimum wholesale subtotal of %1$s.', 'coderembassy-wholesale-pricing' ),
                wc_price($rule->min_subtotal)
            ), 'error' );
        }
        if ( $rule->min_qty_total > 0 && $qty < $rule->min_qty_total ) {
            wc_add_notice( sprintf(
                /* translators: 1: Minimum quantity of items */
                __( 'Your order does not meet the minimum wholesale quantity of %1$d items.', 'coderembassy-wholesale-pricing' ),
                $rule->min_qty_total
            ), 'error' );
        }
    }

    public static function cart_notice(): void {
        $rule = self::get_rule();
        if ( ! $rule ) return;

        $cart     = WC()->cart;
        $subtotal = (float)$cart->get_subtotal();

        if ( $rule->min_subtotal > 0 && $subtotal < $rule->min_subtotal ) {
            $remaining = $rule->min_subtotal - $subtotal;
            printf(
                '<tr><td colspan="2"><div class="dpp-min-notice">%s</div></td></tr>',
                esc_html( sprintf(
                    /* translators: 1: Remaining amount needed */
                    __( 'Add %1$s more to reach the wholesale minimum order.', 'coderembassy-wholesale-pricing' ),
                    wp_strip_all_tags(wc_price($remaining))
                ) )
            );
        }
    }
}
