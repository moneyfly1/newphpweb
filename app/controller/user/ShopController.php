<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class ShopController extends BaseController
{
    public function index()
    {
        return $this->render('user/shop', array_merge($this->panel->shop(), [
            'navKey'       => 'shop',
            'pageTitle'    => '套餐商店',
            'pageHeadline' => '套餐购买与自定义计算',
            'pageBlurb'    => '支持设备数、时长、优惠码和多支付方式切换，支付状态会实时回流。',
        ]));
    }
}
