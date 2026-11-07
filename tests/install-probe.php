<?php
define('ABSPATH',__DIR__.'/install-fixture/');
define('CEWP_VERSION','1.1.0');
$scenario=$argv[1]; $version=$scenario==='current'?CEWP_VERSION:'1.0.0';
$events=[]; $schema_calls=0;
// Use WordPress's real dispatcher, with an in-memory options/DB boundary.
$wp=dirname(getenv('CEWP_TEST_WP_LOAD'));
require $wp.'/wp-includes/plugin.php';
function get_option($k,$default=false){global $version;return $k==='cewp_db_version'?$version:$default;}
function update_option($k,$v){global $version;if($k==='cewp_db_version')$version=$v;return true;}
class InstallRecorder {
    public $prefix='wp_'; public $last_error='';
    function get_charset_collate(){return '';}
    function get_var($q){global $scenario;return $scenario==='failure'?null:'fixture';}
    function prepare($q,...$args){return $q;}
}
$wpdb=new InstallRecorder();
require dirname(__DIR__).'/includes/Database.php';
add_action('cewp_install',function($old,$new)use(&$events,&$version){$events[]=[$old,$new,$version];},10,2);
try { CEWP\Database::install_if_needed(); CEWP\Database::install_if_needed(); }
catch(Throwable $e){$exception=$e->getMessage();}
echo json_encode(['events'=>$events,'version'=>$version,'schema_calls'=>$schema_calls,'exception'=>$exception??null]);
