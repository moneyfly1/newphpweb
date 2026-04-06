<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class OrderController extends BaseController
{
    public function index()
    {
        $filters = [];
        foreach (['keyword', 'status', 'type', 'date_from', 'date_to'] as $field) {
            if (($value = $this->request->get($field, '')) !== '') {
                $filters[$field] = $value;
            }
        }

        $orders = $this->panel->adminOrders($filters);
        $stats = [
            'total' => count($orders),
            'pending' => count(array_filter($orders, fn ($order) => ($order['status_key'] ?? '') === 'pending')),
            'paid' => count(array_filter($orders, fn ($order) => ($order['status_key'] ?? '') === 'paid')),
            'refunded' => count(array_filter($orders, fn ($order) => ($order['status_key'] ?? '') === 'refunded')),
        ];

        return $this->render('admin/orders', [
            'navKey'       => 'admin-orders',
            'pageTitle'    => '订单管理',
            'pageHeadline' => '支付流与退款操作',
            'pageBlurb'    => '统一搜索、状态筛选、批量操作和支付流处理。',
            'orders'       => $orders,
            'filters'      => $filters,
            'stats'        => $stats,
        ]);
    }
}
