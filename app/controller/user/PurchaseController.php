<?php
declare (strict_types = 1);

namespace app\controller\user;

use app\BaseController;

class PurchaseController extends BaseController
{
    public function index()
    {
        // 获取所有活跃套餐
        $packages = \app\model\Package::where('is_active', 1)
            ->order('sort_order', 'asc')
            ->select();

        return $this->render('user/purchase', [
            'navKey'       => 'purchase',
            'pageTitle'    => '购买套餐',
            'pageHeadline' => '购买套餐',
            'pageBlurb'    => '选择适合你的套餐并快速完成支付。',
            'packages'     => $packages,
        ]);
    }

    /**
     * 套餐详情和购买选项
     */
    public function details(int $packageId)
    {
        $package = \app\model\Package::findOrFail($packageId);

        if (!$package->is_active) {
            return $this->jsonError('套餐已下架', 404);
        }

        $subscription = \app\model\Subscription::where('user_id', (int) ($this->auth->user()['id'] ?? 0))
            ->where('package_id', $packageId)
            ->findOrEmpty();

        return $this->render('user/purchase-details', [
            'navKey' => 'purchase',
            'pageTitle' => '套餐详情',
            'pageHeadline' => '套餐详情与购买',
            'pageBlurb' => '查看套餐详细参数并直接创建订单。',
            'package' => $package,
            'subscription' => $subscription,
            'currentBalance' => (float) (($this->auth->user()['balance'] ?? 0)),
        ]);
    }
}
