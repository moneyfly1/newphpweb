<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\User;
use app\service\CaptchaService;
use app\service\VerificationService;

class AuthController extends BaseController
{
    private function authSettings(): array
    {
        $advanced = $this->panel->advancedSettings();

        return [
            'allow_registration' => (bool) ($advanced['system']['allow_registration'] ?? true),
            'require_email_verification' => (bool) ($advanced['system']['require_email_verification'] ?? false),
            'require_captcha' => (bool) ($advanced['security']['mfa_enabled'] ?? false),
        ];
    }

    private function captchaService(): CaptchaService
    {
        return app(CaptchaService::class);
    }

    private function verifyService(): VerificationService
    {
        return app(VerificationService::class);
    }

    public function showLogin()
    {
        if ($this->auth->check()) {
            return $this->redirectTo($this->auth->isAdmin() ? '/admin/dashboard' : '/dashboard');
        }

        $site = $this->panel->adminSiteSettings();

        return $this->render('auth/login', [
            'pageTitle'    => '登录 ' . $this->settings->appName(),
            'loginNotice'  => $site['login_notice'],
            'supportEmail' => $site['support_email'],
            'authSettings' => $this->authSettings(),
            'captcha'      => $this->captchaService()->generate(),
        ]);
    }

    public function login()
    {
        $this->requireCsrf();

        $data = $this->request->only(['email', 'password', 'captcha']);

        try {
            $this->validate($data, [
                'email'    => 'require|email',
                'password' => 'require|min:6',
            ]);
        } catch (\think\exception\ValidateException $e) {
            $site = $this->panel->adminSiteSettings();
            return $this->render('auth/login', [
                'pageTitle'    => '登录 ' . $this->settings->appName(),
                'loginNotice'  => $site['login_notice'],
                'supportEmail' => $site['support_email'],
                'authSettings' => $this->authSettings(),
                'captcha'      => $this->captchaService()->generate(),
                'error'        => $e->getMessage(),
                'old'          => ['email' => $data['email'] ?? ''],
            ]);
        }

        $site = $this->panel->adminSiteSettings();

        if ($this->authSettings()['require_captcha'] && trim((string) ($data['captcha'] ?? '')) === '') {
            return $this->render('auth/login', [
                'pageTitle'    => '登录 ' . $this->settings->appName(),
                'loginNotice'  => $site['login_notice'],
                'supportEmail' => $site['support_email'],
                'authSettings' => $this->authSettings(),
                'captcha'      => $this->captchaService()->generate(),
                'error'        => '请输入图形验证码。',
                'old'          => ['email' => $data['email']],
            ]);
        }

        if ($this->authSettings()['require_captcha'] && !$this->captchaService()->verify((string) $data['captcha'])) {
            return $this->render('auth/login', [
                'pageTitle'    => '登录 ' . $this->settings->appName(),
                'loginNotice'  => $site['login_notice'],
                'supportEmail' => $site['support_email'],
                'authSettings' => $this->authSettings(),
                'captcha'      => $this->captchaService()->generate(),
                'error'        => '图形验证码无效或已过期。',
                'old'          => ['email' => $data['email']],
            ]);
        }

        $user = $this->auth->attempt((string) $data['email'], (string) $data['password']);
        if (!$user) {
            return $this->render('auth/login', [
                'pageTitle'    => '登录 ' . $this->settings->appName(),
                'loginNotice'  => $site['login_notice'],
                'supportEmail' => $site['support_email'],
                'authSettings' => $this->authSettings(),
                'captcha'      => $this->captchaService()->generate(),
                'error'        => '邮箱或密码错误。',
                'old'          => ['email' => $data['email']],
            ]);
        }

        return $this->redirectTo($user['role'] === 'admin' ? '/admin/dashboard' : '/dashboard');
    }

    public function sendVerificationCode()
    {
        $this->requireCsrf();
        $email = strtolower(trim((string) $this->request->post('email')));
        $scene = trim((string) $this->request->post('scene', 'register'));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('邮箱格式不正确。', 422);
        }

        $allowedScenes = ['register', 'forgot_password'];
        if (!in_array($scene, $allowedScenes, true)) {
            return $this->jsonError('验证码场景无效。', 422);
        }

        $data = $this->verifyService()->send($email, $scene);
        return $this->jsonSuccess('验证码已发送，请查收邮件。', $data);
    }

    public function register()
    {
        $this->requireCsrf();
        $settings = $this->authSettings();
        if (!$settings['allow_registration']) {
            return $this->jsonError('当前站点未开放注册。', 422);
        }

        $data = $this->request->only(['email', 'nickname', 'password', 'password_confirm', 'verification_code', 'captcha']);

        try {
            $this->validate($data, [
                'email'            => 'require|email',
                'nickname'         => 'require|min:2|max:60',
                'password'         => 'require|min:6|max:128',
                'password_confirm' => 'require|min:6|max:128',
            ]);
        } catch (\think\exception\ValidateException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        if ((string) $data['password'] !== (string) $data['password_confirm']) {
            return $this->jsonError('两次输入的密码不一致。', 422);
        }

        $email = strtolower(trim((string) $data['email']));

        if ($settings['require_email_verification'] && trim((string) ($data['verification_code'] ?? '')) === '') {
            return $this->jsonError('当前注册需要邮箱验证码。', 422);
        }

        if ($settings['require_email_verification'] && !$this->verifyService()->verify($email, 'register', (string) $data['verification_code'])) {
            return $this->jsonError('邮箱验证码无效或已过期。', 422);
        }

        if ($settings['require_captcha'] && trim((string) ($data['captcha'] ?? '')) === '') {
            return $this->jsonError('当前注册需要验证码。', 422);
        }

        if ($settings['require_captcha'] && !$this->captchaService()->verify((string) $data['captcha'])) {
            return $this->jsonError('图形验证码无效或已过期。', 422);
        }

        if (User::where('email', $email)->find()) {
            return $this->jsonError('该邮箱已注册。', 422);
        }

        $inviteCode = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $user = User::create([
            'email' => $email,
            'nickname' => trim((string) $data['nickname']),
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'role' => 0,
            'status' => 1,
            'balance' => 0,
            'invite_code' => $inviteCode,
        ]);

        $payload = $this->auth->attempt($email, (string) $data['password']);
        if ($payload) {
            return $this->jsonSuccess('注册成功。', ['redirect' => '/dashboard', 'user_id' => $user->id]);
        }

        return $this->jsonSuccess('注册成功。', ['user_id' => $user->id]);
    }

    public function refreshCaptcha()
    {
        $this->requireCsrf();
        return $this->jsonSuccess('验证码已刷新。', $this->captchaService()->generate());
    }

    public function forgotPassword()
    {
        $this->requireCsrf();
        $settings = $this->authSettings();

        $data = $this->request->only(['email', 'verification_code', 'captcha', 'new_password', 'confirm_password']);

        try {
            $this->validate($data, [
                'email'            => 'require|email',
                'new_password'     => 'require|min:6|max:128',
                'confirm_password' => 'require|min:6|max:128',
            ]);
        } catch (\think\exception\ValidateException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        if ((string) $data['new_password'] !== (string) $data['confirm_password']) {
            return $this->jsonError('两次输入的新密码不一致。', 422);
        }

        // 忘记密码必须验证邮箱验证码
        if (trim((string) ($data['verification_code'] ?? '')) === '') {
            return $this->jsonError('请输入邮箱验证码。', 422);
        }

        if (!$this->verifyService()->verify((string) $data['email'], 'forgot_password', (string) $data['verification_code'])) {
            return $this->jsonError('邮箱验证码无效或已过期。', 422);
        }

        if ($settings['require_captcha'] && trim((string) ($data['captcha'] ?? '')) === '') {
            return $this->jsonError('当前找回密码需要验证码。', 422);
        }

        if ($settings['require_captcha'] && !$this->captchaService()->verify((string) $data['captcha'])) {
            return $this->jsonError('图形验证码无效或已过期。', 422);
        }

        $user = User::where('email', strtolower(trim((string) $data['email'])))->find();
        if (!$user) {
            return $this->jsonError('该邮箱不存在。', 422);
        }

        $user->password_hash = password_hash((string) $data['new_password'], PASSWORD_DEFAULT);
        $user->save();

        \app\service\NotificationService::notify((int) $user->id, \app\service\NotificationService::PASSWORD_CHANGED, [
            '修改时间' => date('Y-m-d H:i:s'), '操作' => '忘记密码重置',
        ]);

        return $this->jsonSuccess('密码已重置，请重新登录。');
    }

    public function adminEnterUser(int $id)
    {
        $this->requireCsrf();
        if (!$this->auth->isAdmin()) {
            return $this->jsonError('没有权限执行该操作。', 403);
        }

        if (!$this->auth->loginAsUserById($id)) {
            return $this->jsonError('进入用户后台失败。', 422);
        }

        return $this->jsonSuccess('已进入用户后台。', ['redirect' => '/dashboard']);
    }

    public function adminExitUser()
    {
        $this->requireCsrf();
        if (!$this->auth->exitShadowSession()) {
            return $this->jsonError('当前不是代入用户会话。', 422);
        }

        return $this->jsonSuccess('已返回管理员后台。', ['redirect' => '/admin/dashboard']);
    }

    public function logout()
    {
        $this->requireCsrf();
        $this->auth->logout();

        return $this->redirectTo('/login');
    }
}
