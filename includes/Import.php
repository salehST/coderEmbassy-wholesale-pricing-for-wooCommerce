<?php
namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Import {

    public static function init(): void {
        add_action( 'admin_post_cewp_import_csv', [ __CLASS__, 'handle' ] );
    }

    public static function handle(): void {
        check_admin_referer( 'cewp_import_csv' );
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $file = $_FILES['csv_file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_safe_redirect( admin_url('admin.php?page=coderembassy-wholesale-pricing&tab=import&error=no_file') );
            exit;
        }

        $rows   = self::parse_csv( $file['tmp_name'] );
        $count  = 0;
        $errors = [];

        foreach ( $rows as $i => $row ) {
            try {
                $result = self::import_price_row( $row );
                if ( $result === false ) {
                    $errors[] = "Row " . ($i+2) . ": Could not save rule (duplicate or invalid).";
                    break;
                }
                $count++;
            } catch ( \Throwable $e ) {
                $errors[] = "Row " . ($i+2) . ": " . $e->getMessage();
            }
        }

        $qs = "imported={$count}&errors=" . count($errors);
        if ( $errors ) set_transient('cewp_import_errors', $errors, 60);
        wp_safe_redirect( admin_url("admin.php?page=coderembassy-wholesale-pricing&tab=import&{$qs}") );
        exit;
    }

    public static function parse_csv( string $filepath ): array {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( ! $wp_filesystem->exists( $filepath ) ) {
            return [];
        }
        $contents = $wp_filesystem->get_contents( $filepath );
        if ( ! $contents ) {
            return [];
        }

        $rows = [];
        $lines = explode( "\n", str_replace( "\r\n", "\n", $contents ) );
        $headers = null;
        foreach ( $lines as $line ) {
            if ( empty( trim( $line ) ) ) {
                continue;
            }
            $csv_line = str_getcsv( $line );
            if ( $headers === null ) {
                $headers = array_map( 'trim', $csv_line );
                continue;
            }
            if ( count( $csv_line ) !== count( $headers ) ) {
                continue;
            }
            $rows[] = array_combine( $headers, array_map( 'trim', $csv_line ) );
        }
        return $rows;
    }

    private static function import_price_row( array $row ): bool {
        $scope_value = $row['scope_value'] ?? '';
        if ( ($row['scope_type'] ?? '') === 'user' && str_contains($scope_value, '@') ) {
            $user = get_user_by('email', $scope_value);
            $safe_val = esc_html($scope_value);
            if ( ! $user ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \Exception('User not found: ' . $safe_val);
            }
            $scope_value = (string)$user->ID;
        }
        $product_id = absint( $row['product_id'] ?? 0 );
        if ( ! $product_id && ! empty($row['product_sku']) ) {
            $product_id = (int)wc_get_product_id_by_sku($row['product_sku']);
        }
        if ( ! $product_id ) throw new \Exception("Invalid product_id");

        $result = Database::save_price([
            'product_id'   => $product_id,
            'variation_id' => absint($row['variation_id'] ?? 0),
            'scope_type'   => sanitize_text_field($row['scope_type'] ?? 'role'),
            'scope_value'  => $scope_value,
            'price_type'   => sanitize_text_field($row['price_type'] ?? 'fixed'),
            'price_value'  => (float)($row['price_value'] ?? 0),
            'sale_price'   => isset($row['sale_price']) && $row['sale_price'] !== '' ? (float)$row['sale_price'] : null,
            'min_qty'      => absint($row['min_qty'] ?? 1),
            'date_from'    => $row['date_from'] ?: null,
            'date_to'      => $row['date_to']   ?: null,
        ]);
        return $result;
    }
}
