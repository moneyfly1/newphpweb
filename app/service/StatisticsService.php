<?php
declare(strict_types=1);

namespace app\service;

use app\model\AuditLog;
use app\model\Coupon;
use app\model\Order;
use app\model\Package;
use app\model\Subscription;
use app\model\Ticket;
use app\model\User;
use app\model\UserLoginLog;
use think\facade\Db;

class StatisticsService
{
    /**
     * 获取系统统计数据
     */
    public function systemStatistics(string $period = '7day'): array
    {
        // 根据期间计算日期范围
        $dates = $this->getDateRange($period);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        // === KPI 数据 ===
        $stats = [
            'users_total' => User::count(),
            'users_active' => User::where('status', 1)->count(),
            'users_disabled' => User::where('status', 0)->count(),

            'orders_total' => Order::count(),
            'orders_pending' => Order::where('status', 'pending')->count(),
            'orders_paid' => Order::where('status', 'paid')->count(),

            'subscriptions_active' => Subscription::where('status', 'active')->count(),
            'subscriptions_expired' => Subscription::where('status', 'expired')->count(),

            'tickets_total' => Ticket::count(),
            'tickets_open' => Ticket::where('status', 'open')->count(),
            'tickets_progress' => Ticket::where('status', 'in_progress')->count(),

            'packages_active' => Package::where('is_active', 1)->count(),
            'coupons_active' => Coupon::where('status', 1)->count(),

            'revenue_today' => Order::where('status', 'paid')
                ->whereDate('paid_at', date('Y-m-d'))
                ->sum('amount_payable') ?? 0,
            'revenue_month' => Order::where('status', 'paid')
                ->whereMonth('paid_at', date('m'))
                ->whereYear('paid_at', date('Y'))
                ->sum('amount_payable') ?? 0,
            'revenue_total' => Order::where('status', 'paid')
                ->whereBetween('paid_at', [$startDate, $endDate])
                ->sum('amount_payable') ?? 0,
        ];

        // === 每日趋势数据 ===
        $stats['daily_revenue'] = $this->getDailyRevenue($startDate, $endDate);
        $stats['daily_users'] = $this->getDailyUsers($startDate, $endDate);

        // === 套餐统计 ===
        $stats['package_stats'] = $this->getPackageStatistics($startDate, $endDate);

        // === 支付方式统计 ===
        $stats['payment_stats'] = $this->getPaymentStatistics($startDate, $endDate);

        // === 地理位置统计 ===
        $stats['geo_stats'] = $this->getGeoStatistics($startDate, $endDate);

        return $stats;
    }

    /**
     * 获取日期范围
     */
    private function getDateRange(string $period): array
    {
        $end = new \DateTime();
        $start = clone $end;

        switch ($period) {
            case '1day':
                $start->modify('-1 day');
                break;
            case '7day':
                $start->modify('-7 days');
                break;
            case '30day':
                $start->modify('-30 days');
                break;
            case '90day':
                $start->modify('-90 days');
                break;
            case 'all':
                $start = new \DateTime('2020-01-01');
                break;
            default:
                $start->modify('-7 days');
        }

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 获取每日收入数据
     */
    private function getDailyRevenue(string $startDate, string $endDate): array
    {
        $dailyData = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->selectRaw('DATE(paid_at) as date, SUM(amount_payable) as amount')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        $dates = [];
        $amounts = [];

        foreach ($dailyData as $record) {
            $dates[] = $record['date'];
            $amounts[] = $record['amount'] ?? 0;
        }

        return [
            'dates' => $dates,
            'amounts' => $amounts,
        ];
    }

    /**
     * 获取每日新增用户数
     */
    private function getDailyUsers(string $startDate, string $endDate): array
    {
        $dailyData = User::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        $dates = [];
        $counts = [];

        foreach ($dailyData as $record) {
            $dates[] = $record['date'];
            $counts[] = $record['count'] ?? 0;
        }

        return [
            'dates' => $dates,
            'counts' => $counts,
        ];
    }

    /**
     * 获取套餐统计数据
     */
    private function getPackageStatistics(string $startDate, string $endDate): array
    {
        $packages = Package::where('is_active', 1)->get();
        $periodRevenue = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('amount_payable') ?? 0;

        // Batch load subscription counts
        $subRows = Subscription::field('package_id, status, COUNT(*) as cnt')
            ->group('package_id, status')->select()->toArray();
        $subMap = [];
        foreach ($subRows as $r) {
            $pid = $r['package_id'];
            if (!isset($subMap[$pid])) { $subMap[$pid] = ['total' => 0, 'active' => 0, 'expired' => 0]; }
            $subMap[$pid]['total'] += $r['cnt'];
            if ($r['status'] === 'active') { $subMap[$pid]['active'] += $r['cnt']; }
            if ($r['status'] === 'expired') { $subMap[$pid]['expired'] += $r['cnt']; }
        }

        // Batch load revenue per package
        $revRows = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->field('package_id, SUM(amount_payable) as revenue')
            ->group('package_id')->select()->toArray();
        $revMap = [];
        foreach ($revRows as $r) { $revMap[$r['package_id']] = (float) $r['revenue']; }

        $stats = [];
        foreach ($packages as $package) {
            $s = $subMap[$package->id] ?? ['total' => 0, 'active' => 0, 'expired' => 0];
            $rev = $revMap[$package->id] ?? 0;
            $stats[] = [
                'name' => $package->name,
                'total_subscriptions' => $s['total'],
                'active_subscriptions' => $s['active'],
                'expired_subscriptions' => $s['expired'],
                'monthly_revenue' => $rev,
                'percentage' => $periodRevenue > 0 ? ($rev / $periodRevenue * 100) : 0,
            ];
        }

        return $stats;
    }

    /**
     * 获取支付方式统计
     */
    private function getPaymentStatistics(string $startDate, string $endDate): array
    {
        $paymentMethods = [
            'manual' => '人工转账',
            'alipay' => '支付宝',
            'wechat' => '微信',
            'stripe' => 'Stripe',
        ];

        $totalAmount = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('amount_payable') ?? 0;

        $stats = [];

        foreach ($paymentMethods as $method => $label) {
            $orders = Order::where('status', 'paid')
                ->where('payment_method', $method)
                ->whereBetween('paid_at', [$startDate, $endDate])
                ->get();

            $count = $orders->count();
            $amount = $orders->sum('amount_payable') ?? 0;
            $avg = $count > 0 ? $amount / $count : 0;

            if ($count > 0) {
                $stats[] = [
                    'method' => $label,
                    'order_count' => $count,
                    'total_amount' => $amount,
                    'avg_amount' => $avg,
                    'percentage' => $totalAmount > 0 ? ($amount / $totalAmount * 100) : 0,
                ];
            }
        }

        return $stats;
    }

    /**
     * 获取地理位置统计
     */
    private function getGeoStatistics(string $startDate, string $endDate): array
    {
        // 如果有 user_login_logs 表且包含位置信息，使用该表
        // 否则返回按国家的用户统计

        $geoData = [];

        try {
            // 尝试从登录日志中获取地理信息
            $logs = \DB::table('user_login_logs')
                ->selectRaw('country, COUNT(DISTINCT user_id) as user_count, SUM(COALESCE((SELECT SUM(amount_payable) FROM `orders` WHERE user_id = user_login_logs.user_id AND status = "paid"), 0)) as revenue')
                ->whereNotNull('country')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->groupBy('country')
                ->orderByDesc('user_count')
                ->limit(10)
                ->get();

            foreach ($logs as $record) {
                $geoData[] = [
                    'country' => $record->country ?: '未知',
                    'user_count' => $record->user_count ?? 0,
                    'revenue' => $record->revenue ?? 0,
                ];
            }
        } catch (\Exception $e) {
            // 灰雅降级：按用户统计
            $geoData = [];
        }

        // 如果没有获取到地理数据，返回按用户计数的数据
        if (empty($geoData)) {
            $geoData[] = [
                'country' => '全部',
                'user_count' => User::count(),
                'revenue' => Order::where('status', 'paid')->sum('amount_payable') ?? 0,
            ];
        }

        return $geoData;
    }

    /**
     * 获取用户行为分析
     */
    public function getUserBehaviorAnalysis(string $period = '7day'): array
    {
        $dateRange = $this->getDateRange($period);

        // 新用户注册
        $newUsers = User::whereBetween('created_at', $dateRange)
            ->count();

        // 活跃用户
        $activeUsers = UserLoginLog::selectRaw('DISTINCT user_id')
            ->whereBetween('created_at', $dateRange)
            ->count();

        // 订单数量和金额
        $orders = Order::whereBetween('created_at', $dateRange)
            ->where('status', 'paid')
            ->get();

        $orderCount = $orders->count();
        $revenue = (float) $orders->sum('amount_payable');
        $avgOrderValue = $orderCount > 0 ? $revenue / $orderCount : 0;

        return [
            'new_users' => $newUsers,
            'active_users' => $activeUsers,
            'order_count' => $orderCount,
            'total_revenue' => $revenue,
            'avg_order_value' => $avgOrderValue,
            'period' => $period,
        ];
    }

    /**
     * 获取设备分析数据
     */
    public function getDeviceAnalysis(string $period = '7day'): array
    {
        $dateRange = $this->getDateRange($period);

        $devices = UserLoginLog::selectRaw('user_agent, COUNT(*) as count')
            ->whereBetween('created_at', $dateRange)
            ->whereNotNull('user_agent')
            ->groupBy('user_agent')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();

        return array_map(function($device) {
            return [
                'device_name' => substr($device['user_agent'] ?? '未知', 0, 80),
                'count' => $device['count'] ?? 0
            ];
        }, $devices);
    }

    /**
     * 获取流失用户预警
     */
    public function getChurnWarning(int $daysInactive = 30): array
    {
        $inactiveThreshold = date('Y-m-d H:i:s', strtotime("-{$daysInactive} days"));

        $churnUsers = User::where('status', 1)
            ->where(function($q) use ($inactiveThreshold) {
                $q->whereNull('last_login_at')
                  ->orWhere('last_login_at', '<', $inactiveThreshold);
            })
            ->limit(20)
            ->get()
            ->toArray();

        return array_map(function($user) {
            return [
                'id' => $user['id'],
                'email' => $user['email'],
                'nickname' => $user['nickname'],
                'last_login' => $user['last_login_at'] ?: '从未登录',
                'created_at' => $user['created_at'],
            ];
        }, $churnUsers);
    }

    /**
     * 获取套餐分布统计
     */
    public function getPackageDistribution(): array
    {
        $packages = Subscription::selectRaw('package_id, status, COUNT(*) as count, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_count')
            ->groupBy('package_id', 'status')
            ->get()
            ->groupBy('package_id');

        return Package::where('is_active', 1)
            ->get()
            ->map(function(Package $pkg) use ($packages) {
                $stats = $packages->get($pkg->id, collect());
                $total = $stats->sum('count');
                $active = $stats->sum('active_count');

                return [
                    'name' => $pkg->name,
                    'total' => $total,
                    'active' => $active,
                    'expired' => $total - $active,
                    'percentage' => $total > 0 ? round($active / $total * 100, 1) : 0,
                ];
            })
            ->toArray();
    }

    /**
     * 审计日志
     */
    public function auditLogs(int $page = 1, int $limit = 20): array
    {
        $skip = ($page - 1) * $limit;
        $total = AuditLog::count();
        $items = AuditLog::order('created_at', 'desc')
            ->limit($limit)
            ->offset($skip)
            ->select()
            ->toArray();

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'items' => array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'action' => $item['action'],
                    'target' => $item['target_type'] . ':' . $item['target_id'],
                    'admin_email' => User::find($item['admin_id'])?->email ?? 'Unknown',
                    'old_values' => $item['old_values'] ? json_decode($item['old_values'], true) : [],
                    'new_values' => $item['new_values'] ? json_decode($item['new_values'], true) : [],
                    'created_at' => $item['created_at'],
                ];
            }, $items),
        ];
    }

    /**
     * 7天趋势
     */
    private function sevenDayTrend(): array
    {
        $items = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $sum = (float) Order::where('status', 'paid')->whereDay('paid_at', $date)->sum('amount_payable');
            $items[] = [
                'label' => date('D', strtotime($date)),
                'value' => max(24, (int) round($sum / 10)),
            ];
        }

        return $items;
    }

    private function money(float $amount): string
    {
        return '¥' . number_format($amount, 2);
    }
}
