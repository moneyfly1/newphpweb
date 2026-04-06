<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\UserSetting;
use app\service\notify\EmailChannel;
use app\service\notify\TelegramChannel;
use app\service\notify\BarkChannel;
use think\facade\Log;

class NotificationService
{
    public const ORDER_CREATED = 'order_created';
    public const ORDER_PAID = 'order_paid';
    public const ORDER_CANCELLED = 'order_cancelled';
    public const ORDER_REFUNDED = 'order_refunded';
    public const SUBSCRIPTION_CREATED = 'subscription_created';
    public const SUBSCRIPTION_EXPIRING = 'subscription_expiring';
    public const SUBSCRIPTION_EXPIRED = 'subscription_expired';
    public const SUBSCRIPTION_RESET = 'subscription_reset';
    public const TICKET_CREATED = 'ticket_created';
    public const TICKET_REPLIED = 'ticket_replied';
    public const TICKET_CLOSED = 'ticket_closed';
    public const PASSWORD_CHANGED = 'password_changed';
    public const ACCOUNT_FROZEN = 'account_frozen';
    public const ACCOUNT_DELETED = 'account_deleted';

    private static ?EmailChannel $emailChannel = null;
    private static ?TelegramChannel $telegramChannel = null;
    private static ?BarkChannel $barkChannel = null;

    public static function notify(int $userId, string $event, array $data = []): void
    {
        try {
            self::email()->send($userId, $event, $data);
            self::sendTelegram($userId, $event, $data);
            self::sendBark($userId, $event, $data);
        } catch (\Throwable $e) {
            Log::error("通知发送失败 ({$event}, user:{$userId}): " . $e->getMessage());
        }
    }

    public static function notifyAdmins(string $event, array $data = []): void
    {
        try {
            $admins = User::where('role', 1)->where('status', 1)->select();
            foreach ($admins as $admin) {
                self::email()->send((int) $admin->id, $event, $data);
                self::sendTelegram((int) $admin->id, $event, $data);
                self::sendBark((int) $admin->id, $event, $data);
            }
        } catch (\Throwable $e) {
            Log::error("管理员通知失败 ({$event}): " . $e->getMessage());
        }
    }

    private static function sendTelegram(int $userId, string $event, array $data): void
    {
        $tg = self::telegram();
        if (!$tg->isConfigured()) { return; }
        $chatId = (string) (UserSetting::where('user_id', $userId)->where('item_key', 'telegram')->value('item_value') ?? '');
        if ($chatId === '') { return; }
        $tg->send($chatId, self::formatMessage($event, $data));
    }

    private static function sendBark(int $userId, string $event, array $data): void
    {
        $bark = self::bark();
        if (!$bark->isConfigured()) { return; }
        // Bark 使用系统级配置，推送给管理员；或用户自定义 bark_key
        $barkKey = (string) (UserSetting::where('user_id', $userId)->where('item_key', 'bark_key')->value('item_value') ?? '');
        if ($barkKey === '') {
            // 使用系统默认 bark key
            $barkKey = (string) app(AppConfigService::class)->get('notification.bark_key', '');
        }
        if ($barkKey === '') { return; }
        $icon = self::eventIcon($event);
        $title = $icon . ' ' . self::eventTitle($event);
        $body = self::formatPlainMessage($event, $data);
        $bark->send($barkKey, $title, $body);
    }

    private static function eventTitle(string $event): string
    {
        return match ($event) {
            self::ORDER_CREATED => '订单创建', self::ORDER_PAID => '支付成功',
            self::ORDER_CANCELLED => '订单取消', self::ORDER_REFUNDED => '订单退款',
            self::SUBSCRIPTION_CREATED => '订阅激活', self::SUBSCRIPTION_EXPIRING => '订阅即将到期',
            self::SUBSCRIPTION_EXPIRED => '订阅已过期', self::SUBSCRIPTION_RESET => '订阅已重置',
            self::TICKET_CREATED => '新工单', self::TICKET_REPLIED => '工单回复',
            self::TICKET_CLOSED => '工单关闭', self::PASSWORD_CHANGED => '密码已修改',
            self::ACCOUNT_FROZEN => '账户已冻结', self::ACCOUNT_DELETED => '账户已删除',
            default => '系统通知',
        };
    }

    private static function eventIcon(string $event): string
    {
        return match ($event) {
            self::ORDER_CREATED => '📦', self::ORDER_PAID => '✅',
            self::ORDER_CANCELLED => '❌', self::ORDER_REFUNDED => '💰',
            self::SUBSCRIPTION_CREATED => '🎉', self::SUBSCRIPTION_EXPIRING => '⏰',
            self::SUBSCRIPTION_EXPIRED => '⚠️', self::SUBSCRIPTION_RESET => '🔄',
            self::TICKET_CREATED => '📝', self::TICKET_REPLIED => '💬',
            self::TICKET_CLOSED => '🔒', self::PASSWORD_CHANGED => '🔑',
            self::ACCOUNT_FROZEN => '🚫', self::ACCOUNT_DELETED => '🗑',
            default => '📢',
        };
    }

    /**
     * Telegram 消息格式 — 使用 HTML 富文本
     */
    private static function formatMessage(string $event, array $data): string
    {
        $appName = app(AppConfigService::class)->appName();
        $icon = self::eventIcon($event);
        $title = self::eventTitle($event);
        $time = date('Y-m-d H:i:s');

        $lines = [
            "{$icon} <b>{$title}</b>",
            "<i>{$appName}</i> · {$time}",
            str_repeat('─', 20),
        ];

        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $lines[] = "▸ <b>{$key}</b>: {$value}";
            }
        }

        // 根据事件类型添加尾部提示
        $hint = match ($event) {
            self::ORDER_CREATED => '💡 请尽快完成支付',
            self::ORDER_PAID => '🎊 订阅服务已激活',
            self::SUBSCRIPTION_EXPIRING => '⚡ 请及时续费以免服务中断',
            self::SUBSCRIPTION_EXPIRED => '🔴 服务已暂停，请续费恢复',
            self::PASSWORD_CHANGED => '🛡 如非本人操作请立即联系客服',
            self::ACCOUNT_FROZEN => '📞 如有疑问请联系客服',
            default => null,
        };

        if ($hint) {
            $lines[] = '';
            $lines[] = $hint;
        }

        return implode("\n", $lines);
    }

    /**
     * Bark 消息格式 — 纯文本，简洁明了
     */
    private static function formatPlainMessage(string $event, array $data): string
    {
        $icon = self::eventIcon($event);
        $lines = [];

        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $lines[] = "{$key}: {$value}";
            }
        }

        $hint = match ($event) {
            self::ORDER_CREATED => '请尽快完成支付',
            self::ORDER_PAID => '订阅服务已激活',
            self::SUBSCRIPTION_EXPIRING => '请及时续费',
            self::PASSWORD_CHANGED => '如非本人操作请立即联系客服',
            default => null,
        };

        if ($hint) { $lines[] = $hint; }

        return implode("\n", $lines);
    }

    private static function email(): EmailChannel { return self::$emailChannel ??= new EmailChannel(); }
    private static function telegram(): TelegramChannel { return self::$telegramChannel ??= new TelegramChannel(); }
    private static function bark(): BarkChannel { return self::$barkChannel ??= new BarkChannel(); }
}