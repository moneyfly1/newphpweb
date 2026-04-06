<?php
declare (strict_types = 1);

namespace app\model;

class SystemSetting extends BaseModel
{
    protected $name = 'system_settings';

    protected $type = [
        'autoload' => 'integer',
    ];
}
