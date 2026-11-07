<?php
// Real database behavior, confined to a randomly named clone of the roles schema.
$load = getenv('CEWP_TEST_WP_LOAD');
if (!$load || basename(dirname($load)) !== 'testsite') exit(2);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_URI'] = '/';
require $load;
if (DB_NAME !== 'cewp_char' || !WP_HTTP_BLOCK_EXTERNAL || !DISABLE_WP_CRON) exit(3);
global $wpdb, $wp_roles;
$original = $wpdb->prefix;
$prefix = 't59_'.bin2hex(random_bytes(5)).'_';
$table = $prefix.'cewp_roles';
if (!preg_match('/^t59_[a-f0-9]{10}_cewp_roles$/D', $table)) exit(4);
$saved_roles = $wp_roles;
$suppressed = $wpdb->suppress_errors(true);
if ($wpdb->query("CREATE TABLE `$table` LIKE `{$original}cewp_roles`") === false) throw new RuntimeException('Clone failed');
try {
    $wpdb->set_prefix($prefix);
    // Test actual WP remove_role/add_role without persisting any role option.
    $wp_roles = clone $saved_roles;
    $wp_roles->use_db = false;
    remove_role('wholesale_customer');
    CEWP\Roles::create_default_roles();
    $r = ['missing WP role restored' => get_role('wholesale_customer') !== null,
          'first activation silent' => $wpdb->last_error === ''];
    $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
    $r['first activation inserts default once'] = count($rows) === 1 && $rows[0]['role_key'] === 'wholesale_customer' && $rows[0]['label'] === 'Wholesale Customer' && (int)$rows[0]['is_default'] === 1;
    foreach ([2, 3] as $attempt) {
        $wpdb->last_error = '';
        CEWP\Roles::create_default_roles();
        // Capture error BEFORE the count SELECT clears wpdb::last_error.
        $error = $wpdb->last_error;
        $r["activation $attempt silent (".$error.")"] = $error === '';
        $r["activation $attempt retains one row"] = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`") === 1;
    }
    $wpdb->update($table, ['label'=>'Admin custom label', 'is_default'=>0], ['role_key'=>'wholesale_customer']);
    $wpdb->insert($table, ['role_key'=>'other_dealer', 'label'=>'Other Dealer', 'is_default'=>0]);
    $before = $wpdb->get_results("SELECT * FROM `$table` ORDER BY id", ARRAY_A);
    remove_role('wholesale_customer');
    CEWP\Roles::create_default_roles();
    $r['edited activation silent'] = $wpdb->last_error === '';
    $r['deleted WP role restored with existing DB row'] = get_role('wholesale_customer') !== null;
    $r['admin edits and non-default dealer exactly preserved'] = $before === $wpdb->get_results("SELECT * FROM `$table` ORDER BY id", ARRAY_A);
    echo json_encode($r, JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $wp_roles = $saved_roles;
    $wpdb->set_prefix($original);
    $wpdb->query("DROP TABLE `$table`");
    $wpdb->suppress_errors($suppressed);
}
