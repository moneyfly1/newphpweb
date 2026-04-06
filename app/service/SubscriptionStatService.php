<?php
declare (strict_types = 1);

namespace app\service;

use app\model\SubscriptionAccessLog;
use app\model\Subscription;
use think\facade\Db;

/**
 * 订阅统计分析服务
 */
class SubscriptionStatService
{
    /**
     * 获取订阅统计信息
     */
    public function getSubscriptionStats(): array
    {
        $totalSubs = Subscription::count();
        $activeSubs = Subscription::where('status', 'active')
            ->where('expire_at', '>', date('Y-m-d H:i:s'))
            ->count();
        $expiredSubs = Subscription::where('expire_at', '<=', date('Y-m-d H:i:s'))->count();

        // 近7天新增
        $sevenDaysAgo = date('Y-m-d H:i:s', time() - 7 * 86400);
        $newInSevenDays = Subscription::where('created_at', '>=', $sevenDaysAgo)->count();

        // 格式分布
        $formatDistribution = Db::table('subscription_access_logs')
            ->field('format, COUNT(*) as count')
            ->group('format')
            ->select()
            ->toArray();

        $formatDist = [];
        foreach ($formatDistribution as $item) {
            $formatDist[$item['format']] = $item['count'];
        }

        return [
            'total_subscriptions'     => $totalSubs,
            'active_subscriptions'    => $activeSubs,
            'expired_subscriptions'   => $expiredSubs,
            'active_rate'             => $totalSubs > 0 ? round(($activeSubs / $totalSubs) * 100, 2) : 0,
            'new_in_seven_days'       => $newInSevenDays,
            'format_distribution'     => $formatDist,
        ];
    }

    /**
     * 获取格式使用统计
     */
    public function getFormatUsageStats(int $days = 7): array
    {
        $date = date('Y-m-d H:i:s', time() - $days * 86400);

        $formatStats = Db::table('subscription_access_logs')
            ->where('accessed_at', '>=', $date)
            ->field('format, COUNT(*) as access_count')
            ->group('format')
            ->order('access_count', 'desc')
            ->select()
            ->toArray();

        $result = [];
        $total = 0;

        foreach ($formatStats as $stat) {
            $result[$stat['format']] = [
                'count' => (int) $stat['access_count'],
                'percentage' => 0,
            ];
            $total += (int) $stat['access_count'];
        }

        // 计算百分比
        if ($total > 0) {
            foreach ($result as &$item) {
                $item['percentage'] = round(($item['count'] / $total) * 100, 2);
            }
        }

        return $result;
    }

    /**
     * 获取协议类型分布
     */
    public function getProtocolDistribution(int $days = 7): array
    {
        // 这个需要在节点数据中记录协议类型
        // 示例实现：假设节点在 config_json 中存储
        $date = date('Y-m-d H:i:s', time() - $days * 86400);

        return [
            'vmess' => 25,
            'vless' => 20,
            'ss' => 15,
            'ssr' => 10,
            'trojan' => 30,
        ];
    }

    /**
     * 获取最受欢迎的格式（TOP 5）
     */
    public function getTopFormats(int $limit = 5): array
    {
        $date = date('Y-m-d H:i:s', time() - 30 * 86400);

        return Db::table('subscription_access_logs')
            ->where('accessed_at', '>=', $date)
            ->field('format, COUNT(*) as count')
            ->group('format')
            ->order('count', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取用户订阅使用情况
     */
    public function getUserSubscriptionUsage(int $userId): array
    {
        $subscription = Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->find();

        if (!$subscription) {
            return [];
        }

        // 获取该订阅的访问统计
        $accessLogs = SubscriptionAccessLog::where('subscription_id', $subscription->id)
            ->field('format, COUNT(*) as count')
            ->group('format')
            ->select()
            ->toArray();

        return [
            'subscription_id' => $subscription->id,
            'format_usage'    => $accessLogs,
            'total_accesses'  => SubscriptionAccessLog::where('subscription_id', $subscription->id)->count(),
        ];
    }

    /**
     * 获取订阅增长趋势
     */
    public function getSubscriptionGrowthTrend(int $days = 30): array
    {
        $trends = [];
        
        for ($i = $days; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - $i * 86400);
            $count = Subscription::where('created_at', 'like', $date . '%')->count();
            $trends[$date] = $count;
        }

        return $trends;
    }

    /**
     * 获取格式相容性报告
     */
    public function getFormatCompatibilityReport(): array
    {
        return [
            'vmess' => [
                'supported_formats' => ['clash', 'base64', 'quantumult', 'quantumultx', 'surge', 'loon'],
                'best_format' => 'clash',
            ],
            'vless' => [
                'supported_formats' => ['clash', 'base64', 'quantumultx', 'surge'],
                'best_format' => 'clash',
            ],
            'ss' => [
                'supported_formats' => ['clash', 'base64', 'quantumult', 'quantumultx', 'surge', 'loon', 'ssr'],
                'best_format' => 'base64',
            ],
            'ssr' => [
                'supported_formats' => ['ssr', 'base64', 'clash'],
                'best_format' => 'ssr',
            ],
            'trojan' => [
                'supported_formats' => ['clash', 'base64', 'quantumultx', 'surge', 'loon'],
                'best_format' => 'clash',
            ],
        ];
    }
}
