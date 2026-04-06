<?php
declare (strict_types = 1);

namespace app\model;

class Payment extends BaseModel
{
    protected $name = 'payments';

    protected $type = [
        'order_id' => 'integer',
        'user_id'  => 'integer',
        'amount'   => 'float',
    ];
}
