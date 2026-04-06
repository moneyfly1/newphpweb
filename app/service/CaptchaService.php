<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Session;

class CaptchaService
{
    private const SESSION_KEY = 'cboard.captcha';

    public function generate(): array
    {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
        Session::set(self::SESSION_KEY, [
            'code' => $code,
            'expires_at' => time() + 300,
        ]);

        return [
            'code' => $code,
            'svg' => $this->svg($code),
        ];
    }

    public function verify(?string $input): bool
    {
        $captcha = Session::get(self::SESSION_KEY);
        if (!is_array($captcha) || empty($captcha['code']) || empty($captcha['expires_at'])) {
            return false;
        }

        if ((int) $captcha['expires_at'] < time()) {
            Session::delete(self::SESSION_KEY);
            return false;
        }

        $ok = strtoupper(trim((string) $input)) === strtoupper((string) $captcha['code']);
        if ($ok) {
            Session::delete(self::SESSION_KEY);
        }

        return $ok;
    }

    private function svg(string $code): string
    {
        $chars = str_split($code);
        $text = '';
        foreach ($chars as $index => $char) {
            $x = 18 + ($index * 22);
            $y = 28 + (($index % 2) * 4);
            $rotate = ($index % 2 === 0) ? -8 : 8;
            $text .= '<text x="' . $x . '" y="' . $y . '" font-size="20" transform="rotate(' . $rotate . ' ' . $x . ' ' . $y . ')" fill="#8f3512">' . $char . '</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40" viewBox="0 0 120 40"><rect width="120" height="40" fill="#fff8ee" rx="8" /><path d="M6 30 C 20 10, 40 10, 60 26 S 95 35, 114 14" stroke="#b95c2b" stroke-width="1.5" fill="none" opacity="0.35" />' . $text . '</svg>';
    }
}
