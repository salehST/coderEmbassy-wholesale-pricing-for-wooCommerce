<?php
// Task 21: batch B public contracts. No implementation helpers or source edits.
function b_child($file,$arg='') {
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file).' '.escapeshellarg($arg),$lines,$code);
    eq($code,0,$file.' child exit');
    $json=implode("\n",$lines);
    if(str_contains($json,'PROBE_JSON:'))$json=substr($json,strrpos($json,'PROBE_JSON:')+11);
    return json_decode($json,true,512,JSON_THROW_ON_ERROR);
}
a_test('T-60',function(){
    $r=b_child('lifecycle-probe.php');eq($r['count'],1,'loaded once with early Pro-style listener');
    eq($r['events'],[['version'=>$r['version'],'priority'=>20,'services'=>true,'count'=>1]],'loaded version and timing after services');
    eq($r['late'],[$r['version']],'listener after plugin files but before services also runs');
});
a_test('T-61',function(){
    $r=b_child('install-probe.php','upgrade');
    eq($r['events'],[['1.0.0','1.1.0','1.1.0']],'successful upgrade notifies after version saved exactly once');
    eq($r['version'],'1.1.0','schema version advanced');eq($r['exception'],null,'successful upgrade no exception');
});
a_test('T-62',function(){
    $r=b_child('install-probe.php','current');eq($r['events'],[],'no upgrade no notification');eq($r['schema_calls'],0,'current schema no DDL');
    $r=b_child('install-probe.php','failure');eq($r['events'],[],'failed schema never notifies');eq($r['version'],'1.0.0','failure remains retryable');
});
a_test('T-63',function(){
    global $wp_rest_server;$old=$wp_rest_server;$wp_rest_server=null;$seen=[];
    a_hook('cewp_register_rest_routes',function($ns,$auth)use(&$seen){
        $seen[]=[$ns,is_callable($auth),doing_action('rest_api_init')];
        register_rest_route($ns,'/example/secure',['methods'=>'GET','callback'=>static fn()=>['ok'=>true],'permission_callback'=>$auth]);
        register_rest_route($ns,'/example/currency',['methods'=>'GET','callback'=>static fn()=>['currency'=>'USD'],'permission_callback'=>'__return_true']);
    },10,2);
    try {
        admin_ctx();eq(api('GET','example/secure')->get_status(),200,'listener route registered');
        api('GET','example/secure');eq($seen,[['cewp/v1',true,true]],'REST hook once in initialization');
        ctx('guest');eq(api('GET','example/secure')->get_status(),401,'admin callback denies guest');
        eq(api('GET','example/currency')->get_status(),200,'public extension retains own auth');
        eq(api('GET','settings')->get_status(),401,'core management remains private');
    } finally {$wp_rest_server=$old;}
});
a_test('T-64',function(){
    global $wp_rest_server;$old=$wp_rest_server;$wp_rest_server=null;$calls=0;
    a_hook('cewp_register_rest_routes',function($ns,$auth)use(&$calls){$calls++;register_rest_route($ns,'/settings',['methods'=>'GET','callback'=>static fn()=>['hijacked'=>true],'permission_callback'=>'__return_true'],true);},10,2);
    try {admin_ctx();$d=api('GET','settings')->get_data();eq($calls,1,'overwrite attempt actually dispatched');check(empty($d['hijacked']),'extension cannot replace core route');ctx('guest');eq(api('GET','settings')->get_status(),401,'override cannot expose management');}
    finally {$wp_rest_server=$old;}
});
function b_tabs() {
    $scripts=wp_scripts();$old=$scripts->get_data('cewp-admin','data');
    try {CEWP\Admin\Menu::assets('toplevel_page_coderembassy-wholesale-pricing');$data=$scripts->get_data('cewp-admin','data');
        if(preg_match('/var CEWP = (.*);/',$data,$m))return json_decode($m[1],true,512,JSON_THROW_ON_ERROR)['tabs']??[];
        return [];
    } finally {$scripts->add_data('cewp-admin','data',$old);}
}
function b_tab($id,$order=50,$cap='manage_woocommerce') {return ['id'=>$id,'label'=>'Example','order'=>$order,'capability'=>$cap,'script_handle'=>'example-admin'];}
a_test('T-65',function(){
    admin_ctx();$calls=0;
    a_hook('cewp_admin_tabs',function($tabs,$ctx)use(&$calls){$calls++;check(is_array($ctx),'tab context');return array_merge($tabs,[b_tab('example/z',50),b_tab('example/a',50),b_tab('example/early',40)]);},10,2);
    $tabs=b_tabs();eq($calls,1,'tabs filter dispatched');$ids=array_column($tabs,'id');
    eq(array_values(array_filter($ids,static fn($id)=>str_starts_with($id,'example/'))),['example/early','example/a','example/z'],'order then id');
});
a_test('T-66',function(){
    admin_ctx();a_hook('cewp_admin_tabs',function($tabs){return array_merge($tabs,[b_tab('settings'),b_tab('example/one'),b_tab('example/one'),b_tab('not namespaced'),b_tab('example/secret',50,'nonexistent_capability'),['id'=>'example/broken']]);});
    $tabs=b_tabs();$ids=array_column($tabs,'id');eq(count(array_keys($ids,'example/one',true)),1,'duplicate extension rejected');
    foreach(['not namespaced','example/secret','example/broken']as$id)check(!in_array($id,$ids,true),'reject '.$id);
    eq(count(array_keys($ids,'settings',true)),1,'core tab retained exactly once');
});
a_test('T-67',function(){
    admin_ctx();$calls=0;a_hook('cewp_admin_tabs',function($tabs)use(&$calls){$calls++;return $tabs;});
    b_tabs();eq($calls,1,'authorized bootstrap establishes registry');$calls=0;ctx('retail');
    eq(b_tabs(),[],'unauthorized screen exposes no tabs');eq($calls,0,'unauthorized bootstrap never dispatches extension');
});
a_test('T-68',function(){
    exec('node '.escapeshellarg(__DIR__.'/admin-probe.js').' tabs',$lines,$code);eq($code,0,'tab adapter exit');
    $r=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
    foreach(['available','selectedOnly','duplicateRejected','coreRejected','unknownRejected','safeMissing']as$key)check($r[$key]??false,'JS registry '.$key);
});
function b_schema(&$calls) {
    a_hook('cewp_settings_schema',function($schema)use(&$calls){$calls++;$schema['example_enabled']=['type'=>'boolean','default'=>false,'sanitize_callback'=>'rest_sanitize_boolean','owner'=>'example'];return $schema;});
}
a_test('T-69',function(){admin_ctx();$calls=0;b_schema($calls);$d=api('GET','settings')->get_data();eq($d['example_enabled']??null,false,'extension default merged');api('GET','settings');eq($calls,1,'schema cached once');});
a_test('T-70',function(){
    admin_ctx();$calls=0;b_schema($calls);update_option('cewp_settings',['inactive_key'=>['keep'=>7],'catalog_mode'=>true]);
    eq(api('POST','settings',['example_enabled'=>true])->get_status(),200,'recognized extension write');
    $d=api('GET','settings')->get_data();eq($d['example_enabled']??null,true,'extension typed round trip');
    $s=get_option('cewp_settings');eq($s['inactive_key']??null,['keep'=>7],'inactive unknown preserved');eq($s['catalog_mode']??null,true,'partial POST preserves core');
});
a_test('T-71',function(){
    admin_ctx();$calls=0;b_schema($calls);update_option('cewp_settings',['catalog_mode'=>false,'inactive_key'=>7]);$before=get_option('cewp_settings');
    eq(api('POST','settings',['catalog_mode'=>true,'unknown_write'=>1])->get_status(),400,'unknown write rejected');eq(get_option('cewp_settings'),$before,'mixed invalid POST atomic');
});
a_test('T-72',function(){
    admin_ctx();a_hook('cewp_settings_schema',function($s){$s['catalog_mode']=['type'=>'string','default'=>'hijacked','sanitize_callback'=>'sanitize_text_field','owner'=>'example'];return $s;});
    eq(api('GET','settings')->get_data()['catalog_mode']??null,false,'core schema cannot be redefined');
    eq(api('POST','settings',['catalog_mode'=>['bad']])->get_status(),400,'invalid core type rejected');
});
a_test('T-73',function(){
    ctx('retail');$calls=0;a_hook('cewp_is_dealer',function($v,$c)use(&$calls){$calls++;eq($v,false,'stored retail eligibility');eq($c['uid']??null,get_current_user_id(),'trusted role context');return true;},10,2);
    eq(CEWP\Roles::is_dealer(),true,'extension eligibility applied');eq(CEWP\Roles::is_dealer(),true,'cached eligibility');eq($calls,1,'once per context');
});
a_test('T-74',function(){
    ctx('wholesale');$calls=0;a_hook('cewp_dealer_role',function($v,$c)use(&$calls){$calls++;check(isset($c['uid'],$c['roles'],$c['groups']),'role context shape');return 'vip';},10,2);
    eq(CEWP\Roles::get_user_dealer_role(),'vip','existing dealer role accepted');eq(CEWP\Roles::get_user_dealer_role(),'vip','selected role cached');eq($calls,1,'role once per context');
});
a_test('T-75',function(){
    ctx('wholesale');$bad=static fn()=> 'nonexistent_dealer_role';a_hook('cewp_dealer_role',$bad);
    eq(CEWP\Roles::get_user_dealer_role(),'wholesale','invalid role retains valid selection');
    remove_filter('cewp_dealer_role',$bad);a_reset_context();
    $before=CEWP\Pricing::resolve(100,pid('fixed'));$dealer=CEWP\Roles::is_dealer();
    a_hook('cewp_dealer_role',static fn()=>null);
    eq(CEWP\Roles::get_user_dealer_role(),null,'null explicitly clears selected role');
    eq(CEWP\Roles::is_dealer(),$dealer,'null selected role preserves eligibility');
    eq(CEWP\Pricing::resolve(100,pid('fixed')),$before,'null selected role preserves trusted multi-role pricing');
    check($before!==0 && $before!==null,'fixture keeps its existing exact role match');
});
a_test('T-76',function(){
    global $fixture;ctx('retail');$calls=0;a_hook('cewp_is_dealer',function($v)use(&$calls){$calls++;return $v;});
    eq(CEWP\Roles::is_dealer(),false,'retail before membership write');eq(CEWP\Roles::is_dealer(),false,'retail cached');
    $u=wp_get_current_user();$u->add_role('wholesale');
    try {eq(CEWP\Roles::is_dealer(),true,'membership write invalidates');eq($calls,2,'membership creates new context');}finally{$u->remove_role('wholesale');}
});
a_test('T-77',function(){
    ctx('wholesale');$calls=0;a_hook('cewp_dealer_role',function($v)use(&$calls){$calls++;return $v;});
    CEWP\Roles::get_user_dealer_role();CEWP\Roles::get_user_dealer_role();eq($calls,1,'cached before role write');
    CEWP\Database::save_role(['role_key'=>'example_dealer','label'=>'Example Dealer']);
    CEWP\Roles::get_user_dealer_role();eq($calls,2,'role repository write invalidates selection');
});
a_test('T-81',function(){
    ctx('retail');a_hook('cewp_is_dealer',static fn()=>['invalid']);
    eq(CEWP\Roles::is_dealer(),false,'malformed eligibility retains stored boolean');
    // A valid later listener must see the normalized previous value.
    $seen=null;a_hook('cewp_is_dealer',function($v)use(&$seen){$seen=$v;return true;},20);
    a_reset_context();eq(CEWP\Roles::is_dealer(),true,'later valid eligibility applied');eq($seen,false,'invalid listener cannot poison next value');
});
// Hardening uses a private test tag, with the real WP dispatcher and Pricing::filtered.
function b_filtered($value,$depth=0) {return CEWP\Pricing::filtered('cewp_test_nested',$value,[$depth],static fn($v)=>is_int($v));}
a_test('T-78',function(){
    $nested=null;$trace=[];a_hook('cewp_test_nested',function($v,$depth)use(&$nested,&$trace){$trace[]=$depth;if(!$depth)$nested=b_filtered(100,1);return $v+1;},10,2);
    eq(b_filtered(1),2,'outer dispatch isolated');eq($nested,101,'inner dispatch isolated');eq($trace,[0,1],'one callback per nesting level');
    eq(b_filtered(3),4,'subsequent dispatch restored');
});
a_test('T-79',function(){
    $laterCalls=0;$later=function($v)use(&$laterCalls){$laterCalls++;return $v+100;};
    a_hook('cewp_test_nested',function($v)use($later){remove_filter('cewp_test_nested',$later,20);return $v+1;});a_hook('cewp_test_nested',$later,20);
    eq(b_filtered(1),2,'removed listener skipped in dispatch');eq(has_filter('cewp_test_nested',$later),false,'removal persists after dispatch');
    eq(b_filtered(2),3,'removed listener stays removed');eq($laterCalls,0,'removed listener never called');
});
a_test('T-80',function(){
    global $wp_current_filter;$stack=$wp_current_filter;$cb=static function(){throw new RuntimeException('expected listener failure');};a_hook('cewp_test_nested',$cb);
    try {b_filtered(1);check(false,'exception must propagate');}catch(RuntimeException $e){eq($e->getMessage(),'expected listener failure','original exception');}
    check(has_filter('cewp_test_nested',$cb)!==false,'callback identity restored after throw');
    eq($wp_current_filter,$stack,'dispatcher stack restored after throw');
    remove_filter('cewp_test_nested',$cb);eq(b_filtered(7),7,'next dispatch works after throw');
    // WP itself does not unwind this global on exceptions; restore fixture isolation.
    $wp_current_filter=$stack;
});
