<?php
if (PHP_SAPI !== 'cli') exit(2);
$load = getenv('CEWP_TEST_WP_LOAD');
if (!$load || !is_file($load) || basename(dirname($load)) !== 'testsite') throw new RuntimeException('Set CEWP_TEST_WP_LOAD to disposable testsite/wp-load.php');
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_URI'] = '/';
require $load;
if (!defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) throw new RuntimeException('Offline guards required');
add_filter('pre_http_request', static fn() => new WP_Error('test_offline','Network disabled by regression suite'), PHP_INT_MAX);
if (DB_NAME !== 'cewp_char' || realpath(ABSPATH) !== realpath(dirname($load))) throw new RuntimeException('Disposable database required');
global $wpdb;
foreach ($wpdb->get_results('SHOW TABLE STATUS') as $t) if ($t->Engine !== 'InnoDB') throw new RuntimeException('InnoDB required');
$wpdb->query('START TRANSACTION');
register_shutdown_function(function () use ($wpdb) { $wpdb->query('ROLLBACK'); });
$fixture = json_decode(file_get_contents(dirname($load).'/fixtures.json'),true,512,JSON_THROW_ON_ERROR);
$source = dirname(__DIR__);
$active = (new ReflectionClass(CEWP\Pricing::class))->getFileName();
if (basename(dirname(dirname($active))) !== 'coderembassy-wholesale-pricing-fix') throw new RuntimeException('Activate state F first');
require __DIR__.'/helpers.php';
wc_load_cart();
$coupon = new WC_Coupon('char-ten');
if (!$coupon->get_id()) { $coupon->set_code('char-ten'); $coupon->set_discount_type('percent'); $coupon->set_amount(10); $coupon->save(); }
foreach (['date_active'=>[-1,1],'date_future'=>[1,2],'date_expired'=>[-2,-1]] as $n=>[$a,$b]) $wpdb->update(CEWP\Database::t('prices'),['date_from'=>wp_date('Y-m-d',time()+$a*DAY_IN_SECONDS),'date_to'=>wp_date('Y-m-d',time()+$b*DAY_IN_SECONDS)],['product_id'=>$fixture['products'][$n]]);
