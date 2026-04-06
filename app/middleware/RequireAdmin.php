<?php
declare (strict_types = 1);

namespace app\middleware;

use app\service\AuthService;
use Closure;
use think\Request;
use think\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AuthService $auth */
        $auth = app(AuthService::class);

        if (!$auth->check()) {
            return redirect('/login');
        }

        if (!$auth->isAdmin()) {
            if ($request->isAjax() || str_starts_with($request->pathinfo(), 'api/')) {
                return json([
                    'code'    => 403,
                    'message' => '当前账号没有管理端权限。',
                    'data'    => [],
                ], 403);
            }

            return redirect('/dashboard');
        }

        return $next($request);
    }
}
