<?php
declare (strict_types = 1);

namespace app\model;

class InviteRecord extends BaseModel
{
    protected $name = 'invite_records';

    protected $type = [
        'inviter_user_id' => 'integer',
        'invited_user_id' => 'integer',
        'order_id'        => 'integer',
        'reward_amount'   => 'float',
    ];
}
