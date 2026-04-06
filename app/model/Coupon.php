<?php
declare (strict_types = 1);

namespace app\model;

class Coupon extends BaseModel
{
    protected $name = 'coupons';

    protected $type = [
        'discount_value'      => 'float',
        'max_discount_amount' => 'float',
        'min_order_amount'    => 'float',
        'total_limit'         => 'integer',
        'used_count'          => 'integer',
        'user_limit'          => 'integer',
        'status'              => 'integer',
    ];
}
