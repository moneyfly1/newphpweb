<?php
declare (strict_types = 1);

namespace app\model;

class UserLevel extends BaseModel
{
    protected $name = 'user_levels';

    protected $type = [
        'min_consumption' => 'float',
        'discount_rate' => 'float',
    ];

    /**
     * 关联用户
     */
    public function users()
    {
        return $this->hasMany('User', 'user_level_id', 'id');
    }

    /**
     * 获取享受该等级的用户数
     */
    public function getUserCount()
    {
        return User::where('user_level_id', $this->id)->count();
    }

    /**
     * 根据消费金额获取应该的等级
     */
    public static function getLevelByConsumption(float $totalConsumption)
    {
        return self::where('is_active', 1)
            ->where('min_consumption', '<=', $totalConsumption)
            ->order('level_order', 'asc')
            ->find();
    }
}
