<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class InviteController extends BaseController
{
    public function index()
    {
        return $this->render('user/invite', array_merge($this->panel->invite(), [
            'navKey'       => 'invite',
            'pageTitle'    => '邀请返利',
            'pageHeadline' => '邀请链接与奖励进度',
            'pageBlurb'    => '展示邀请码、转化率、待结算金额和最近邀请明细。',
        ]));
    }
}
