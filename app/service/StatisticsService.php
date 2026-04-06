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
    public function systemStatistics(string $period = '7day'): array
    {
        $dates = $this->getDateRange($period);
        $startDate = $dates['start'];
        $endDate = $dates['end'];
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd = date('Y-m-t 23:59:59');

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

            'revenue_today' => (float) Order::where('status', 'paid')
                ->whereRaw("DATE(paid_at) = ?", [$today])
                ->sum('amount_payable'),
            'revenue_month' => (float) Order::where('status', 'paid')
                ->whereBetween('paid_at', [$monthStart, $monthEnd])
                ->sum('amount_payable'),
            'revenue_total' => (float) Order::where('status', 'paid')
                ->whereBetween('paid_at', [$startDate, $endDate])
                ->sum('amount_payable'),
        ];

        $stats['daily_revenue'] = $this->getDailyRevenue($startDate, $endDate);
        $stats['daily_users'] = $this->getDailyUsers($startDate, $endDate);
        $stats['package_stats'] = $this->getPackageStatistics($startDate, $endDate);
        $stats['payment_stats'] = $this->getPaymentStatistics($startDate, $endDate);
        $stats['geo_stats'] = $this->getGeoStatistics();

        return $stats;
    }

    private function getDateRange(string $period): array
    {
        $end = new \DateTime();
        $start = clone $end;

        match ($period) {
            '1day' => $start->modify('-1 day'),
            '7day' => $start->modify('-7 days'),
            '30day' => $start->modify('-30 days'),
            '90day' => $start->modify('-90 days'),
            'all' => $start = new \DateTime('2020-01-01'),
            default => $start->modify('-7 days'),
        };

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }

    private function getDailyRevenue(string $startDate, string $endDate): array
    {
        $rows = Db::table('orders')
            ->whereRaw("status = 'paid' AND paid_at BETWEEN ? AND ?", [$startDate, $endDate])
            ->fieldRaw("DATE(paid_at) as d, SUM(amount_payable) as amount")
            ->group('d')->order('d')->select()->toArray();

        $dates = [];
        $amounts = [];
        foreach ($rows as $r) {
            $dates[] = $r['d'];
            $amounts[] = (float) ($r['amount'] ?? 0);
        }
        return ['dates' => $dates, 'amounts' => $amounts];
    }

    private function getDailyUsers(string $startDate, string $endDate): array
    {
        $rows = Db::table('users')
            ->whereRaw("created_at BETWEEN ? AND ?", [$startDate, $endDate])
            ->fieldRaw("DATE(created_at) as d, COUNT(*) as cnt")
            ->group('d')->order('d')->select()->toArray();

        $dates = [];
        $counts = [];
        foreach ($rows as $r) {
            $dates[] = $r['d'];
            $counts[] = (int) ($r['cnt'] ?? 0);
        }
        return ['dates' => $dates, 'counts' => $counts];
    }

    // __PLACEHOLDER_STATS_2__

    private function getPackageStatistics(string $startDate, string $endDate): array
    {
        $packages = Package::where('is_active', 1)->order('sort_order')->select();
        $periodRevenue = (float) Order::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('amount_payable');

        $subRows = Subscription::field('package_id, status, COUNT(*) as cnt')
            ->group('package_id, status')->select()->toArray();
        $subMap = [];
        foreach ($subRows as $r) {
            $pid = $r['package_id'];
            if (!isset($subMap[$pid])) { $subMap[$pid] = ['total' => 0, 'active' => 0, 'expired' => 0]; }
            $subMap[$pid]['total'] += (int) $r['cnt'];
            if ($r['status'] === 'active') { $subMap[$pid]['active'] += (int) $r['cnt']; }
            if ($r['status'] === 'expired') { $subMap[$pid]['expired'] += (int) $r['cnt']; }
        }

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
                'percentage' => $periodRevenue > 0 ? round($rev / $periodRevenue * 100, 1) : 0,
            ];
        }
        return $stats;
    }

    private function getPaymentStatistics(string $startDate, string $endDate): array
    {
        $rows = Db::table('orders')
            ->whereRaw("status = 'paid' AND paid_at BETWEEN ? AND ?", [$startDate, $endDate])
            ->fieldRaw("payment_method, COUNT(*) as cnt, SUM(amount_payable) as total")
            ->group('payment_method')->select()->toArray();

        $totalAmount = 0;
        foreach ($rows as $r) { $totalAmount += (float) $r['total']; }

        $labels = [
            'manual' => '人工转账', 'alipay' => '支付宝', 'wechat' => '微信',
            'stripe' => 'Stripe', 'balance' => '余额支付', 'usdt' => 'USDT',
        ];

        $stats = [];
        foreach ($rows as $r) {
            $method = $r['payment_method'] ?? 'unknown';
            $cnt = (int) $r['cnt'];
            $amount = (float) $r['total'];
            $stats[] = [
                'method' => $labels[$method] ?? $method,
                'order_count' => $cnt,
                'total_amount' => $amount,
                'avg_amount' => $cnt > 0 ? round($amount / $cnt, 2) : 0,
                'percentage' => $totalAmount > 0 ? round($amount / $totalAmount * 100, 1) : 0,
            ];
        }
        return $stats;
    }

    private function getGeoStatistics(): array
    {
        try {
            $rows = Db::table('user_login_logs')
                ->where('country', '<>', '')
                ->fieldRaw("country, COUNT(DISTINCT user_id) as user_count")
                ->group('country')->order('user_count', 'desc')
                ->limit(10)->select()->toArray();

            if (!empty($rows)) {
                return array_map(fn($r) => [
                    'country' => $r['country'] ?: '未知',
                    'user_count' => (int) ($r['user_count'] ?? 0),
                    'revenue' => 0,
                ], $rows);
            }
        } catch (\Exception $e) {}

        return [[
            'country' => '全部',
            'user_count' => User::count(),
            'revenue' => (float) Order::where('status', 'paid')->sum('amount_payable'),
        ]];
    }

    // __PLACEHOLDER_STATS_3__

    public function getRecentLogins(int $limit = 20): array
    {
        try {
            $rows = Db::table('user_login_logs')
                ->order('created_at', 'desc')
                ->limit($limit)
                ->select()->toArray();

            return array_map(function ($r) {
                $user = User::find($r['user_id']);
                return [
                    'id' => $r['id'],
                    'user_id' => $r['user_id'],
                    'email' => $user->email ?? 'Unknown',
                    'ip_address' => $r['ip_address'] ?? '',
                    'device_type' => $r['device_type'] ?? '',
                    'country' => $r['country'] ?? '',
                    'city' => $r['city'] ?? '',
                    'status' => $r['status'] ?? 'success',
                    'created_at' => $r['created_at'] ?? '',
                ];
            }, $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function detectSuspiciousLogins(): array
    {
        try {
            // 查找同一 IP 多个用户登录的情况
            $rows = Db::table('user_login_logs')
                ->fieldRaw("ip_address, COUNT(DISTINCT user_id) as user_count, MAX(created_at) as last_at")
                ->where('status', 'success')
                ->whereRaw("created_at >= datetime('now', '-7 days')")
                ->group('ip_address')
                ->having('user_count > 2')
                ->order('user_count', 'desc')
                ->limit(10)->select()->toArray();

            return array_map(fn($r) => [
                'ip_address' => $r['ip_address'],
                'user_count' => (int) $r['user_count'],
                'last_at' => $r['last_at'] ?? '',
            ], $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getUserBehaviorAnalysis(string $period = '7day'): array
    {
        $dates = $this->getDateRange($period);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        $newUsers = User::whereBetween('created_at', [$startDate, $endDate])->count();

        $activeUsers = 0;
        try {
            $row = Db::table('user_login_logs')
                ->whereRaw("created_at BETWEEN ? AND ?", [$startDate, $endDate])
                ->fieldRaw("COUNT(DISTINCT user_id) as cnt")
                ->find();
            $activeUsers = (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {}

        $orderCount = Order::where('status', 'paid')
            ->whereBetween('created_at', [$startDate, $endDate])->count();
        $revenue = (float) Order::where('status', 'paid')
            ->whereBetween('created_at', [$startDate, $endDate])->sum('amount_payable');

        return [
            'new_users' => $newUsers,
            'active_users' => $activeUsers,
            'order_count' => $orderCount,
            'total_revenue' => $revenue,
            'avg_order_value' => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0,
            'period' => $period,
        ];
    }

    public function getDeviceAnalysis(string $period = '7day'): array
    {
        $dates = $this->getDateRange($period);
        try {
            $rows = Db::table('user_login_logs')
                ->whereRaw("created_at BETWEEN ? AND ? AND user_agent IS NOT NULL AND user_agent != ''", [$dates['start'], $dates['end']])
                ->fieldRaw("user_agent, COUNT(*) as cnt")
                ->group('user_agent')->order('cnt', 'desc')
                ->limit(10)->select()->toArray();

            return array_map(fn($r) => [
                'device_name' => mb_substr($r['user_agent'] ?? '未知', 0, 80),
                'count' => (int) ($r['cnt'] ?? 0),
            ], $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    // __PLACEHOLDER_STATS_4__

    public function getChurnWarning(int $daysInactive = 30): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$daysInactive} days"));

        $rows = User::where('status', 1)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_login_at')
                  ->whereOr('last_login_at', '<', $threshold);
            })
            ->limit(20)
            ->order('last_login_at')
            ->select()->toArray();

        return array_map(fn($u) => [
            'id' => $u['id'],
            'email' => $u['email'],
            'nickname' => $u['nickname'] ?? '',
            'last_login' => $u['last_login_at'] ?: '从未登录',
            'created_at' => $u['created_at'],
        ], $rows);
    }

    public function getPackageDistribution(): array
    {
        $subRows = Subscription::field('package_id, status, COUNT(*) as cnt')
            ->group('package_id, status')->select()->toArray();

        $subMap = [];
        foreach ($subRows as $r) {
            $pid = $r['package_id'];
            if (!isset($subMap[$pid])) { $subMap[$pid] = ['total' => 0, 'active' => 0]; }
            $subMap[$pid]['total'] += (int) $r['cnt'];
            if ($r['status'] === 'active') { $subMap[$pid]['active'] += (int) $r['cnt']; }
        }

        $packages = Package::where('is_active', 1)->order('sort_order')->select()->toArray();

        return array_map(function ($pkg) use ($subMap) {
            $s = $subMap[$pkg['id']] ?? ['total' => 0, 'active' => 0];
            return [
                'name' => $pkg['name'],
                'total' => $s['total'],
                'active' => $s['active'],
                'expired' => $s['total'] - $s['active'],
                'percentage' => $s['total'] > 0 ? round($s['active'] / $s['total'] * 100, 1) : 0,
            ];
        }, $packages);
    }

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
                    'action' => $item['action'] ?? '',
                    'target' => ($item['target_type'] ?? '') . ':' . ($item['target_id'] ?? ''),
                    'admin_email' => User::find($item['actor_user_id'] ?? 0)?->email ?? 'System',
                    'detail' => $item['detail_json'] ? json_decode($item['detail_json'], true) : [],
                    'created_at' => $item['created_at'] ?? '',
                ];
            }, $items),
        ];
    }

    public function sevenDayTrend(): array
    {
        $items = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $sum = (float) Order::where('status', 'paid')
                ->whereRaw("DATE(paid_at) = ?", [$date])
                ->sum('amount_payable');
            $items[] = [
                'label' => date('D', strtotime($date)),
                'value' => max(24, (int) round($sum / 10)),
            ];
        }
        return $items;
    }

    public function getLoginsByCountry(): array
    {
        try {
            $rows = Db::table('user_login_logs')
                ->where('country', '<>', '')
                ->fieldRaw("country, COUNT(*) as cnt")
                ->group('country')->order('cnt', 'desc')
                ->limit(10)->select()->toArray();

            return array_map(fn($r) => [
                'country' => $r['country'] ?: '未知',
                'count' => (int) ($r['cnt'] ?? 0),
            ], $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getLoginAnomalies(): array
    {
        return $this->detectSuspiciousLogins();
    }

    private function money(float $amount): string
    {
        return '¥' . number_format($amount, 2);
    }
}