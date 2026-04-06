<?php
declare (strict_types = 1);

namespace app\model;

class Ticket extends BaseModel
{
    protected $name = 'tickets';

    protected $type = [
        'user_id'  => 'integer',
        'admin_id' => 'integer',
    ];
}
