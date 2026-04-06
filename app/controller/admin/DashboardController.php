<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class DashboardController extends BaseController
{
    public function index()
    {
        $stats = $this->panel->systemStatistics('7day');
        $recentLogins = $this->panel->getRecentLogins(8);
        $suspiciousRaw = $this->panel->detectSuspiciousLogins();
        $suspiciousLogins = [
            'total_alerts' => count($suspiciousRaw),
            'items' => $suspiciousRaw,
        ];

        return $this->render('admin/dashboard', array_merge($this->panel->adminDashboard(), [
            'navKey'            => 'admin-dashboard',
            'pageTitle'         => '管理仪表盘',
            'pageHeadline'      => '运营总览',
            'pageBlurb'         => '收入趋势、异常提醒和高优先级工作台集中展示。',
            'systemStats'       => $stats,
            'recentLogins'      => $recentLogins,
            'suspiciousLogins'  => $suspiciousLogins,
        ]));
    }

    public function statistics()
    {
        return $this->render('admin/statistics', [
            'navKey'       => 'admin-statistics',
            'pageTitle'    => '统计分析',
            'pageHeadline' => '统计仪表盘',
            'pageBlurb'    => '实时系统数据、趋势分析和性能监控。',
        ]);
    }

    /**
     * 用户行为分析
     */
    public function analysis()
    {
        $period = $this->request->get('period', '7day');
        $behavior = $this->panel->getUserBehaviorAnalysis($period);
        $devices = $this->panel->getDeviceAnalysis($period);
        $packageDist = $this->panel->getPackageDistribution();
        $churnWarning = $this->panel->getChurnWarning(30);

        return $this->render('admin/analysis', [
            'navKey'            => 'admin-analysis',
            'pageTitle'         => '用户分析',
            'pageHeadline'      => '用户行为与趋势分析',
            'pageBlurb'         => '用户活跃度、设备分布、流失预警和套餐分布统计。',
            'period'            => $period,
            'behavior'          => $behavior,
            'devices'           => $devices,
            'packageDist'       => $packageDist,
            'churnWarning'      => $churnWarning,
        ]);
    }
}

