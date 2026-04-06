<?php
declare (strict_types = 1);

namespace app\middleware;

use app\service\AuthService;
use Closure;
use think\Request;
use think\Response;

class RequireLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var AuthService $auth */
        $auth = app(AuthService::class);

        if (!$auth->check()) {
            if ($request->isAjax() || str_starts_with($request->pathinfo(), 'api/')) {
                return json([
                    'code'    => 401,
                    'message' => '登录状态已失效，请重新登录。',
                    'data'    => [],
                ], 401);
            }

            return redirect('/login');
        }

        return $next($request);
    }
}
