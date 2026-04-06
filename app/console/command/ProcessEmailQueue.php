<?php
declare (strict_types = 1);

namespace app\console\command;

use app\service\MailService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class ProcessEmailQueue extends Command
{
    protected function configure(): void
    {
        $this->setName('email:queue')
            ->setDescription('处理邮件队列');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('<info>开始处理邮件队列...</info>');

        try {
            // 处理待发送的邮件
            $batchSize = (int)$input->getOption('batch');
            $stats = MailService::processQueue($batchSize);

            $output->writeln("<info>处理完成：</info>");
            $output->writeln("  - 已处理: {$stats['processed']}");
            $output->writeln("  - 已发送: {$stats['sent']}");
            $output->writeln("  - 已重试: {$stats['retried']}");
            $output->writeln("  - 失败: {$stats['failed']}");

            // 可选：清理已发送的邮件
            if ($input->getOption('cleanup')) {
                $output->writeln('<info>清理已发送邮件...</info>');
                $deleted = MailService::cleanupSentEmails(30); // 保留30天内的记录
                $output->writeln("<info>已清理 {$deleted} 条记录</info>");
            }

            // 获取队列统计
            $queueStats = MailService::getQueueStats();
            $output->writeln('<info>队列状态：</info>');
            $output->writeln("  - 待发送: {$queueStats['pending']}");
            $output->writeln("  - 已发送: {$queueStats['sent']}");
            $output->writeln("  - 失败: {$queueStats['failed']}");
            $output->writeln("  - 成功率: {$queueStats['success_rate']}%");

        } catch (\Exception $e) {
            $output->error('处理邮件队列失败: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
