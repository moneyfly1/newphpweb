<?php
declare (strict_types = 1);

namespace app\model;

class TicketReply extends BaseModel
{
    protected $name = 'ticket_replies';
    protected $updateTime = null;

    protected $type = [
        'ticket_id'   => 'integer',
        'replier_id'  => 'integer',
    ];
}
