<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------

use app\console\command\ProcessEmailQueue;
use app\console\command\ResetAdminPassword;
use app\console\command\Schedule;
use app\console\command\ValidateSubscriptionOutput;

return [
    // 指令定义
    'commands' => [
        ProcessEmailQueue::class,
        ValidateSubscriptionOutput::class,
        ResetAdminPassword::class,
        Schedule::class,
    ],
];
