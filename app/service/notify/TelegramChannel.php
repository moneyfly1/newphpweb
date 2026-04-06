<?php
declare(strict_types=1);

namespace app\service\notify;

use app\service\AppConfigService;
use think\facade\Log;

class TelegramChannel
{
    public function send(string $chatId, string $message, ?string $botToken = null): bool
    {
        if ($chatId === '' || $message === '') {
            return false;
        }

        $token = $botToken ?: $this->getBotToken();
        if ($token === '') {
            Log::warning('Telegram 通知失败: Bot Token 未配置');
            return false;
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = json_encode([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ]);

        try {
            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 10,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                Log::warning('Telegram 通知发送失败: 网络错误');
                return false;
            }

            $data = json_decode($result, true);
            if (!($data['ok'] ?? false)) {
                Log::warning('Telegram 通知发送失败: ' . ($data['description'] ?? '未知错误'));
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram 通知异常: ' . $e->getMessage());
            return false;
        }
    }

    public function isConfigured(): bool
    {
        return $this->getBotToken() !== '';
    }

    private function getBotToken(): string
    {
        return (string) app(AppConfigService::class)->get('notification.telegram_bot_token', '');
    }
}
