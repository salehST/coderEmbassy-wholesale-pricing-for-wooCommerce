<?php
namespace CEWP\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CEWP\Database;
use WP_REST_Request;
use WP_REST_Response;

class Controller {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void {
        $ns = 'cewp/v1';
        $a  = [__CLASS__, 'auth'];

        register_rest_route($ns, '/prices',              ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_prices'],  'permission_callback'=>$a]);
        register_rest_route($ns, '/prices',              ['methods'=>'POST',   'callback'=>[__CLASS__,'handle_prices'],  'permission_callback'=>$a]);
        register_rest_route($ns, '/prices/(?P<id>\d+)', ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_price'],   'permission_callback'=>$a]);
        register_rest_route($ns, '/prices/(?P<id>\d+)', ['methods'=>'PUT',    'callback'=>[__CLASS__,'handle_price'],   'permission_callback'=>$a]);
        register_rest_route($ns, '/prices/(?P<id>\d+)', ['methods'=>'DELETE', 'callback'=>[__CLASS__,'handle_price'],   'permission_callback'=>$a]);
        register_rest_route($ns, '/roles',               ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_roles'],   'permission_callback'=>$a]);
        register_rest_route($ns, '/import/csv',          ['methods'=>'POST',   'callback'=>[__CLASS__,'handle_import_csv'],'permission_callback'=>$a]);
        register_rest_route($ns, '/settings',            ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_settings'],'permission_callback'=>$a]);
        register_rest_route($ns, '/settings',            ['methods'=>'POST',   'callback'=>[__CLASS__,'handle_settings'],'permission_callback'=>$a]);
        register_rest_route($ns, '/stats',               ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_stats'],   'permission_callback'=>$a]);
        register_rest_route($ns, '/products/search',     ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_search_products'],'permission_callback'=>$a]);
        register_rest_route($ns, '/users/search',        ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_search_users'],   'permission_callback'=>$a]);
        register_rest_route($ns, '/wp-roles',            ['methods'=>'GET',    'callback'=>[__CLASS__,'handle_wp_roles'],        'permission_callback'=>$a]);
    }

    public static function auth(): bool {
        return current_user_can('manage_woocommerce');
    }

    private static function json( mixed $data, int $code = 200 ): WP_REST_Response {
        return new WP_REST_Response($data, $code);
    }

    // ── PRICES ───────────────────────────────────────────────────────────────
    public static function handle_prices(WP_REST_Request $r): WP_REST_Response {
        if ($r->get_method() === 'POST') {
            $d = $r->get_json_params() ?: [];
            $product_id   = absint( $d['product_id'] ?? 0 );
            $variation_id = absint( $d['variation_id'] ?? 0 );
            $scope_value  = sanitize_text_field( $d['scope_value'] ?? '' );
            if ( Database::rule_exists( $product_id, $variation_id, 'role', $scope_value, null ) ) {
                return self::json([
                    'error'   => 'duplicate_rule',
                    'message' => 'A price rule already exists for this product and role. Edit or delete the existing rule first.',
                ], 400);
            }
            $id = Database::save_price( $d );
            if ( $id === false ) {
                return self::json(['error'=>'save_failed','message'=>'Failed to save price rule.'], 400);
            }
            return self::json(['id'=>$id]);
        }
        $out = Database::list_prices([
            'page'        => $r->get_param('page')??1,
            'per_page'    => $r->get_param('per_page')??25,
            'search'      => $r->get_param('search')?? '',
            'product_id'  => $r->get_param('product_id')??0,
            'scope_type'  => $r->get_param('scope_type')?? '',
            'scope_value' => $r->get_param('scope_value')?? '',
            'price_type'  => $r->get_param('price_type')?? '',
        ]);
        foreach ( $out['rows'] as $row ) {
            $row->manageable = true;
        }
        return self::json( $out );
    }

    public static function handle_price(WP_REST_Request $r): WP_REST_Response {
        $id = (int)$r['id'];
        if ($r->get_method() === 'DELETE') { Database::delete_price($id); return self::json(['deleted'=>true]); }
        if ($r->get_method() === 'PUT') {
            $d = $r->get_json_params() ?: [];
            $d['id'] = $id;
            $product_id   = absint( $d['product_id'] ?? 0 );
            $variation_id = absint( $d['variation_id'] ?? 0 );
            $scope_value  = sanitize_text_field( $d['scope_value'] ?? '' );
            if ( Database::rule_exists( $product_id, $variation_id, 'role', $scope_value, $id ) ) {
                return self::json([
                    'error'   => 'duplicate_rule',
                    'message' => 'A price rule already exists for this product and role. Edit or delete the existing rule first.',
                ], 400);
            }
            Database::save_price( $d );
            return self::json(['id'=>$id]);
        }
        $row = Database::get_price($id);
        if ( ! $row ) return self::json(['error'=>'Not found'],404);
        $row = (array) $row;
        $row['manageable'] = true;
        return self::json( $row );
    }

    // ── ROLES ────────────────────────────────────────────────────────────────
    public static function handle_roles(WP_REST_Request $r): WP_REST_Response {
        return self::json(Database::get_roles());
    }

    // ── SETTINGS ─────────────────────────────────────────────────────────────
    public static function handle_settings(WP_REST_Request $r): WP_REST_Response {
        if ( $r->get_method() === 'POST' ) {
            $data = $r->get_json_params() ?: [];
            $allowed = [ 'show_retail_to_wholesale', 'hide_retail_from_wholesale', 'disallow_coupons', 'catalog_mode', 'delete_on_uninstall' ];
            $to_save = [];
            foreach ( $allowed as $key ) {
                if ( array_key_exists( $key, $data ) ) $to_save[ $key ] = sanitize_text_field( $data[ $key ] ?? '0' );
            }
            if ( $to_save ) Database::save_settings( $to_save );
            return self::json( [ 'saved' => true ] );
        }
        $s = get_option( 'cewp_settings', [] );
        return self::json( [
            'delete_on_uninstall' => ! empty( $s['delete_on_uninstall'] ),
            'catalog_mode'        => ! empty( $s['catalog_mode'] ),
            'hide_retail_from_wholesale' => ! empty( $s['hide_retail_from_wholesale'] ),
        ] );
    }

    // ── STATS ────────────────────────────────────────────────────────────────
    public static function handle_stats(WP_REST_Request $r): WP_REST_Response {
        return self::json(Database::get_stats());
    }

    // ── SEARCH HELPERS ───────────────────────────────────────────────────────
    public static function handle_search_products(WP_REST_Request $r): WP_REST_Response {
        $q = $r->get_param('q') ?? '';
        $ids = get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 's' => $q, 'posts_per_page' => 20, 'fields' => 'ids', 'post_status' => 'publish' ] );
        $out = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) continue;
            $is_variation = ( $post->post_type === 'product_variation' );
            $product_id   = $is_variation ? (int) $post->post_parent : (int) $id;
            $variation_id = $is_variation ? (int) $id : 0;
            $out[] = [
                'id'           => $product_id,
                'variation_id' => $variation_id,
                'name'         => get_the_title( $id ),
                'sku'          => get_post_meta( $id, '_sku', true ),
                'price'        => get_post_meta( $id, '_regular_price', true ),
            ];
        }
        return self::json( $out );
    }

    public static function handle_search_users(WP_REST_Request $r): WP_REST_Response {
        $q=$r->get_param('q')??'';
        return self::json(get_users(['search'=>"*{$q}*",'search_columns'=>['user_login','user_email','display_name'],'number'=>20,'fields'=>['ID','display_name','user_email']]));
    }

    public static function handle_wp_roles(): WP_REST_Response {
        global $wp_roles;
        return self::json(array_map(fn($k,$v)=>['slug'=>$k,'name'=>$v['name']],array_keys($wp_roles->roles),array_values($wp_roles->roles)));
    }

    /** CSV import (prices only). */
    public static function handle_import_csv( WP_REST_Request $r ): WP_REST_Response {
        $data = $r->get_json_params() ?: [];
        $csv  = (string) ( $data['csv'] ?? '' );
        $has_header = ! empty( $data['has_header'] );
        $csv = preg_replace( "/^\xEF\xBB\xBF/", '', $csv );
        if ( trim( $csv ) === '' ) {
            return self::json( [ 'error' => 'CSV is empty' ], 400 );
        }
        $lines = preg_split( "/\r\n|\n|\r/", trim( $csv ) );
        if ( ! $lines ) return self::json( [ 'error' => 'Failed to parse CSV' ], 400 );
        $created = 0;
        $errors  = [];
        $allowed_types = [ 'fixed', 'percent_discount', 'fixed_discount' ];
        $header = null;
        foreach ( $lines as $idx => $line ) {
            $line_no = $idx + 1;
            if ( trim( $line ) === '' ) continue;
            $cols = str_getcsv( $line );
            if ( $idx === 0 && $has_header ) {
                $header = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $cols );
                continue;
            }
            $row = $header ? array_combine( $header, array_pad( $cols, count( $header ), '' ) ) : [
                'product_id' => $cols[0] ?? '', 'variation_id' => $cols[1] ?? 0, 'scope_type' => 'role', 'scope_value' => $cols[4] ?? '',
                'price_type' => $cols[5] ?? '', 'price_value' => $cols[6] ?? '', 'min_qty' => $cols[7] ?? 1,
            ];
            $product_id   = absint( $row['product_id'] ?? 0 );
            $variation_id = absint( $row['variation_id'] ?? 0 );
            $scope_value  = sanitize_text_field( $row['scope_value'] ?? '' );
            $price_type   = sanitize_text_field( $row['price_type'] ?? '' );
            $price_value  = $row['price_value'] ?? '';
            if ( ! $product_id ) { $errors[] = [ 'line' => $line_no, 'message' => 'Missing product_id' ]; continue; }
            if ( ! in_array( $price_type, $allowed_types, true ) ) { $errors[] = [ 'line' => $line_no, 'message' => 'Invalid price_type' ]; continue; }
            if ( ! is_numeric( $price_value ) ) { $errors[] = [ 'line' => $line_no, 'message' => 'Invalid price_value' ]; continue; }
            if ( Database::rule_exists( $product_id, $variation_id, 'role', $scope_value ?: 'wholesale_customer', null ) ) {
                $errors[] = [ 'line' => $line_no, 'message' => 'Duplicate: a rule already exists for this product and role.' ];
                continue;
            }
            $id = Database::save_price( [
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'scope_type'   => 'role',
                'scope_value'  => $scope_value ?: 'wholesale_customer',
                'price_type'   => $price_type,
                'price_value'  => (float) $price_value,
                'min_qty'      => max( 1, absint( $row['min_qty'] ?? 1 ) ),
            ] );
            if ( $id ) $created++;
            else $errors[] = [ 'line' => $line_no, 'message' => 'Could not save rule (duplicate or invalid).' ];
        }
        return self::json( [ 'total_rows' => count( $lines ), 'created' => $created, 'errors' => $errors ] );
    }
}
