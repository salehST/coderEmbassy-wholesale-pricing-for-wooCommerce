<?php
function public_tiers($name,$variation=0,$user=0) {
    return cewp_get_dealer_price_tiers(pid($name),$variation,$user);
}
a_test('T-92',function(){
    foreach(['guest','retail'] as $c){ctx($c);eq(public_tiers('fixed'),[],$c.' empty');}
    ctx('wholesale');eq(public_tiers('plain'),[],'unmatched empty');
    eq(public_tiers('fixed'),[['min_qty'=>1,'price'=>60.0]],'fixed typed result');
});
a_test('T-93',function(){
    ctx('wholesale');eq(public_tiers('tier'),[['min_qty'=>1,'price'=>90.0],['min_qty'=>5,'price'=>75.0]],'tier thresholds');
    global $wpdb;$wpdb->insert(CEWP\Database::t('prices'),rule(pid('tier'),'fixed',1,'unrelated',['min_qty'=>3]));
    eq(array_column(public_tiers('tier'),'min_qty'),[1,5],'other role does not leak');
});
a_test('T-94',function(){
    global $wpdb;ctx('wholesale');$t=CEWP\Database::t('prices');$wpdb->delete($t,['product_id'=>pid('variable')]);
    foreach([[0,90,1],[0,75,5],[pid('variation_small'),85,1],[pid('variation_small'),70,5]]as[$v,$p,$q])
        $wpdb->insert($t,rule(pid('variable'),'fixed',$p,'wholesale',['variation_id'=>$v,'min_qty'=>$q]));
    eq(public_tiers('variable',pid('variation_small')),[['min_qty'=>1,'price'=>85.0],['min_qty'=>5,'price'=>70.0]],'exact variation');
    eq(public_tiers('variable',pid('variation_large')),[['min_qty'=>1,'price'=>90.0],['min_qty'=>5,'price'=>75.0]],'parent fallback');
});
a_test('T-95',function(){
    ctx('wholesale');eq(public_tiers('on_sale'),[['min_qty'=>1,'price'=>80.0]],'retail ceiling');
    eq(public_tiers('zero_cap'),[['min_qty'=>1,'price'=>90.0]],'D14 zero cap');
    global $wpdb;$wpdb->update(CEWP\Database::t('prices'),['price_value'=>0],['product_id'=>pid('fixed')]);
    eq(public_tiers('fixed'),[['min_qty'=>1,'price'=>0.0]],'fixed zero');
});
a_test('T-96',function(){
    ctx('wholesale');foreach([[0,0],[-1,0],[PHP_INT_MAX,0],[pid('fixed'),pid('variation_small')],[pid('variable'),pid('fixed')],[pid('variable'),PHP_INT_MAX],[pid('variation_small'),0]]as[$p,$v])
        eq(cewp_get_dealer_price_tiers($p,$v,0),[],'invalid product/variation');
});
a_test('T-97',function(){
    ctx('wholesale');foreach(public_tiers('tier')as$row){
        foreach(cart(pid('tier'),$row['min_qty'])[1]as$cart)eq($cart[1],$row['price'],'cart/API parity');
    }
    eq(wc_get_product(pid('tier'))->get_price(),90,'catalog unchanged');
    a_hook('cewp_resolved_price',static fn($p)=>$p*.5);
    eq(public_tiers('tier'),[['min_qty'=>1,'price'=>45.0],['min_qty'=>5,'price'=>37.5]],'final resolver conversion');
});
a_test('T-98',function(){
    global $fixture;ctx('retail');$before=wp_get_current_user();
    eq(public_tiers('fixed',0,$fixture['users']['wholesale']),[['min_qty'=>1,'price'=>60.0]],'explicit dealer');
    eq(wp_get_current_user(),$before,'current user object preserved');
    eq(wc_get_product(pid('fixed'))->get_price(),100,'current price cache preserved');
    ctx('wholesale');eq(public_tiers('fixed',0,$fixture['users']['retail']),[],'explicit retail');
    eq(public_tiers('fixed',0,PHP_INT_MAX),[],'nonexistent user');
    eq(wc_get_product(pid('fixed'))->get_price(),60,'dealer cache preserved');
});
a_test('T-101',function(){
    global $fixture;ctx('retail');$before=wp_get_current_user();
    $stack=$GLOBALS['wp_current_filter'];
    $cb=static function(){throw new RuntimeException('tier probe');};a_hook('cewp_price_rules',$cb);
    try{public_tiers('fixed',0,$fixture['users']['wholesale']);check(false,'expected exception');}catch(RuntimeException $e){eq($e->getMessage(),'tier probe','exception propagated');}
    eq(wp_get_current_user(),$before,'exception restores current user');remove_filter('cewp_price_rules',$cb);
    eq($GLOBALS['wp_current_filter'],$stack,'exception restores filter stack');
    eq(public_tiers('fixed',0,$fixture['users']['wholesale']),[['min_qty'=>1,'price'=>60.0]],'dispatch recovers');
});
a_test('T-102',function(){
    global $wpdb;ctx('wholesale');$t=CEWP\Database::t('prices');
    $wpdb->delete($t,['product_id'=>pid('tier'),'min_qty'=>1]);
    eq(public_tiers('tier'),[['min_qty'=>1,'price'=>100.0],['min_qty'=>5,'price'=>75.0]],'quantity one present before first eligible tier');
});
