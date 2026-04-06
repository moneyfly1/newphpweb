<?php
declare(strict_types=1);

namespace app\service\notify;

use app\service\AppConfigService;
use app\service\MailService;
use app\model\User;
use app\model\UserSetting;
use think\facade\Log;

class EmailChannel
{
    public function send(int $userId, string $event, array $data): bool
    {
        $user = User::find($userId);
        if (!$user || !$user->email) {
            return false;
        }

        // Check user preference
        if (!$this->userWantsEmail($userId, $event)) {
            return false;
        }

        // Check system switch
        if (!$this->systemAllowsEmail($event)) {
            return false;
        }

        $template = $this->getTemplate($event);
        if (!$template) {
            return false;
        }

        $subject = $template['subject'];
        $body = $this->renderTemplate($template['view'], array_merge($data, [
            'appName'  => app(AppConfigService::class)->appName(),
            'userName' => $user->nickname ?: $user->email,
        ]));

        try {
            MailService::queue($userId, $user->email, $subject, strip_tags($body), $body, $template['type']);
            return true;
        } catch (\Throwable $e) {
            Log::error("邮件通知失败 ({$event}): " . $e->getMessage());
            return false;
        }
    }

    private function userWantsEmail(int $userId, string $event): bool
    {
        $prefs = UserSetting::where('user_id', $userId)->column('item_value', 'item_key');
        $map = [
            'order_created' => 'email_order', 'order_paid' => 'email_order',
            'order_cancelled' => 'email_order', 'order_refunded' => 'email_order',
            'subscription_expiring' => 'email_subscription', 'subscription_expired' => 'email_subscription',
            'subscription_created' => 'email_subscription', 'subscription_reset' => 'email_subscription',
            'ticket_replied' => 'email_ticket', 'ticket_created' => 'email_ticket',
        ];
        $key = $map[$event] ?? null;
        if (!$key) { return true; }
        $val = $prefs[$key] ?? '1';
        return $val === '1' || $val === 'true';
    }

    private function systemAllowsEmail(string $event): bool
    {
        $config = app(AppConfigService::class);
        $map = [
            'order_created' => 'email_order_notification', 'order_paid' => 'email_order_notification',
            'subscription_expiring' => 'email_subscription_notification',
            'subscription_expired' => 'email_subscription_notification',
            'ticket_replied' => 'email_ticket_reply_notification',
            'ticket_created' => 'email_ticket_reply_notification',
        ];
        $key = $map[$event] ?? null;
        if (!$key) { return true; }
        return (bool) $config->get('notification.' . $key, 1);
    }

    private function getTemplate(string $event): ?array
    {
        $templates = [
            'order_created'          => ['subject' => '订单创建通知', 'view' => 'email/order_created', 'type' => 'order'],
            'order_paid'             => ['subject' => '订单支付成功', 'view' => 'email/order_paid', 'type' => 'order'],
            'subscription_expiring'  => ['subject' => '订阅即将到期提醒', 'view' => 'email/subscription_expiring', 'type' => 'subscription'],
            'subscription_expired'   => ['subject' => '订阅已过期', 'view' => 'email/subscription_expired', 'type' => 'subscription'],
            'ticket_replied'         => ['subject' => '工单回复通知', 'view' => 'email/ticket_replied', 'type' => 'ticket'],
            'password_changed'       => ['subject' => '密码修改通知', 'view' => 'email/password_changed', 'type' => 'notification'],
            'account_frozen'         => ['subject' => '账户冻结通知', 'view' => 'email/account_frozen', 'type' => 'notification'],
        ];
        return $templates[$event] ?? null;
    }

    private function renderTemplate(string $view, array $data): string
    {
        try {
            $file = app()->getRootPath() . 'view/' . str_replace('.', '/', $view) . '.html';
            if (!file_exists($file)) { return $this->fallbackRender($data); }
            $html = file_get_contents($file);
            foreach ($data as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $html = str_replace('{$' . $key . '}', (string) $value, $html);
                }
            }
            return $html;
        } catch (\Throwable $e) {
            return $this->fallbackRender($data);
        }
    }

    private function fallbackRender(array $data): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) { $lines[] = "{$key}: {$value}"; }
        }
        return implode("\n", $lines);
    }
}