<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class SubscriptionController extends BaseController
{
    public function index()
    {
        $filters = [];
        foreach (['keyword', 'status', 'type', 'date_from', 'date_to'] as $field) {
            if (($value = $this->request->get($field, '')) !== '') {
                $filters[$field] = $value;
            }
        }

        return $this->render('admin/subscriptions', [
            'navKey'         => 'admin-subscriptions',
            'pageTitle'      => '订阅管理',
            'pageHeadline'   => '后台订阅控制台',
            'pageBlurb'      => '统一搜索用户、订阅、套餐、状态与批量操作。',
            'subscriptions'  => $this->panel->adminSubscriptions($filters),
            'filters'        => $filters,
        ]);
    }

}
