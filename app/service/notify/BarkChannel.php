<?php
declare(strict_types=1);

namespace app\service\notify;

use app\service\AppConfigService;
use think\facade\Log;

/**
 * Bark 推送通知通道
 * Bark 是一款 iOS 推送通知应用，通过简单的 HTTP 请求发送推送
 * API: https://api.day.app/{key}/{title}/{body}
 */
class BarkChannel
{
    private string $serverUrl = 'https://api.day.app';

    public function send(string $barkKey, string $title, string $body, array $options = []): bool
    {
        if ($barkKey === '' || $title === '') {
            return false;
        }

        $url = rtrim($this->getServerUrl(), '/') . '/' . urlencode($barkKey);

        $payload = array_merge([
            'title' => $title,
            'body'  => $body,
            'sound' => $options['sound'] ?? 'default',
            'group' => $options['group'] ?? 'CBoard',
        ], $options);

        // Remove empty values
        $payload = array_filter($payload, fn ($v) => $v !== '' && $v !== null);

        try {
            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                Log::warning('Bark 推送失败: 网络错误');
                return false;
            }

            $data = json_decode($result, true);
            if (($data['code'] ?? -1) !== 200) {
                Log::warning('Bark 推送失败: ' . ($data['message'] ?? '未知错误'));
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Bark 推送异常: ' . $e->getMessage());
            return false;
        }
    }

    public function isConfigured(): bool
    {
        $key = (string) app(AppConfigService::class)->get('notification.bark_key', '');
        return $key !== '';
    }

    private function getServerUrl(): string
    {
        $url = (string) app(AppConfigService::class)->get('notification.bark_server_url', '');
        return $url !== '' ? $url : $this->serverUrl;
    }
}
