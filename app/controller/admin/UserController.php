<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class UserController extends BaseController
{
    public function index()
    {
        $filters = [];
        foreach (['email', 'role', 'status', 'balance_min', 'balance_max', 'created_from', 'created_to'] as $field) {
            if ($value = $this->request->get($field)) {
                $filters[$field] = $value;
            }
        }

        $users = !empty($filters)
            ? $this->panel->searchAdminUsers($filters)
            : $this->panel->adminUsers();

        $stats = $this->panel->systemStatistics('7day');
        $recentLogins = $this->panel->getRecentLogins(6);
        $suspiciousLogins = $this->panel->detectSuspiciousLogins();
        $userSummary = [
            'total' => count($users),
            'enabled' => count(array_filter($users, fn ($user) => !empty($user['enabled']))),
            'disabled' => count(array_filter($users, fn ($user) => empty($user['enabled']))),
            'admins' => count(array_filter($users, fn ($user) => ($user['level'] ?? '') === 'Admin')),
            'active' => count(array_filter($users, fn ($user) => (time() - strtotime($user['created_at'] ?? 0)) < 7 * 86400)),
            'new' => count(array_filter($users, fn ($user) => (time() - strtotime($user['created_at'] ?? 0)) < 24 * 3600)),
        ];

        return $this->render('admin/users', [
            'navKey'          => 'admin-users',
            'pageTitle'       => '用户管理',
            'pageHeadline'    => '用户列表与批量操作',
            'pageBlurb'       => '搜索、筛选、批量操作、登录安全和订阅状态在同一页面集中处理。',
            'users'           => $users,
            'filters'         => $filters,
            'summaryStats'    => $stats,
            'recentLogins'    => $recentLogins,
            'suspiciousLogins'=> $suspiciousLogins,
            'userSummary'     => $userSummary,
        ]);
    }

}
