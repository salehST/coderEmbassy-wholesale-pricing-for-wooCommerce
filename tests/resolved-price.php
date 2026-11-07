<?php
// Task 24: post-resolution conversion uses the same result in every surface.
a_test('T-82',function(){
    ctx('wholesale');$calls=[];
    a_hook('cewp_resolved_price',function($price,$product,$variation,$qty,$context)use(&$calls){
        $calls[]=$qty;eq($price,90,'conversion receives resolved dealer price');
        eq($product,pid('percent'),'product argument');eq($variation,0,'variation argument');
        eq($context['uid'],get_current_user_id(),'trusted context');return $price*.5;
    },10,5);
    $p=wc_get_product(pid('percent'));eq($p->get_price(),45,'converted getter');eq($p->get_regular_price(),45,'converted regular');
    eq(text_html($p->get_price_html()),text_html(wc_price(45)),'converted catalog HTML');
    foreach([1,5]as$q)foreach(cart(pid('percent'),$q)[1]as$row)tuple($row,[45,45,45*$q,45*$q,45*$q],'converted repeated cart totals');
    eq($calls,[1,5],'once per quantity resolution across getters and totals');
});
a_test('T-83',function(){
    $calls=0;a_hook('cewp_resolved_price',function($p)use(&$calls){$calls++;return $p*.5;});
    ctx('guest');eq(wc_get_product(pid('percent'))->get_price(),100,'guest unchanged');
    ctx('wholesale');eq(wc_get_product(pid('plain'))->get_price(),100,'unmatched dealer unchanged');eq($calls,0,'no unmatched dispatch');
    eq(wc_get_product(pid('percent'))->get_price(),45,'matched dealer dispatch');eq($calls,1,'matched hook called');
});
a_test('T-84',function(){
    ctx('wholesale');$calls=0;$value=NAN;
    a_hook('cewp_resolved_price',function($p)use(&$calls,&$value){$calls++;return $value;});
    foreach([NAN,INF,-1,'bad',null,[]]as$invalid){$value=$invalid;a_reset_context();eq(CEWP\Pricing::resolve(100,pid('percent')),90,'invalid conversion ignored');}
    eq($calls,6,'invalid conversions were dispatched');
});
a_test('T-85',function(){
    ctx('wholesale');$seen=[];
    a_hook('cewp_resolve_price',static fn()=>a_fallback(a_rule(['price_type'=>'percent_discount','price_value'=>10])));
    a_hook('cewp_resolved_price',function($p)use(&$seen){$seen[]=$p;return $p*.5;});
    eq(CEWP\Pricing::resolve(100,pid('plain')),45,'fallback converted');
    eq(CEWP\Pricing::resolve(100,pid('on_sale')),40,'D8 ceiling before conversion');
    eq($seen,[90.0,80.0],'post-ceiling values');
});
a_test('T-86',function(){
    ctx('wholesale');$seen=null;$nested=1;$calls=0;
    a_hook('cewp_price_rules',static fn()=>[a_rule(['price_value'=>33.335])]);
    a_hook('cewp_resolved_price',function($p)use(&$seen,&$nested,&$calls){$calls++;$seen=$p;$nested=CEWP\Pricing::resolve(100,pid('plain'));return 0.0;});
    eq(CEWP\Pricing::resolve(100,pid('plain')),0,'zero is valid');eq($seen,33.34,'rounded before conversion');eq($nested,null,'resolver re-entry guarded');
    eq(CEWP\Pricing::resolve(100,pid('plain')),0,'zero cached');eq($calls,1,'zero dispatched once');
});
a_test('T-87',function(){
    ctx('wholesale');eq(CEWP\Pricing::resolve(100,pid('percent')),90,'before listener');
    $cb=static fn($p)=>$p*.5;a_hook('cewp_resolved_price',$cb);
    eq(CEWP\Pricing::resolve(100,pid('percent')),45,'listener registration changes cache identity');
    remove_filter('cewp_resolved_price',$cb);eq(CEWP\Pricing::resolve(100,pid('percent')),90,'listener removal restores original result');
});
