<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class NodesController extends BaseController
{
    public function index()
    {
        return $this->render('user/nodes', [
            'navKey'       => 'nodes',
            'pageTitle'    => '节点中心',
            'pageHeadline' => '订阅节点与格式说明',
            'pageBlurb'    => '展示真实订阅格式、设备状态与客户端下载建议。',
            'subscription' => $this->panel->subscription(),
        ]);
    }
}
