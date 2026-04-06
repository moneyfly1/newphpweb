<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class SubscriptionController extends BaseController
{
    public function index()
    {
        return $this->render('user/subscriptions', array_merge($this->panel->subscription(), [
            'navKey'       => 'subscriptions',
            'pageTitle'    => '订阅管理',
            'pageHeadline' => '订阅控制台',
            'pageBlurb'    => '管理链接格式、设备限制、升级试算和高危动作入口。',
        ]));
    }
}
