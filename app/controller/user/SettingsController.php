<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class SettingsController extends BaseController
{
    public function index()
    {
        return $this->render('user/settings', array_merge($this->panel->settings(), [
            'navKey'       => 'settings',
            'pageTitle'    => '用户设置',
            'pageHeadline' => '资料、密码与通知偏好',
            'pageBlurb'    => '通知和隐私开关会即时保存，密码区域保留规则提示。',
        ]));
    }
}
