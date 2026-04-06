<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class KnowledgeController extends BaseController
{
    public function index()
    {
        return $this->render('user/knowledge', [
            'navKey'       => 'knowledge',
            'pageTitle'    => '知识库',
            'pageHeadline' => '教程与说明文档',
            'pageBlurb'    => '帮助用户快速理解订阅格式、客户端导入、节点与支付流程。',
        ]);
    }
}
