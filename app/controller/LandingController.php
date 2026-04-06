<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;

class LandingController extends BaseController
{
    public function index()
    {
        if ($this->auth->check()) {
            return $this->redirectTo($this->auth->isAdmin() ? '/admin/dashboard' : '/dashboard');
        }

        $site = $this->panel->adminSiteSettings();

        return $this->render('landing/index', [
            'pageTitle'    => '低资源部署友好的代理服务平台',
            'pageHeadline' => $site['landing_headline'],
            'pageBlurb'    => $site['landing_blurb'],
            'heroStats'    => [
                ['label' => '部署体积', 'value' => $site['hero_stat_one']],
                ['label' => '运行模式', 'value' => $site['hero_stat_two']],
                ['label' => '支付流程', 'value' => $site['hero_stat_three']],
            ],
            'siteNotice'   => $site['landing_notice'],
        ]);
    }
}
