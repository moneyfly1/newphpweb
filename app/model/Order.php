<?php
declare (strict_types = 1);

namespace app\model;

class Order extends BaseModel
{
    protected $name = 'orders';

    protected $type = [
        'user_id'          => 'integer',
        'package_id'       => 'integer',
        'device_count'     => 'integer',
        'month_count'      => 'integer',
        'amount_original'  => 'float',
        'discount_amount'  => 'float',
        'amount_payable'   => 'float',
    ];
}
