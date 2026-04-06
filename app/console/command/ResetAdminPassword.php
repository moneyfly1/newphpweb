<?php
declare(strict_types=1);

namespace app\console\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;

class ResetAdminPassword extends Command
{
    protected function configure(): void
    {
        $this->setName('admin:reset-password')
            ->addArgument('email', Argument::REQUIRED, '管理员邮箱')
            ->addArgument('password', Argument::REQUIRED, '新密码')
            ->setDescription('重置管理员密码');
    }

    protected function execute(Input $input, Output $output): int
    {
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        if (strlen($password) < 6) {
            $output->error('密码长度不能少于6位');
            return 1;
        }

        $user = Db::table('users')
            ->where('email', $email)
            ->where('role', 1)
            ->find();

        if (!$user) {
            $output->error("未找到管理员账号: {$email}");
            return 1;
        }

        Db::table('users')
            ->where('id', $user['id'])
            ->update(['password_hash' => password_hash($password, PASSWORD_BCRYPT)]);

        $output->info("管理员 {$email} 密码已重置");
        return 0;
    }
}
