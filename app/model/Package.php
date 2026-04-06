<?php
declare (strict_types = 1);

namespace app\model;

class Package extends BaseModel
{
    protected $name = 'packages';

    protected $type = [
        'price_monthly'    => 'float',
        'price_quarterly'  => 'float',
        'price_yearly'     => 'float',
        'device_limit'     => 'integer',
        'traffic_limit_gb' => 'integer',
        'speed_limit_mbps' => 'integer',
        'sort_order'       => 'integer',
        'is_active'        => 'integer',
    ];
}
