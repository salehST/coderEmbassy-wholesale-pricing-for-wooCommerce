<?php
// Isolated process: never boot WordPress, never connect a database.
define('ABSPATH',dirname(__DIR__).'/');
$mode=$argv[1]; $scenario=$argv[2]; $calls=[];
function get_option($k,$default=false){global $mode,$scenario;if($mode==='uninstall'){if($k==='cewp_delete_on_uninstall')return true;if($k==='cewp_settings')return $scenario==='unset'?[]:['delete_on_uninstall'=>$scenario!=='off'];}return $default;}
function delete_option($k){global $calls;$calls[]=['delete',$k];}
function get_plugins(){global $scenario;if($scenario==='failure')throw new RuntimeException('discovery unavailable');return in_array($scenario,['active','inactive'])?['coderembassy-wholesale-pricing-agency/coderembassy-wholesale-pricing-agency.php'=>['RequiresPlugins'=>'coderembassy-wholesale-pricing']]:[];}
function is_plugin_active($p){global $scenario;return $scenario==='active';}
function is_plugin_active_for_network($p){return false;}
function __( $text, $domain = null ) { return $text; }
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return (string)$v;}
// Only product 1 belongs to this isolated fixture; unknown ids must fail validation.
function wc_get_product($id){return (int)$id===1 ? (object)['id'=>1] : false;}
class Recorder {
    public $prefix='wp_';
    public $posts='wp_posts';
    public $insert_id=0;
    public bool $row_exists;
    public int|false $update_result;
    function __construct(){
        global $scenario;
        $this->row_exists=$scenario!=='missing';
        $this->update_result=match($scenario){'false'=>false,'missing','zero'=>0,default=>1};
    }
    function query($q){global $calls;$calls[]=['query',$q];return true;}
    function prepare($q,...$args){return $q;}
    // No conflicting rule; get_row represents the update target, independently.
    function get_var($q){return null;}
    function get_row($q){return $this->row_exists ? (object)['id'=>7,'product_id'=>1,'variation_id'=>0,'scope_type'=>'role','scope_value'=>'wholesale','price_type'=>'fixed','price_value'=>50] : null;}
    function update(...$a){global $calls;$calls[]=['update',...$a];return $this->update_result;}
}
$wpdb=new Recorder();
require dirname(__DIR__).'/includes/Database.php';
require dirname(__DIR__).'/includes/REST/Controller.php';
class WP_REST_Response {function __construct(public $data,public $status=200){}}
class WP_REST_Request implements ArrayAccess {function get_method(){return 'PUT';}function get_json_params(){return ['product_id'=>1,'price_type'=>'fixed','price_value'=>50,'scope_value'=>'wholesale'];}function offsetGet($k):mixed{return 7;}function offsetSet($k,$v):void{}function offsetExists($k):bool{return true;}function offsetUnset($k):void{}}
try {if($mode==='uninstall'){CEWP\Database::uninstall();echo json_encode(['calls'=>$calls]);}else{$r=CEWP\REST\Controller::handle_price(new WP_REST_Request());echo json_encode(['status'=>$r->status,'data'=>$r->data,'calls'=>$calls]);}}catch(Throwable $e){echo json_encode(['exception'=>$e->getMessage(),'calls'=>$calls]);}
