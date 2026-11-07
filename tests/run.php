<?php
require __DIR__.'/bootstrap.php';
$tests=[];
foreach(['pricing','persistence','safety','extension-a','extension-b','resolved-price','visibility','price-tiers','default-roles','rest-urls'] as $group) require __DIR__.'/'.$group.'.php';
foreach (range(1,26) as $i) if (!isset($tests[sprintf('T-%02d',$i)])) throw new RuntimeException('Missing T-'.$i);
ksort($tests);
foreach($tests as $current=>$test) {
    $wpdb->query('SAVEPOINT test_case');
    delete_option('cewp_settings');
    try { $test(); } catch(Throwable $e) { check(false,get_class($e).': '.$e->getMessage()); }
    $wpdb->query('ROLLBACK TO SAVEPOINT test_case');
    wp_cache_flush();
    echo $current.' | '.(empty($failures[$current])?'green':'red').' | '.($checks[$current]??0).' assertions | '.($failures[$current][0]??'all assertions passed').PHP_EOL;
}
file_put_contents(__DIR__.'/last-results.json',json_encode(['checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$wpdb->query('ROLLBACK');
exit($failures?1:0);
