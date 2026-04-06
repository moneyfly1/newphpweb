<?php
declare(strict_types=1);

namespace app\service;

use app\model\AuditLog;
use app\model\SystemSetting;

class AdminSettingsService
{
    public function __construct(
        private readonly AppConfigService $config
    ) {}

    public function adminSiteSettings(): array
    {
        return [
            'app_name'              => (string) $this->config->get('site.app_name', $this->config->appName()),
            'base_url'              => (string) $this->config->get('site.base_url', $this->config->baseUrl()),
            'landing_headline'      => (string) $this->config->get('site.landing_headline', '低资源部署友好的代理服务平台'),
            'landing_blurb'         => (string) $this->config->get('site.landing_blurb', '用户端、订单、订阅、工单和后台设置全部落到同一套 PHP + MySQL 系统中。'),
            'login_notice'          => (string) $this->config->get('site.login_notice', '这是一个真实网站控制台，适合轻量 VPS 部署。'),
            'hero_stat_one'         => (string) $this->config->get('site.hero_stat_one', '0.5G VPS 可部署'),
            'hero_stat_two'         => (string) $this->config->get('site.hero_stat_two', 'PHP + MySQL'),
            'hero_stat_three'       => (string) $this->config->get('site.hero_stat_three', '后台控制前台'),
            'landing_notice'        => (string) $this->config->get('site.landing_notice', '后台各项设置都可以直接控制前台显示和业务开关。'),
            'shop_note'             => (string) $this->config->get('site.shop_note', '套餐价格、支付方式、签到奖励由后台统一控制。'),
            'notice_text'           => (string) $this->config->get('site.notice_text', '请在后台持续维护系统配置。'),
            'support_email'         => (string) $this->config->get('site.support_email', ''),
            'support_qq'            => (string) $this->config->get('site.support_qq', ''),
            'subscription_site_url' => (string) $this->config->get('site.subscription_site_url', (string) $this->config->get('site.base_url', $this->config->baseUrl())),
            'subscription_site_name'=> (string) $this->config->get('site.subscription_site_name', (string) $this->config->get('site.app_name', $this->config->appName())),
            'subscription_notice'   => (string) $this->config->get('site.subscription_notice', ''),
            'checkin_reward'        => (string) $this->config->get('business.checkin_reward', 1),
            'extra_device_price'    => (string) $this->config->get('business.extra_device_price', 8),
            'balance_convert_rate'  => (string) $this->config->get('business.balance_convert_rate', 1),
            'payment_enabled_text'  => implode(',', $this->config->paymentMethods() ? array_column($this->config->paymentMethods(), 'code') : ['manual']),
        ];
    }

    public function saveSiteSettings(array $payload): void
    {
        // 验证应用名称
        $appName = trim((string) ($payload['app_name'] ?? $this->config->appName()));
        if (empty($appName)) {
            throw new \RuntimeException('应用名称不能为空。');
        }
        if (strlen($appName) > 100) {
            throw new \RuntimeException('应用名称不能超过100个字符。');
        }

        // 验证基础 URL
        $baseUrl = rtrim((string) ($payload['base_url'] ?? $this->config->baseUrl()), '/');
        if (!empty($baseUrl) && !preg_match('/^https?:\/\/.+/', $baseUrl)) {
            throw new \RuntimeException('基础URL必须以http://或https://开头。');
        }

        // 验证邮箱
        $supportEmail = trim((string) ($payload['support_email'] ?? ''));
        if (!empty($supportEmail) && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('支持邮箱格式不正确。');
        }

        $supportQq = trim((string) ($payload['support_qq'] ?? ''));
        if ($supportQq !== '' && !preg_match('/^[1-9][0-9]{4,19}$/', $supportQq)) {
            throw new \RuntimeException('客服QQ格式不正确。');
        }

        $subscriptionSiteUrl = rtrim((string) ($payload['subscription_site_url'] ?? $baseUrl), '/');
        if ($subscriptionSiteUrl !== '' && !preg_match('/^https?:\/\/.+/', $subscriptionSiteUrl)) {
            throw new \RuntimeException('订阅显示站点地址必须以http://或https://开头。');
        }

        $subscriptionSiteName = trim((string) ($payload['subscription_site_name'] ?? $appName));
        $checkinReward = (float) ($payload['checkin_reward'] ?? 1);
        if ($checkinReward < 0 || $checkinReward > 10000) {
            throw new \RuntimeException('签到奖励必须在0-10000之间。');
        }

        $extraDevicePrice = (float) ($payload['extra_device_price'] ?? 8);
        if ($extraDevicePrice < 0 || $extraDevicePrice > 10000) {
            throw new \RuntimeException('额外设备价格必须在0-10000之间。');
        }

        $balanceConvertRate = (float) ($payload['balance_convert_rate'] ?? 1);
        if ($balanceConvertRate <= 0 || $balanceConvertRate > 100) {
            throw new \RuntimeException('余额转换率必须在0-100之间（不含0）。');
        }

        $map = [
            'site.app_name'               => $appName,
            'site.base_url'               => $baseUrl,
            'site.landing_headline'       => trim((string) ($payload['landing_headline'] ?? '')),
            'site.landing_blurb'          => trim((string) ($payload['landing_blurb'] ?? '')),
            'site.login_notice'           => trim((string) ($payload['login_notice'] ?? '')),
            'site.hero_stat_one'          => trim((string) ($payload['hero_stat_one'] ?? '')),
            'site.hero_stat_two'          => trim((string) ($payload['hero_stat_two'] ?? '')),
            'site.hero_stat_three'        => trim((string) ($payload['hero_stat_three'] ?? '')),
            'site.landing_notice'         => trim((string) ($payload['landing_notice'] ?? '')),
            'site.shop_note'              => trim((string) ($payload['shop_note'] ?? '')),
            'site.notice_text'            => trim((string) ($payload['notice_text'] ?? '')),
            'site.support_email'          => $supportEmail,
            'site.support_qq'             => $supportQq,
            'site.subscription_site_url'  => $subscriptionSiteUrl,
            'site.subscription_site_name' => $subscriptionSiteName,
            'site.subscription_notice'    => trim((string) ($payload['subscription_notice'] ?? '')),
            'business.checkin_reward'     => $checkinReward,
            'business.extra_device_price' => $extraDevicePrice,
            'business.balance_convert_rate' => $balanceConvertRate,
            'payment.enabled_methods'     => array_values(array_filter(array_map('trim', explode(',', (string) ($payload['payment_enabled_text'] ?? 'manual'))))),
        ];

        foreach ($map as $key => $value) {
            [$group, $item] = explode('.', $key, 2);
            $this->config->set($group, $item, $value);
        }
    }

    /**
     * 获取所有高级系统设置
     */
    public function advancedSettings(): array
    {
        return [
            'system' => [
                'maintenance_mode' => $this->config->get('system.maintenance_mode', 0),
                'allow_registration' => $this->config->get('system.allow_registration', 1),
                'require_email_verification' => $this->config->get('system.require_email_verification', 0),
                'login_fail_limit' => $this->config->get('system.login_fail_limit', 5),
                'session_timeout_minutes' => $this->config->get('system.session_timeout_minutes', 480),
                'backup_schedule_cron' => $this->config->get('system.backup_schedule_cron', '0 2 * * *'),
            ],
            'security' => [
                'password_min_length' => $this->config->get('security.password_min_length', 6),
                'require_password_special_char' => $this->config->get('security.require_password_special_char', 0),
                'password_expiry_days' => $this->config->get('security.password_expiry_days', 0),
                'mfa_enabled' => $this->config->get('security.mfa_enabled', 0),
                'rate_limit_enabled' => $this->config->get('security.rate_limit_enabled', 1),
                'rate_limit_requests' => $this->config->get('security.rate_limit_requests', 100),
                'rate_limit_window_seconds' => $this->config->get('security.rate_limit_window_seconds', 60),
            ],
        ];
    }

    /**
     * 保存高级系统设置（完整实现）
     */
    public function saveAdvancedSettings(array $payload, int $adminId = 0): array
    {
        // ==================== 系统设置 ====================

        if (isset($payload['maintenance_mode'])) {
            $this->upsertSystemSetting('maintenance_mode', (int) $payload['maintenance_mode']);
        }

        if (isset($payload['allow_registration'])) {
            $this->upsertSystemSetting('allow_registration', (int) $payload['allow_registration']);
        }

        if (isset($payload['require_email_verification'])) {
            $this->upsertSystemSetting('require_email_verification', (int) $payload['require_email_verification']);
        }

        if (isset($payload['login_fail_limit'])) {
            $limit = (int) $payload['login_fail_limit'];
            if ($limit < 1 || $limit > 100) {
                throw new \RuntimeException('登录失败限制必须在1-100之间。');
            }
            $this->upsertSystemSetting('login_fail_limit', $limit);
        }

        if (isset($payload['session_timeout_minutes'])) {
            $timeout = (int) $payload['session_timeout_minutes'];
            if ($timeout < 5 || $timeout > 1440) {
                throw new \RuntimeException('会话超时必须在5-1440分钟之间。');
            }
            $this->upsertSystemSetting('session_timeout_minutes', $timeout);
        }

        if (isset($payload['backup_schedule_cron'])) {
            $cron = trim((string) $payload['backup_schedule_cron']);
            if (strlen($cron) > 100) {
                throw new \RuntimeException('备份计划Cron表达式过长。');
            }
            $this->upsertSystemSetting('backup_schedule_cron', $cron);
        }

        // ==================== 安全设置 ====================

        if (isset($payload['password_min_length'])) {
            $length = (int) $payload['password_min_length'];
            if ($length < 4 || $length > 128) {
                throw new \RuntimeException('密码最小长度必须在4-128之间。');
            }
            $this->upsertSystemSetting('password_min_length', $length);
        }

        if (isset($payload['require_password_special_char'])) {
            $this->upsertSystemSetting('require_password_special_char', (int) $payload['require_password_special_char']);
        }

        if (isset($payload['password_expiry_days'])) {
            $days = (int) $payload['password_expiry_days'];
            if ($days < 0 || $days > 365) {
                throw new \RuntimeException('密码过期天数必须在0-365之间。');
            }
            $this->upsertSystemSetting('password_expiry_days', $days);
        }

        if (isset($payload['rate_limit_enabled'])) {
            $this->upsertSystemSetting('rate_limit_enabled', (int) $payload['rate_limit_enabled']);
        }

        if (isset($payload['rate_limit_requests'])) {
            $requests = (int) $payload['rate_limit_requests'];
            if ($requests < 10 || $requests > 10000) {
                throw new \RuntimeException('频率限制请求数必须在10-10000之间。');
            }
            $this->upsertSystemSetting('rate_limit_requests', $requests);
        }

        if (isset($payload['rate_limit_window_seconds'])) {
            $window = (int) $payload['rate_limit_window_seconds'];
            if ($window < 1 || $window > 3600) {
                throw new \RuntimeException('时间窗口必须在1-3600秒之间。');
            }
            $this->upsertSystemSetting('rate_limit_window_seconds', $window);
        }

        // ==================== 支付设置 ====================

        if (isset($payload['order_timeout_minutes'])) {
            $timeout = (int) $payload['order_timeout_minutes'];
            if ($timeout < 5 || $timeout > 1440) {
                throw new \RuntimeException('订单支付超时必须在5-1440分钟之间。');
            }
            $this->upsertSystemSetting('order_timeout_minutes', $timeout);
        }

        if (isset($payload['manual_enabled'])) {
            $this->upsertSystemSetting('manual_enabled', (int) $payload['manual_enabled']);
        }

        if (isset($payload['alipay_enabled'])) {
            $this->upsertSystemSetting('alipay_enabled', (int) $payload['alipay_enabled']);
        }

        if (isset($payload['wechat_enabled'])) {
            $this->upsertSystemSetting('wechat_enabled', (int) $payload['wechat_enabled']);
        }

        if (isset($payload['stripe_enabled'])) {
            $this->upsertSystemSetting('stripe_enabled', (int) $payload['stripe_enabled']);
        }

        // ==================== 邮件设置 ====================

        if (isset($payload['smtp_host'])) {
            $host = trim((string) $payload['smtp_host']);
            if (empty($host)) {
                throw new \RuntimeException('SMTP服务器不能为空。');
            }
            $this->upsertSystemSetting('smtp_host', $host);
        }

        if (isset($payload['smtp_port'])) {
            $port = (int) $payload['smtp_port'];
            if ($port < 1 || $port > 65535) {
                throw new \RuntimeException('SMTP端口必须在1-65535之间。');
            }
            $this->upsertSystemSetting('smtp_port', $port);
        }

        if (isset($payload['smtp_encryption'])) {
            $encryption = trim((string) $payload['smtp_encryption']);
            if (!in_array($encryption, ['tls', 'ssl', 'none'])) {
                throw new \RuntimeException('加密方式必须是 tls, ssl 或 none。');
            }
            $this->upsertSystemSetting('smtp_encryption', $encryption);
        }

        if (isset($payload['from_address'])) {
            $email = trim((string) $payload['from_address']);
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('发件人邮箱格式不正确。');
            }
            $this->upsertSystemSetting('from_address', $email);
        }

        if (isset($payload['from_name'])) {
            $name = trim((string) $payload['from_name']);
            if (strlen($name) > 100) {
                throw new \RuntimeException('发件人名称过长。');
            }
            $this->upsertSystemSetting('from_name', $name);
        }

        // ==================== 通知设置 ====================

        if (isset($payload['email_order_notification'])) {
            $this->upsertSystemSetting('email_order_notification', (int) $payload['email_order_notification']);
        }

        if (isset($payload['email_subscription_notification'])) {
            $this->upsertSystemSetting('email_subscription_notification', (int) $payload['email_subscription_notification']);
        }

        if (isset($payload['email_ticket_reply_notification'])) {
            $this->upsertSystemSetting('email_ticket_reply_notification', (int) $payload['email_ticket_reply_notification']);
        }

        if (isset($payload['email_expiration_warning_days'])) {
            $days = (int) $payload['email_expiration_warning_days'];
            if ($days < 1 || $days > 90) {
                throw new \RuntimeException('过期前提醒天数必须在1-90之间。');
            }
            $this->upsertSystemSetting('email_expiration_warning_days', $days);
        }

        if (isset($payload['in_app_notifications_enabled'])) {
            $this->upsertSystemSetting('in_app_notifications_enabled', (int) $payload['in_app_notifications_enabled']);
        }

        // ==================== 主题设置 ====================

        if (isset($payload['primary_color'])) {
            $color = trim((string) $payload['primary_color']);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                throw new \RuntimeException('主色必须是有效的十六进制颜色值（#RRGGBB）。');
            }
            $this->upsertSystemSetting('primary_color', $color);
        }

        if (isset($payload['secondary_color'])) {
            $color = trim((string) $payload['secondary_color']);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                throw new \RuntimeException('辅助色必须是有效的十六进制颜色值（#RRGGBB）。');
            }
            $this->upsertSystemSetting('secondary_color', $color);
        }

        if (isset($payload['danger_color'])) {
            $color = trim((string) $payload['danger_color']);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                throw new \RuntimeException('危险色必须是有效的十六进制颜色值（#RRGGBB）。');
            }
            $this->upsertSystemSetting('danger_color', $color);
        }

        if (isset($payload['logo_url'])) {
            $url = trim((string) $payload['logo_url']);
            if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('Logo URL格式不正确。');
            }
            $this->upsertSystemSetting('logo_url', $url);
        }

        if (isset($payload['favicon_url'])) {
            $url = trim((string) $payload['favicon_url']);
            if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('Favicon URL格式不正确。');
            }
            $this->upsertSystemSetting('favicon_url', $url);
        }

        if (isset($payload['dark_mode_default'])) {
            $this->upsertSystemSetting('dark_mode_default', (int) $payload['dark_mode_default']);
        }

        // 记录审计日志
        AuditLog::create([
            'action' => 'settings_changed',
            'target_type' => 'system',
            'target_id' => 1,
            'detail_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'actor_user_id' => $adminId,
        ]);

        return ['message' => '高级设置已保存'];
    }

    /**
     * 获取邮件设置
     */
    public function emailSettings(): array
    {
        return [
            'smtp_configured' => !empty($this->config->get('smtp.host')),
            'smtp_host' => $this->config->get('smtp.host', ''),
            'smtp_port' => $this->config->get('smtp.port', 587),
            'smtp_encryption' => $this->config->get('smtp.encryption', 'tls'),
            'from_address' => $this->config->get('smtp.from_address', ''),
            'from_name' => $this->config->get('smtp.from_name', ''),
            'send_test_email' => false,
        ];
    }

    /**
     * 保存邮件设置
     */
    public function saveEmailSettings(array $payload): void
    {
        // 验证 SMTP 信息
        if (isset($payload['smtp_host'])) {
            $host = trim((string) $payload['smtp_host']);
            if (empty($host)) {
                throw new \RuntimeException('SMTP 服务器地址不能为空。');
            }
            $this->config->set('email', 'smtp_host', $host);
        }

        if (isset($payload['smtp_port'])) {
            $port = (int) $payload['smtp_port'];
            if ($port < 1 || $port > 65535) {
                throw new \RuntimeException('SMTP 端口必须在1-65535之间。');
            }
            $this->config->set('email', 'smtp_port', $port);
        }

        if (isset($payload['from_address'])) {
            $address = trim((string) $payload['from_address']);
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('邮件地址格式不正确。');
            }
            $this->config->set('email', 'from_address', $address);
        }
    }

    /**
     * 获取支付方式设置
     */
    public function paymentSettings(): array
    {
        return [
            'manual_enabled' => $this->config->get('payment.manual_enabled', 1),
            'manual_instructions' => $this->config->get('payment.manual_instructions', ''),
            'alipay_enabled' => $this->config->get('payment.alipay_enabled', 0),
            'alipay_configured' => !empty($this->config->get('payment.alipay_app_id')),
            'wechat_enabled' => $this->config->get('payment.wechat_enabled', 0),
            'wechat_configured' => !empty($this->config->get('payment.wechat_app_id')),
            'stripe_enabled' => $this->config->get('payment.stripe_enabled', 0),
            'stripe_configured' => !empty($this->config->get('payment.stripe_secret_key')),
            'order_timeout_minutes' => $this->config->get('payment.order_timeout_minutes', 30),
        ];
    }

    /**
     * 获取通知设置
     */
    public function notificationSettings(): array
    {
        return [
            'email_order_notification' => $this->config->get('notification.email_order_notification', 1),
            'email_subscription_notification' => $this->config->get('notification.email_subscription_notification', 1),
            'email_ticket_reply_notification' => $this->config->get('notification.email_ticket_reply_notification', 1),
            'email_expiration_warning_days' => $this->config->get('notification.email_expiration_warning_days', 7),
            'in_app_notifications_enabled' => $this->config->get('notification.in_app_notifications_enabled', 1),
        ];
    }

    /**
     * 保存通知设置
     */
    public function saveNotificationSettings(array $payload): void
    {
        if (isset($payload['email_order_notification'])) {
            $this->config->set('notification', 'email_order_notification', (int) $payload['email_order_notification']);
        }
        if (isset($payload['email_expiration_warning_days'])) {
            $days = (int) $payload['email_expiration_warning_days'];
            if ($days < 1 || $days > 90) {
                throw new \RuntimeException('过期提醒天数必须在1-90之间。');
            }
            $this->config->set('notification', 'email_expiration_warning_days', $days);
        }
    }

    /**
     * 获取主题设置
     */
    public function themeSettings(): array
    {
        return [
            'primary_color' => $this->config->get('theme.primary_color', '#b95c2b'),
            'secondary_color' => $this->config->get('theme.secondary_color', '#3f5f59'),
            'danger_color' => $this->config->get('theme.danger_color', '#b03a2d'),
            'logo_url' => $this->config->get('theme.logo_url', ''),
            'favicon_url' => $this->config->get('theme.favicon_url', ''),
            'dark_mode_default' => $this->config->get('theme.dark_mode_default', 0),
        ];
    }

    /**
     * 保存主题设置
     */
    public function saveThemeSettings(array $payload): void
    {
        if (isset($payload['primary_color'])) {
            $color = trim((string) $payload['primary_color']);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                throw new \RuntimeException('颜色值格式不正确。');
            }
            $this->config->set('theme', 'primary_color', $color);
        }

        if (isset($payload['logo_url'])) {
            $url = trim((string) $payload['logo_url']);
            if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('Logo URL 格式不正确。');
            }
            $this->config->set('theme', 'logo_url', $url);
        }
    }

    /**
     * 辅助方法：插入或更新系统设置
     */
    private function upsertSystemSetting(string $itemKey, $value): void
    {
        SystemSetting::where('group_key', 'system')
            ->where('item_key', $itemKey)
            ->delete();

        SystemSetting::create([
            'group_key' => 'system',
            'item_key' => $itemKey,
            'item_value' => is_array($value) ? json_encode($value) : (string)$value,
        ]);
    }
}
