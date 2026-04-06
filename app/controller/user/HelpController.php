<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class HelpController extends BaseController
{
    public function index()
    {
        return $this->render('user/help', [
            'navKey'       => 'help',
            'pageTitle'    => '帮助中心',
            'pageHeadline' => '常见问题与使用帮助',
            'pageBlurb'    => '统一收纳订阅导入、购买、充值、工单与账户问题。',
        ]);
    }
}
