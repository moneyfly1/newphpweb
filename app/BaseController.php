<?php
declare (strict_types = 1);

namespace app;

use app\service\AppConfigService;
use app\service\AuthService;
use app\service\PanelService;
use think\App;
use think\exception\ValidateException;
use think\Response;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    protected AuthService $auth;

    protected PanelService $panel;

    protected AppConfigService $settings;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        $this->auth    = app(AuthService::class);
        $this->panel   = app(PanelService::class);
        $this->settings = app(AppConfigService::class);

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    protected function render(string $template, array $data = []): Response
    {
        return view($template, array_merge($this->sharedViewData(), $data));
    }

    protected function sharedViewData(): array
    {
        $currentUser = $this->auth->user();

        return [
            'appName'        => $this->settings->appName(),
            'baseUrl'        => $this->settings->baseUrl(),
            'currentUser'    => $currentUser,
            'csrfToken'      => $this->auth->csrfToken(),
            'currentPath'    => $this->request->pathinfo(),
            'pageTitle'      => '',
            'pageHeadline'   => '',
            'pageBlurb'      => '',
            'navKey'         => '',
            'error'          => '',
            'old'            => [],
            'userOverview'   => $currentUser && $currentUser['role'] === 'user' ? $this->panel->userOverview() : null,
            'paymentMethods' => $this->settings->paymentMethods(),
            'clientFormats'  => $this->settings->clientFormats(),
            'isShadowSession'=> $this->auth->isShadowSession(),
        ];
    }

    protected function jsonSuccess(string $message, array $data = []): Response
    {
        return json([
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    protected function jsonError(string $message, int $code = 422, array $data = []): Response
    {
        return json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ], $code >= 400 ? $code : 400);
    }

    protected function requireCsrf(): void
    {
        $token = (string) ($this->request->post('csrf_token')
            ?: $this->request->header('X-CSRF-TOKEN')
            ?: $this->request->header('x-csrf-token'));

        if (!$this->auth->verifyCsrf($token)) {
            abort(419, '表单令牌无效，请刷新后重试。');
        }
    }

    protected function redirectTo(string $path): Response
    {
        return redirect($path);
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

}
