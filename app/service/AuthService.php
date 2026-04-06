<?php
declare (strict_types = 1);

namespace app\service;

use app\model\User;
use think\facade\Session;

class AuthService
{
    private const SESSION_KEY = 'cboard.auth_user';
    private const ADMIN_SHADOW_KEY = 'cboard.admin_shadow';
    private const ADMIN_SHADOW_TARGET_KEY = 'cboard.admin_shadow_target';
    private const CSRF_KEY = 'cboard.csrf_token';

    public function check(): bool
    {
        return (bool) Session::get(self::SESSION_KEY);
    }

    public function user(): ?array
    {
        $user = Session::get(self::SESSION_KEY);

        return is_array($user) ? $user : null;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? null) === 'admin';
    }

    public function attempt(string $email, string $password): ?array
    {
        $user = User::where('email', strtolower(trim($email)))
            ->where('status', 1)
            ->find();

        if (!$user || !password_verify($password, (string) $user->password_hash)) {
            return null;
        }

        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save();

        $payload = $this->sessionPayload($user);
        Session::set(self::SESSION_KEY, $payload);
        return $payload;
    }

    public function loginAsUserById(int $userId): bool
    {
        $admin = $this->user();
        if (!$admin || ($admin['role'] ?? null) !== 'admin') {
            return false;
        }

        $user = User::where('id', $userId)->where('status', 1)->find();
        if (!$user) {
            return false;
        }

        Session::set(self::ADMIN_SHADOW_KEY, $admin);
        Session::set(self::ADMIN_SHADOW_TARGET_KEY, (int) $user->id);
        Session::set(self::SESSION_KEY, $this->sessionPayload($user) + ['is_shadow' => true, 'shadow_admin_id' => $admin['id']]);
        return true;
    }

    public function exitShadowSession(): bool
    {
        $admin = Session::get(self::ADMIN_SHADOW_KEY);
        $targetUserId = Session::get(self::ADMIN_SHADOW_TARGET_KEY);
        $current = $this->user();

        if (!is_array($admin) || $admin === [] || !is_numeric($targetUserId)) {
            return false;
        }

        if (!is_array($current) || empty($current['is_shadow']) || (int) ($current['id'] ?? 0) !== (int) $targetUserId) {
            return false;
        }

        Session::set(self::SESSION_KEY, $admin);
        Session::delete(self::ADMIN_SHADOW_KEY);
        Session::delete(self::ADMIN_SHADOW_TARGET_KEY);
        return true;
    }

    public function isShadowSession(): bool
    {
        return (bool) ((Session::get(self::SESSION_KEY)['is_shadow'] ?? false));
    }

    public function csrfToken(): string
    {
        $token = Session::get(self::CSRF_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(20));
        Session::set(self::CSRF_KEY, $token);

        return $token;
    }

    public function verifyCsrf(?string $token): bool
    {
        return is_string($token) && hash_equals($this->csrfToken(), $token);
    }

    public function refreshUser(array $patch): void
    {
        $user = $this->user();
        if (!$user) {
            return;
        }

        Session::set(self::SESSION_KEY, array_merge($user, $patch));
    }

    private function buildAvatar(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'CB';
        }

        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars) {
            return 'CB';
        }

        return strtoupper((string) ($chars[0] ?? 'C') . (string) ($chars[1] ?? 'B'));
    }

    public function refreshFromDatabase(int $userId): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        Session::set(self::SESSION_KEY, $this->sessionPayload($user));
    }

    private function sessionPayload(User $user): array
    {
        $display = (string) ($user->nickname ?: $user->email);

        return [
            'id'       => (int) $user->id,
            'name'     => $display,
            'email'    => (string) $user->email,
            'role'     => (int) $user->role === 1 ? 'admin' : 'user',
            'headline' => (int) $user->role === 1 ? '后台管理账号' : '网站用户账号',
            'avatar'   => $this->buildAvatar($display),
        ];
    }
}
