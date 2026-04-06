<?php
declare (strict_types = 1);

namespace app\service;

use app\model\AuditLog;
use app\model\BalanceLog;
use app\model\Coupon;
use app\model\EmailQueue;
use app\model\InviteRecord;
use app\model\Order;
use app\model\Package;
use app\model\Payment;
use app\model\Subscription;
use app\model\Ticket;
use app\model\TicketReply;
use app\model\User;
use app\model\UserLoginLog;
use app\model\UserSetting;
use think\facade\Db;
use app\service\NotificationService;

/**
 * PanelService — 薄代理层
 * 已拆分的方法委托给专注的 Service 类
 * 未拆分的方法保留在此处
 */
class PanelService
{
    private OrderService $orderService;
    private TicketService $ticketService;
    private StatisticsService $statisticsService;
    private AdminSettingsService $adminSettingsService;

    public function __construct(
        private readonly AuthService $auth,
        private readonly AppConfigService $config
    ) {
        $this->orderService = new OrderService($auth, $config);
        $this->ticketService = new TicketService($auth, $config);
        $this->statisticsService = new StatisticsService();
        $this->adminSettingsService = new AdminSettingsService($config);
    }

    // ==================== 委托: OrderService ====================
    public function orders(): array { return $this->orderService->orders(); }
    public function createOrder(array $p): array { return $this->orderService->createOrder($p); }
    public function paymentStatus(string $no): array { return $this->orderService->paymentStatus($no); }
    public function cancelOrder(string $no): array { return $this->orderService->cancelOrder($no); }
    public function adminOrders(array $f = []): array { return $this->orderService->adminOrders($f); }
    public function updateOrderStatus(string $no, string $a): array { return $this->orderService->updateOrderStatus($no, $a); }
    public function verifyCoupon(string $code, float $amount = 0): array { return $this->orderService->verifyCoupon($code, $amount); }

    // ==================== 委托: TicketService ====================
    public function tickets(): array { return $this->ticketService->tickets(); }
    public function createTicket(array $p): array { return $this->ticketService->createTicket($p); }
    public function adminTickets(array $f = []): array { return $this->ticketService->adminTickets($f); }
    public function replyTicket(string $no, string $c): array { return $this->ticketService->replyTicket($no, $c); }

    // ==================== 委托: StatisticsService ====================
    public function systemStatistics(string $p = '7day'): array { return $this->statisticsService->systemStatistics($p); }
    public function auditLogs(int $p = 1, int $l = 20): array { return $this->statisticsService->auditLogs($p, $l); }
    public function getUserBehaviorAnalysis(string $p = '7day'): array { return $this->statisticsService->getUserBehaviorAnalysis($p); }
    public function getDeviceAnalysis(string $p = '7day'): array { return $this->statisticsService->getDeviceAnalysis($p); }
    public function getChurnWarning(int $d = 30): array { return $this->statisticsService->getChurnWarning($d); }
    public function getPackageDistribution(): array { return $this->statisticsService->getPackageDistribution(); }
    public function getRecentLogins(int $l = 20): array { return $this->statisticsService->getRecentLogins($l); }
    public function detectSuspiciousLogins(): array { return $this->statisticsService->detectSuspiciousLogins(); }
    public function getLoginStatsByCountry(): array { return $this->statisticsService->getLoginStatsByCountry(); }
    public function getLoginAnomalies(): array { return $this->statisticsService->getLoginAnomalies(); }

    // ==================== 委托: AdminSettingsService ====================
    public function adminSiteSettings(): array { return $this->adminSettingsService->adminSiteSettings(); }
    public function saveSiteSettings(array $p): void { $this->adminSettingsService->saveSiteSettings($p); }
    public function advancedSettings(): array { return $this->adminSettingsService->advancedSettings(); }
    public function saveAdvancedSettings(array $p): array { return $this->adminSettingsService->saveAdvancedSettings($p); }

    // ==================== 保留: Dashboard & Overview ====================

    public function userOverview(): array
    {
        $user = $this->currentUser();
        $subscription = $this->activeSubscription($user->id);
        return [
            'balance_label' => $this->money((float) $user->balance),
            'plan_name'     => $subscription ? $this->packageName((int) $subscription->package_id) : '未开通套餐',
            'plan_badge'    => $subscription ? 'Active' : 'Free',
            'expire_at'     => $subscription?->expire_at ? (string) $subscription->expire_at : '未开通',
        ];
    }

    public function dashboard(): array
    {
        $user = $this->currentUser();
        $subscription = $this->activeSubscription($user->id);
        $package = $subscription ? Package::find((int) $subscription->package_id) : null;
        $rawUrl = $subscription?->sub_url ?: $this->config->baseUrl() . '/sub/not-configured';
        $remainingTraffic = max(0, (int) ($subscription?->traffic_total_gb ?? 0) - (int) ($subscription?->traffic_used_gb ?? 0));
        $daysRemaining = $subscription?->expire_at ? max(0, (int) floor((strtotime((string) $subscription->expire_at) - time()) / 86400)) : null;
        $loginHistory = UserLoginLog::where('user_id', $user->id)->order('id', 'desc')->limit(5)->select();

        return [
            'stats' => [
                ['label' => '当前余额', 'value' => $this->money((float) $user->balance)],
                ['label' => '设备占用', 'value' => ($subscription?->used_devices ?? 0) . '/' . ($subscription?->device_limit ?? 0)],
                ['label' => '剩余流量', 'value' => $remainingTraffic . ' GB'],
                ['label' => '套餐到期', 'value' => $subscription?->expire_at ? (string) $subscription->expire_at : '未开通'],
            ],
            'account_summary' => [
                'plan_name' => $package?->name ?: '未开通套餐',
                'plan_badge' => $subscription ? 'Active' : 'Free',
                'balance_label' => $this->money((float) $user->balance),
                'device_usage' => ($subscription?->used_devices ?? 0) . ' / ' . ($subscription?->device_limit ?? 0),
                'traffic_usage' => ($subscription?->traffic_used_gb ?? 0) . ' / ' . ($subscription?->traffic_total_gb ?? 0) . ' GB',
                'expire_at' => $subscription?->expire_at ? (string) $subscription->expire_at : '未开通',
                'days_remaining' => $daysRemaining,
                'support_email' => (string) $this->config->get('site.support_email', ''),
            ],
            'checkin' => [
                'checked_in_today' => $this->checkedInToday($user),
                'reward_label' => '+' . $this->money((float) $this->config->get('business.checkin_reward', 1)),
                'streak_label' => $this->checkedInToday($user) ? '今天已签到' : '今日可签到',
            ],
            'subscription' => [
                'masked_url' => str_repeat('*', 16),
                'raw_url' => $rawUrl,
                'formats' => $this->subscriptionFormats($rawUrl),
            ],
            'quick_links' => [
                ['label' => '套餐商店', 'href' => '/shop'],
                ['label' => '购买套餐', 'href' => '/purchase'],
                ['label' => '账户充值', 'href' => '/recharge'],
                ['label' => '订单管理', 'href' => '/orders'],
                ['label' => '订阅管理', 'href' => '/subscriptions'],
                ['label' => '工单中心', 'href' => '/tickets'],
                ['label' => '邀请返利', 'href' => '/invite'],
                ['label' => '用户设置', 'href' => '/settings'],
            ],
            'recent_logins' => $loginHistory->map(fn (UserLoginLog $log): array => [
                'time' => (string) $log->created_at,
                'ip' => (string) ($log->ip_address ?: '未记录'),
                'device' => (string) ($log->user_agent ?: '未知设备'),
            ])->all(),
            'renewal_notice' => $daysRemaining !== null && $daysRemaining <= 7 ? '您的套餐即将到期，请及时续费。' : null,
        ];
    }

    // ==================== 保留: Shop & Subscription ====================

    public function shop(): array
    {
        $packages = Package::where('is_active', 1)->order('sort_order', 'asc')->select();
        return ['packages' => $packages->map(fn (Package $p): array => $this->packageCard($p))->all()];
    }

    public function subscription(): array
    {
        $user = $this->currentUser();
        $subscription = $this->activeSubscription($user->id);
        $package = $subscription ? Package::find((int) $subscription->package_id) : null;
        $rawUrl = $subscription?->sub_url ?: $this->config->baseUrl() . '/sub/not-configured';
        $formats = $this->subscriptionFormats($rawUrl);

        return [
            'has_subscription' => (bool) $subscription,
            'plan' => [
                'name' => $package?->name ?: '未开通套餐',
                'badge' => $subscription ? 'Active' : 'Free',
                'device_usage' => ($subscription?->used_devices ?? 0) . ' / ' . ($subscription?->device_limit ?? 0),
                'traffic_total' => (int) ($subscription?->traffic_total_gb ?? 0),
                'traffic_used' => (int) ($subscription?->traffic_used_gb ?? 0),
                'expire_at' => $subscription?->expire_at ? (string) $subscription->expire_at : '未开通',
                'speed_limit' => (int) ($package?->speed_limit_mbps ?? 0),
            ],
            'formats' => $formats,
            'actions' => ['reset' => '重置订阅链接', 'balance' => '折算剩余天数为余额', 'send_email' => '发送到邮箱'],
        ];
    }

    public function resetSubscription(): array
    {
        $subscription = $this->activeSubscription($this->currentUserId());
        if (!$subscription) { throw new \RuntimeException('当前没有有效订阅。'); }
        $token = bin2hex(random_bytes(8));
        $subscription->sub_token = $token;
        $subscription->sub_url = $this->config->baseUrl() . '/sub/' . $token;
        $subscription->reset_count = (int) $subscription->reset_count + 1;
        $subscription->last_reset_at = date('Y-m-d H:i:s');
        $subscription->save();
        NotificationService::notify($this->currentUserId(), NotificationService::SUBSCRIPTION_RESET, ['操作' => '订阅链接已重置']);
        return ['sub_url' => $subscription->sub_url];
    }

    public function convertSubscriptionBalance(): array
    {
        $subscription = $this->activeSubscription($this->currentUserId());
        $user = $this->currentUser();
        if (!$subscription || !$subscription->expire_at) { throw new \RuntimeException('当前没有可折算的订阅。'); }
        $days = max(0, (int) floor((strtotime((string) $subscription->expire_at) - time()) / 86400));
        if ($days <= 0) { throw new \RuntimeException('当前没有剩余天数。'); }
        $rate = (float) $this->config->get('business.balance_convert_rate', 1);
        $amount = round($days * $rate, 2);
        $before = (float) $user->balance;
        $after = $before + $amount;
        Db::transaction(function () use ($subscription, $user, $before, $after, $amount, $days): void {
            $subscription->status = 'expired'; $subscription->expire_at = date('Y-m-d H:i:s'); $subscription->save();
            User::update(['id' => $user->id, 'balance' => $after]);
            BalanceLog::create(['user_id' => $user->id, 'type' => 'manual', 'amount' => $amount, 'balance_before' => $before, 'balance_after' => $after, 'remark' => '订阅剩余 ' . $days . ' 天折算余额']);
        });
        $this->auth->refreshFromDatabase((int) $user->id);
        NotificationService::notify((int) $user->id, NotificationService::SUBSCRIPTION_EXPIRED, ['余额' => $this->money($after)]);
        return ['balance_label' => $this->money($after)];
    }

    public function sendSubscriptionEmail(): array
    {
        $user = $this->currentUser();
        $subscription = $this->activeSubscription($user->id);
        if (!$subscription) { throw new \RuntimeException('当前没有有效订阅。'); }
        AuditLog::create(['actor_user_id' => $user->id, 'actor_role' => 'user', 'action' => 'subscription_email_requested', 'target_type' => 'subscription', 'target_id' => $subscription->id, 'detail_json' => json_encode(['email' => $user->email], JSON_UNESCAPED_UNICODE)]);
        return ['email' => $user->email];
    }

    public function clearSubscriptionDevices(): array
    {
        $subscription = $this->activeSubscription($this->currentUserId());
        if (!$subscription) { throw new \RuntimeException('当前没有有效订阅。'); }
        $subscription->used_devices = 0; $subscription->save();
        return ['used_devices' => 0];
    }

    // ==================== 保留: Admin Dashboard & Management ====================

    public function adminDashboard(): array
    {
        $todayRevenue = (float) Order::whereDay('paid_at')->where('status', 'paid')->sum('amount_payable');
        $monthRevenue = (float) Order::whereMonth('paid_at')->where('status', 'paid')->sum('amount_payable');
        $totalRevenue = (float) Order::where('status', 'paid')->sum('amount_payable');
        $activeUsers = User::where('status', 1)->count();
        $totalUsers = User::count();
        $disabledUsers = User::where('status', 0)->count();
        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $expiredSubscriptions = Subscription::where('status', 'expired')->count();
        $pendingOrders = Order::where('status', 'pending')->count();
        $paidOrders = Order::where('status', 'paid')->count();
        $openTickets = Ticket::where('status', 'open')->count();
        $inProgressTickets = Ticket::where('status', 'in_progress')->count();

        return [
            'cards' => [
                ['label' => '今日收入', 'value' => $this->money($todayRevenue), 'trend' => '订单实时聚合', 'href' => '/admin/orders'],
                ['label' => '本月收入', 'value' => $this->money($monthRevenue), 'trend' => '月度累计', 'href' => '/admin/statistics'],
                ['label' => '总收入', 'value' => $this->money($totalRevenue), 'trend' => '历史总计', 'href' => '/admin/statistics'],
                ['label' => '活跃用户', 'value' => (string) $activeUsers, 'trend' => '总计 ' . $totalUsers . ' / 禁用 ' . $disabledUsers, 'href' => '/admin/users'],
                ['label' => '活跃订阅', 'value' => (string) $activeSubscriptions, 'trend' => '已过期 ' . $expiredSubscriptions, 'href' => '/admin/subscriptions'],
                ['label' => '待支付订单', 'value' => (string) $pendingOrders, 'trend' => '已支付 ' . $paidOrders, 'href' => '/admin/orders'],
                ['label' => '待处理工单', 'value' => (string) $openTickets, 'trend' => '处理中 ' . $inProgressTickets, 'href' => '/admin/tickets'],
            ],
            'trend' => $this->statisticsService->sevenDayTrend(),
            'systemStats' => ['users_total' => $totalUsers, 'users_active' => $activeUsers, 'subscriptions_active' => $activeSubscriptions],
        ];
    }

    public function adminSubscriptions(array $filters = []): array
    {
        $filters = $this->normalizeListFilters($filters);
        $query = Subscription::order('id', 'desc');
        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $userIds = User::where('email', 'like', '%' . $keyword . '%')->whereOr('nickname', 'like', '%' . $keyword . '%')->column('id');
            $packageIds = Package::where('name', 'like', '%' . $keyword . '%')->column('id');
            $query->where(function ($q) use ($keyword, $userIds, $packageIds) {
                if (is_numeric($keyword)) { $q->where('id', (int) $keyword)->whereOr('user_id', (int) $keyword); }
                $q->whereOr('sub_token', 'like', '%' . $keyword . '%');
                if (!empty($userIds)) { $q->whereOr('user_id', 'in', $userIds); }
                if (!empty($packageIds)) { $q->whereOr('package_id', 'in', $packageIds); }
            });
        }
        if (!empty($filters['status'])) { $query->where('status', $filters['status']); }
        return $query->select()->map(fn (Subscription $s): array => [
            'id' => $s->id, 'user' => $this->userName((int) $s->user_id), 'user_id' => (int) $s->user_id,
            'package' => $this->packageName((int) $s->package_id), 'status' => (string) $s->status,
            'devices' => $s->used_devices . '/' . $s->device_limit,
            'traffic' => $s->traffic_used_gb . '/' . $s->traffic_total_gb . ' GB',
            'expire_at' => $s->expire_at ? (string) $s->expire_at : '未设置',
        ])->all();
    }

    public function extendSubscription(int $id, int $days): array
    {
        $s = Subscription::find($id);
        if (!$s) { throw new \RuntimeException('订阅不存在。'); }
        $base = $s->expire_at ? strtotime((string) $s->expire_at) : time();
        $s->expire_at = date('Y-m-d H:i:s', strtotime('+' . $days . ' days', max(time(), $base)));
        $s->status = 'active'; $s->save();
        return ['expire_at' => (string) $s->expire_at];
    }

    public function updateSubscriptionDeviceLimit(int $id, int $limit): array
    {
        $s = Subscription::find($id);
        if (!$s) { throw new \RuntimeException('订阅不存在。'); }
        if ($limit < 0 || $limit > 999) { throw new \RuntimeException('设备数量必须在0-999之间。'); }
        $s->device_limit = $limit; $s->save();
        return ['device_limit' => (int) $s->device_limit, 'used_devices' => (int) $s->used_devices, 'devices' => $s->used_devices . '/' . $s->device_limit];
    }

    public function updateSubscriptionExpireAt(int $id, string $expireAt): array
    {
        $s = Subscription::find($id);
        if (!$s) { throw new \RuntimeException('订阅不存在。'); }
        $ts = strtotime($expireAt);
        if ($ts === false) { throw new \RuntimeException('到期时间格式不正确。'); }
        $s->expire_at = date('Y-m-d H:i:s', $ts);
        $s->status = $ts < time() ? 'expired' : 'active'; $s->save();
        return ['expire_at' => (string) $s->expire_at, 'status' => (string) $s->status];
    }

    public function resetSubscriptionByAdmin(int $id): array
    {
        $s = Subscription::find($id);
        if (!$s) { throw new \RuntimeException('订阅不存在。'); }
        $token = bin2hex(random_bytes(8));
        $s->sub_token = $token; $s->sub_url = $this->config->baseUrl() . '/sub/' . $token;
        $s->reset_count = (int) $s->reset_count + 1; $s->save();
        return ['sub_url' => $s->sub_url];
    }

    public function clearSubscriptionDevicesByAdmin(int $id): array
    {
        $s = Subscription::find($id);
        if (!$s) { throw new \RuntimeException('订阅不存在。'); }
        $s->used_devices = 0; $s->save();
        return ['used_devices' => 0];
    }

    // ==================== 保留: User Settings & Management ====================

    public function settings(): array
    {
        $user = $this->currentUser();
        $prefs = $this->userSettings($user->id);
        $subscription = $this->activeSubscription($user->id);
        $loginHistory = UserLoginLog::where('user_id', $user->id)->order('id', 'desc')->limit(10)->select();
        $userSubscriptions = Subscription::where('user_id', $user->id)->order('id', 'desc')->limit(10)->select();

        return [
            'profile' => [
                'name' => $user->nickname ?: $user->email, 'email' => $user->email,
                'telegram' => (string) ($prefs['telegram'] ?? ''), 'timezone' => (string) ($prefs['timezone'] ?? 'Asia/Shanghai'),
                'balance' => $this->money((float) $user->balance),
                'created_at' => $user->created_at ? (string) $user->created_at : '暂无记录',
                'last_login_at' => $user->last_login_at ? (string) $user->last_login_at : '暂无记录',
                'device_usage' => ($subscription?->used_devices ?? 0) . ' / ' . ($subscription?->device_limit ?? 0),
            ],
            'security' => [
                'last_login' => $user->last_login_at ? (string) $user->last_login_at : '暂无记录',
                'login_history' => $loginHistory->map(fn (UserLoginLog $log): array => [
                    'time' => (string) $log->created_at, 'ip' => (string) ($log->ip_address ?: '未记录'), 'device' => (string) ($log->user_agent ?: '未知设备'),
                ])->all(),
            ],
            'notifications' => [
                'email_order' => $this->userSettingBool($prefs, 'email_order', true),
                'email_subscription' => $this->userSettingBool($prefs, 'email_subscription', true),
                'email_ticket' => $this->userSettingBool($prefs, 'email_ticket', true),
                'email_announcement' => $this->userSettingBool($prefs, 'email_announcement', true),
                'notification_frequency' => (string) ($prefs['notification_frequency'] ?? 'immediate'),
            ],
            'privacy' => [
                'profile_public' => $this->userSettingBool($prefs, 'profile_public', false),
                'email_hidden' => $this->userSettingBool($prefs, 'email_hidden', true),
                'marketing_email' => $this->userSettingBool($prefs, 'marketing_email', false),
            ],
            'preferences' => [
                'dark_mode' => (string) ($prefs['dark_mode'] ?? '0'),
                'language' => (string) ($prefs['language'] ?? 'zh-CN'),
                'timezone' => (string) ($prefs['timezone'] ?? 'Asia/Shanghai'),
            ],
            'subscriptions' => $userSubscriptions->map(fn (Subscription $item): array => [
                'id' => $item->id, 'status' => (string) $item->status,
                'package_name' => $this->packageName((int) $item->package_id),
                'device_usage' => (int) $item->used_devices . ' / ' . (int) $item->device_limit,
                'traffic_usage' => (int) $item->traffic_used_gb . ' / ' . (int) $item->traffic_total_gb . ' GB',
                'expire_at' => $item->expire_at ? (string) $item->expire_at : '未设置',
                'sub_url' => (string) ($item->sub_url ?: ''),
            ])->all(),
        ];
    }

    public function invite(): array
    {
        $user = $this->currentUser();
        $records = InviteRecord::where('inviter_user_id', $user->id)->order('id', 'desc')->select();
        return [
            'invite_code' => (string) ($user->invite_code ?: ''),
            'invite_link' => $this->config->baseUrl() . '/register?code=' . ($user->invite_code ?: ''),
            'team_size' => $records->count(),
            'pending_reward' => number_format((float) $records->where('status', 'pending')->sum('reward_amount'), 2),
            'settled_reward' => number_format((float) $records->where('status', 'settled')->sum('reward_amount'), 2),
            'records' => $records->map(fn (InviteRecord $r): array => [
                'user' => $this->userName((int) $r->invited_user_id), 'status' => (string) $r->status,
                'reward' => $this->money((float) $r->reward_amount), 'created_at' => (string) $r->created_at,
            ])->all(),
        ];
    }

    public function checkin(): array
    {
        $user = $this->currentUser();
        if ($this->checkedInToday($user)) { throw new \RuntimeException('今天已经签到过了。'); }
        $reward = (float) $this->config->get('business.checkin_reward', 1);
        $before = (float) $user->balance;
        $after = $before + $reward;
        User::update(['id' => $user->id, 'balance' => $after, 'last_checkin_at' => date('Y-m-d H:i:s')]);
        BalanceLog::create(['user_id' => $user->id, 'type' => 'checkin', 'amount' => $reward, 'balance_before' => $before, 'balance_after' => $after, 'remark' => '每日签到']);
        $this->auth->refreshFromDatabase((int) $user->id);
        return ['reward_label' => '+' . $this->money($reward), 'balance_label' => $this->money($after)];
    }

    public function toggleSetting(string $key, bool $value): array
    {
        $this->upsertUserSetting($this->currentUserId(), $key, $value ? 'true' : 'false');
        return ['key' => $key, 'value' => $value];
    }

    public function saveProfile(array $payload): array
    {
        $user = $this->currentUser();
        $nickname = trim((string) $payload['name']);
        if (empty($nickname)) { throw new \RuntimeException('昵称不能为空。'); }
        if (strlen($nickname) > 50) { throw new \RuntimeException('昵称不能超过50个字符。'); }
        $telegram = trim((string) ($payload['telegram'] ?? ''));
        $timezone = trim((string) ($payload['timezone'] ?? ''));
        User::update(['id' => $user->id, 'nickname' => $nickname]);
        $this->upsertUserSetting((int) $user->id, 'telegram', $telegram);
        $this->upsertUserSetting((int) $user->id, 'timezone', $timezone);
        $this->auth->refreshFromDatabase((int) $user->id);
        return ['name' => $nickname];
    }

    public function updatePassword(array $payload): array
    {
        $user = $this->currentUser();
        $old = (string) $payload['old_password']; $new = (string) $payload['new_password']; $confirm = (string) $payload['confirm_password'];
        if (!password_verify($old, (string) $user->password_hash)) { throw new \RuntimeException('旧密码不正确。'); }
        if ($old === $new) { throw new \RuntimeException('新旧密码不能相同。'); }
        if ($new !== $confirm) { throw new \RuntimeException('两次输入的新密码不一致。'); }
        if (strlen($new) < 6) { throw new \RuntimeException('新密码至少需要6个字符。'); }
        User::update(['id' => $user->id, 'password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        $this->upsertUserSetting((int) $user->id, 'last_password_change', date('Y-m-d'));
        NotificationService::notify((int) $user->id, NotificationService::PASSWORD_CHANGED, ['修改时间' => date('Y-m-d H:i:s')]);
        return ['updated' => true];
    }

    // ==================== 保留: Admin Users & Packages & Coupons ====================

    public function adminUsers(array $filters = []): array
    {
        $filters = $this->normalizeListFilters($filters);
        $query = User::order('id', 'desc');
        if (!empty($filters['keyword'])) {
            $kw = $filters['keyword'];
            $query->where(function ($q) use ($kw) {
                $q->where('email', 'like', '%' . $kw . '%')->whereOr('nickname', 'like', '%' . $kw . '%');
                if (is_numeric($kw)) { $q->whereOr('id', (int) $kw); }
            });
        }
        if ($filters['status'] !== '') { $query->where('status', (int) $filters['status']); }
        return $query->select()->map(fn (User $u): array => [
            'id' => $u->id, 'email' => $u->email, 'name' => $u->nickname ?: $u->email,
            'role' => (int) $u->role === 1 ? '管理员' : '用户', 'role_key' => (int) $u->role,
            'status' => (int) $u->status === 1 ? '正常' : '禁用', 'status_key' => (int) $u->status,
            'balance' => $this->money((float) $u->balance), 'created_at' => (string) $u->created_at,
        ])->all();
    }

    public function searchAdminUsers(string $keyword): array { return $this->adminUsers(['keyword' => $keyword]); }

    public function updateUserStatus(int $userId, int $status): array
    {
        $user = User::find($userId);
        if (!$user) { throw new \RuntimeException('用户不存在。'); }
        $user->status = $status; $user->save();
        return ['status' => $status];
    }

    public function batchUsers(array $ids, string $action): array
    {
        if (empty($ids)) { throw new \RuntimeException('请选择用户。'); }
        return Db::transaction(fn () => match ($action) {
            'enable' => $this->batchSetUserStatus($ids, 1), 'disable' => $this->batchSetUserStatus($ids, 0),
            'set_user' => $this->batchSetUserRole($ids, 0), 'set_admin' => $this->batchSetUserRole($ids, 1),
            'delete' => $this->batchDeleteUsers($ids), default => throw new \RuntimeException('不支持的批量动作。'),
        });
    }

    public function resetUserPassword(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) { throw new \RuntimeException('用户不存在。'); }
        $password = 'CB' . random_int(100000, 999999);
        $user->password_hash = password_hash($password, PASSWORD_DEFAULT); $user->save();
        NotificationService::notify($userId, NotificationService::PASSWORD_CHANGED, ['修改时间' => date('Y-m-d H:i:s'), '操作' => '管理员重置']);
        return ['password' => $password];
    }

    public function adminPackages(): array
    {
        return Package::order('sort_order', 'asc')->select()->map(fn (Package $p): array => [
            'id' => $p->id, 'name' => $p->name, 'monthly' => $this->money((float) $p->price_monthly),
            'quarterly' => $this->money((float) $p->price_quarterly), 'yearly' => $this->money((float) $p->price_yearly),
            'devices' => (int) $p->device_limit, 'traffic' => (int) $p->traffic_limit_gb . ' GB',
            'speed' => (int) $p->speed_limit_mbps . ' Mbps', 'active' => (int) $p->is_active,
            'sort' => (int) $p->sort_order,
        ])->all();
    }

    public function savePackage(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $data = [
            'name' => trim((string) $payload['name']), 'description' => trim((string) ($payload['description'] ?? '')),
            'price_monthly' => (float) $payload['price_monthly'], 'price_quarterly' => (float) ($payload['price_quarterly'] ?? 0),
            'price_yearly' => (float) ($payload['price_yearly'] ?? 0), 'device_limit' => max(1, (int) $payload['device_limit']),
            'traffic_limit_gb' => max(0, (int) ($payload['traffic_limit_gb'] ?? 0)),
            'speed_limit_mbps' => max(0, (int) ($payload['speed_limit_mbps'] ?? 0)),
            'is_active' => (int) ($payload['is_active'] ?? 1), 'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'features_json' => json_encode($payload['features'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
        if ($id > 0) { $data['id'] = $id; Package::update($data); } else { Package::create($data); }
        return ['saved' => true];
    }

    public function adminCoupons(array $filters = []): array
    {
        $filters = $this->normalizeListFilters($filters);
        $query = Coupon::order('id', 'desc');
        if (!empty($filters['keyword'])) {
            $kw = $filters['keyword'];
            $query->where(function ($q) use ($kw) { $q->where('code', 'like', '%' . $kw . '%')->whereOr('name', 'like', '%' . $kw . '%'); });
        }
        return $query->select()->map(fn (Coupon $c): array => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name ?: $c->code,
            'type' => $c->discount_type === 'percent' ? '折扣' : '满减',
            'value' => $c->discount_type === 'percent' ? ((float) $c->discount_value) . ' 折' : '- ' . $this->money((float) $c->discount_value),
            'used' => $c->used_count . ' / ' . ($c->total_limit ?: '不限'),
            'period' => ($c->start_at ?: '立即') . ' ~ ' . ($c->end_at ?: '长期'),
            'discount_type' => $c->discount_type, 'discount_value' => (float) $c->discount_value,
            'min_order_amount' => (float) $c->min_order_amount, 'total_limit' => (int) $c->total_limit,
            'user_limit' => (int) $c->user_limit, 'status_flag' => (int) $c->status,
        ])->all();
    }

    public function saveCoupon(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $data = [
            'code' => trim((string) $payload['code']), 'name' => trim((string) ($payload['name'] ?? '')),
            'discount_type' => (string) $payload['discount_type'], 'discount_value' => (float) $payload['discount_value'],
            'min_order_amount' => (float) ($payload['min_order_amount'] ?? 0),
            'total_limit' => (int) ($payload['total_limit'] ?? 0), 'user_limit' => (int) ($payload['user_limit'] ?? 1),
            'status' => (int) ($payload['status'] ?? 1),
            'start_at' => $payload['start_at'] ?? null, 'end_at' => $payload['end_at'] ?? null,
        ];
        if ($id > 0) { $data['id'] = $id; Coupon::update($data); } else { Coupon::create($data); }
        return ['saved' => true];
    }

    // ==================== 保留: Email Queue ====================

    public function getEmailQueueStats(): array
    {
        return [
            'pending' => EmailQueue::where('status', 'pending')->count(),
            'sent' => EmailQueue::where('status', 'sent')->count(),
            'failed' => EmailQueue::where('status', 'failed')->count(),
            'total' => EmailQueue::count(),
        ];
    }
    public function getEmailQueueRecent(int $limit = 50): array { return EmailQueue::order('created_at', 'desc')->limit($limit)->select()->toArray(); }
    public function cleanupSentEmails(int $days = 7): array { $count = EmailQueue::where('status', 'sent')->where('created_at', '<', date('Y-m-d H:i:s', strtotime("-{$days} days")))->delete(); return ['deleted' => $count]; }
    public function retryFailedEmails(): array { $count = EmailQueue::where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0]); return ['retried' => $count]; }
    public function deleteEmailQueueItem(int $id): array { EmailQueue::destroy($id); return ['deleted' => true]; }
    public function getEmailStatsByType(): array { return Db::table('email_queue')->field('type, status, COUNT(*) as count')->group('type, status')->select()->toArray(); }

    // ==================== 保留: User Advanced Settings ====================

    public function saveUserNotificationPreferences(int $userId, array $payload): void
    {
        if (isset($payload['email_order'])) { $this->upsertUserSetting($userId, 'notify_order', (string) (int) $payload['email_order']); }
        if (isset($payload['email_subscription'])) { $this->upsertUserSetting($userId, 'notify_subscription', (string) (int) $payload['email_subscription']); }
        if (isset($payload['notification_frequency'])) {
            $f = trim((string) $payload['notification_frequency']);
            if (!in_array($f, ['immediate', 'daily', 'weekly'])) { throw new \RuntimeException('通知频率无效。'); }
            $this->upsertUserSetting($userId, 'notification_frequency', $f);
        }
    }

    public function saveUserPrivacySettings(int $userId, array $payload): void
    {
        if (isset($payload['profile_public'])) { $this->upsertUserSetting($userId, 'profile_public', (string) (int) $payload['profile_public']); }
        if (isset($payload['email_hidden'])) { $this->upsertUserSetting($userId, 'email_hidden', (string) (int) $payload['email_hidden']); }
    }

    public function saveUserPreferences(int $userId, array $payload): void
    {
        if (isset($payload['timezone'])) { $this->upsertUserSetting($userId, 'timezone', trim((string) $payload['timezone'])); }
        if (isset($payload['language'])) {
            $lang = trim((string) $payload['language']);
            if (!in_array($lang, ['zh-CN', 'en-US', 'ja-JP'])) { throw new \RuntimeException('不支持的语言。'); }
            $this->upsertUserSetting($userId, 'language', $lang);
        }
        if (isset($payload['dark_mode'])) { $this->upsertUserSetting($userId, 'dark_mode', (string) (int) $payload['dark_mode']); }
    }

    public function exportUserData(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) { throw new \RuntimeException('用户不存在。'); }
        return [
            'user' => $user->toArray(),
            'subscriptions' => Subscription::where('user_id', $userId)->select()->toArray(),
            'orders' => Order::where('user_id', $userId)->select()->toArray(),
            'tickets' => Ticket::where('user_id', $userId)->select()->toArray(),
            'exported_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function userLoginHistory(int $userId, int $limit = 10): array
    {
        return AuditLog::where('action', 'login')->where('target_type', 'user')->where('target_id', $userId)
            ->order('created_at', 'desc')->limit($limit)->select()->map(fn ($log) => [
                'logged_in_at' => $log['created_at'] ?? '', 'details' => isset($log['old_values']) && $log['old_values'] ? json_decode($log['old_values'], true) : [],
            ])->toArray();
    }

    public function getUserSubscriptions(int $userId): array
    {
        return Subscription::where('user_id', $userId)->order('id', 'desc')->select()->map(fn (Subscription $s): array => [
            'id' => $s->id, 'package_name' => $this->packageName((int) $s->package_id), 'status' => (string) $s->status,
            'expire_at' => $s->expire_at ? (string) $s->expire_at : '未设置',
        ])->all();
    }

    public function freezeUserAccount(int $userId, string $reason = ''): void
    {
        $user = User::find($userId);
        if (!$user) { throw new \RuntimeException('用户不存在。'); }
        if ($user->id === $this->currentUserId()) { throw new \RuntimeException('不能冻结自己的账户。'); }
        $user->status = 0; $user->save();
        $this->upsertUserSetting($userId, 'account_frozen_reason', $reason);
        NotificationService::notify($userId, NotificationService::ACCOUNT_FROZEN, ['原因' => $reason ?: '未说明']);
    }

    public function deleteUserAccount(int $userId): void
    {
        $user = User::find($userId);
        if (!$user) { throw new \RuntimeException('用户不存在。'); }
        Db::startTrans();
        try {
            Subscription::where('user_id', $userId)->delete();
            Order::where('user_id', $userId)->delete();
            Ticket::where('user_id', $userId)->delete();
            BalanceLog::where('user_id', $userId)->delete();
            Db::table('user_settings')->where('user_id', $userId)->delete();
            $user->delete();
            AuditLog::create(['action' => 'account_deleted', 'target_type' => 'user', 'target_id' => $userId]);
            Db::commit();
        } catch (\Exception $e) { Db::rollback(); throw new \RuntimeException('账户删除失败：' . $e->getMessage()); }
    }

    // ==================== 保留: User Level ====================

    public function getUserLevelInfo(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) { throw new \RuntimeException('用户不存在。'); }
        $currentLevel = Db::table('user_levels')->find($user->user_level_id ?? 0);
        $totalConsumption = (float) ($user->total_consumption ?? 0);
        $nextLevel = Db::table('user_levels')->where('is_active', 1)->where('min_consumption', '>', $totalConsumption)->order('level_order', 'asc')->find();
        $upgrade_progress = null;
        if ($nextLevel) {
            $currentMin = (float) ($currentLevel['min_consumption'] ?? 0);
            $nextMin = (float) $nextLevel['min_consumption'];
            $progress = $nextMin > 0 ? (($totalConsumption - $currentMin) / ($nextMin - $currentMin)) * 100 : 0;
            $upgrade_progress = ['percentage' => round(min(100, max(0, $progress)), 1), 'remaining' => max(0, $nextMin - $totalConsumption), 'next_level' => $nextLevel['name']];
        }
        return ['current_level_name' => $currentLevel['name'] ?? '普通用户', 'total_consumption' => $this->money($totalConsumption), 'upgrade_progress' => $upgrade_progress];
    }

    public function getUserLevels(): array { return Db::table('user_levels')->where('is_active', 1)->order('level_order', 'asc')->select()->toArray(); }

    public function checkAndUpgradeUserLevel(int $userId, float $amount = 0): bool
    {
        $user = User::find($userId);
        if (!$user) { throw new \RuntimeException('用户不存在。'); }
        $total = (float) ($user->total_consumption ?? 0) + $amount;
        $user->total_consumption = $total;
        $newLevel = Db::table('user_levels')->where('is_active', 1)->where('min_consumption', '<=', $total)->order('level_order', 'asc')->find();
        $oldId = (int) ($user->user_level_id ?? 0);
        $newId = $newLevel ? (int) $newLevel['id'] : 0;
        if ($oldId > $newId && $oldId > 0) { $user->save(); return false; }
        if ($newId !== $oldId && $newId > 0) { $user->user_level_id = $newId; }
        $user->save();
        return $newId !== $oldId;
    }

    public function adminUserLevels(): array
    {
        $levels = Db::table('user_levels')->order('level_order', 'asc')->select();
        $levelIds = array_column($levels->toArray(), 'id');
        $userCounts = [];
        if (!empty($levelIds)) {
            $rows = User::whereIn('user_level_id', $levelIds)->field('user_level_id, COUNT(*) as cnt')->group('user_level_id')->select();
            foreach ($rows as $row) { $userCounts[(int) $row->user_level_id] = (int) $row->cnt; }
        }
        return $levels->map(function ($level) use ($userCounts) {
            return ['id' => $level['id'], 'name' => $level['name'], 'level_order' => $level['level_order'],
                'min_consumption' => $this->money((float) $level['min_consumption']),
                'discount_rate' => round((float) $level['discount_rate'] * 10, 1) . '折',
                'user_count' => $userCounts[(int) $level['id']] ?? 0,
                'is_active' => (int) $level['is_active']];
        })->toArray();
    }

    // ==================== Private Helpers ====================

    private function currentUser(): User { $u = User::find($this->currentUserId()); if (!$u) { throw new \RuntimeException('用户不存在。'); } return $u; }
    private function currentUserId(): int { return (int) ($this->auth->user()['id'] ?? 0); }
    private function activeSubscription(int $userId): ?Subscription { return Subscription::where('user_id', $userId)->where('status', 'active')->order('id', 'desc')->find(); }
    private function money(float $amount): string { return '¥' . number_format($amount, 2); }
    private function packageName(int $id): string { return $id > 0 ? (Package::find($id)?->name ?: '已删除套餐') : '无套餐'; }
    private function userName(int $uid): string { $u = User::find($uid); return $u ? ((string) ($u->nickname ?: $u->email)) : '未知用户'; }
    private function checkedInToday(User $user): bool { return $user->last_checkin_at && date('Y-m-d', strtotime((string) $user->last_checkin_at)) === date('Y-m-d'); }
    private function userSettingBool(array $prefs, string $key, bool $default): bool { return isset($prefs[$key]) ? ((string) $prefs[$key] === 'true' || (string) $prefs[$key] === '1') : $default; }

    private function userSettings(int $userId): array { return UserSetting::where('user_id', $userId)->column('item_value', 'item_key'); }

    private function upsertUserSetting(int $userId, string $key, string $value): void
    {
        $s = UserSetting::where('user_id', $userId)->where('item_key', $key)->find();
        if ($s) { $s->item_value = $value; $s->save(); return; }
        UserSetting::create(['user_id' => $userId, 'item_key' => $key, 'item_value' => $value]);
    }

    private function upsertSystemSetting(string $key, mixed $value): void
    {
        $this->adminSettingsService->upsertSystemSetting($key, $value);
    }

    private function subscriptionFormats(string $rawUrl): array
    {
        $formats = $this->config->clientFormats();
        return array_map(fn ($f) => ['code' => $f['code'] ?? $f, 'label' => $f['label'] ?? ucfirst($f['code'] ?? $f), 'url' => $rawUrl . '/' . ($f['code'] ?? $f)], $formats);
    }

    private function packageCard(Package $p): array
    {
        $features = json_decode((string) $p->features_json, true);
        return [
            'id' => $p->id, 'name' => $p->name, 'badge' => 'Package', 'description' => $p->description,
            'monthly_price' => (float) $p->price_monthly, 'quarterly_price' => (float) $p->price_quarterly,
            'yearly_price' => (float) $p->price_yearly, 'included_devices' => (int) $p->device_limit,
            'extra_device_price' => (float) $this->config->get('business.extra_device_price', 8),
            'speed_limit' => (int) $p->speed_limit_mbps, 'traffic_limit' => (int) $p->traffic_limit_gb,
            'feature_tags' => is_array($features) ? $features : [],
        ];
    }

    private function orderStatusLabel(string $s): string { return match ($s) { 'pending' => '待支付', 'paid' => '已支付', 'cancelled' => '已取消', 'refunded' => '已退款', default => $s }; }
    private function paymentMethodLabel(string $m): string { return match ($m) { 'balance' => '余额', 'alipay' => '支付宝', 'wechat' => '微信', 'manual' => '人工转账', 'stripe' => 'Stripe', 'usdt' => 'USDT', default => $m ?: '未选择' }; }
    private function ticketStatusLabel(string $s): string { return match ($s) { 'open' => '待处理', 'in_progress' => '处理中', 'replied' => '已回复', 'closed' => '已关闭', default => $s }; }
    private function ticketPriorityLabel(string $p): string { return match ($p) { 'low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急', default => $p }; }

    private function normalizeListFilters(array $f): array
    {
        return ['keyword' => trim((string) ($f['keyword'] ?? '')), 'status' => trim((string) ($f['status'] ?? '')), 'type' => trim((string) ($f['type'] ?? '')), 'date_from' => trim((string) ($f['date_from'] ?? '')), 'date_to' => trim((string) ($f['date_to'] ?? ''))];
    }

    private function batchSetUserStatus(array $ids, int $status): array
    {
        if ($status === 0 && in_array($this->currentUserId(), $ids, true)) { throw new \RuntimeException('不能批量禁用当前管理员账号。'); }
        User::whereIn('id', $ids)->update(['status' => $status]);
        return ['count' => count($ids)];
    }

    private function batchSetUserRole(array $ids, int $role): array { User::whereIn('id', $ids)->update(['role' => $role]); return ['count' => count($ids)]; }

    private function batchDeleteUsers(array $ids): array
    {
        if (in_array($this->currentUserId(), $ids, true)) { throw new \RuntimeException('不能删除当前管理员账号。'); }
        $usersWithOrders = Order::whereIn('user_id', $ids)->distinct()->column('user_id');
        if (!empty($usersWithOrders)) { throw new \RuntimeException('部分用户拥有订单，不能删除。'); }
        UserSetting::whereIn('user_id', $ids)->delete();
        InviteRecord::whereIn('inviter_user_id', $ids)->delete();
        User::whereIn('id', $ids)->delete();
        return ['count' => count($ids), 'deleted' => true];
    }
}
