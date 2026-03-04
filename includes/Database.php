<?php
namespace CEWP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

class Database {

    public static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'cewp_' . $name;
    }

    /**
     * One-time migration: copy options and tables from old dps_* prefix to cewp_*, then remove old data.
     * Run before install_if_needed() so existing sites keep their data after the prefix change.
     */
    public static function migrate_dps_to_cewp(): void {
        if ( get_option( 'cewp_dps_migrated', '0' ) === '1' ) {
            return;
        }
        global $wpdb;
        $prefix = $wpdb->prefix;
        $old_roles = $prefix . 'dps_roles';
        $old_prices = $prefix . 'dps_prices';

        // Migrate options
        $old_version = get_option( 'dps_db_version', null );
        $old_settings = get_option( 'dps_settings', null );
        if ( $old_version !== null ) {
            update_option( 'cewp_db_version', $old_version );
        }
        if ( $old_settings !== null && is_array( $old_settings ) ) {
            update_option( 'cewp_settings', $old_settings );
        }
        if ( $old_version !== null || $old_settings !== null ) {
            delete_option( 'dps_db_version' );
            delete_option( 'dps_settings' );
        }

        // Migrate tables (create cewp_ tables if needed, copy data, drop old)
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_roles ) ) === $old_roles ) {
            dbDelta( "CREATE TABLE " . self::t( 'roles' ) . " (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                role_key    VARCHAR(100) NOT NULL,
                label       VARCHAR(200) NOT NULL,
                is_default  TINYINT(1) NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_role_key (role_key)
            ) $charset;" );
            $wpdb->query( "INSERT IGNORE INTO " . self::t( 'roles' ) . " SELECT * FROM `{$old_roles}`" );
            $wpdb->query( "DROP TABLE IF EXISTS `{$old_roles}`" );
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_prices ) ) === $old_prices ) {
            dbDelta( "CREATE TABLE " . self::t( 'prices' ) . " (
                id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id      BIGINT(20) UNSIGNED NOT NULL,
                variation_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                scope_type      ENUM('user','group','role') NOT NULL,
                scope_value     VARCHAR(200) NOT NULL,
                price_type      ENUM('fixed','percent_discount','fixed_discount') NOT NULL DEFAULT 'fixed',
                price_value     DECIMAL(15,4) NOT NULL,
                sale_price      DECIMAL(15,4) NULL,
                min_qty         INT(11) UNSIGNED NOT NULL DEFAULT 1,
                date_from       DATE NULL,
                date_to         DATE NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY     (id),
                KEY idx_product (product_id, variation_id),
                KEY idx_scope   (scope_type, scope_value)
            ) $charset;" );
            $wpdb->query( "INSERT IGNORE INTO " . self::t( 'prices' ) . " SELECT * FROM `{$old_prices}`" );
            $wpdb->query( "DROP TABLE IF EXISTS `{$old_prices}`" );
        }

        delete_transient( 'dps_import_errors' );
        update_option( 'cewp_dps_migrated', '1' );
    }

    public static function install_if_needed(): void {
        if ( version_compare( get_option( 'cewp_db_version', '0' ), CEWP_VERSION, '<' ) ) {
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE " . self::t('roles') . " (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            role_key    VARCHAR(100) NOT NULL,
            label       VARCHAR(200) NOT NULL,
            is_default  TINYINT(1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_role_key (role_key)
        ) $c;" );

        /* Starter: no groups table — dealer groups are a Business+ feature */

        dbDelta( "CREATE TABLE " . self::t('prices') . " (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id      BIGINT(20) UNSIGNED NOT NULL,
            variation_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            scope_type      ENUM('user','group','role') NOT NULL,
            scope_value     VARCHAR(200) NOT NULL,
            price_type      ENUM('fixed','percent_discount','fixed_discount') NOT NULL DEFAULT 'fixed',
            price_value     DECIMAL(15,4) NOT NULL,
            sale_price      DECIMAL(15,4) NULL,
            min_qty         INT(11) UNSIGNED NOT NULL DEFAULT 1,
            date_from       DATE NULL,
            date_to         DATE NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY     (id),
            KEY idx_product (product_id, variation_id),
            KEY idx_scope   (scope_type, scope_value)
        ) $c;" );

        update_option( 'cewp_db_version', CEWP_VERSION );
    }

    public static function uninstall(): void {
        global $wpdb;
        if ( ! get_option( 'cewp_delete_on_uninstall' ) ) return;
        foreach ( [ 'prices', 'roles' ] as $t ) {
            $wpdb->query( "DROP TABLE IF EXISTS " . self::t( $t ) );
        }
        delete_option( 'cewp_db_version' );
        delete_option( 'cewp_settings' );
        delete_option( 'cewp_dps_migrated' );
    }

    public static function get_setting( string $key, mixed $default = '' ): mixed {
        $settings = get_option( 'cewp_settings', [] );
        return $settings[$key] ?? $default;
    }

    public static function save_settings( array $data ): void {
        $settings = get_option( 'cewp_settings', [] );
        update_option( 'cewp_settings', array_merge( $settings, $data ) );
    }

    // ── Roles CRUD ───────────────────────────────────────────────────────────

    public static function get_roles(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM " . self::t('roles') . " ORDER BY label ASC" );
    }

    public static function save_role( array $data ): int {
        global $wpdb;
        $fields = [
            'role_key'   => sanitize_key( $data['role_key'] ),
            'label'      => sanitize_text_field( $data['label'] ),
            'is_default' => (int) ( $data['is_default'] ?? 0 ),
        ];
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( self::t('roles'), $fields, ['id' => absint($data['id'])] );
            return absint( $data['id'] );
        }
        $wpdb->insert( self::t('roles'), $fields );
        return (int) $wpdb->insert_id;
    }

    // ── Groups: not in Starter (Business+ feature). Stubs for Pricing compatibility. ──

    public static function get_user_group_ids( int $user_id ): array {
        return [];
    }

    // ── Price Rules CRUD ─────────────────────────────────────────────────────

    public static function get_rule_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::t('prices') );
    }

    public static function is_at_limit(): bool {
        return self::get_rule_count() >= CEWP_MAX_RULES;
    }

    /**
     * IDs of rules that count toward the "manageable" set (first CEWP_MAX_RULES by id).
     * Used for soft lock: rules beyond this can still apply on storefront but cannot be edited in admin.
     */
    public static function get_manageable_rule_ids(): array {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT id FROM " . self::t( 'prices' ) . " ORDER BY id ASC LIMIT " . (int) CEWP_MAX_RULES
        );
        return array_map( 'intval', $ids ?: [] );
    }

    /** True if this rule is within the Starter manageable limit (can be edited in admin). */
    public static function is_rule_manageable( int $rule_id ): bool {
        $manageable = self::get_manageable_rule_ids();
        return in_array( $rule_id, $manageable, true );
    }

    /** Check if a rule already exists for this product (or variation) and scope. */
    public static function rule_exists( int $product_id, int $variation_id, string $scope_type, string $scope_value, ?int $exclude_id = null ): bool {
        global $wpdb;
        $sql = "SELECT 1 FROM " . self::t('prices') . " WHERE product_id=%d AND variation_id=%d AND scope_type=%s AND scope_value=%s";
        $args = [ $product_id, $variation_id, $scope_type, $scope_value ];
        if ( $exclude_id !== null && $exclude_id > 0 ) {
            $sql .= " AND id!=%d";
            $args[] = $exclude_id;
        }
        $sql .= " LIMIT 1";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) ) === 1;
    }

    public static function save_price( array $d ): int|false {
        global $wpdb;
        // Enforce 10-rule limit for new rules (not updates)
        if ( empty( $d['id'] ) && self::is_at_limit() ) {
            return false;
        }
        // Starter: only role-based pricing (no user/group scope in code)
        $scope_type = 'role';
        $scope_value = sanitize_text_field( $d['scope_value'] ?? '' );
        $product_id   = absint( $d['product_id'] );
        $variation_id = absint( $d['variation_id'] ?? 0 );
        $exclude_id   = ! empty( $d['id'] ) ? absint( $d['id'] ) : null;
        if ( self::rule_exists( $product_id, $variation_id, $scope_type, $scope_value, $exclude_id ) ) {
            return false; /* duplicate: same product/variation + role */
        }
        $fields = [
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'scope_type'   => $scope_type,
            'scope_value'  => $scope_value,
            'price_type'   => sanitize_text_field( $d['price_type'] ),
            'price_value'  => (float) $d['price_value'],
            'sale_price'   => isset($d['sale_price']) && $d['sale_price'] !== '' ? (float)$d['sale_price'] : null,
            'min_qty'      => absint( $d['min_qty'] ?? 1 ),
            'date_from'    => null, /* Starter: no date-limited rules */
            'date_to'      => null,
        ];
        if ( $exclude_id ) {
            $wpdb->update( self::t('prices'), $fields, ['id' => $exclude_id] );
            return $exclude_id;
        }
        $wpdb->insert( self::t('prices'), $fields );
        return (int) $wpdb->insert_id;
    }

    public static function get_price( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::t('prices') . " WHERE id=%d", $id ) );
    }

    public static function delete_price( int $id ): void {
        global $wpdb;
        $wpdb->delete( self::t('prices'), ['id' => $id] );
    }

    public static function list_prices( array $args = [] ): array {
        global $wpdb;
        $per  = absint( $args['per_page'] ?? 25 );
        $page = absint( $args['page']     ?? 1 );
        $off  = ( $page - 1 ) * $per;
        $w    = '1=1'; $qa = [];
        if ( ! empty( $args['search'] ) ) {
            $w   .= ' AND (p.scope_value LIKE %s OR po.post_title LIKE %s)';
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $qa[] = $like; $qa[] = $like;
        }
        if ( ! empty( $args['product_id'] ) ) { $w .= ' AND p.product_id=%d'; $qa[] = absint($args['product_id']); }
        if ( ! empty( $args['scope_type'] ) )  { $w .= ' AND p.scope_type=%s';  $qa[] = $args['scope_type']; }
        if ( ! empty( $args['scope_value'] ) ) { $w .= ' AND p.scope_value=%s'; $qa[] = $args['scope_value']; }
        if ( ! empty( $args['price_type'] ) )  { $w .= ' AND p.price_type=%s';  $qa[] = $args['price_type']; }

        $total_args = $qa;
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::t('prices') . " p LEFT JOIN {$wpdb->posts} po ON po.ID=p.product_id WHERE $w", ...$total_args ) );
        $qa[] = $per; $qa[] = $off;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, po.post_title AS product_name FROM " . self::t('prices') . " p LEFT JOIN {$wpdb->posts} po ON po.ID=p.product_id WHERE $w ORDER BY p.id DESC LIMIT %d OFFSET %d",
            ...$qa
        ) );
        return compact( 'total', 'rows' );
    }

    public static function get_matching_prices( int $product_id, int $variation_id, int $user_id, array $roles, array $group_ids ): array {
        global $wpdb;
        $today = current_time('Y-m-d');

        $role_ph  = implode(',', array_fill(0, max(1, count($roles)),     '%s'));
        $group_ph = implode(',', array_fill(0, max(1, count($group_ids)), '%d'));

        $sql  = "SELECT *, CASE scope_type WHEN 'user' THEN 1 WHEN 'group' THEN 2 ELSE 3 END AS _pri
                 FROM " . self::t('prices') . "
                 WHERE product_id=%d AND (variation_id=0 OR variation_id=%d)
                   AND ( (scope_type='user' AND scope_value=%s)";
        $args = [ $product_id, $variation_id, (string)$user_id ];

        if ( $group_ids ) { $sql .= " OR (scope_type='group' AND scope_value IN ($group_ph))"; $args = array_merge($args, $group_ids); }
        if ( $roles )     { $sql .= " OR (scope_type='role'  AND scope_value IN ($role_ph))";  $args = array_merge($args, $roles); }

        $sql .= " ) AND (date_from IS NULL OR date_from<=%s) AND (date_to IS NULL OR date_to>=%s) ORDER BY _pri ASC, variation_id DESC";
        $args[] = $today; $args[] = $today;

        return $wpdb->get_results( $wpdb->prepare($sql, ...$args) );
    }

    public static function get_stats(): array {
        global $wpdb;
        return [
            'total_rules'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::t('prices') ),
            'total_groups'  => 0,
            'total_dealers' => 0,
            'total_products'=> (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM " . self::t('prices') ),
            'rule_limit'    => CEWP_MAX_RULES,
            'rules_used'    => self::get_rule_count(),
        ];
    }

    /** Starter: no min-order enforcement (display-only notice; no stored rules). */
    public static function get_min_order( string $role_key ): ?object {
        return null;
    }

    /** Product IDs that have at least one price rule for the given role(s). Used to hide retail-only products from wholesale. */
    public static function get_product_ids_with_role_rules( array $role_slugs ): array {
        if ( empty( $role_slugs ) ) return [];
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $role_slugs ), '%s' ) );
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT product_id FROM " . self::t('prices') . " WHERE scope_type='role' AND scope_value IN ($placeholders)",
            ...$role_slugs
        ) ) );
    }
}
// phpcs:enable
