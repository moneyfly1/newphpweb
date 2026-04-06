<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class CouponController extends BaseController
{
    public function index()
    {
        $filters = [];
        foreach (['keyword', 'status', 'type'] as $field) {
            if (($value = $this->request->get($field, '')) !== '') {
                $filters[$field] = $value;
            }
        }

        $coupons = $this->panel->adminCoupons($filters);
        $couponSummary = [
            'total' => count($coupons),
            'enabled' => count(array_filter($coupons, fn ($coupon) => !empty($coupon['status_flag']))),
            'disabled' => count(array_filter($coupons, fn ($coupon) => empty($coupon['status_flag']))),
        ];

        return $this->render('admin/coupons', [
            'navKey'       => 'admin-coupons',
            'pageTitle'    => '优惠券管理',
            'pageHeadline' => '优惠券与活动投放',
            'pageBlurb'    => '统一搜索、类型筛选、状态筛选与批量操作。',
            'coupons'      => $coupons,
            'filters'      => $filters,
            'couponSummary'=> $couponSummary,
        ]);
    }
}
