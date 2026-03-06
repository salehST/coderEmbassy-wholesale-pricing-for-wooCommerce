<?php
namespace CEWP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CEWP\Roles;

/**
 * OrderColumn – adds "Order Type" (wholesale/retail) column to WooCommerce orders list.
 */
class OrderColumn {

    public static function init(): void {
        add_filter( 'woocommerce_shop_order_list_table_columns', [ __CLASS__, 'add' ] );
        add_action( 'woocommerce_shop_order_list_table_custom_column', [ __CLASS__, 'render' ], 10, 2 );
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'mark_wholesale_order' ] );
    }

    public static function mark_wholesale_order( $order ): void {
        if ( ! $order || ! method_exists( $order, 'get_customer_id' ) ) return;
        if ( Roles::is_dealer( (int) $order->get_customer_id() ) ) {
            $order->update_meta_data( '_cewp_wholesale_order', '1' );
            $order->save();
        }
    }

    public static function add( array $cols ): array {
        $cols['cewp_type'] = __( 'Type', 'coderembassy-wholesale-pricing' );
        return $cols;
    }

    public static function render( string $col, $order ): void {
        if ( $col !== 'cewp_type' ) return;
        $is = $order->get_meta( '_cewp_wholesale_order' );
        echo $is ? '<span style="color:#10b981;font-size:11px;font-weight:600">WHOLESALE</span>' : '<span style="color:#aaa;font-size:11px">retail</span>';
    }
}
