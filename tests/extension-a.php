<?php
// Task 19 / EXTENSION-API.md batch A. Use public hooks and existing entry points.
// Rows are isolated by the runner's DB savepoints; restore listeners and context cache too.
$a_hooks=[];
function a_hook($tag,$callback,$priority=10,$argc=1) {
    global $a_hooks;
    add_filter($tag,$callback,$priority,$argc);
    $a_hooks[]=[$tag,$callback,$priority];
}
function a_reset_context() {
    // Existing request-local cache must not leak between independently registered listeners.
    $p=new ReflectionProperty(CEWP\Pricing::class,'cache');
    $p->setValue(null,[]);
}
function a_test($id,$body) {
    global $tests;
    $tests[$id]=function()use($body){
        global $a_hooks,$warnings;
        $a_hooks=[];
        $warning_count=count($warnings);
        a_reset_context();
        try {$body();eq(count($warnings),$warning_count,'batch A adds no PHP warnings');} finally {
            foreach(array_reverse($a_hooks) as [$tag,$cb,$priority]) remove_filter($tag,$cb,$priority);
            a_reset_context();
        }
    };
}
function a_rule($extra=[]) {
    return array_merge(rule(pid('plain'),'fixed',60,'wholesale'),[
        'id'=>900001,'sale_price'=>null,'date_from'=>null,'date_to'=>null,
    ],$extra);
}
function a_payload($extra=[]) {
    $row=a_rule($extra);unset($row['id']);return $row;
}
function a_candidates($rows,&$calls) {
    a_hook('cewp_price_rules',function($rules,$base,$pid,$vid,$qty,$ctx)use($rows,&$calls){
        $calls++;
        eq($base,100,'candidate raw regular base');
        eq($pid,$rows[0]['product_id']===pid('variable')?pid('variable'):pid('plain'),'candidate product argument');
        check(is_array($ctx)&&isset($ctx['uid'],$ctx['roles'],$ctx['groups']),'candidate context shape');
        return $rows;
    },10,6);
}
function a_enable($types=['role','user','group']) {
    a_hook('cewp_allowed_scope_types',static fn($old)=>$types);
}
function a_fallback($rule,$source='test/category') {
    return ['matched'=>true,'rule'=>$rule,'source'=>$source];
}
function a_count() {global $wpdb;return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.CEWP\Database::t('prices'));}
function a_seed($row) {
    global $wpdb;
    unset($row['id']);
    eq($wpdb->insert(CEWP\Database::t('prices'),$row),1,'direct fixture insert');
    return (int)$wpdb->insert_id;
}
function a_csv($scope,$value,$via_rest=false) {
    $csv="product_id,scope_type,scope_value,price_type,price_value\n".pid('plain').",$scope,$value,fixed,43\n";
    if($via_rest) return api('POST','import/csv',['csv'=>$csv,'has_header'=>true]);
    return csv_probe($csv);
}

a_test('T-30',function(){
    ctx('guest');$seen=[];
    a_hook('cewp_user_context',function($c,$uid)use(&$seen){$seen=[$c,$uid];return $c;},10,2);
    eq(wc_get_product(pid('plain'))->get_price(),100,'guest retail');
    check(!empty($seen),'guest context hook fired');
    if($seen){eq($seen[0],['uid'=>0,'roles'=>[],'groups'=>[]],'guest normalized context');eq($seen[1],0,'guest uid argument');}
});
a_test('T-31',function(){
    ctx('wholesale');$uid=get_current_user_id();$trusted=(array)wp_get_current_user()->roles;sort($trusted);
    $calls=0;
    a_hook('cewp_user_context',function($c,$arg)use(&$calls,$uid){$calls++;eq($arg,$uid,'trusted uid argument');$c['uid']=99999999;$c['roles']=['administrator'];$c['groups']=[9,2,9,2];return $c;},10,2);
    $c=CEWP\Pricing::ctx();
    eq($calls,1,'context enrichment dispatched once');
    eq($c,['uid'=>$uid,'roles'=>$trusted,'groups'=>[2,9]],'groups normalized and identity immutable');
    CEWP\Pricing::ctx();eq($calls,1,'cached context avoids second dispatch');
});
a_test('T-32',function(){
    ctx('wholesale');a_enable();
    a_hook('cewp_user_context',function($c){$c['groups']=[7];return $c;});
    $uid=get_current_user_id();$calls=0;
    a_candidates([a_rule(['scope_type'=>'role','price_value'=>30]),a_rule(['id'=>900002,'scope_type'=>'group','scope_value'=>'7','price_value'=>50]),a_rule(['id'=>900003,'scope_type'=>'user','scope_value'=>(string)$uid,'price_value'=>70])],$calls);
    eq(CEWP\Pricing::resolve(100,pid('plain')),70,'user wins over group and cheaper role');
    eq($calls,1,'candidate hook fired once');
});
a_test('T-33',function(){
    ctx('wholesale');a_enable();a_hook('cewp_user_context',function($c){$c['groups']=[7];return $c;});$calls=0;
    a_candidates([a_rule(['scope_type'=>'role','price_value'=>30]),a_rule(['id'=>900002,'scope_type'=>'group','scope_value'=>'7','price_value'=>50])],$calls);
    eq(CEWP\Pricing::resolve(100,pid('plain')),50,'group wins over role');eq($calls,1,'candidate hook fired');
});
a_test('T-34',function(){
    ctx('wholesale');$calls=0;$parent=pid('variable');$vid=pid('variation_small');
    a_candidates([a_rule(['product_id'=>$parent,'price_value'=>30,'min_qty'=>5]),a_rule(['id'=>900002,'product_id'=>$parent,'variation_id'=>$vid,'price_value'=>70])],$calls);
    eq(CEWP\Pricing::resolve(100,$parent,$vid,5),70,'exact variation before parent higher tier');eq($calls,1,'candidate hook fired');
});
a_test('T-35',function(){
    ctx('wholesale');$calls=0;
    a_candidates([a_rule(['price_value'=>90]),a_rule(['id'=>900002,'price_value'=>75,'min_qty'=>5])],$calls);
    foreach([1=>90,4=>90,5=>75,6=>75]as$q=>$want)eq(CEWP\Pricing::resolve(100,pid('plain'),0,$q),$want,'injected tier q'.$q);
    check($calls>0,'candidate hook fired');
});
a_test('T-36',function(){
    ctx('wholesale');$calls=0;$today=current_time('Y-m-d');
    a_candidates([a_rule(['date_from'=>$today,'date_to'=>$today,'price_value'=>61])],$calls);
    eq(CEWP\Pricing::resolve(100,pid('plain')),61,'inclusive site-local start and end today');eq($calls,1,'candidate hook fired');
});
a_test('T-37',function(){
    ctx('wholesale');$calls=0;
    $future=wp_date('Y-m-d',time()+DAY_IN_SECONDS);$past=wp_date('Y-m-d',time()-DAY_IN_SECONDS);
    a_candidates([a_rule(['date_from'=>$future,'price_value'=>10]),a_rule(['id'=>900002,'date_to'=>$past,'price_value'=>20]),a_rule(['id'=>900003,'price_value'=>63])],$calls);
    eq(CEWP\Pricing::resolve(100,pid('plain')),63,'future and expired candidates rejected after filter');eq($calls,1,'candidate hook fired');
});
a_test('T-38',function(){
    ctx('wholesale');$calls=0;
    a_hook('cewp_price_rules',function()use(&$calls){$calls++;return [a_rule(['product_id'=>pid('fixed'),'price_value'=>1]),a_rule(['variation_id'=>pid('variation_small'),'price_value'=>2]),a_rule(['price_type'=>'unknown']),a_rule(['price_value'=>-1]),a_rule(['price_type'=>'percent_discount','price_value'=>101]),a_rule(['price_value'=>NAN]),a_rule(['min_qty'=>0]),['id'=>5],a_rule(['id'=>900009,'price_value'=>64])];},10,6);
    eq(CEWP\Pricing::resolve(100,pid('plain')),64,'malformed / foreign product / foreign variation rules rejected');eq($calls,1,'candidate hook fired');
});
a_test('T-39',function(){
    ctx('wholesale');a_enable();$calls=0;
    a_hook('cewp_price_rules',function()use(&$calls){$calls++;return [a_rule(['scope_type'=>'user','scope_value'=>'99999999','price_value'=>1]),a_rule(['scope_type'=>'group','scope_value'=>'123','price_value'=>2]),a_rule(['scope_value'=>'administrator','price_value'=>3]),a_rule(['id'=>900004,'price_value'=>65])];});
    eq(CEWP\Pricing::resolve(100,pid('plain')),65,'filtered rules recheck identity membership');eq($calls,1,'candidate hook fired');
});

a_test('T-40',function(){
    ctx('wholesale');$calls=[];
    a_hook('cewp_resolve_price',function($r,$base,$pid,$vid,$qty,$c,$miss)use(&$calls){
        $calls[]='category';eq($r,['matched'=>false,'rule'=>null,'source'=>null],'initial fallback');eq([$base,$pid,$vid,$qty,$c['uid'],$miss],[100.0,pid('plain'),0,1,get_current_user_id(),true],'fallback arguments');
        return a_fallback(a_rule(['price_type'=>'percent_discount','price_value'=>10]));
    },10,7);
    a_hook('cewp_resolve_price',function($r)use(&$calls){$calls[]='global';return $r['matched']?$r:a_fallback(a_rule(['price_type'=>'percent_discount','price_value'=>20]),'test/global');},20);
    eq(CEWP\Pricing::resolve(100,pid('plain')),90,'category 10 before global 20');
    check(isset($calls[0])&&$calls[0]==='category','category provider dispatched first');
});
a_test('T-41',function(){
    ctx('wholesale');$seen=[];
    a_hook('cewp_resolve_price',function($r)use(&$seen){$seen[]='category';return $r;},10);
    a_hook('cewp_resolve_price',function($r)use(&$seen){$seen[]='global';return a_fallback(a_rule(['price_type'=>'percent_discount','price_value'=>20]),'test/global');},20);
    eq(CEWP\Pricing::resolve(100,pid('plain')),80,'category miss uses global');eq($seen,['category','global'],'provider priority order');
});
a_test('T-42',function(){
    ctx('wholesale');$calls=0;
    a_hook('cewp_resolve_price',function($r)use(&$calls){$calls++;return $r;},10,7);
    eq(wc_get_product(pid('plain'))->get_price(),100,'all miss leaves retail unchanged');check($calls>0,'fallback dispatched on genuine miss');
});
a_test('T-43',function(){
    ctx('wholesale');$calls=0;
    a_hook('cewp_resolve_price',function($r)use(&$calls){$calls++;return a_fallback(a_rule(['price_value'=>0]));});
    eq(wc_get_product(pid('plain'))->get_price(),0,'zero fallback remains a match');check($calls>0,'zero provider dispatched');
});
a_test('T-44',function(){
    ctx('wholesale');$calls=0;
    // Later provider deliberately replaces the match: host must retain the first valid winner.
    a_hook('cewp_resolve_price',static fn($r)=>a_fallback(a_rule(['price_value'=>100])) ,10);
    a_hook('cewp_resolve_price',function($r)use(&$calls){$calls++;return a_fallback(a_rule(['price_value'=>20]),'test/global');},20);
    eq(CEWP\Pricing::resolve(100,pid('plain')),100,'equal-price category match is immutable');
});
a_test('T-45',function(){
    ctx('wholesale');$calls=0;
    a_hook('cewp_resolve_price',function($r)use(&$calls){$calls++;return a_fallback(a_rule(['price_type'=>'fixed_discount','price_value'=>10]));});
    foreach(cart(pid('plain'),2)[1]as$i=>$row)tuple($row,[90,90,180,180,180],'fallback applied once pass'.$i);
    eq(wc_get_product(pid('plain'))->get_price(),90,'catalog agrees with cart');check($calls>0,'fallback invoked');
});
a_test('T-46',function(){
    ctx('wholesale');$calls=0;$candidate_calls=0;
    a_candidates([a_rule(['price_value'=>100])],$candidate_calls);
    a_hook('cewp_resolve_price',function($r)use(&$calls){$calls++;return a_fallback(a_rule(['price_value'=>20]));});
    eq(CEWP\Pricing::resolve(100,pid('plain')),100,'equal-price product rule matches');eq($candidate_calls,1,'product candidate dispatched');eq($calls,0,'no fallback on product match');
});
a_test('T-47',function(){
    ctx('wholesale');$invalid=[['matched'=>true,'rule'=>null,'source'=>'test/bad'],a_fallback(a_rule(['price_value'=>-1])),a_fallback(a_rule(['price_value'=>NAN])),a_fallback(a_rule(),''),['matched'=>true,'price'=>1]];
    foreach($invalid as$i=>$bad){
        $seen=null;
        $category=static fn($r)=>$bad;
        $global=function($r)use(&$seen){$seen=$r;return $r['matched']?$r:a_fallback(a_rule(['price_value'=>82]),'test/global');};
        add_filter('cewp_resolve_price',$category,10);add_filter('cewp_resolve_price',$global,20);
        try {eq(CEWP\Pricing::resolve(100,pid('plain')),82,'invalid category cannot suppress global case'.$i);eq($seen,['matched'=>false,'rule'=>null,'source'=>null],'later provider sees valid unmatched state');}
        finally {remove_filter('cewp_resolve_price',$category,10);remove_filter('cewp_resolve_price',$global,20);}
    }
});

a_test('T-48',function(){
    admin_ctx();$seen=null;
    a_hook('cewp_allowed_scope_types',function($types)use(&$seen){$seen=$types;return $types;});
    api('POST','prices',a_payload(['scope_type'=>'user','scope_value'=>(string)get_current_user_id()]));
    eq($seen,['role'],'repository dispatches default allowed vocabulary');
});
a_test('T-49',function(){
    admin_ctx();global $fixture;
    foreach(['user'=>(string)$fixture['users']['wholesale'],'group'=>'7','unknown'=>'7']as$scope=>$value){$before=a_count();eq(api('POST','prices',a_payload(['scope_type'=>$scope,'scope_value'=>$value]))->get_status(),400,'disabled '.$scope.' REST 400');eq(a_count(),$before,'disabled scope writes nothing');}
});
a_test('T-50',function(){
    admin_ctx();global $fixture;
    foreach(['user'=>(string)$fixture['users']['wholesale'],'group'=>'7']as$scope=>$value){
        $before=a_count();[$q]=a_csv($scope,$value);eq((int)($q['imported']??-1),0,'disabled '.$scope.' CSV rejected');eq((int)($q['errors']??0),1,'CSV scope error');eq(a_count(),$before,'CSV no write');
        $r=a_csv($scope,$value,true);eq($r->get_status(),400,'REST CSV invalid scope 400');eq(a_count(),$before,'REST CSV no write');
    }
});
a_test('T-51',function(){
    admin_ctx();global $fixture;a_enable();$uid=(string)$fixture['users']['wholesale'];
    $r=api('POST','prices',a_payload(['scope_type'=>'user','scope_value'=>$uid]));eq($r->get_status(),200,'enabled existing user accepted');$id=(int)($r->get_data()['id']??0);
    check($id>0,'user row allocated');if($id){$d=api('GET','prices/'.$id)->get_data();eq($d['scope_type']??null,'user','user exact scope round trip');eq($d['scope_value']??null,$uid,'decimal user id round trip');}
});
a_test('T-52',function(){
    admin_ctx();global $fixture;a_enable();$uid=(string)$fixture['users']['wholesale'];
    [$q]=a_csv('user',$uid);eq((int)($q['imported']??0),1,'enabled user CSV accepted');
    global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.CEWP\Database::t('prices').' WHERE product_id=%d AND scope_value=%s',pid('plain'),$uid));
    check($row!==null,'CSV row exists');if($row)eq($row->scope_type,'user','CSV keeps user scope');
    // Reuse the same identity through the other CSV endpoint without a duplicate masking it.
    if($row)$wpdb->delete(CEWP\Database::t('prices'),['id'=>$row->id]);
    $r=a_csv('user',$uid,true);eq($r->get_status(),200,'enabled user REST CSV accepted');
    $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.CEWP\Database::t('prices').' WHERE product_id=%d AND scope_value=%s',pid('plain'),$uid));
    check($row!==null,'REST CSV row exists');if($row)eq($row->scope_type,'user','REST CSV preserves user scope');
});
a_test('T-53',function(){
    admin_ctx();a_enable();check(get_user_by('id',99999999)===false,'nonexistent fixture uid');
    foreach(['user'=>'99999999','group'=>'777']as$scope=>$value){$before=a_count();eq(api('POST','prices',a_payload(['scope_type'=>$scope,'scope_value'=>$value]))->get_status(),400,$scope.' identity rejected');eq(a_count(),$before,'invalid membership no write');[$q]=a_csv($scope,$value);eq((int)($q['imported']??-1),0,'CSV invalid membership rejected');eq(a_count(),$before,'CSV invalid membership no write');}
});
a_test('T-54',function(){
    admin_ctx();global $fixture;a_enable();$uid=(string)$fixture['users']['wholesale'];
    $role=api('POST','prices',a_payload(['scope_value'=>'wholesale']));eq($role->get_status(),200,'independent role identity');
    $user=api('POST','prices',a_payload(['scope_type'=>'user','scope_value'=>$uid]));eq($user->get_status(),200,'independent user identity');$id=(int)($user->get_data()['id']??0);$before=a_count();
    eq(api('POST','prices',a_payload(['scope_type'=>'user','scope_value'=>$uid,'min_qty'=>5]))->get_status(),400,'same scope identity duplicate despite tier');eq(a_count(),$before,'duplicate no extra row');
    if($id){eq(api('GET','prices/'.$id)->get_data()['scope_type']??null,'user','duplicate identity retained user scope');eq(api('PUT','prices/'.$id,a_payload(['scope_type'=>'user','scope_value'=>$uid]))->get_status(),200,'self update allowed');}
    $gid=a_seed(a_rule(['scope_type'=>'group','scope_value'=>'7']));
    eq(api('POST','prices',a_payload(['scope_type'=>'group','scope_value'=>'7']))->get_status(),400,'group duplicate or unvalidated group rejected');
    eq(CEWP\Database::get_price($gid)->scope_type,'group','group fixture preserved');
});
a_test('T-55',function(){
    global $fixture,$wpdb;ctx('wholesale');$uid=(string)$fixture['users']['wholesale'];
    $id=a_seed(a_rule(['scope_type'=>'user','scope_value'=>$uid,'price_value'=>22]));
    $gid=a_seed(a_rule(['scope_type'=>'group','scope_value'=>'7','price_value'=>23]));
    a_hook('cewp_user_context',function($c){$c['groups']=[7];return $c;});
    eq(wc_get_product(pid('plain'))->get_price(),100,'disabled stored user/group do not match');
    admin_ctx();$before=$wpdb->get_results('SELECT * FROM '.CEWP\Database::t('prices').' ORDER BY id',ARRAY_A);
    $list=api('GET','prices')->get_data();
    foreach($list['rows']??[]as$row)check(!in_array((int)$row->id,[$id,$gid],true),'disabled scopes absent from default list');
    foreach([$id,$gid]as$row_id){
        check(api('GET','prices/'.$row_id)->get_status()>=400,'disabled item not exposed');
        check(api('PUT','prices/'.$row_id,a_payload())->get_status()>=400,'role payload cannot overwrite disabled stored scope');
        check(api('DELETE','prices/'.$row_id)->get_status()>=400,'role-only operation cannot delete disabled scope');
    }
    eq($wpdb->get_results('SELECT * FROM '.CEWP\Database::t('prices').' ORDER BY id',ARRAY_A),$before,'disabled rows preserved byte for byte');
});
a_test('T-56',function(){
    ctx('wholesale');$today=current_time('Y-m-d');
    $invalid=[a_rule(['min_qty'=>5]),a_rule(['date_from'=>wp_date('Y-m-d',time()+DAY_IN_SECONDS)]),a_rule(['date_to'=>wp_date('Y-m-d',time()-DAY_IN_SECONDS)]),a_rule(['price_type'=>'percent_discount','price_value'=>101])];
    foreach($invalid as$i=>$bad){
        $category=static fn($r)=>a_fallback($bad);
        $global=static fn($r)=>$r['matched']?$r:a_fallback(a_rule(['price_value'=>83,'date_from'=>$today,'date_to'=>$today]),'test/global');
        add_filter('cewp_resolve_price',$category,10);add_filter('cewp_resolve_price',$global,20);
        try {eq(CEWP\Pricing::resolve(100,pid('plain')),83,'fallback rechecks dates quantity numeric range '.$i);}
        finally {remove_filter('cewp_resolve_price',$category,10);remove_filter('cewp_resolve_price',$global,20);}
    }
});
a_test('T-57',function(){
    global $wpdb;ctx('wholesale');$candidate_calls=0;$fallback_calls=0;
    $wpdb->update(CEWP\Database::t('prices'),['price_value'=>100],['product_id'=>pid('fixed')]);
    a_hook('cewp_price_rules',function($r)use(&$candidate_calls){$candidate_calls++;return $r;});
    a_hook('cewp_resolve_price',function($r)use(&$fallback_calls){$fallback_calls++;return a_fallback(a_rule(['price_value'=>1]));});
    eq(wc_get_product(pid('fixed'))->get_price(),100,'stored equal-retail product match');
    check($candidate_calls>0,'stored candidates pass through hook');eq($fallback_calls,0,'stored product match never dispatches fallback');
});
a_test('T-58',function(){
    ctx('wholesale');global $wpdb;$wpdb->delete(CEWP\Database::t('prices'),['product_id'=>pid('on_sale')]);$seen=[];
    a_hook('cewp_resolve_price',function($r,$base)use(&$seen){$seen[]=$base;return a_fallback(a_rule(['price_type'=>'percent_discount','price_value'=>10]));},10,2);
    eq(CEWP\Pricing::resolve(80,pid('on_sale')),80,'fallback uses regular 100 then retail ceiling 80');
    check($seen!==[],'sale fallback dispatched');foreach($seen as$base)eq($base,100,'fallback base is regular not active sale');
});
