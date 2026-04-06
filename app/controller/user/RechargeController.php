<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class RechargeController extends BaseController
{
    public function index()
    {
        return $this->render('user/recharge', [
            'pageTitle'    => '账户充值',
            'pageHeadline' => '账户充值',
            'recharge_amounts' => [50, 100, 300, 500, 1000],
        ]);
    }
}
