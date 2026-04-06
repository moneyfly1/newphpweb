<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class SettingsController extends BaseController
{
    public function index()
    {
        return $this->render('admin/settings', array_merge($this->panel->adminSiteSettings(), [
            'navKey'       => 'admin-settings',
            'pageTitle'    => '站点设置',
            'pageHeadline' => '控制前台展示',
            'pageBlurb'    => '这里的内容会直接驱动登录页文案、首页提示和运营公告。',
        ]));
    }

    public function save()
    {
        $this->requireCsrf();

        $data = $this->request->only([
            'app_name',
            'base_url',
            'landing_headline',
            'landing_blurb',
            'login_notice',
            'hero_stat_one',
            'hero_stat_two',
            'hero_stat_three',
            'landing_notice',
            'shop_note',
            'notice_text',
            'support_email',
            'support_qq',
            'subscription_site_url',
            'subscription_site_name',
            'subscription_notice',
            'checkin_reward',
            'extra_device_price',
            'balance_convert_rate',
            'payment_enabled_text',
        ]);

        $this->panel->saveSiteSettings($data);

        return $this->redirectTo('/admin/settings');
    }

    public function advanced()
    {
        $settings = $this->panel->advancedSettings();

        return $this->render('admin/advanced-settings', array_merge($settings, [
            'navKey'       => 'admin-settings',
            'pageTitle'    => '高级设置',
            'pageHeadline' => '系统配置与安全设置',
            'pageBlurb'    => '配置系统级别的安全、支付、邮件、通知和主题设置。',
        ]));
    }

    public function auditLogs()
    {
        $page = (int) $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 20);

        $logs = $this->panel->auditLogs($page, $limit);

        return $this->render('admin/audit-logs', array_merge($logs, [
            'navKey'       => 'admin-settings',
            'pageTitle'    => '审计日志',
            'pageHeadline' => '系统操作记录',
            'pageBlurb'    => '查看所有管理员和系统操作的详细日志。',
            'page'         => $page,
            'limit'        => $limit,
        ]));
    }

    public function emailQueue()
    {
        return $this->render('admin/email-queue', [
            'navKey'       => 'admin-email-queue',
            'pageTitle'    => '邮件队列',
            'pageHeadline' => '邮件队列管理',
            'pageBlurb'    => '监控异步邮件发送状态、失败重试和队列统计。',
        ]);
    }

    public function dataExport()
    {
        return $this->render('admin/data-export', [
            'navKey'       => 'admin-data-export',
            'pageTitle'    => '数据导出',
            'pageHeadline' => '数据导出',
            'pageBlurb'    => '导出用户、订单、订阅和其他重要数据到 CSV 或 JSON 格式。',
        ]);
    }

    public function loginSecurity()
    {
        return $this->render('admin/login-security', [
            'navKey'       => 'admin-login-security',
            'pageTitle'    => '登录安全',
            'pageHeadline' => '登录历史与安全',
            'pageBlurb'    => '监控用户登录活动、检测异常行为和地理位置分析。',
        ]);
    }

    public function batchExport()
    {
        return $this->render('admin/batch-export', [
            'navKey'       => 'admin-batch-export',
            'pageTitle'    => '批量导出',
            'pageHeadline' => '批量导出数据',
            'pageBlurb'    => '一次性导出多个数据集到 ZIP 文件，支持 CSV 和 JSON 格式。',
        ]);
    }

    public function paymentGatewayConfig()
    {
        return $this->render('admin/payment-gateway-config', [
            'navKey'       => 'admin-payment-gateway-config',
            'pageTitle'    => '支付网关配置',
            'pageHeadline' => '支付网关管理',
            'pageBlurb'    => '统一查看、启用、配置和测试支付网关。',
        ]);
    }

    public function nodeManagement()
    {
        return $this->render('admin/node-management', [
            'navKey'       => 'admin-node-management',
            'pageTitle'    => '节点管理',
            'pageHeadline' => '节点资源管理',
            'pageBlurb'    => '统一查看节点、筛选状态、处理批量导入和删除。',
        ]);
    }

    public function sourceManagement()
    {
        return $this->render('admin/source-management', [
            'navKey'       => 'admin-source-management',
            'pageTitle'    => '采集源管理',
            'pageHeadline' => '节点采集源管理',
            'pageBlurb'    => '统一管理采集源、采集日志和源状态切换。',
        ]);
    }

}
