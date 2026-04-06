<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\RouteNotFoundException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        RouteNotFoundException::class,
    ];

    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    public function render($request, Throwable $e): Response
    {
        // 调试模式下保留框架默认行为（显示详细错误）
        if (env('APP_DEBUG', false)) {
            return parent::render($request, $e);
        }

        // --- 以下为生产模式的友好错误处理 ---

        $isJson = $request->isJson() || $request->isAjax()
            || str_starts_with((string) $request->pathinfo(), 'api/')
            || str_starts_with((string) $request->pathinfo(), 'admin/api/');

        // 验证异常
        if ($e instanceof ValidateException) {
            if ($isJson) {
                return json(['code' => 422, 'message' => $e->getMessage(), 'data' => []], 422);
            }
            return $this->renderErrorPage(422, $e->getMessage());
        }

        // 路由未找到
        if ($e instanceof RouteNotFoundException) {
            if ($isJson) {
                return json(['code' => 404, 'message' => '请求的接口不存在。', 'data' => []], 404);
            }
            return $this->renderErrorPage(404, '页面不存在');
        }

        // 数据未找到
        if ($e instanceof ModelNotFoundException || $e instanceof DataNotFoundException) {
            if ($isJson) {
                return json(['code' => 404, 'message' => '数据不存在。', 'data' => []], 404);
            }
            return $this->renderErrorPage(404, '请求的数据不存在');
        }

        // HTTP 异常 (403, 404, 419, 500 等)
        if ($e instanceof HttpException) {
            $code = $e->getStatusCode();
            $msg  = $e->getMessage() ?: $this->defaultHttpMessage($code);
            if ($isJson) {
                return json(['code' => $code, 'message' => $msg, 'data' => []], $code);
            }
            return $this->renderErrorPage($code, $msg);
        }

        // 其他未知异常
        if ($isJson) {
            return json(['code' => 500, 'message' => '服务器内部错误，请稍后再试。', 'data' => []], 500);
        }
        return $this->renderErrorPage(500, '服务器内部错误，请稍后再试');
    }

    private function renderErrorPage(int $code, string $message): Response
    {
        $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>错误 {$code}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
background:#f5f7fa;color:#333;display:flex;align-items:center;justify-content:center;
min-height:100vh;padding:20px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);
padding:48px;text-align:center;max-width:480px;width:100%}
.code{font-size:72px;font-weight:700;color:#e2e8f0;line-height:1;margin-bottom:12px}
.msg{font-size:18px;color:#475569;margin-bottom:24px;line-height:1.6}
.actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.btn{display:inline-block;padding:10px 24px;border-radius:8px;font-size:14px;
text-decoration:none;font-weight:500;transition:all .2s}
.btn-primary{background:#3b82f6;color:#fff}
.btn-primary:hover{background:#2563eb}
.btn-ghost{background:#f1f5f9;color:#475569}
.btn-ghost:hover{background:#e2e8f0}
</style>
</head>
<body>
<div class="card">
<div class="code">{$code}</div>
<div class="msg">{$message}</div>
<div class="actions">
<a class="btn btn-primary" href="/">返回首页</a>
<a class="btn btn-ghost" href="javascript:history.back()">返回上页</a>
</div>
</div>
</body>
</html>
HTML;

        return Response::create($html, 'html', $code);
    }

    private function defaultHttpMessage(int $code): string
    {
        return match ($code) {
            400 => '请求参数有误',
            401 => '请先登录',
            403 => '没有权限访问',
            404 => '页面不存在',
            405 => '请求方法不允许',
            419 => '表单令牌已过期，请刷新页面重试',
            429 => '请求过于频繁，请稍后再试',
            500 => '服务器内部错误',
            502 => '网关错误',
            503 => '服务暂时不可用',
            default => '发生了一个错误',
        };
    }
}
