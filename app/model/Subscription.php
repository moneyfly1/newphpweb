<?php
declare (strict_types = 1);

namespace app\model;

class Subscription extends BaseModel
{
    protected $name = 'subscriptions';

    protected $type = [
        'user_id'          => 'integer',
        'package_id'       => 'integer',
        'source_order_id'  => 'integer',
        'device_limit'     => 'integer',
        'used_devices'     => 'integer',
        'traffic_total_gb' => 'integer',
        'traffic_used_gb'  => 'integer',
        'reset_count'      => 'integer',
    ];
}
