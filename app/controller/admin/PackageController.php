<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class PackageController extends BaseController
{
    public function index()
    {
        $filters = [];
        foreach (['keyword', 'status'] as $field) {
            if (($value = $this->request->get($field, '')) !== '') {
                $filters[$field] = $value;
            }
        }

        $packages = $this->panel->adminPackages($filters);
        $packageSummary = [
            'total' => count($packages),
            'active' => count(array_filter($packages, fn ($package) => !empty($package['is_active']))),
            'inactive' => count(array_filter($packages, fn ($package) => empty($package['is_active']))),
        ];

        return $this->render('admin/packages', [
            'navKey'       => 'admin-packages',
            'pageTitle'    => '套餐管理',
            'pageHeadline' => '套餐与特性配置',
            'pageBlurb'    => '统一搜索、状态筛选和套餐维护入口。',
            'packages'     => $packages,
            'filters'      => $filters,
            'packageSummary'=> $packageSummary,
        ]);
    }
}
