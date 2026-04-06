<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class DashboardController extends BaseController
{
    public function index()
    {
        return $this->render('user/dashboard', array_merge($this->panel->dashboard(), [
            'navKey'       => 'dashboard',
            'pageTitle'    => '用户仪表盘',
            'pageHeadline' => '账户概览与高频操作',
            'pageBlurb'    => '把签到、订阅导入、快捷跳转和余额信息压缩在一个面板里。',
        ]));
    }
}
