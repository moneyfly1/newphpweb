<?php
declare (strict_types = 1);

namespace app\service;

use app\model\EmailQueue;
use think\facade\Log;

class MailService
{
    /**
     * 发送邮件（异步 - 入队）
     *
     * @param int|null $userId 用户ID
     * @param string $toEmail 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body 纯文本内容
     * @param string $bodyHtml HTML内容
     * @param string $type 邮件类型 (order, subscription, ticket, password-reset, announcement)
     * @param string $scheduleTime 延迟发送时间 (可选)
     * @return EmailQueue|bool
     */
    public static function queue(
        ?int $userId,
        string $toEmail,
        string $subject,
        string $body,
        string $bodyHtml = '',
        string $type = 'other',
        string $scheduleTime = null
    ): EmailQueue|bool
    {
        try {
            $emailData = [
                'user_id' => $userId,
                'to_email' => $toEmail,
                'subject' => $subject,
                'body' => $body,
                'body_html' => $bodyHtml ?: $body,
                'type' => $type,
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
                'scheduled_at' => $scheduleTime ? date('Y-m-d H:i:s', strtotime($scheduleTime)) : null,
            ];

            $email = EmailQueue::create($emailData);
            
            Log::info("邮件已入队: {$subject} -> {$toEmail}");
            
            return $email;
        } catch (\Exception $e) {
            Log::error("邮件入队失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 立即发送邮件（同步、用于紧急邮件）
     *
     * @param string $toEmail 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body 邮件内容
     * @param array $config SMTP配置（可选）
     * @return bool
     */
    public static function sendNow(
        string $toEmail,
        string $subject,
        string $body,
        array $config = []
    ): bool
    {
        try {
            // 获取系统SMTP配置
            $appConfig = new \app\service\AppConfigService();
            $smtpConfig = $config ?: [
                'host' => $appConfig->get('smtp_host', 'smtp.gmail.com'),
                'port' => $appConfig->get('smtp_port', 587),
                'encryption' => $appConfig->get('smtp_encryption', 'tls'),
                'username' => $appConfig->get('smtp_username', ''),
                'password' => $appConfig->get('smtp_password', ''),
                'fromAddress' => $appConfig->get('from_address', 'noreply@example.com'),
                'fromName' => $appConfig->get('from_name', 'System'),
            ];

            // 这里使用 PHP mail() 或 SwiftMailer 库
            // 示例使用 PHP mail()（生产环境建议使用 PHPMailer 或 SwiftMailer）
            $headers = "From: {$smtpConfig['fromName']} <{$smtpConfig['fromAddress']}>\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Reply-To: {$smtpConfig['fromAddress']}\r\n";

            $result = mail(
                $toEmail,
                $subject,
                $body,
                $headers
            );

            if ($result) {
                Log::info("邮件已发送: {$subject} -> {$toEmail}");
            } else {
                Log::error("邮件发送失败: {$subject} -> {$toEmail}");
            }

            return $result;
        } catch (\Exception $e) {
            Log::error("邮件发送异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 处理邮件队列（处理待发送的邮件）
     *
     * @param int $batchSize 批处理大小
     * @return array 处理统计
     */
    public static function processQueue(int $batchSize = 50): array
    {
        $stats = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'retried' => 0,
        ];

        try {
            // 获取待处理的邮件
            $pendingEmails = EmailQueue::getPending($batchSize);

            foreach ($pendingEmails as $email) {
                try {
                    // 发送邮件
                    $result = self::sendNow(
                        $email->to_email,
                        $email->subject,
                        $email->body_html,
                    );

                    if ($result) {
                        $email->markAsSent();
                        $stats['sent']++;
                    } else {
                        $email->markAsFailed('SMTP发送失败');
                        if ($email->shouldRetry()) {
                            $stats['retried']++;
                        } else {
                            $stats['failed']++;
                        }
                    }
                } catch (\Exception $e) {
                    $email->markAsFailed($e->getMessage());
                    if ($email->shouldRetry()) {
                        $stats['retried']++;
                    } else {
                        $stats['failed']++;
                    }
                    Log::error("处理队列中的邮件失败: " . $e->getMessage());
                }

                $stats['processed']++;
            }

            Log::info("邮件队列处理完成: " . json_encode($stats));

        } catch (\Exception $e) {
            Log::error("邮件队列处理异常: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * 清理已发送的邮件（可选保留天数）
     *
     * @param int $keepDays 保留天数（0=全部删除）
     * @return int 删除数量
     */
    public static function cleanupSentEmails(int $keepDays = 30): int
    {
        try {
            $query = EmailQueue::where('status', 'sent');

            if ($keepDays > 0) {
                $query->where('sent_at', '<', date('Y-m-d H:i:s', strtotime("-{$keepDays} days")));
            }

            $deleted = $query->delete();
            Log::info("已清理{$deleted}条已发送邮件记录");

            return $deleted;
        } catch (\Exception $e) {
            Log::error("清理已发送邮件失败: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 获取队列统计信息
     */
    public static function getQueueStats(): array
    {
        return EmailQueue::getQueueStats();
    }

    /**
     * 重试失败的邮件
     *
     * @param int $maxAttempts 最大重试次数
     * @return int 重试数量
     */
    public static function retryFailed(int $maxAttempts = 3): int
    {
        try {
            $failed = EmailQueue::where('status', 'failed')
                ->where('attempts', '<', $maxAttempts)
                ->get();

            $retried = 0;
            foreach ($failed as $email) {
                $email->status = 'pending';
                $email->attempts = $email->attempts - 1; // 重置计数以允许重试
                $email->scheduled_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                if ($email->save()) {
                    $retried++;
                }
            }

            Log::info("已重新入队{$retried}条失败邮件");
            return $retried;

        } catch (\Exception $e) {
            Log::error("失败邮件重试失败: " . $e->getMessage());
            return 0;
        }
    }
}
