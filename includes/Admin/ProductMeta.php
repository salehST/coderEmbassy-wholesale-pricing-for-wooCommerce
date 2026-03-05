<?php
namespace CEWP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CEWP\Database;

/**
 * ProductMeta – wholesale price column in product list.
 */
class ProductMeta {

    public static function init(): void {
        add_filter( 'manage_product_posts_columns', [ __CLASS__, 'add_column' ] );
        add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
    }

    public static function add_column( array $cols ): array {
        $cols['cewp_wholesale_price'] = __( 'Wholesale Price', 'coderembassy-wholesale-pricing' );
        return $cols;
    }

    public static function render_column( string $col, int $product_id ): void {
        if ( $col !== 'cewp_wholesale_price' ) return;
        $result = Database::list_prices( [ 'product_id' => $product_id, 'per_page' => 1 ] );
        if ( $result['total'] > 0 ) {
            $r = $result['rows'][0];
            $val = $r->price_type === 'fixed' ? wc_price( $r->price_value ) : $r->price_value . ( $r->price_type === 'percent_discount' ? '% off' : ' off' );
            $url = admin_url( 'admin.php?page=coderembassy-wholesale-pricing&tab=prices&product_id=' . $product_id );
            echo '<a href="' . esc_url( $url ) . '">' . wp_kses_post( $val ) . ' <small>(' . (int) $result['total'] . ')</small></a>';
        } else {
            echo '<span style="color:#999">—</span>';
        }
    }
}
