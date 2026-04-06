<?php
declare (strict_types = 1);

namespace app\service;

use app\model\VerificationCode;

class VerificationService
{
    public function send(string $email, string $scene): array
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        VerificationCode::where('email', strtolower(trim($email)))
            ->where('scene', $scene)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        VerificationCode::create([
            'email' => strtolower(trim($email)),
            'scene' => $scene,
            'code' => $code,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);

        MailService::queue(
            null,
            strtolower(trim($email)),
            '验证码 - ' . strtoupper($scene),
            '您的验证码是：' . $code . '，10 分钟内有效。',
            '<p>您的验证码是：<strong>' . $code . '</strong></p><p>10 分钟内有效。</p>',
            'verification'
        );

        return [
            'email' => strtolower(trim($email)),
            'scene' => $scene,
            'expires_at' => $expiresAt,
        ];
    }

    public function verify(string $email, string $scene, string $code): bool
    {
        $record = VerificationCode::where('email', strtolower(trim($email)))
            ->where('scene', $scene)
            ->where('code', trim($code))
            ->where('status', 'pending')
            ->order('id', 'desc')
            ->find();

        if (!$record) {
            return false;
        }

        if (strtotime((string) $record->expires_at) < time()) {
            $record->status = 'expired';
            $record->save();
            return false;
        }

        $record->status = 'verified';
        $record->verified_at = date('Y-m-d H:i:s');
        $record->save();

        return true;
    }
}
