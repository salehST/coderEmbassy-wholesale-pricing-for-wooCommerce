<?php
// Task 45: exercise the catalog query and single-product redirect entry points.
function v_catalog() {
    $q=new WP_Query();$q->is_singular=false;$q->set('post_type','product');
    $old=$GLOBALS['wp_the_query'];$GLOBALS['wp_the_query']=$q;
    try { CEWP\Visibility::hide_retail_only_from_wholesale($q);return $q->get('post__in'); }
    finally {$GLOBALS['wp_the_query']=$old;}
}
function v_single($id) {
    $oldq=$GLOBALS['wp_query'];$oldpost=$GLOBALS['post']??null;
    $q=new WP_Query();$q->is_singular=true;$q->queried_object=get_post($id);$q->queried_object_id=$id;
    $GLOBALS['wp_query']=$q;$GLOBALS['post']=get_post($id);$redirect=false;
    $cb=function($url)use(&$redirect){$redirect=true;throw new RuntimeException('visibility_redirect');};
    add_filter('wp_redirect',$cb,999);
    try {CEWP\Visibility::maybe_redirect_retail_only_single();}
    catch(RuntimeException $e){if($e->getMessage()!=='visibility_redirect')throw $e;}
    finally {remove_filter('wp_redirect',$cb,999);$GLOBALS['wp_query']=$oldq;$GLOBALS['post']=$oldpost;}
    return $redirect;
}
a_test('T-88',function(){
    ctx('wholesale');update_option('cewp_settings',['hide_retail_from_wholesale'=>'1']);
    $base=CEWP\Database::get_product_ids_with_role_rules(['wholesale']);
    eq(v_catalog(),$base,'no listener catalog parity');
    eq(v_single(pid('plain')),true,'no listener retail-only single redirects');
    eq(v_single(pid('percent')),false,'no listener rule single allowed');
});
a_test('T-89',function(){
    ctx('wholesale');update_option('cewp_settings',['hide_retail_from_wholesale'=>'1']);$calls=0;
    a_hook('cewp_visible_product_ids',function($ids,$ctx)use(&$calls){$calls++;
        check(in_array(pid('percent'),$ids,true),'role ids supplied');
        eq($ctx['uid'],get_current_user_id(),'trusted uid');check(in_array('wholesale',$ctx['roles'],true),'trusted roles');
        return [pid('plain'),pid('plain')];
    },10,2);
    eq(v_catalog(),[pid('plain')],'listener replaces catalog ids and deduplicates');
    eq(v_single(pid('plain')),false,'added single allowed');eq(v_single(pid('percent')),true,'removed single redirects');eq($calls,3,'both surfaces dispatch');
});
a_test('T-90',function(){
    ctx('wholesale');update_option('cewp_settings',['hide_retail_from_wholesale'=>'1']);$value=null;$calls=0;
    a_hook('cewp_visible_product_ids',function($ids)use(&$value,&$calls){$calls++;return $value;});
    $base=CEWP\Database::get_product_ids_with_role_rules(['wholesale']);
    foreach([null,false,'bad',[0],[-1],[1.5],[true],['12'],[PHP_INT_MAX],[wp_insert_post(['post_type'=>'page','post_title'=>'Visibility invalid product','post_status'=>'publish'])],[pid('plain'),0]] as $bad){
        $value=$bad;eq(v_catalog(),$base,'invalid return retains role list');
    }
    $value=[];eq(v_catalog(),[0],'explicit empty hides all');eq(v_single(pid('percent')),true,'empty single redirects');
    check($calls>0,'validation test dispatched');
});
a_test('T-91',function(){
    ctx('retail');update_option('cewp_settings',['hide_retail_from_wholesale'=>'1']);$calls=0;
    a_hook('cewp_visible_product_ids',function($ids)use(&$calls){$calls++;return [];});
    v_catalog();v_single(pid('plain'));ctx('guest');v_catalog();v_single(pid('plain'));
    ctx('wholesale');update_option('cewp_settings',['hide_retail_from_wholesale'=>'0']);v_catalog();v_single(pid('plain'));
    eq($calls,0,'nondealers and disabled setting do not dispatch');
});
