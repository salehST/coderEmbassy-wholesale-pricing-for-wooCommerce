<?php

if ( ! defined('ABSPATH') ) exit;

/** Read final dealer unit prices per quantity tier without changing caller state. */
function cewp_get_dealer_price_tiers( int $product_id, int $variation_id = 0, int $user_id = 0 ): array {
    return \CEWP\Pricing::dealer_price_tiers( $product_id, $variation_id, $user_id );
}
