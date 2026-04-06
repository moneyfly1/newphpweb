<?php
declare (strict_types = 1);

namespace app\model;

class User extends BaseModel
{
    protected $name = 'users';

    protected $type = [
        'balance' => 'float',
        'total_consumption' => 'float',
    ];

    /**
     * 关联用户等级
     */
    public function userLevel()
    {
        return $this->belongsTo('UserLevel', 'user_level_id', 'id');
    }

    /**
     * 关联订阅
     */
    public function subscriptions()
    {
        return $this->hasMany('Subscription', 'user_id', 'id');
    }

    /**
     * 关联工单
     */
    public function tickets()
    {
        return $this->hasMany('Ticket', 'user_id', 'id');
    }

    /**
     * 关联订单
     */
    public function orders()
    {
        return $this->hasMany('Order', 'user_id', 'id');
    }
}
