<?php
declare (strict_types = 1);

namespace app\model;

class AuditLog extends BaseModel
{
    protected $name = 'audit_logs';
    protected $updateTime = null;

    protected $type = [
        'actor_user_id' => 'integer',
        'target_id'     => 'integer',
    ];
}
