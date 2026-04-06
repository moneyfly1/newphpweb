<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class OrderController extends BaseController
{
    public function index()
    {
        $status = (string) $this->request->get('status', 'all');

        return $this->render('user/orders', array_merge($this->panel->orders($status), [
            'navKey'       => 'orders',
            'pageTitle'    => '订单管理',
            'pageHeadline' => '订单与充值记录',
            'pageBlurb'    => '按状态筛选、继续支付和取消操作都会在这里收口。',
        ]));
    }
}
