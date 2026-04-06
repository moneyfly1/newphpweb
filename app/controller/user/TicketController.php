<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class TicketController extends BaseController
{
    public function index()
    {
        return $this->render('user/tickets', array_merge($this->panel->tickets(), [
            'navKey'       => 'tickets',
            'pageTitle'    => '工单系统',
            'pageHeadline' => '售后与技术支持',
            'pageBlurb'    => '新建工单采用抽屉式表单，列表保留优先级和最近更新时间。',
        ]));
    }
}
