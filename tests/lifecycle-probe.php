<?php
// Fresh request; preinitialized WP hooks simulate a Pro listener loaded before Free.
$load=getenv('CEWP_TEST_WP_LOAD');
if (!$load || basename(dirname($load))!=='testsite') exit(2);
$_SERVER['HTTP_HOST']='127.0.0.1:8099'; $_SERVER['REQUEST_URI']='/';
$seen=[];
$late=[];
$GLOBALS['wp_filter']['plugins_loaded'][0][]=['function'=>function()use(&$late){
    // Plugin files have now loaded, but core services must not boot until priority 20.
    add_action('cewp_loaded',function($version)use(&$late){$late[]=$version;});
},'accepted_args'=>0];
$GLOBALS['wp_filter']['cewp_loaded'][10][]=['function'=>function($version)use(&$seen){
    global $wp_filter;
    $seen[]=['version'=>$version,'priority'=>$wp_filter['plugins_loaded']->current_priority(),
        'services'=>class_exists('CEWP\\Pricing',false),'count'=>did_action('cewp_loaded')];
},'accepted_args'=>1];
require $load;
if (DB_NAME!=='cewp_char' || !WP_HTTP_BLOCK_EXTERNAL || !DISABLE_WP_CRON) exit(3);
echo 'PROBE_JSON:'.json_encode(['events'=>$seen,'late'=>$late,'version'=>CEWP_VERSION,'count'=>did_action('cewp_loaded')]).PHP_EOL;
