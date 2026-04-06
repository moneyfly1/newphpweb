<?php
declare (strict_types = 1);

namespace app\model;

class BalanceLog extends BaseModel
{
    protected $name = 'balance_logs';

    protected $type = [
        'user_id'        => 'integer',
        'amount'         => 'float',
        'balance_before' => 'float',
        'balance_after'  => 'float',
        'ref_id'         => 'integer',
    ];
}
